<?php
declare(strict_types=1);

function get_dashboard_attendance_activity_data(array $user, array $params = []): array
{
    $pdo = db();
    $date = !empty($params['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $params['date']) ? $params['date'] : date('Y-m-d');
    $range = in_array($params['range'] ?? '', ['day', 'week', 'month'], true) ? $params['range'] : 'day';

    $gymId = isset($params['gym_id']) ? (int)$params['gym_id'] : (int)(get_user_gym_id($user) ?? 0);
    if ($gymId <= 0) {
        $gymId = (int) scalar('SELECT gym_id FROM attendance WHERE user_id = ? ORDER BY attendance_id DESC LIMIT 1', [$user['user_id']]);
    }

    $gymName = 'All Gyms';
    if ($gymId > 0) {
        $gName = scalar('SELECT name FROM gyms WHERE gym_id = ?', [$gymId]);
        if ($gName) $gymName = (string)$gName;
    }

    // Attendance Roster Query for the selected date
    $rosterStmt = $pdo->prepare("
        SELECT 
            a.attendance_id,
            a.user_id,
            a.gym_id,
            a.check_in_time,
            a.check_out_time,
            a.check_in_method,
            u.first_name,
            u.last_name,
            u.email,
            u.profile_picture,
            u.role,
            g.name AS gym_name
        FROM attendance a
        JOIN users u ON u.user_id = a.user_id
        LEFT JOIN gyms g ON g.gym_id = a.gym_id
        WHERE (DATE(a.check_in_time) = :date OR DATE(a.check_out_time) = :date)
          AND (:gym_id = 0 OR a.gym_id = :gym_id)
        ORDER BY a.check_in_time DESC
    ");
    $rosterStmt->execute(['date' => $date, 'gym_id' => $gymId]);
    $rawRoster = $rosterStmt->fetchAll(PDO::FETCH_ASSOC);

    $nowTs = time();
    $roster = [];
    $totalCheckins = 0;
    $totalCheckouts = 0;
    $currentlyInside = 0;

    foreach ($rawRoster as $row) {
        $inTs = !empty($row['check_in_time']) ? strtotime($row['check_in_time']) : null;
        $outTs = !empty($row['check_out_time']) ? strtotime($row['check_out_time']) : null;
        
        $isCheckedIn = empty($outTs);
        if ($inTs && date('Y-m-d', $inTs) === $date) {
            $totalCheckins++;
        }
        if ($outTs && date('Y-m-d', $outTs) === $date) {
            $totalCheckouts++;
        }
        if ($isCheckedIn) {
            $currentlyInside++;
        }

        // Duration string calculation
        $durationStr = '—';
        if ($inTs) {
            if ($outTs) {
                $diffSec = max(0, $outTs - $inTs);
                $hrs = (int) floor($diffSec / 3600);
                $mins = (int) round(($diffSec % 3600) / 60);
                if ($hrs > 0) {
                    $durationStr = $hrs . 'h ' . ($mins > 0 ? $mins . 'm' : '');
                } else {
                    $durationStr = max(1, $mins) . 'm';
                }
            } else {
                $diffSec = max(0, $nowTs - $inTs);
                $hrs = (int) floor($diffSec / 3600);
                $mins = (int) round(($diffSec % 3600) / 60);
                $durationStr = 'Active (' . ($hrs > 0 ? $hrs . 'h ' : '') . max(1, $mins) . 'm)';
            }
        }

        $memberIdStr = '#MEM-' . str_pad((string)$row['user_id'], 4, '0', STR_PAD_LEFT);
        $fullName = trim($row['first_name'] . ' ' . $row['last_name']);

        $roster[] = [
            'attendance_id'       => (int) $row['attendance_id'],
            'user_id'             => (int) $row['user_id'],
            'member_id'           => $memberIdStr,
            'name'                => $fullName,
            'email'               => $row['email'],
            'profile_picture'     => $row['profile_picture'] ?? null,
            'check_in_time'       => $row['check_in_time'],
            'check_in_formatted'  => $inTs ? date('h:i A', $inTs) : '—',
            'check_out_time'      => $row['check_out_time'],
            'check_out_formatted' => $outTs ? date('h:i A', $outTs) : 'Still Inside',
            'is_checked_in'       => $isCheckedIn,
            'status'              => $isCheckedIn ? 'checked_in' : 'checked_out',
            'status_label'        => $isCheckedIn ? 'Checked In' : 'Checked Out',
            'duration'            => $durationStr,
            'gym_name'            => $row['gym_name'] ?: $gymName
        ];
    }

    // Peak Hours Analysis
    $chartLabels = [];
    $chartData = [];
    $peakHourLabel = 'No Activity';
    $peakMaxCount = 0;
    $peakIndices = [];

    if ($range === 'day') {
        $hrStmt = $pdo->prepare("
            SELECT HOUR(check_in_time) AS hr, COUNT(*) AS cnt
            FROM attendance
            WHERE DATE(check_in_time) = :date
              AND (:gym_id = 0 OR gym_id = :gym_id)
            GROUP BY HOUR(check_in_time)
        ");
        $hrStmt->execute(['date' => $date, 'gym_id' => $gymId]);
    } elseif ($range === 'week') {
        $targetDt = new DateTime($date);
        $dStart = (clone $targetDt)->modify('-6 days')->format('Y-m-d');
        $dEnd = $targetDt->format('Y-m-d');

        $hrStmt = $pdo->prepare("
            SELECT HOUR(check_in_time) AS hr, COUNT(*) AS cnt
            FROM attendance
            WHERE DATE(check_in_time) BETWEEN :d_start AND :d_end
              AND (:gym_id = 0 OR gym_id = :gym_id)
            GROUP BY HOUR(check_in_time)
        ");
        $hrStmt->execute(['d_start' => $dStart, 'd_end' => $dEnd, 'gym_id' => $gymId]);
    } elseif ($range === 'month') {
        $yearMonth = date('Y-m', strtotime($date));

        $hrStmt = $pdo->prepare("
            SELECT HOUR(check_in_time) AS hr, COUNT(*) AS cnt
            FROM attendance
            WHERE DATE_FORMAT(check_in_time, '%Y-%m') = :ym
              AND (:gym_id = 0 OR gym_id = :gym_id)
            GROUP BY HOUR(check_in_time)
        ");
        $hrStmt->execute(['ym' => $yearMonth, 'gym_id' => $gymId]);
    }

    $hourCounts = array_fill(5, 19, 0); // 5 AM to 11 PM
    if (isset($hrStmt)) {
        foreach ($hrStmt->fetchAll(PDO::FETCH_ASSOC) as $hRow) {
            $h = (int) $hRow['hr'];
            if ($h >= 5 && $h <= 23) {
                $hourCounts[$h] = (int) $hRow['cnt'];
            }
        }
    }

    foreach ($hourCounts as $hour => $cnt) {
        $chartLabels[] = date('g A', strtotime("$hour:00"));
        $chartData[] = $cnt;
        if ($cnt > $peakMaxCount) {
            $peakMaxCount = $cnt;
        }
    }

    if ($peakMaxCount > 0) {
        $peakHoursList = [];
        foreach ($chartData as $k => $c) {
            if ($c === $peakMaxCount) {
                $peakIndices[] = $k;
                $peakHoursList[] = $chartLabels[$k];
            }
        }
        $peakHourLabel = implode(', ', $peakHoursList) . " ($peakMaxCount " . ($peakMaxCount === 1 ? 'check-in' : 'check-ins') . ')';
    }

    return [
        'date'               => $date,
        'date_formatted'     => date('F j, Y', strtotime($date)),
        'range'              => $range,
        'gym_id'             => $gymId,
        'gym_name'           => $gymName,
        'total_checkins'     => $totalCheckins,
        'total_checkouts'    => $totalCheckouts,
        'currently_inside'   => $currentlyInside,
        'peak_hour_label'    => $peakHourLabel,
        'peak_max_count'     => $peakMaxCount,
        'peak_indices'       => $peakIndices,
        'chart'              => [
            'labels'       => $chartLabels,
            'data'         => $chartData,
            'peak_indices' => $peakIndices
        ],
        'roster'             => $roster,
        'roster_count'       => count($roster),
        'last_updated'       => date('h:i:s A')
    ];
}

function member_dashboard(PDO $pdo, array $user): void
{
    // Use cached engagement score if computed within the last hour to prevent redundant query load
    $scoreStmt = $pdo->prepare('SELECT engagement_score, engagement_computed_at FROM users WHERE user_id = ?');
    $scoreStmt->execute([(int)$user['user_id']]);
    $scoreRow = $scoreStmt->fetch(PDO::FETCH_ASSOC);
    $isRecent = !empty($scoreRow['engagement_computed_at']) && (time() - strtotime((string)$scoreRow['engagement_computed_at']) < 3600);
    $score = ($isRecent && isset($scoreRow['engagement_score'])) ? (int)$scoreRow['engagement_score'] : calculate_engagement_score((int) $user['user_id']);
    $category = get_engagement_category($score);
    $attendance = scalar('SELECT COUNT(*) FROM attendance WHERE user_id = ?', [$user['user_id']]);
    $progressLogs = scalar('SELECT COUNT(*) FROM progress_logs WHERE user_id = ?', [$user['user_id']]);
    $classBookings = scalar('SELECT COUNT(*) FROM class_bookings WHERE user_id = ?', [$user['user_id']]);

    $stmt = db()->prepare('SELECT fitness_tier FROM member_profiles WHERE user_id = ?');
    $stmt->execute([$user['user_id']]);
    $tier = (int) ($stmt->fetchColumn() ?: 1);
    $tierName = get_fitness_tier_name($tier);

    $actInitialData = get_dashboard_attendance_activity_data($user, ['date' => date('Y-m-d'), 'range' => 'day']);

    // Skeleton for member dashboard
    render_skeleton_banner();
    render_skeleton_stats(3);
    ?>

    <style>
    /* Member Dashboard Tab Switcher */
    .member-dashboard-nav {
        display: flex;
        gap: 6px;
        margin-bottom: 24px;
        background: rgba(16, 19, 27, 0.85);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 5px;
        position: sticky;
        top: 78px;
        z-index: 15;
        box-shadow: 0 4px 20px rgba(0,0,0,0.18);
        overflow-x: auto;
        scrollbar-width: none;
        -webkit-overflow-scrolling: touch;
    }
    .member-dashboard-nav::-webkit-scrollbar {
        display: none;
    }
    .member-dash-tab {
        flex: 1 1 auto;
        min-width: max-content;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 10px 14px;
        border-radius: 8px;
        border: 1px solid transparent;
        background: transparent;
        color: var(--muted);
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        white-space: nowrap;
        text-decoration: none;
    }
    .member-dash-tab svg {
        flex-shrink: 0;
        transition: stroke 0.2s ease, transform 0.2s ease;
    }
    .member-dash-tab:hover {
        color: var(--ink);
        background: color-mix(in srgb, var(--ink) 6%, transparent);
    }
    .member-dash-tab.active {
        color: var(--lime);
        background: color-mix(in srgb, var(--lime) 14%, var(--panel-soft));
        border-color: color-mix(in srgb, var(--lime) 40%, transparent);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
    }
    .member-dash-tab.active svg {
        stroke: var(--lime);
        transform: scale(1.08);
    }

    /* Responsive tab labels - prevents any truncation on smaller screens */
    .tab-label-full {
        display: inline;
    }
    .tab-label-compact {
        display: none;
    }
    @media (max-width: 860px) {
        .tab-label-full {
            display: none;
        }
        .tab-label-compact {
            display: inline;
        }
        .member-dash-tab {
            padding: 9px 10px;
            font-size: 12.5px;
            gap: 6px;
        }
    }
    @media (max-width: 640px) {
        .member-dashboard-nav {
            top: 58px;
            padding: 4px;
            gap: 4px;
        }
        .member-dash-tab {
            padding: 8px 6px;
            font-size: 12px;
            gap: 5px;
        }
        .member-dash-tab svg {
            width: 14px;
            height: 14px;
        }
    }

    /* Light Theme Styling */
    [data-theme="light"] .member-dashboard-nav {
        background: rgba(255, 255, 255, 0.96);
        border-color: #cbd5e1;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
    }
    [data-theme="light"] .member-dash-tab {
        color: #475569;
    }
    [data-theme="light"] .member-dash-tab:hover {
        color: #0f172a;
        background: #f1f5f9;
    }
    [data-theme="light"] .member-dash-tab.active {
        color: #166534;
        background: #dcfce7;
        border-color: #86efac;
        box-shadow: 0 2px 8px rgba(22, 101, 52, 0.15);
    }
    [data-theme="light"] .member-dash-tab.active svg {
        stroke: #166534;
    }

    .member-tab-panel {
        display: none;
        animation: memberTabFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .member-tab-panel.active {
        display: block;
    }
    @keyframes memberTabFadeIn {
        from { opacity: 0; transform: translateY(6px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Quick Action Strip */
    .member-quick-actions {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 180px), 1fr));
        gap: 12px;
        margin-bottom: 24px;
    }
    .member-action-pill {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 10px;
        color: var(--ink);
        text-decoration: none;
        font-size: 13px;
        font-weight: 700;
        transition: all 0.2s ease;
    }
    .member-action-pill:hover {
        border-color: var(--lime);
        background: color-mix(in srgb, var(--lime) 8%, var(--surface));
        transform: translateY(-2px);
        box-shadow: 0 4px 14px rgba(0,0,0,0.15);
    }
    .member-action-icon {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        background: rgba(199,255,34,0.12);
        border: 1px solid rgba(199,255,34,0.3);
        color: var(--lime);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    [data-theme="light"] .member-action-pill {
        background: #ffffff;
        border-color: #cbd5e1;
        color: #1e293b;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
    }
    [data-theme="light"] .member-action-pill:hover {
        background: #f8fafc;
        border-color: #84cc16;
    }
    [data-theme="light"] .member-action-icon {
        background: #ecfccb;
        border-color: #a3e635;
        color: #4d7c0f;
    }

    /* Member Key Stats Grid */
    body.loaded .member-stats-grid,
    .member-stats-grid {
        display: grid !important;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 200px), 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .member-stat-card {
        background: linear-gradient(135deg, rgba(22, 27, 39, 0.85) 0%, rgba(15, 19, 28, 0.95) 100%);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 14px;
        padding: 18px 20px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        text-decoration: none;
        color: inherit;
        position: relative;
        overflow: hidden;
        transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
    }
    .member-stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 28px rgba(0, 0, 0, 0.28);
    }
    .member-stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        opacity: 0;
        transition: opacity 0.25s ease;
    }
    .member-stat-card:hover::before {
        opacity: 1;
    }
    .stat-card-attendance::before { background: var(--lime); }
    .stat-card-progress::before { background: var(--teal); }
    .stat-card-classes::before { background: #a855f7; }

    .stat-card-attendance:hover { border-color: color-mix(in srgb, var(--lime) 40%, var(--line)); }
    .stat-card-progress:hover { border-color: color-mix(in srgb, var(--teal) 40%, var(--line)); }
    .stat-card-classes:hover { border-color: color-mix(in srgb, #a855f7 40%, var(--line)); }

    .member-stat-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 12px;
    }
    .member-stat-label {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--muted);
    }
    .member-stat-icon-wrap {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: transform 0.25s ease;
    }
    .member-stat-card:hover .member-stat-icon-wrap {
        transform: scale(1.08);
    }

    .icon-accent-attendance {
        background: color-mix(in srgb, var(--lime) 15%, transparent);
        color: var(--lime);
        border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
    }
    .icon-accent-progress {
        background: color-mix(in srgb, var(--teal) 15%, transparent);
        color: var(--teal);
        border: 1px solid color-mix(in srgb, var(--teal) 30%, transparent);
    }
    .icon-accent-classes {
        background: rgba(168, 85, 247, 0.15);
        color: #a855f7;
        border: 1px solid rgba(168, 85, 247, 0.3);
    }

    .member-stat-mid {
        display: flex;
        align-items: baseline;
        gap: 6px;
        margin-bottom: 14px;
    }
    .member-stat-num {
        font-size: 32px;
        font-weight: 800;
        color: var(--ink);
        line-height: 1;
        letter-spacing: -0.02em;
    }
    .member-stat-unit {
        font-size: 13px;
        color: var(--muted);
        font-weight: 600;
    }
    .member-stat-bot {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding-top: 10px;
        border-top: 1px solid rgba(255, 255, 255, 0.05);
        font-size: 11.5px;
    }
    .member-stat-hint {
        color: var(--muted);
        font-weight: 500;
    }
    .member-stat-cta {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        font-weight: 700;
        color: var(--ink);
        transition: gap 0.2s ease, color 0.2s ease;
    }
    .member-stat-card:hover .member-stat-cta {
        gap: 6px;
    }
    .stat-card-attendance:hover .member-stat-cta { color: var(--lime); }
    .stat-card-progress:hover .member-stat-cta { color: var(--teal); }
    .stat-card-classes:hover .member-stat-cta { color: #a855f7; }

    /* Missions & Leaderboard Smooth Panels */
    .missions-panel-card {
        background: linear-gradient(135deg, rgba(22, 27, 39, 0.85) 0%, rgba(15, 19, 28, 0.95) 100%);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 14px;
        padding: 20px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        transition: all 0.25s ease;
    }
    .missions-panel-card:hover {
        border-color: rgba(255, 255, 255, 0.14);
    }
    html[data-theme="light"] .missions-panel-card,
    [data-theme="light"] .missions-panel-card {
        background: #ffffff !important;
        border: 1px solid #cbd5e1 !important;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04) !important;
    }

    /* Mission Item Smooth Cards */
    .mission-card-item {
        display: flex;
        align-items: center;
        gap: 16px;
        background: linear-gradient(135deg, rgba(22, 27, 39, 0.6) 0%, rgba(15, 19, 28, 0.75) 100%);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 12px;
        padding: 14px 18px;
        margin-bottom: 10px;
        position: relative;
        overflow: hidden;
        transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    }
    .mission-card-item:hover {
        transform: translateY(-2px);
        border-color: color-mix(in srgb, var(--lime) 35%, var(--line));
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.18);
    }
    .mission-card-item::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        bottom: 0;
        width: 3px;
        background: var(--lime);
        opacity: 0;
        transition: opacity 0.25s ease;
    }
    .mission-card-item:hover::before {
        opacity: 1;
    }
    .mission-card-item.mission-completed {
        background: linear-gradient(135deg, rgba(22, 27, 39, 0.35) 0%, rgba(15, 19, 28, 0.45) 100%);
        border-color: color-mix(in srgb, #22c55e 20%, var(--line));
    }
    .mission-card-item.mission-completed::before {
        background: #22c55e;
        opacity: 0.5;
    }
    html[data-theme="light"] .mission-card-item,
    [data-theme="light"] .mission-card-item {
        background: #ffffff !important;
        border: 1px solid #e2e8f0 !important;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.03) !important;
    }
    html[data-theme="light"] .mission-card-item:hover,
    [data-theme="light"] .mission-card-item:hover {
        background: #ffffff !important;
        border-color: #84cc16 !important;
        box-shadow: 0 4px 14px rgba(101, 163, 13, 0.1) !important;
    }
    html[data-theme="light"] .mission-card-item.mission-completed,
    [data-theme="light"] .mission-card-item.mission-completed {
        background: #f8fafc !important;
        border-color: #bbf7d0 !important;
    }

    /* Explore Item Smooth Cards */
    .explore-card-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        background: linear-gradient(135deg, rgba(22, 27, 39, 0.6) 0%, rgba(15, 19, 28, 0.75) 100%);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 12px;
        padding: 14px 18px;
        margin-bottom: 10px;
        position: relative;
        overflow: hidden;
        transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    }
    .explore-card-item:hover {
        transform: translateY(-2px);
        border-color: color-mix(in srgb, var(--lime) 35%, var(--line));
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.18);
    }
    .explore-card-item::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        bottom: 0;
        width: 3px;
        background: var(--lime);
        opacity: 0;
        transition: opacity 0.25s ease;
    }
    .explore-card-item:hover::before {
        opacity: 1;
    }
    .explore-card-item.accent-gym:hover {
        border-color: rgba(168, 85, 247, 0.4);
    }
    .explore-card-item.accent-gym::before {
        background: #a855f7;
    }
    html[data-theme="light"] .explore-card-item,
    [data-theme="light"] .explore-card-item {
        background: #ffffff !important;
        border: 1px solid #e2e8f0 !important;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.03) !important;
    }
    html[data-theme="light"] .explore-card-item:hover,
    [data-theme="light"] .explore-card-item:hover {
        background: #ffffff !important;
        border-color: #84cc16 !important;
        box-shadow: 0 4px 14px rgba(101, 163, 13, 0.1) !important;
    }
    html[data-theme="light"] .explore-card-item.accent-gym:hover,
    [data-theme="light"] .explore-card-item.accent-gym:hover {
        border-color: #a855f7 !important;
        box-shadow: 0 4px 14px rgba(168, 85, 247, 0.12) !important;
    }

    /* Explore Header & Action Buttons */
    .explore-browse-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 18px;
        background: var(--lime);
        color: #080b0d !important;
        font-size: 13px;
        font-weight: 700;
        border-radius: 10px;
        text-decoration: none !important;
        border: 1px solid transparent;
        box-shadow: 0 4px 14px color-mix(in srgb, var(--lime) 28%, transparent);
        transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        cursor: pointer;
    }
    .explore-browse-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px color-mix(in srgb, var(--lime) 45%, transparent);
        filter: brightness(1.06);
        color: #080b0d !important;
        text-decoration: none !important;
    }
    .explore-browse-btn svg {
        transition: transform 0.2s ease;
    }
    .explore-browse-btn:hover svg {
        transform: translateX(3px);
    }

    .explore-action-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 7px 15px;
        font-size: 12.5px;
        font-weight: 700;
        border-radius: 8px;
        text-decoration: none !important;
        flex-shrink: 0;
        align-self: center;
        transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        cursor: pointer;
    }
    .explore-action-btn.btn-book {
        background: color-mix(in srgb, var(--lime) 14%, transparent);
        color: var(--lime) !important;
        border: 1px solid color-mix(in srgb, var(--lime) 35%, transparent);
    }
    .explore-action-btn.btn-book:hover {
        background: var(--lime);
        color: #080b0d !important;
        border-color: var(--lime);
        box-shadow: 0 4px 14px color-mix(in srgb, var(--lime) 30%, transparent);
        transform: translateY(-2px);
        text-decoration: none !important;
    }
    .explore-action-btn.btn-view {
        background: rgba(168, 85, 247, 0.14);
        color: #c084fc !important;
        border: 1px solid rgba(168, 85, 247, 0.35);
    }
    .explore-action-btn.btn-view:hover {
        background: #a855f7;
        color: #ffffff !important;
        border-color: #a855f7;
        box-shadow: 0 4px 14px rgba(168, 85, 247, 0.35);
        transform: translateY(-2px);
        text-decoration: none !important;
    }
    .explore-action-btn svg {
        transition: transform 0.2s ease;
    }
    .explore-action-btn:hover svg {
        transform: translateX(2px);
    }

    /* Light Theme Styling for Explore Buttons */
    html[data-theme="light"] .explore-browse-btn,
    [data-theme="light"] .explore-browse-btn {
        background: #84cc16 !important;
        color: #0f172a !important;
        box-shadow: 0 2px 8px rgba(132, 204, 22, 0.25) !important;
    }
    html[data-theme="light"] .explore-browse-btn:hover,
    [data-theme="light"] .explore-browse-btn:hover {
        background: #65a30d !important;
        color: #ffffff !important;
        box-shadow: 0 4px 14px rgba(101, 163, 13, 0.3) !important;
    }
    html[data-theme="light"] .explore-action-btn.btn-book,
    [data-theme="light"] .explore-action-btn.btn-book {
        background: #f7fee7 !important;
        color: #4d7c0f !important;
        border-color: #bef264 !important;
    }
    html[data-theme="light"] .explore-action-btn.btn-book:hover,
    [data-theme="light"] .explore-action-btn.btn-book:hover {
        background: #84cc16 !important;
        color: #ffffff !important;
        border-color: #84cc16 !important;
        box-shadow: 0 3px 10px rgba(132, 204, 22, 0.25) !important;
    }
    html[data-theme="light"] .explore-action-btn.btn-view,
    [data-theme="light"] .explore-action-btn.btn-view {
        background: #faf5ff !important;
        color: #7e22ce !important;
        border-color: #e9d5ff !important;
    }
    html[data-theme="light"] .explore-action-btn.btn-view:hover,
    [data-theme="light"] .explore-action-btn.btn-view:hover {
        background: #9333ea !important;
        color: #ffffff !important;
        border-color: #9333ea !important;
        box-shadow: 0 3px 10px rgba(147, 51, 234, 0.25) !important;
    }

    /* Light Theme Styling for Stat Cards */
    html[data-theme="light"] .member-stat-card,
    [data-theme="light"] .member-stat-card {
        background: #ffffff !important;
        border: 1px solid #cbd5e1 !important;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04) !important;
    }
    html[data-theme="light"] .member-stat-card:hover,
    [data-theme="light"] .member-stat-card:hover {
        background: #ffffff !important;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08) !important;
    }
    html[data-theme="light"] .member-stat-label,
    [data-theme="light"] .member-stat-label {
        color: #64748b !important;
    }
    html[data-theme="light"] .member-stat-num,
    [data-theme="light"] .member-stat-num {
        color: #0f172a !important;
    }
    html[data-theme="light"] .member-stat-unit,
    [data-theme="light"] .member-stat-unit {
        color: #64748b !important;
    }
    html[data-theme="light"] .member-stat-bot,
    [data-theme="light"] .member-stat-bot {
        border-top-color: #f1f5f9 !important;
    }
    html[data-theme="light"] .member-stat-hint,
    [data-theme="light"] .member-stat-hint {
        color: #64748b !important;
    }
    html[data-theme="light"] .member-stat-cta,
    [data-theme="light"] .member-stat-cta {
        color: #0f172a !important;
    }
    html[data-theme="light"] .icon-accent-attendance,
    [data-theme="light"] .icon-accent-attendance {
        background: #ecfccb !important;
        color: #365314 !important;
        border-color: #bef264 !important;
    }
    html[data-theme="light"] .icon-accent-progress,
    [data-theme="light"] .icon-accent-progress {
        background: #e0f2fe !important;
        color: #0369a1 !important;
        border-color: #bae6fd !important;
    }
    html[data-theme="light"] .icon-accent-classes,
    [data-theme="light"] .icon-accent-classes {
        background: #f3e8ff !important;
        color: #6b21a8 !important;
        border-color: #e9d5ff !important;
    }
    html[data-theme="light"] .stat-card-attendance:hover,
    [data-theme="light"] .stat-card-attendance:hover { border-color: #84cc16 !important; }
    html[data-theme="light"] .stat-card-attendance:hover .member-stat-cta,
    [data-theme="light"] .stat-card-attendance:hover .member-stat-cta { color: #166534 !important; }
    html[data-theme="light"] .stat-card-progress:hover,
    [data-theme="light"] .stat-card-progress:hover { border-color: #0ea5e9 !important; }
    html[data-theme="light"] .stat-card-progress:hover .member-stat-cta,
    [data-theme="light"] .stat-card-progress:hover .member-stat-cta { color: #0284c7 !important; }
    html[data-theme="light"] .stat-card-classes:hover,
    [data-theme="light"] .stat-card-classes:hover { border-color: #a855f7 !important; }
    html[data-theme="light"] .stat-card-classes:hover .member-stat-cta,
    [data-theme="light"] .stat-card-classes:hover .member-stat-cta { color: #7e22ce !important; }

    /* Member Welcome Banner & Hero Engagement Card */
    body.loaded .member-welcome-banner {
        display: flex !important;
    }
    .member-welcome-banner {
        display: flex !important;
        align-items: center;
        justify-content: space-between;
        flex-wrap: nowrap;
        gap: 24px;
        background: linear-gradient(135deg, rgba(26, 32, 46, 0.88) 0%, rgba(16, 20, 30, 0.96) 100%);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 16px;
        padding: 22px 28px;
        margin-bottom: 24px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 8px 28px rgba(0, 0, 0, 0.25);
    }
    .member-welcome-banner::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 3px;
        background: linear-gradient(90deg, var(--lime) 0%, var(--teal) 60%, transparent 100%);
        pointer-events: none;
    }
    .member-welcome-left {
        flex: 1 1 auto;
        min-width: 0;
    }
    .member-welcome-title-row {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 6px;
        flex-wrap: wrap;
    }
    .member-welcome-title {
        margin: 0;
        font-size: 24px;
        font-weight: 800;
        color: var(--ink);
        letter-spacing: -0.02em;
    }
    .member-tier-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: rgba(124, 92, 252, 0.18);
        color: #c4b5fd;
        border: 1px solid rgba(124, 92, 252, 0.35);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.02em;
    }
    .tier-info-link {
        color: inherit;
        opacity: 0.8;
        display: inline-flex;
        align-items: center;
        text-decoration: none;
        transition: opacity 0.2s;
    }
    .tier-info-link:hover {
        opacity: 1;
    }
    .member-welcome-desc {
        margin: 0;
        color: var(--muted);
        font-size: 13.5px;
        line-height: 1.5;
    }

    /* Score Hero Card */
    .member-hero-score {
        flex: 0 0 auto;
        min-width: 230px;
        background: rgba(13, 16, 23, 0.8);
        border: 1px solid rgba(255, 255, 255, 0.09);
        border-radius: 14px;
        padding: 14px 18px;
        cursor: pointer;
        transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
        text-align: center;
        position: relative;
        text-decoration: none;
    }
    .member-hero-score:hover {
        transform: translateY(-3px);
        border-color: color-mix(in srgb, var(--lime) 45%, var(--line));
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
    }
    .hero-score-header {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        font-size: 10.5px;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--muted);
        margin-bottom: 8px;
        font-weight: 700;
    }
    .hero-info-btn {
        color: inherit;
        opacity: 0.7;
        display: inline-flex;
        align-items: center;
        text-decoration: none;
        transition: opacity 0.2s;
    }
    .hero-info-btn:hover {
        opacity: 1;
        color: var(--lime);
    }
    .hero-score-body {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 14px;
    }
    .hero-score-ring {
        position: relative;
        width: 52px;
        height: 52px;
        flex-shrink: 0;
    }
    .hero-score-ring svg {
        transform: rotate(-90deg);
        width: 100%;
        height: 100%;
    }
    .hero-ring-bg {
        stroke: rgba(255, 255, 255, 0.08);
    }
    .hero-ring-fg {
        transition: stroke-dashoffset 0.8s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .hero-score-ring-val {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 17px;
        font-weight: 800;
        color: var(--ink);
    }
    .hero-score-meta {
        text-align: left;
        min-width: 0;
    }
    .hero-score-badge {
        display: inline-block;
        font-size: 11px;
        padding: 3px 10px;
        border-radius: 12px;
        font-weight: 700;
        letter-spacing: 0.02em;
        white-space: nowrap;
    }
    .hero-score-action {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 11px;
        color: var(--muted);
        font-weight: 600;
        margin-top: 4px;
        white-space: nowrap;
        transition: color 0.2s ease;
    }
    .hero-score-action svg {
        transition: transform 0.2s ease;
    }
    .member-hero-score:hover .hero-score-action {
        color: var(--lime);
    }
    .member-hero-score:hover .hero-score-action svg {
        transform: translateX(3px);
    }

    /* Category Themes (Dark Mode) */
    .score-theme-highly_engaged .hero-ring-fg { stroke: var(--lime); }
    .score-theme-highly_engaged .hero-score-badge {
        background: color-mix(in srgb, var(--lime) 15%, transparent);
        color: var(--lime);
        border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
    }
    .score-theme-moderately_engaged .hero-ring-fg { stroke: #f59e0b; }
    .score-theme-moderately_engaged .hero-score-badge {
        background: rgba(245, 158, 11, 0.15);
        color: #f59e0b;
        border: 1px solid rgba(245, 158, 11, 0.3);
    }
    .score-theme-at_risk .hero-ring-fg { stroke: #fb923c; }
    .score-theme-at_risk .hero-score-badge {
        background: rgba(251, 146, 60, 0.15);
        color: #fb923c;
        border: 1px solid rgba(251, 146, 60, 0.35);
    }

    /* Light Theme Styling */
    html[data-theme="light"] .member-welcome-banner,
    [data-theme="light"] .member-welcome-banner {
        background: #ffffff !important;
        border: 1px solid #cbd5e1 !important;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05) !important;
    }
    html[data-theme="light"] .member-welcome-banner::before,
    [data-theme="light"] .member-welcome-banner::before {
        background: linear-gradient(90deg, #65a30d 0%, #059669 60%, transparent 100%) !important;
    }
    html[data-theme="light"] .member-welcome-title,
    [data-theme="light"] .member-welcome-title {
        color: #0f172a !important;
    }
    html[data-theme="light"] .member-tier-pill,
    [data-theme="light"] .member-tier-pill {
        background: #ede9fe !important;
        color: #6d28d9 !important;
        border-color: #ddd6fe !important;
    }
    html[data-theme="light"] .member-welcome-desc,
    [data-theme="light"] .member-welcome-desc {
        color: #64748b !important;
    }
    html[data-theme="light"] .member-hero-score,
    [data-theme="light"] .member-hero-score {
        background: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03) !important;
    }
    html[data-theme="light"] .member-hero-score:hover,
    [data-theme="light"] .member-hero-score:hover {
        background: #ffffff !important;
        border-color: #84cc16 !important;
        box-shadow: 0 6px 18px rgba(101, 163, 13, 0.12) !important;
    }
    html[data-theme="light"] .hero-score-header,
    [data-theme="light"] .hero-score-header {
        color: #64748b !important;
    }
    html[data-theme="light"] .hero-ring-bg,
    [data-theme="light"] .hero-ring-bg {
        stroke: #e2e8f0 !important;
    }
    html[data-theme="light"] .hero-score-ring-val,
    [data-theme="light"] .hero-score-ring-val {
        color: #0f172a !important;
    }
    html[data-theme="light"] .hero-score-action,
    [data-theme="light"] .hero-score-action {
        color: #64748b !important;
    }
    html[data-theme="light"] .member-hero-score:hover .hero-score-action,
    [data-theme="light"] .member-hero-score:hover .hero-score-action {
        color: #166534 !important;
    }
    html[data-theme="light"] .score-theme-highly_engaged .hero-ring-fg,
    [data-theme="light"] .score-theme-highly_engaged .hero-ring-fg { stroke: #166534 !important; }
    html[data-theme="light"] .score-theme-highly_engaged .hero-score-badge,
    [data-theme="light"] .score-theme-highly_engaged .hero-score-badge {
        background: #dcfce7 !important;
        color: #166534 !important;
        border: 1px solid #86efac !important;
    }
    html[data-theme="light"] .score-theme-moderately_engaged .hero-ring-fg,
    [data-theme="light"] .score-theme-moderately_engaged .hero-ring-fg { stroke: #b45309 !important; }
    html[data-theme="light"] .score-theme-moderately_engaged .hero-score-badge,
    [data-theme="light"] .score-theme-moderately_engaged .hero-score-badge {
        background: #fef3c7 !important;
        color: #92400e !important;
        border: 1px solid #fde68a !important;
    }
    html[data-theme="light"] .score-theme-at_risk .hero-ring-fg,
    [data-theme="light"] .score-theme-at_risk .hero-ring-fg { stroke: #ea580c !important; }
    html[data-theme="light"] .score-theme-at_risk .hero-score-badge,
    [data-theme="light"] .score-theme-at_risk .hero-score-badge {
        background: #ffedd5 !important;
        color: #c2410c !important;
        border: 1px solid #fed7aa !important;
    }

    @media (max-width: 720px) {
        body.loaded .member-welcome-banner,
        .member-welcome-banner {
            flex-direction: column !important;
            align-items: stretch !important;
            gap: 12px !important;
            padding: 14px 16px !important;
            margin-bottom: 16px !important;
            border-radius: 14px !important;
        }
        .member-welcome-title {
            font-size: 18px !important;
        }
        .member-welcome-title-row {
            gap: 8px !important;
            margin-bottom: 4px !important;
        }
        .member-tier-pill {
            padding: 2px 8px !important;
            font-size: 11px !important;
        }
        .member-welcome-desc {
            font-size: 12px !important;
            line-height: 1.4 !important;
        }
        .member-hero-score {
            width: 100% !important;
            max-width: 100% !important;
            padding: 8px 12px !important;
            border-radius: 10px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: 10px !important;
            text-align: left !important;
        }
        .hero-score-header {
            display: none !important;
        }
        .hero-score-body {
            width: 100% !important;
            justify-content: space-between !important;
            gap: 10px !important;
        }
        .hero-score-ring {
            width: 38px !important;
            height: 38px !important;
        }
        .hero-score-ring-val {
            font-size: 13.5px !important;
        }
        .hero-score-meta {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            flex: 1 1 auto !important;
            gap: 8px !important;
        }
        .hero-score-badge {
            font-size: 10.5px !important;
            padding: 2px 8px !important;
        }
        .hero-score-action {
            font-size: 10.5px !important;
            margin-top: 0 !important;
            flex-shrink: 0 !important;
        }
    }

    /* Activity Tab Toolbar & Components */
    .act-toolbar-wrap {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        margin-bottom: 20px;
    }
    .act-toolbar {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: color-mix(in srgb, var(--surface) 90%, transparent);
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 6px 8px;
        box-sizing: border-box;
    }
    .act-calendar-btn {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        min-width: 36px;
        border-radius: 8px;
        background: transparent;
        border: 1px solid var(--line);
        color: var(--muted);
        cursor: pointer;
        flex: 0 0 auto !important;
        box-sizing: border-box !important;
        transition: all 0.2s ease;
        overflow: hidden;
    }
    .act-calendar-btn svg {
        display: block;
        flex-shrink: 0;
        pointer-events: none;
    }
    .act-calendar-btn:hover {
        background: color-mix(in srgb, var(--ink) 8%, transparent);
        color: var(--ink);
        border-color: color-mix(in srgb, var(--lime) 50%, var(--line));
    }
    .act-calendar-btn:focus-within {
        border-color: var(--lime);
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--lime) 20%, transparent);
    }
    .act-date-picker-native {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
        border: none;
        padding: 0;
        margin: 0;
        z-index: 2;
    }
    .act-refresh-btn {
        height: 36px !important;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: transparent;
        border: 1px solid var(--line);
        color: var(--muted);
        padding: 0 14px !important;
        border-radius: 8px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        flex: 0 0 auto !important;
        box-sizing: border-box !important;
        transition: all 0.2s ease;
        white-space: nowrap;
    }
    .act-refresh-btn:hover {
        background: color-mix(in srgb, var(--ink) 8%, transparent);
        color: var(--ink);
        border-color: color-mix(in srgb, var(--lime) 50%, var(--line));
    }
    .act-refresh-btn.loading svg {
        animation: actSpin 0.75s linear infinite;
    }
    @keyframes actSpin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    .act-gym-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 0 12px;
        height: 36px;
        border-radius: 8px;
        background: color-mix(in srgb, var(--panel-soft) 80%, transparent);
        border: 1px solid var(--line);
        font-size: 12px;
        font-weight: 600;
        color: var(--ink);
        box-sizing: border-box;
        white-space: nowrap;
    }

    /* Activity Subnav Switcher (Daily Attendance vs Peak Hours) */
    .act-subnav-wrap {
        display: flex;
        justify-content: flex-start;
        margin-bottom: 20px;
    }
    .act-subnav {
        display: inline-flex;
        background: rgba(16, 20, 30, 0.85);
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 4px;
        gap: 6px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
    }
    .act-subnav-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 18px;
        border-radius: 9px;
        border: 1px solid transparent;
        background: transparent;
        color: var(--muted);
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        white-space: nowrap;
    }
    .act-subnav-btn:hover {
        color: var(--ink);
        background: rgba(255, 255, 255, 0.05);
    }
    .act-subnav-btn.active {
        background: color-mix(in srgb, var(--lime) 18%, var(--surface));
        border-color: color-mix(in srgb, var(--lime) 40%, transparent);
        color: var(--lime);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
    }
    .act-subnav-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 2px 7px;
        border-radius: 10px;
        font-size: 11px;
        font-weight: 800;
        background: rgba(255, 255, 255, 0.08);
        color: var(--muted);
        transition: all 0.2s ease;
    }
    .act-subnav-btn.active .act-subnav-count {
        background: var(--lime);
        color: #080b0d;
    }
    .act-subpanel {
        display: none;
    }
    .act-subpanel.active {
        display: block;
    }

    /* Tablet & Mobile Responsive Optimizations for Toolbar and Controls */
    @media (max-width: 768px) {
        .act-roster-controls {
            flex-direction: column;
            align-items: stretch;
            gap: 10px;
        }
        .act-search-box {
            flex: none !important;
            min-width: 0 !important;
            width: 100% !important;
            height: 38px !important;
        }
        .act-status-pills {
            width: 100%;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 4px;
            box-sizing: border-box;
        }
        .act-status-pill {
            padding: 7px 6px;
            justify-content: center;
            font-size: 11.5px;
            gap: 5px;
            text-align: center;
            width: 100%;
            box-sizing: border-box;
        }
        .act-pill-text-extra {
            display: none;
        }
        .act-pill-badge {
            padding: 1px 5px;
            font-size: 10.5px;
        }
        .act-peak-controls {
            width: 100%;
        }
        .act-range-group {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            width: 100%;
            box-sizing: border-box;
        }
        .act-range-btn {
            text-align: center;
            padding: 7px 4px;
            font-size: 11.5px;
            width: 100%;
        }
    }

    @media (max-width: 640px) {
        .act-toolbar-wrap {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 14px;
        }
        .act-toolbar {
            padding: 4px 6px;
            gap: 6px;
        }
        .act-calendar-btn {
            height: 32px !important;
            width: 32px !important;
            min-width: 32px !important;
        }
        .act-refresh-btn {
            height: 32px !important;
            padding: 0 10px !important;
            font-size: 11.5px;
        }
        .act-panel {
            padding: 16px 14px;
        }
        .act-panel-header {
            gap: 8px;
        }
        .act-panel-title {
            font-size: 15px;
        }
        .act-panel-sub {
            font-size: 11.5px;
        }

        /* Subnav Mobile View */
        .act-subnav-wrap {
            width: 100%;
            margin-bottom: 16px;
        }
        .act-subnav {
            width: 100%;
            display: grid;
            grid-template-columns: 1fr 1fr;
            padding: 3px;
            gap: 4px;
        }
        .act-subnav-btn {
            padding: 9px 6px;
            font-size: 11.5px;
            gap: 5px;
            justify-content: center;
        }
        .act-subnav-btn span:first-of-type {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Card-like transformation for Attendance Roster on Mobile */
        .act-table-container {
            border: none !important;
            background: transparent !important;
            overflow: visible !important;
            box-shadow: none !important;
            margin-top: 8px;
        }
        #act-roster-table {
            display: block !important;
            width: 100% !important;
            border: none !important;
            background: transparent !important;
        }
        #act-roster-table thead {
            display: none !important;
        }
        #act-roster-tbody {
            display: flex !important;
            flex-direction: column !important;
            gap: 12px !important;
            width: 100% !important;
        }
        #act-roster-tbody tr[data-name] {
            display: grid;
            grid-template-columns: 1fr auto !important;
            gap: 12px 14px !important;
            background: linear-gradient(145deg, rgba(22, 27, 39, 0.95) 0%, rgba(15, 19, 28, 0.98) 100%) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: 14px !important;
            padding: 16px !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.18) !important;
            transition: transform 0.2s ease, box-shadow 0.2s ease !important;
        }
        #act-roster-tbody tr[data-name].act-row-hidden {
            display: none !important;
        }
        #act-roster-tbody tr[data-name]:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.28) !important;
        }
        #act-roster-tbody tr[data-name] > td[data-label="Member"] {
            grid-column: 1 / 2 !important;
            grid-row: 1 !important;
            padding: 0 0 10px 0 !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.07) !important;
            display: flex !important;
            align-items: center !important;
            justify-content: flex-start !important;
        }
        #act-roster-tbody tr[data-name] > td[data-label="Status"] {
            grid-column: 2 / 3 !important;
            grid-row: 1 !important;
            padding: 0 0 10px 0 !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.07) !important;
            display: flex !important;
            align-items: center !important;
            justify-content: flex-end !important;
        }
        #act-roster-tbody tr[data-name] > td[data-label="Status"]::before {
            display: none !important;
        }
        #act-roster-tbody tr[data-name] > td[data-label="Check-In"],
        #act-roster-tbody tr[data-name] > td[data-label="Check-Out"],
        #act-roster-tbody tr[data-name] > td[data-label="Duration"] {
            display: flex !important;
            flex-direction: column !important;
            align-items: flex-start !important;
            gap: 4px !important;
            padding: 0 !important;
            border: none !important;
        }
        #act-roster-tbody tr[data-name] > td[data-label="Check-In"]::before,
        #act-roster-tbody tr[data-name] > td[data-label="Check-Out"]::before,
        #act-roster-tbody tr[data-name] > td[data-label="Duration"]::before {
            content: attr(data-label) !important;
            font-size: 10px !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.06em !important;
            color: var(--muted) !important;
        }
        #act-roster-tbody tr[data-name] > td[data-label="Check-In"] {
            grid-column: 1 / 2 !important;
            grid-row: 2 !important;
        }
        #act-roster-tbody tr[data-name] > td[data-label="Check-Out"] {
            grid-column: 2 / 3 !important;
            grid-row: 2 !important;
        }
        #act-roster-tbody tr[data-name] > td[data-label="Duration"] {
            grid-column: 1 / -1 !important;
            grid-row: 3 !important;
        }
        #act-no-match-row {
            background: var(--surface) !important;
            border: 1px dashed var(--line) !important;
            border-radius: 12px !important;
            padding: 20px !important;
            text-align: center !important;
        }
        #act-no-match-row[style*="display: none"] {
            display: none !important;
        }
        #act-no-match-row td {
            display: block !important;
            padding: 0 !important;
            border: none !important;
        }

        /* 2x2 Metric Cards Grid on Mobile to Save Vertical Space */
        .act-stats-grid {
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 10px !important;
            margin-bottom: 16px !important;
        }
        .act-stat-card {
            padding: 12px 14px !important;
            border-radius: 12px !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
        }
        .act-stat-header {
            margin-bottom: 8px !important;
        }
        .act-stat-label {
            font-size: 9.5px !important;
            letter-spacing: 0.05em !important;
        }
        .act-stat-icon-wrap {
            width: 28px !important;
            height: 28px !important;
            border-radius: 8px !important;
        }
        .act-stat-icon-wrap svg {
            width: 14px !important;
            height: 14px !important;
        }
        .act-stat-val {
            font-size: 24px !important;
            margin-bottom: 2px !important;
            letter-spacing: -0.02em !important;
        }
        .act-stat-val-text {
            font-size: 13.5px !important;
            line-height: 1.25 !important;
            margin-bottom: 2px !important;
        }
        .act-stat-sub {
            font-size: 10.5px !important;
            line-height: 1.25 !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }
        .act-pulse-dot {
            width: 6px !important;
            height: 6px !important;
            margin-right: 4px !important;
        }

        /* Mobile Pagination Styling */
        .act-pagination-bar {
            flex-direction: column !important;
            align-items: stretch !important;
            gap: 10px !important;
            padding: 12px !important;
        }
        .act-pagination-info {
            justify-content: space-between !important;
            width: 100% !important;
            font-size: 11.5px !important;
        }
        .act-pagination-nav {
            justify-content: center !important;
            width: 100% !important;
            gap: 6px !important;
        }
        .act-page-btn {
            min-width: 34px !important;
            height: 34px !important;
            font-size: 12px !important;
        }
    }

    @media (max-width: 440px) {
        .act-status-pill {
            font-size: 10px;
            padding: 6px 2px;
            gap: 3px;
        }
        .act-status-pill .act-pill-badge {
            padding: 0 4px;
            font-size: 9.5px;
        }
        .act-range-btn {
            font-size: 10.5px;
            padding: 6px 2px;
        }
    }

    /* 4-Stat Metric Cards */
    .act-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 210px), 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .act-stat-card {
        background: linear-gradient(135deg, rgba(22, 27, 39, 0.85) 0%, rgba(15, 19, 28, 0.95) 100%);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 14px;
        padding: 18px 20px;
        position: relative;
        overflow: hidden;
        transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
    }
    .act-stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
    }
    .act-stat-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 2px;
    }
    .act-stat-in::before { background: var(--lime); }
    .act-stat-out::before { background: var(--teal); }
    .act-stat-inside::before { background: #22c55e; }
    .act-stat-peak::before { background: #f97316; }

    .act-stat-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
    }
    .act-stat-label {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--muted);
    }
    .act-stat-icon-wrap {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .act-icon-in { background: color-mix(in srgb, var(--lime) 15%, transparent); color: var(--lime); border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent); }
    .act-icon-out { background: color-mix(in srgb, var(--teal) 15%, transparent); color: var(--teal); border: 1px solid color-mix(in srgb, var(--teal) 30%, transparent); }
    .act-icon-inside { background: rgba(34, 197, 94, 0.15); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.3); }
    .act-icon-peak { background: rgba(249, 115, 22, 0.15); color: #f97316; border: 1px solid rgba(249, 115, 22, 0.3); }

    .act-stat-val {
        font-size: 30px;
        font-weight: 800;
        color: var(--ink);
        line-height: 1.1;
        margin-bottom: 4px;
        letter-spacing: -0.02em;
    }
    .act-stat-val-text {
        font-size: 18px;
        font-weight: 700;
        color: var(--ink);
        line-height: 1.2;
        margin-bottom: 4px;
    }
    .act-stat-sub {
        font-size: 11.5px;
        color: var(--muted);
        font-weight: 500;
    }
    .act-pulse-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #22c55e;
        margin-right: 6px;
        box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
        animation: actPulse 1.8s infinite cubic-bezier(0.66, 0, 0, 1);
    }
    @keyframes actPulse {
        to {
            box-shadow: 0 0 0 10px rgba(34, 197, 94, 0);
        }
    }

    /* Peak Hours Section */
    .act-panel {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 14px;
        padding: 22px;
        margin-bottom: 24px;
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.1);
    }
    .act-panel-top {
        display: flex;
        flex-direction: column;
        gap: 14px;
        margin-bottom: 18px;
    }
    .act-panel-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        width: 100%;
    }
    .act-heading {
        flex: 1 1 auto;
        min-width: 0;
    }
    .act-panel-title {
        font-size: 17px;
        font-weight: 800;
        color: var(--ink);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .act-panel-sub {
        font-size: 12.5px;
        color: var(--muted);
        margin: 3px 0 0 0;
    }
    .act-panel-header .act-toolbar {
        flex-shrink: 0;
        margin: 0;
    }
    .act-peak-controls {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        width: 100%;
    }
    .act-range-group {
        display: inline-flex;
        background: rgba(0,0,0,0.25);
        border: 1px solid var(--line);
        border-radius: 10px;
        padding: 3px;
        gap: 2px;
    }
    .act-range-btn {
        background: transparent;
        border: none;
        color: var(--muted);
        font-size: 12px;
        font-weight: 700;
        padding: 6px 14px;
        border-radius: 7px;
        cursor: pointer;
        transition: all 0.2s ease;
        white-space: nowrap;
    }
    .act-range-btn:hover {
        color: var(--ink);
    }
    .act-range-btn.active {
        background: color-mix(in srgb, var(--lime) 18%, var(--surface));
        color: var(--lime);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    }
    .act-peak-banner {
        display: flex;
        align-items: center;
        gap: 12px;
        background: color-mix(in srgb, #f97316 12%, var(--panel-soft));
        border: 1px solid color-mix(in srgb, #f97316 35%, transparent);
        border-left: 4px solid #f97316;
        border-radius: 10px;
        padding: 12px 16px;
        margin-bottom: 18px;
        font-size: 13px;
        color: var(--ink);
    }
    .act-peak-banner svg {
        color: #f97316;
        flex-shrink: 0;
    }
    .act-chart-wrap {
        position: relative;
        height: 250px;
        width: 100%;
    }

    /* Roster Table & Search */
    .act-roster-controls {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        width: 100%;
    }
    .act-search-box {
        position: relative;
        display: flex;
        align-items: center;
        flex: 1 1 240px;
        min-width: 200px;
        height: 38px;
    }
    .act-search-box svg {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--muted);
        pointer-events: none;
        z-index: 2;
    }
    .act-search-input {
        width: 100%;
        height: 38px;
        box-sizing: border-box;
        background: rgba(16, 20, 30, 0.7);
        border: 1px solid var(--line);
        border-radius: 8px;
        padding: 0 12px 0 36px;
        color: var(--ink);
        font-size: 13px;
        outline: none;
        transition: border-color 0.2s ease;
    }
    .act-search-input:focus {
        border-color: var(--lime);
    }
    .act-status-pills {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: rgba(16, 20, 30, 0.7);
        border: 1px solid var(--line);
        border-radius: 10px;
        padding: 3px;
        flex-wrap: nowrap;
        flex-shrink: 0;
    }
    .act-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 6px 12px;
        border-radius: 7px;
        border: 1px solid transparent;
        background: transparent;
        color: var(--muted);
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        white-space: nowrap;
    }
    .act-status-pill:hover {
        color: var(--ink);
        background: rgba(255, 255, 255, 0.05);
    }
    .act-status-pill.active {
        background: color-mix(in srgb, var(--lime) 15%, var(--surface));
        border-color: color-mix(in srgb, var(--lime) 35%, transparent);
        color: var(--lime);
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
    }
    .act-pill-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 1px 7px;
        border-radius: 8px;
        font-size: 11px;
        font-weight: 800;
        background: rgba(255, 255, 255, 0.08);
        color: var(--muted);
        transition: all 0.2s ease;
    }
    .act-status-pill.active .act-pill-badge {
        background: var(--lime);
        color: #080b0d;
    }
    .act-pill-badge-in {
        color: #22c55e;
    }
    .act-pill-badge-out {
        color: #2dd4bf;
    }
    .act-table-container {
        overflow-x: auto;
        border: 1px solid var(--line);
        border-radius: 12px;
        margin-top: 14px;
        background: var(--surface);
    }
    .act-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        text-align: left;
    }
    .act-table th {
        background: color-mix(in srgb, var(--panel-soft) 80%, transparent);
        color: var(--muted);
        font-weight: 700;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        padding: 12px 16px;
        border-bottom: 1px solid var(--line);
        white-space: nowrap;
    }
    .act-table td {
        padding: 13px 16px;
        border-bottom: 1px solid color-mix(in srgb, var(--line) 50%, transparent);
        color: var(--ink);
        vertical-align: middle;
    }
    .act-table tr:last-child td {
        border-bottom: none;
    }
    .act-table tr:hover td {
        background: color-mix(in srgb, var(--ink) 3%, transparent);
    }
    .act-member-info {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .act-avatar {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        object-fit: cover;
        background: var(--panel-soft);
        border: 1px solid var(--line);
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 12px;
        color: var(--lime);
    }
    .act-member-name {
        font-weight: 700;
        color: var(--ink);
        line-height: 1.2;
    }
    .act-member-sub {
        font-size: 11px;
        color: var(--muted);
    }
    .act-id-pill {
        display: inline-block;
        font-family: monospace;
        font-size: 11.5px;
        font-weight: 700;
        padding: 3px 7px;
        border-radius: 6px;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid var(--line);
        color: var(--muted);
    }
    .act-time-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-weight: 600;
        color: var(--ink);
    }
    .act-time-pill svg {
        color: var(--muted);
    }
    .act-duration-pill {
        display: inline-block;
        font-size: 11.5px;
        font-weight: 700;
        padding: 3px 8px;
        border-radius: 6px;
        background: color-mix(in srgb, var(--panel-soft) 80%, transparent);
        color: var(--ink);
        border: 1px solid var(--line);
    }
    .act-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11.5px;
        font-weight: 700;
        white-space: nowrap;
    }
    .act-status-in {
        background: rgba(34, 197, 94, 0.12);
        color: #4ade80;
        border: 1px solid rgba(34, 197, 94, 0.3);
    }
    .act-status-in .act-badge-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: #22c55e;
        box-shadow: 0 0 6px rgba(34, 197, 94, 0.8);
    }
    .act-status-out {
        background: rgba(148, 163, 184, 0.1);
        color: #94a3b8;
        border: 1px solid rgba(148, 163, 184, 0.25);
    }

    /* Empty state */
    .act-empty-state {
        text-align: center;
        padding: 48px 24px;
        border: 1px dashed var(--line);
        border-radius: 14px;
        background: color-mix(in srgb, var(--panel-soft) 30%, transparent);
        margin-top: 14px;
    }
    .act-empty-icon {
        width: 54px;
        height: 54px;
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid var(--line);
        color: var(--muted);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 14px;
    }
    .act-empty-title {
        font-size: 16px;
        font-weight: 700;
        color: var(--ink);
        margin: 0 0 6px 0;
    }
    .act-empty-desc {
        font-size: 13px;
        color: var(--muted);
        max-width: 400px;
        margin: 0 auto 16px auto;
        line-height: 1.5;
    }
    .act-empty-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        border-radius: 8px;
        background: var(--lime);
        color: #080b0d !important;
        font-size: 12.5px;
        font-weight: 700;
        cursor: pointer;
        border: none;
        transition: all 0.2s ease;
    }
    .act-empty-btn:hover {
        filter: brightness(1.08);
        transform: translateY(-1px);
    }

    /* Activity Pagination Bar */
    .act-pagination-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        padding: 12px 16px;
        margin-top: 14px;
        background: color-mix(in srgb, var(--panel-soft) 40%, transparent);
        border: 1px solid var(--line);
        border-radius: 12px;
    }
    .act-pagination-info {
        font-size: 12.5px;
        color: var(--muted);
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .act-pagination-info strong {
        color: var(--ink);
        font-weight: 700;
    }
    .act-page-size-wrap {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        color: var(--muted);
        margin-left: 8px;
    }
    .act-page-size-select {
        background: rgba(16, 20, 30, 0.7);
        border: 1px solid var(--line);
        border-radius: 6px;
        color: var(--ink);
        font-size: 12px;
        font-weight: 600;
        padding: 2px 6px;
        outline: none;
        cursor: pointer;
    }
    .act-pagination-nav {
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .act-page-btn {
        min-width: 32px;
        height: 32px;
        padding: 0 8px;
        border-radius: 8px;
        border: 1px solid var(--line);
        background: transparent;
        color: var(--muted);
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
    }
    .act-page-btn:hover:not(:disabled) {
        background: color-mix(in srgb, var(--ink) 8%, transparent);
        color: var(--ink);
        border-color: var(--muted);
    }
    .act-page-btn.active {
        background: color-mix(in srgb, var(--lime) 18%, var(--surface));
        border-color: var(--lime);
        color: var(--lime);
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
    }
    .act-page-btn:disabled {
        opacity: 0.35;
        cursor: not-allowed;
    }
    .act-page-dots {
        color: var(--muted);
        padding: 0 4px;
        font-weight: 700;
        font-size: 12px;
    }

    /* Light Theme Styling for Activity Tab */
    html[data-theme="light"] .act-toolbar,
    [data-theme="light"] .act-toolbar {
        background: #ffffff !important;
        border-color: #cbd5e1 !important;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04) !important;
    }
    html[data-theme="light"] .act-calendar-btn,
    [data-theme="light"] .act-calendar-btn {
        border-color: #cbd5e1 !important;
        color: #475569 !important;
        background: transparent !important;
    }
    html[data-theme="light"] .act-calendar-btn:hover,
    [data-theme="light"] .act-calendar-btn:hover {
        background: #f1f5f9 !important;
        color: #0f172a !important;
        border-color: #94a3b8 !important;
    }
    html[data-theme="light"] .act-refresh-btn,
    [data-theme="light"] .act-refresh-btn {
        border-color: #cbd5e1 !important;
        color: #475569 !important;
        background: transparent !important;
    }
    html[data-theme="light"] .act-refresh-btn:hover,
    [data-theme="light"] .act-refresh-btn:hover {
        background: #f1f5f9 !important;
        color: #0f172a !important;
        border-color: #94a3b8 !important;
    }
    html[data-theme="light"] .act-gym-badge,
    [data-theme="light"] .act-gym-badge {
        background: #f1f5f9 !important;
        border-color: #cbd5e1 !important;
        color: #0f172a !important;
    }
    html[data-theme="light"] .act-stat-card,
    [data-theme="light"] .act-stat-card {
        background: #ffffff !important;
        border-color: #cbd5e1 !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04) !important;
    }
    html[data-theme="light"] .act-stat-label,
    [data-theme="light"] .act-stat-label {
        color: #64748b !important;
    }
    html[data-theme="light"] .act-stat-val,
    html[data-theme="light"] .act-stat-val-text,
    [data-theme="light"] .act-stat-val,
    [data-theme="light"] .act-stat-val-text {
        color: #0f172a !important;
    }
    html[data-theme="light"] .act-stat-sub,
    [data-theme="light"] .act-stat-sub {
        color: #64748b !important;
    }
    html[data-theme="light"] .act-panel,
    [data-theme="light"] .act-panel {
        background: #ffffff !important;
        border-color: #cbd5e1 !important;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04) !important;
    }
    html[data-theme="light"] .act-range-group,
    [data-theme="light"] .act-range-group {
        background: #f1f5f9 !important;
        border-color: #e2e8f0 !important;
    }
    html[data-theme="light"] .act-range-btn,
    [data-theme="light"] .act-range-btn {
        color: #64748b !important;
    }
    html[data-theme="light"] .act-range-btn:hover,
    [data-theme="light"] .act-range-btn:hover {
        color: #0f172a !important;
    }
    html[data-theme="light"] .act-range-btn.active,
    [data-theme="light"] .act-range-btn.active {
        background: #dcfce7 !important;
        color: #166534 !important;
        box-shadow: 0 2px 6px rgba(22, 101, 52, 0.15) !important;
    }
    html[data-theme="light"] .act-peak-banner,
    [data-theme="light"] .act-peak-banner {
        background: #fff7ed !important;
        border-color: #fed7aa !important;
        border-left-color: #ea580c !important;
        color: #9a3412 !important;
    }
    html[data-theme="light"] .act-search-input,
    [data-theme="light"] .act-search-input {
        background: #ffffff !important;
        border-color: #cbd5e1 !important;
        color: #0f172a !important;
    }
    html[data-theme="light"] .act-status-pills,
    [data-theme="light"] .act-status-pills {
        background: #f8fafc !important;
        border-color: #cbd5e1 !important;
    }
    html[data-theme="light"] .act-status-pill,
    [data-theme="light"] .act-status-pill {
        color: #475569 !important;
    }
    html[data-theme="light"] .act-status-pill:hover,
    [data-theme="light"] .act-status-pill:hover {
        background: #f1f5f9 !important;
        color: #0f172a !important;
    }
    html[data-theme="light"] .act-status-pill.active,
    [data-theme="light"] .act-status-pill.active {
        background: #dcfce7 !important;
        border-color: #86efac !important;
        color: #166534 !important;
    }
    html[data-theme="light"] .act-pill-badge,
    [data-theme="light"] .act-pill-badge {
        background: #e2e8f0 !important;
        color: #475569 !important;
    }
    html[data-theme="light"] .act-status-pill.active .act-pill-badge,
    [data-theme="light"] .act-status-pill.active .act-pill-badge {
        background: #16a34a !important;
        color: #ffffff !important;
    }
    html[data-theme="light"] .act-table-container,
    [data-theme="light"] .act-table-container {
        border-color: #e2e8f0 !important;
        background: #ffffff !important;
    }
    html[data-theme="light"] .act-table th,
    [data-theme="light"] .act-table th {
        background: #f8fafc !important;
        color: #475569 !important;
        border-bottom-color: #e2e8f0 !important;
    }
    html[data-theme="light"] .act-table td,
    [data-theme="light"] .act-table td {
        border-bottom-color: #f1f5f9 !important;
        color: #1e293b !important;
    }
    html[data-theme="light"] .act-member-name,
    [data-theme="light"] .act-member-name {
        color: #0f172a !important;
    }
    html[data-theme="light"] .act-id-pill,
    [data-theme="light"] .act-id-pill {
        background: #f1f5f9 !important;
        border-color: #e2e8f0 !important;
        color: #475569 !important;
    }
    html[data-theme="light"] .act-duration-pill,
    [data-theme="light"] .act-duration-pill {
        background: #f1f5f9 !important;
        border-color: #e2e8f0 !important;
        color: #334155 !important;
    }
    html[data-theme="light"] .act-status-in,
    [data-theme="light"] .act-status-in {
        background: #dcfce7 !important;
        color: #166534 !important;
        border-color: #86efac !important;
    }
    html[data-theme="light"] .act-status-out,
    [data-theme="light"] .act-status-out {
        background: #f1f5f9 !important;
        color: #475569 !important;
        border-color: #cbd5e1 !important;
    }
    html[data-theme="light"] .act-empty-state,
    [data-theme="light"] .act-empty-state {
        background: #f8fafc !important;
        border-color: #cbd5e1 !important;
    }
    html[data-theme="light"] .act-subnav,
    [data-theme="light"] .act-subnav {
        background: #f1f5f9 !important;
        border-color: #cbd5e1 !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04) !important;
    }
    html[data-theme="light"] .act-subnav-btn,
    [data-theme="light"] .act-subnav-btn {
        color: #64748b !important;
    }
    html[data-theme="light"] .act-subnav-btn:hover,
    [data-theme="light"] .act-subnav-btn:hover {
        color: #0f172a !important;
        background: rgba(0, 0, 0, 0.04) !important;
    }
    html[data-theme="light"] .act-subnav-btn.active,
    [data-theme="light"] .act-subnav-btn.active {
        background: #ffffff !important;
        color: #166534 !important;
        border-color: #86efac !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08) !important;
    }
    html[data-theme="light"] .act-subnav-count,
    [data-theme="light"] .act-subnav-count {
        background: #e2e8f0 !important;
        color: #475569 !important;
    }
    html[data-theme="light"] .act-subnav-btn.active .act-subnav-count,
    [data-theme="light"] .act-subnav-btn.active .act-subnav-count {
        background: #dcfce7 !important;
        color: #166534 !important;
    }
    html[data-theme="light"] #act-roster-tbody tr[data-name],
    [data-theme="light"] #act-roster-tbody tr[data-name] {
        background: #ffffff !important;
        border-color: #e2e8f0 !important;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04) !important;
    }
    html[data-theme="light"] #act-roster-tbody tr[data-name] > td[data-label="Member"],
    html[data-theme="light"] #act-roster-tbody tr[data-name] > td[data-label="Status"],
    [data-theme="light"] #act-roster-tbody tr[data-name] > td[data-label="Member"],
    [data-theme="light"] #act-roster-tbody tr[data-name] > td[data-label="Status"] {
        border-bottom-color: #f1f5f9 !important;
    }
    html[data-theme="light"] #act-no-match-row,
    [data-theme="light"] #act-no-match-row {
        background: #f8fafc !important;
        border-color: #cbd5e1 !important;
    }
    html[data-theme="light"] .act-pagination-bar,
    [data-theme="light"] .act-pagination-bar {
        background: #f8fafc !important;
        border-color: #cbd5e1 !important;
    }
    html[data-theme="light"] .act-page-size-select,
    [data-theme="light"] .act-page-size-select {
        background: #ffffff !important;
        border-color: #cbd5e1 !important;
        color: #0f172a !important;
    }
    html[data-theme="light"] .act-page-btn,
    [data-theme="light"] .act-page-btn {
        border-color: #cbd5e1 !important;
        color: #475569 !important;
    }
    html[data-theme="light"] .act-page-btn:hover:not(:disabled),
    [data-theme="light"] .act-page-btn:hover:not(:disabled) {
        background: #f1f5f9 !important;
        color: #0f172a !important;
    }
    html[data-theme="light"] .act-page-btn.active,
    [data-theme="light"] .act-page-btn.active {
        background: #dcfce7 !important;
        border-color: #86efac !important;
        color: #166534 !important;
    }
    </style>

    <?php
    $catSlug = strtolower(str_replace(' ', '_', $category));
    $ringCircumference = 113.1;
    $ringOffset = round($ringCircumference - ($ringCircumference * max(0, min(100, (int)$score)) / 100), 1);

    // Welcome Banner
    echo '<div class="member-welcome-banner skeleton-content sk-display-flex animate-fade-in">';
    echo '<div class="member-welcome-left">';
    echo '<div class="member-welcome-title-row">';
    echo '<h2 class="member-welcome-title">Welcome back, ' . h($user['first_name']) . '!</h2>';
    echo '<span class="member-tier-pill">';
    echo '<span>' . h($tierName) . ' Tier</span>';
    echo '<a href="#" onclick="event.preventDefault(); document.getElementById(\'guide-modal\').showModal();" class="tier-info-link" title="How it works">';
    echo '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>';
    echo '</a></span></div>';
    echo '<p class="member-welcome-desc">Let\'s crush today\'s fitness goals. Here\'s your progress at a glance.</p>';
    echo '</div>';
    
    // Engagement Score Widget (clicks to switch to Missions tab)
    echo '<div class="member-hero-score score-theme-' . $catSlug . '" onclick="switchMemberDashboardTab(\'missions\')" title="Click to view missions and gym leaderboard">';
    echo '<div class="hero-score-header">';
    echo '<span>Engagement Score</span>';
    echo '<a href="#" onclick="event.stopPropagation(); event.preventDefault(); document.getElementById(\'guide-modal\').showModal();" class="hero-info-btn" title="How Engagement Score works">';
    echo '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>';
    echo '</a></div>';
    echo '<div class="hero-score-body">';
    echo '<div class="hero-score-ring">';
    echo '<svg viewBox="0 0 44 44">';
    echo '<circle class="hero-ring-bg" cx="22" cy="22" r="18" fill="none" stroke-width="3.5" />';
    echo '<circle class="hero-ring-fg" cx="22" cy="22" r="18" fill="none" stroke-width="3.5" stroke-dasharray="113.1" stroke-dashoffset="' . $ringOffset . '" stroke-linecap="round" />';
    echo '</svg>';
    echo '<span class="hero-score-ring-val">' . $score . '</span>';
    echo '</div>';
    echo '<div class="hero-score-meta">';
    $displayCat = ($category === 'At-Risk') ? 'Needs a Boost' : ucfirst(str_replace('_', ' ', $category));
    echo '<span class="hero-score-badge">' . h($displayCat) . '</span>';
    echo '<div class="hero-score-action">';
    echo '<span>Missions & Rank</span>';
    echo '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>';
    echo '</div></div></div></div></div>';
    
    render_announcement_carousel(get_active_announcements('members'));
    ?>

    <!-- Top View Switcher with Vector SVG Icons & Responsive Labels -->
    <nav class="member-dashboard-nav" aria-label="Dashboard Views">
        <button type="button" class="member-dash-tab active" id="tab-btn-today" onclick="switchMemberDashboardTab('today')" title="Today & Workout">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            <span>
                <span class="tab-label-full">Today & Workout</span>
                <span class="tab-label-compact">Workout</span>
            </span>
        </button>
        <button type="button" class="member-dash-tab" id="tab-btn-activity" onclick="switchMemberDashboardTab('activity')" title="Check-in Overview & Peak Hours">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>
            <span>
                <span class="tab-label-full">Daily Check-ins & Activity</span>
                <span class="tab-label-compact">Check-ins</span>
            </span>
        </button>
        <button type="button" class="member-dash-tab" id="tab-btn-missions" onclick="switchMemberDashboardTab('missions')" title="Rank & Missions">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
            <span>
                <span class="tab-label-full">Rank & Missions</span>
                <span class="tab-label-compact">Missions</span>
            </span>
        </button>
        <button type="button" class="member-dash-tab" id="tab-btn-explore" onclick="switchMemberDashboardTab('explore')" title="Explore & Classes">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
            <span>
                <span class="tab-label-full">Explore & Classes</span>
                <span class="tab-label-compact">Classes</span>
            </span>
        </button>
    </nav>

    <!-- ========================================== -->
    <!-- TAB 1: TODAY & WORKOUT                     -->
    <!-- ========================================== -->
    <div id="panel-today" class="member-tab-panel active">
        <!-- Key Stats Metrics Grid -->
        <div class="member-stats-grid skeleton-content sk-display-grid animate-fade-in delay-1">
            <!-- Attendance Records Card -->
            <a href="index.php?page=qr_attendance" class="member-stat-card stat-card-attendance" title="View attendance records and scan QR">
                <div>
                    <div class="member-stat-top">
                        <span class="member-stat-label">Attendance Records</span>
                        <div class="member-stat-icon-wrap icon-accent-attendance">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="m9 16 2 2 4-4"/></svg>
                        </div>
                    </div>
                    <div class="member-stat-mid">
                        <span class="member-stat-num"><?= (int)$attendance ?></span>
                        <span class="member-stat-unit">check-ins</span>
                    </div>
                </div>
                <div class="member-stat-bot">
                    <span class="member-stat-hint"><?= (int)$attendance > 0 ? 'Gym visits tracked' : 'No gym visits logged' ?></span>
                    <span class="member-stat-cta"><span>Scan QR</span> <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></span>
                </div>
            </a>

            <!-- Progress Logs Card -->
            <a href="index.php?page=progress" class="member-stat-card stat-card-progress" title="View progress logs and body measurements">
                <div>
                    <div class="member-stat-top">
                        <span class="member-stat-label">Progress Logs</span>
                        <div class="member-stat-icon-wrap icon-accent-progress">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                        </div>
                    </div>
                    <div class="member-stat-mid">
                        <span class="member-stat-num"><?= (int)$progressLogs ?></span>
                        <span class="member-stat-unit">entries</span>
                    </div>
                </div>
                <div class="member-stat-bot">
                    <span class="member-stat-hint"><?= (int)$progressLogs > 0 ? 'Metrics recorded' : 'Track weight & changes' ?></span>
                    <span class="member-stat-cta"><span>Log stats</span> <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></span>
                </div>
            </a>

            <!-- Class Bookings Card -->
            <a href="index.php?page=book_classes" class="member-stat-card stat-card-classes" title="Browse and book fitness classes">
                <div>
                    <div class="member-stat-top">
                        <span class="member-stat-label">Class Bookings</span>
                        <div class="member-stat-icon-wrap icon-accent-classes">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M8 2v4"/><path d="M16 2v4"/><circle cx="12" cy="15" r="2"/></svg>
                        </div>
                    </div>
                    <div class="member-stat-mid">
                        <span class="member-stat-num"><?= (int)$classBookings ?></span>
                        <span class="member-stat-unit">booked</span>
                    </div>
                </div>
                <div class="member-stat-bot">
                    <span class="member-stat-hint"><?= (int)$classBookings > 0 ? 'Active bookings' : 'Join group classes' ?></span>
                    <span class="member-stat-cta"><span>Browse</span> <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></span>
                </div>
            </a>
        </div>

        <!-- Current Scheduled Workout Section -->
        <div class="skeleton-content animate-fade-in delay-2" style="margin-bottom: 24px;">
            <?php
            $profile = db()->query('SELECT height_cm, weight_kg, primary_goal FROM member_profiles WHERE user_id = ' . (int)$user['user_id'])->fetch();
            if ($profile && ((float)$profile['height_cm'] == 0 || (float)$profile['weight_kg'] == 0)) {
                echo '<div style="background: rgba(255,165,0,0.1); border: 1px solid orange; border-radius: 12px; padding: 24px; text-align: center; margin-bottom: 24px;">';
                echo '<h3 style="color: orange; margin: 0 0 12px 0;">We need a little more info!</h3>';
                echo '<p style="color: var(--muted); margin: 0 0 16px 0;">To build your personalized workout plan, we need your accurate height and weight.</p>';
                echo '<a href="index.php?page=profile" class="btn" style="background: orange; color: #111; font-weight: bold; padding: 10px 20px; text-decoration: none; border-radius: 6px; display: inline-block;">Complete Profile</a>';
                echo '</div>';
            } else {
                render_current_workout($user['user_id'], true);
            }
            ?>
        </div>

        <!-- Exercise Recommendations -->
        <div class="skeleton-content animate-fade-in delay-3" style="margin-bottom: 24px;">
            <?php render_exercise_recommendations((int) $user['user_id'], true); ?>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- TAB: DAILY CHECK-INS & PEAK HOURS          -->
    <!-- ========================================== -->
    <div id="panel-activity" class="member-tab-panel">




        <!-- Sub-View Navigation Switcher (Daily Attendance Overview vs Peak Hours Analysis) -->
        <div class="act-subnav-wrap animate-fade-in delay-2">
            <nav class="act-subnav" role="tablist" aria-label="Activity View Mode">
                <button type="button" class="act-subnav-btn active" id="act-subtab-roster" onclick="actSwitchSubTab('roster')" role="tab" aria-selected="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span>Daily Attendance</span>
                    <span id="act-subtab-badge" class="act-subnav-count"><?= count($actInitialData['roster']) ?></span>
                </button>
                <button type="button" class="act-subnav-btn" id="act-subtab-peak" onclick="actSwitchSubTab('peak')" role="tab" aria-selected="false">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span>Peak Hours Analysis</span>
                </button>
            </nav>
        </div>

        <!-- SUBPANEL 1: Daily Attendance Overview (Active by default) -->
        <div id="act-subpanel-roster" class="act-subpanel active">
            <div class="act-panel animate-fade-in delay-2">
                <div class="act-panel-top">
                    <div class="act-panel-header">
                        <div class="act-heading">
                            <h3 class="act-panel-title">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="color: var(--lime);"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                Daily Attendance Overview
                            </h3>
                            <p class="act-panel-sub">All members who checked in and/or checked out on <span id="act-roster-date-sub" class="act-date-formatted-label"><?= h($actInitialData['date_formatted']) ?></span></p>
                        </div>
                        <span id="act-roster-count" style="display: none;"><?= count($actInitialData['roster']) ?> Records</span>
                        <div class="act-toolbar" style="margin: 0;">
                            <label class="act-calendar-btn" title="Select Date (<?= h($actInitialData['date']) ?>)">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                    <line x1="16" y1="2" x2="16" y2="6"/>
                                    <line x1="8" y1="2" x2="8" y2="6"/>
                                    <line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                                <input type="date" class="act-date-picker-native" value="<?= h($actInitialData['date']) ?>" max="<?= date('Y-m-d') ?>" aria-label="Select Date" onchange="actOnDateChange(this)">
                            </label>
                            <button type="button" class="act-refresh-btn" onclick="actReloadData(this)" title="Refresh attendance data">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
                                <span>Refresh</span>
                            </button>
                        </div>
                    </div>

                    <div class="act-roster-controls">
                        <!-- Live Search Box -->
                        <div class="act-search-box">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" id="act-search-input" class="act-search-input" placeholder="Search by member name..." oninput="actApplyFilter()">
                        </div>

                        <!-- Hidden Status Filter State -->
                        <input type="hidden" id="act-status-filter" value="all">

                        <!-- Status Filter Pills with Number Badges -->
                        <div class="act-status-pills" role="tablist" aria-label="Attendance Status Filter">
                            <button type="button" class="act-status-pill active" id="act-pill-all" data-filter="all" onclick="actSetFilterStatus('all')" role="tab" aria-selected="true" title="Total check-ins on this date">
                                <span>All<span class="act-pill-text-extra"> Check-ins</span></span>
                                <span class="act-pill-badge" id="act-badge-total-checkins"><?= (int)$actInitialData['total_checkins'] ?></span>
                            </button>
                            <button type="button" class="act-status-pill" id="act-pill-checked_in" data-filter="checked_in" onclick="actSetFilterStatus('checked_in')" role="tab" aria-selected="false" title="Currently inside gym">
                                <span class="act-pulse-dot"></span>
                                <span><span class="act-pill-text-extra">Checked </span>In</span>
                                <span class="act-pill-badge act-pill-badge-in" id="act-badge-inside"><?= (int)$actInitialData['currently_inside'] ?></span>
                            </button>
                            <button type="button" class="act-status-pill" id="act-pill-checked_out" data-filter="checked_out" onclick="actSetFilterStatus('checked_out')" role="tab" aria-selected="false" title="Completed gym sessions">
                                <span><span class="act-pill-text-extra">Checked </span>Out</span>
                                <span class="act-pill-badge act-pill-badge-out" id="act-badge-checkouts"><?= (int)$actInitialData['total_checkouts'] ?></span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Attendance Table Container (Transforms to Cards on Mobile) -->
                <div id="act-roster-container" class="act-table-container" style="<?= empty($actInitialData['roster']) ? 'display: none;' : '' ?>">
                    <table class="act-table" id="act-roster-table">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Check-in Time</th>
                                <th>Check-out Time</th>
                                <th>Duration</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="act-roster-tbody">
                            <?php foreach ($actInitialData['roster'] as $m): ?>
                                <tr data-name="<?= h(strtolower($m['name'])) ?>" data-status="<?= h($m['status']) ?>">
                                    <td data-label="Member">
                                        <div class="act-member-info">
                                            <?php if (!empty($m['profile_picture'])): ?>
                                                <img src="<?= h($m['profile_picture']) ?>" alt="<?= h($m['name']) ?>" class="act-avatar">
                                            <?php else: ?>
                                                <div class="act-avatar"><?= h(mb_substr($m['name'], 0, 1)) ?></div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="act-member-name"><?= h($m['name']) ?></div>
                                                <div class="act-member-sub"><?= h($m['email']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Check-In">
                                        <div class="act-time-pill">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                            <span><?= h($m['check_in_formatted']) ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Check-Out">
                                        <div class="act-time-pill">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                            <span style="<?= !$m['is_checked_in'] ? '' : 'color: var(--muted); font-style: italic;' ?>"><?= h($m['check_out_formatted']) ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Duration">
                                        <span class="act-duration-pill"><?= h($m['duration']) ?></span>
                                    </td>
                                    <td data-label="Status">
                                        <?php if ($m['is_checked_in']): ?>
                                            <span class="act-status-badge act-status-in">
                                                <span class="act-badge-dot"></span>
                                                Checked In
                                            </span>
                                        <?php else: ?>
                                            <span class="act-status-badge act-status-out">
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                                Checked Out
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="act-no-match-row" style="display: none;">
                                <td colspan="5" style="text-align: center; padding: 28px; color: var(--muted);">
                                    No members match your search criteria.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Bar -->
                <div id="act-pagination" class="act-pagination-bar" style="<?= empty($actInitialData['roster']) ? 'display: none;' : '' ?>">
                    <div class="act-pagination-info">
                        <span>Showing <strong id="act-page-start">1</strong>–<strong id="act-page-end"><?= min(5, count($actInitialData['roster'])) ?></strong> of <strong id="act-page-total"><?= count($actInitialData['roster']) ?></strong></span>
                        <span class="act-page-size-wrap">
                            Per page:
                            <select id="act-page-size-select" class="act-page-size-select" onchange="actChangePageSize(this.value)">
                                <option value="5" selected>5</option>
                                <option value="10">10</option>
                                <option value="25">25</option>
                            </select>
                        </span>
                    </div>
                    <div class="act-pagination-nav" id="act-pagination-nav"></div>
                </div>

                <!-- Empty State when 0 total records for the date -->
                <div id="act-empty-state" class="act-empty-state" style="<?= !empty($actInitialData['roster']) ? 'display: none;' : '' ?>">
                    <div class="act-empty-icon">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="9" y1="16" x2="15" y2="16"/></svg>
                    </div>
                    <h4 class="act-empty-title">No Check-ins or Check-outs Found</h4>
                    <p class="act-empty-desc">No members have checked in or checked out on <span class="act-date-formatted-label"><?= h($actInitialData['date_formatted']) ?></span> yet. Select another date or check back later.</p>
                    <button type="button" class="act-empty-btn" onclick="actSetQuickDate('today')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                        <span>Jump to Today's Activity</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- SUBPANEL 2: Peak Hours Analysis -->
        <div id="act-subpanel-peak" class="act-subpanel">
            <div class="act-panel animate-fade-in delay-2">
                <div class="act-panel-top">
                    <div class="act-panel-header">
                        <div class="act-heading">
                            <h3 class="act-panel-title">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="color: var(--lime);"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                Peak Hours Analysis
                            </h3>
                            <p class="act-panel-sub" id="act-peak-panel-sub">Traffic distribution and gym congestion patterns</p>
                        </div>
                        <div class="act-toolbar" style="margin: 0;">
                            <label class="act-calendar-btn" title="Select Date (<?= h($actInitialData['date']) ?>)">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                    <line x1="16" y1="2" x2="16" y2="6"/>
                                    <line x1="8" y1="2" x2="8" y2="6"/>
                                    <line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                                <input type="date" class="act-date-picker-native" value="<?= h($actInitialData['date']) ?>" max="<?= date('Y-m-d') ?>" aria-label="Select Date" onchange="actOnDateChange(this)">
                            </label>
                            <button type="button" class="act-refresh-btn" onclick="actReloadData(this)" title="Refresh attendance data">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
                                <span>Refresh</span>
                            </button>
                        </div>
                    </div>
                    <div class="act-peak-controls">
                        <div class="act-range-group" role="group" aria-label="Peak Hours View Mode">
                            <button type="button" class="act-range-btn active" id="act-range-day" onclick="actSetRange('day')">Day (Hourly)</button>
                            <button type="button" class="act-range-btn" id="act-range-week" onclick="actSetRange('week')">Week (7 Days)</button>
                            <button type="button" class="act-range-btn" id="act-range-month" onclick="actSetRange('month')">Month</button>
                        </div>
                    </div>
                </div>

                <!-- Peak Rush Callout Banner -->
                <div class="act-peak-banner" id="act-peak-banner">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
                    <div id="act-peak-banner-text">
                        <?php if ($actInitialData['peak_max_count'] > 0): ?>
                            <strong>Peak Traffic Detected:</strong> Peak check-ins occurred at <strong><?= h($actInitialData['peak_hour_label']) ?></strong>. Plan your gym session to avoid busy hours or join the rush!
                        <?php else: ?>
                            <strong>No Check-in Activity:</strong> No attendance recorded yet for this time window.
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Peak Rush Chart Canvas -->
                <div class="act-chart-wrap">
                    <canvas id="act-peak-chart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- TAB 2: RANK & MISSIONS                     -->
    <!-- ========================================== -->
    <div id="panel-missions" class="member-tab-panel">
        <?php
        $gymId = $user['gym_id'] ?? scalar('SELECT gym_id FROM gym_members WHERE user_id = ? LIMIT 1', [$user['user_id']]);
        $stmtLeaders = $pdo->prepare('SELECT u.user_id, u.first_name, u.last_name, u.engagement_score FROM users u JOIN gym_members gm ON u.user_id = gm.user_id WHERE u.role = "member" AND gm.gym_id = ? AND u.status = "active" ORDER BY u.engagement_score DESC LIMIT 5');
        $stmtLeaders->execute([$gymId]);
        $topLeaders = $stmtLeaders->fetchAll(PDO::FETCH_ASSOC);

        $stmtBadges = $pdo->prepare('SELECT badge_type, unlocked_at FROM member_badges WHERE user_id = ? ORDER BY unlocked_at DESC');
        $stmtBadges->execute([$user['user_id']]);
        $badges = $stmtBadges->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <!-- Leaderboard & Badges Grid -->
        <div class="skeleton-content animate-fade-in" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr)); gap: 24px; margin-bottom: 24px;">
            <!-- Gym Leaderboard -->
            <section id="gym-leaderboard-section" class="panel missions-panel-card" style="padding: 20px;">
                <h3 style="margin: 0 0 16px; color: var(--ink); display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--lime);"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                    Gym Leaderboard
                </h3>
                <?php
                if ($topLeaders) {
                    $rank = 1;
                    foreach ($topLeaders as $leader) {
                        $isMe = ((int)$leader['user_id'] === (int)$user['user_id']);
                        $bg = $isMe ? 'color-mix(in srgb, var(--lime) 12%, transparent)' : 'color-mix(in srgb, var(--panel-soft) 40%, transparent)';
                        $border = $isMe ? '1px solid color-mix(in srgb, var(--lime) 40%, transparent)' : '1px solid var(--line)';
                        echo '<div style="display: flex; align-items: center; justify-content: space-between; padding: 11px 14px; background: '.$bg.'; border: '.$border.'; border-radius: 10px; margin-bottom: 8px; transition: all 0.2s ease;">';
                        echo '<div style="display: flex; align-items: center; gap: 12px;">';
                        echo '<span style="font-weight: 800; color: '.($rank <= 3 ? 'var(--lime)' : 'var(--muted)').'; width: 22px; font-size: 13px;">#'.$rank.'</span>';
                        echo '<span style="font-weight: 600; color: var(--ink); font-size: 13.5px;">'.h($leader['first_name'].' '.mb_substr($leader['last_name'], 0, 1)).'. '.($isMe ? '<small style="color:var(--lime);font-size:11px;font-weight:700;">(You)</small>' : '').'</span>';
                        echo '</div>';
                        echo '<span style="font-weight: 800; color: var(--lime); font-size: 14px;">'.$leader['engagement_score'].' <small style="font-size:11px;font-weight:500;color:var(--muted)">pts</small></span>';
                        echo '</div>';
                        $rank++;
                    }
                } else {
                    echo '<p style="color: var(--muted); font-size: 13px;">No active members found.</p>';
                }
                ?>
            </section>

            <!-- My Badges -->
            <section id="my-badges-section" class="panel missions-panel-card" style="padding: 20px;">
                <h3 style="margin: 0 0 16px; color: var(--ink); display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--lime);"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>
                    My Badges
                </h3>
                <?php
                $badgeDefs = [
                    'early_bird' => [
                        'icon' => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--lime); display:inline-block;"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>',
                        'name' => 'Early Bird',
                        'desc' => 'Checked in before 7 AM'
                    ],
                    'iron_lifter' => [
                        'icon' => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--lime); display:inline-block;"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/><path d="m3 10 7-7"/><path d="m14 21 7-7"/></svg>',
                        'name' => 'Iron Lifter',
                        'desc' => 'Completed 50 exercises'
                    ],
                    'century_club' => [
                        'icon' => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--lime); display:inline-block;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
                        'name' => 'Century Club',
                        'desc' => 'Reached 100 Engagement Score'
                    ]
                ];

                if ($badges) {
                    echo '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(88px, 1fr)); gap: 14px;">';
                    foreach ($badges as $b) {
                        $def = $badgeDefs[$b['badge_type']] ?? [
                            'icon' => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--lime); display:inline-block;"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>',
                            'name' => 'Unknown',
                            'desc' => 'Achievement Unlocked'
                        ];
                        echo '<div style="text-align: center; background: color-mix(in srgb, var(--panel-soft) 45%, transparent); padding: 14px 10px; border-radius: 12px; border: 1px solid var(--line); transition: all 0.2s ease;" title="'.h($def['desc']).' - Unlocked '.date('M j', strtotime($b['unlocked_at'])).'">';
                        echo '<div style="margin-bottom: 8px; display: flex; align-items: center; justify-content: center;">'.$def['icon'].'</div>';
                        echo '<div style="font-size: 11px; font-weight: bold; color: var(--ink); line-height: 1.2;">'.$def['name'].'</div>';
                        echo '</div>';
                    }
                    echo '</div>';
                } else {
                    echo '<div style="text-align: center; padding: 28px 20px; border: 1px dashed var(--line); border-radius: 12px;">';
                    echo '<p style="color: var(--muted); font-size: 13px; margin: 0;">No badges unlocked yet. Keep training!</p>';
                    echo '</div>';
                }
                ?>
            </section>
        </div>

        <!-- Engagement Missions -->
        <?php
        $missions = get_engagement_missions((int) $user['user_id']);
        $completedCount = count(array_filter($missions, fn($m) => $m['completed']));
        $totalMissions = count($missions);
        ?>
        <div id="missions-section" class="skeleton-content animate-fade-in delay-1" style="margin-bottom: 24px;">
            <section class="panel missions-panel-card" style="padding: 0; overflow: hidden;">
                <div style="padding: 20px 24px 16px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <h2 style="margin: 0; font-size: 18px; color: var(--ink);">Missions</h2>
                        <span style="font-size: 12px; background: color-mix(in srgb, var(--lime) 15%, transparent); color: var(--lime); padding: 3px 10px; border-radius: 12px; font-weight: 600;"><?= $completedCount ?>/<?= $totalMissions ?> Complete</span>
                    </div>
                    <span style="font-size: 13px; color: var(--muted);">Complete missions to boost your Engagement Score!</span>
                </div>
                <div style="padding: 18px 24px 24px;">
                    <div class="mission-card-list">
                    <?php 
                    $missionIndex = 0;
                    foreach ($missions as $mission): 
                        $missionIndex++;
                        $isHidden = $missionIndex > 2;
                        
                        $pct = $mission['target'] > 0 ? min(100, round(($mission['current'] / $mission['target']) * 100)) : 0;
                        $barColor = $mission['completed'] ? '#22c55e' : 'var(--lime)';
                        $checkColor = $mission['completed'] ? '#22c55e' : 'var(--line)';
                        $checkBg = $mission['completed'] ? 'rgba(34, 197, 94, 0.15)' : 'color-mix(in srgb, var(--ink) 4%, transparent)';
                        ?>
                        <div class="mission-item mission-card-item <?= $isHidden ? 'hidden-mission' : '' ?> <?= $mission['completed'] ? 'mission-completed' : '' ?>" style="display: <?= $isHidden ? 'none' : 'flex' ?>;">
                            <div style="width: 40px; height: 40px; border-radius: 50%; border: 2px solid <?= $checkColor ?>; background: <?= $checkBg ?>; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.3s;">
                                <?php if ($mission['completed']): ?>
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                <?php else: ?>
                                    <span style="font-size: 16px;"><?= $mission['icon'] ?></span>
                                <?php endif; ?>
                            </div>

                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                    <span style="font-weight: 700; color: var(--ink); font-size: 14.5px; <?= $mission['completed'] ? 'text-decoration: line-through; color: var(--muted);' : '' ?>"><?= h($mission['title']) ?></span>
                                    <span style="font-size: 12px; font-weight: 700; color: <?= $mission['completed'] ? '#22c55e' : 'var(--lime)' ?>; white-space: nowrap; margin-left: 8px;">
                                        +<?= $mission['earnedPoints'] ?>/<?= $mission['maxPoints'] ?> pts
                                    </span>
                                </div>
                                <div style="font-size: 12.5px; color: var(--muted); margin-bottom: 8px;"><?= h($mission['description']) ?></div>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="flex: 1; height: 6px; background: color-mix(in srgb, var(--ink) 8%, transparent); border-radius: 3px; overflow: hidden;">
                                        <div style="width: <?= $pct ?>%; height: 100%; background: <?= $barColor ?>; border-radius: 3px; transition: width 0.5s ease;"></div>
                                    </div>
                                    <span style="font-size: 11.5px; color: var(--muted); font-weight: 600; white-space: nowrap;"><?= $mission['current'] ?>/<?= $mission['target'] ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    
                    <?php if (count($missions) > 2): ?>
                        <div style="text-align: center; margin-top: 18px;">
                            <button id="toggle-missions-btn" onclick="toggleMissions()" style="background: color-mix(in srgb, var(--lime) 12%, transparent); border: 1px solid color-mix(in srgb, var(--lime) 35%, transparent); color: var(--lime); font-size: 13px; font-weight: 600; cursor: pointer; padding: 7px 18px; border-radius: 20px; transition: all 0.2s;" onmouseover="this.style.background='color-mix(in srgb, var(--lime) 20%, transparent)'" onmouseout="this.style.background='color-mix(in srgb, var(--lime) 12%, transparent)'">
                                Show All Missions
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
    </div>

    <!-- ========================================== -->
    <!-- TAB 3: EXPLORE & CLASSES                   -->
    <!-- ========================================== -->
    <div id="panel-explore" class="member-tab-panel">
        <?php
        $profile = db()->query('SELECT height_cm, weight_kg, primary_goal FROM member_profiles WHERE user_id = ' . (int)$user['user_id'])->fetch();
        $recommendations = !empty($profile['primary_goal']) ? get_recommendations_by_goal(db(), $profile['primary_goal']) : ['classes' => [], 'gyms' => []];
        $hasExploreContent = !empty($recommendations['classes']) || !empty($recommendations['gyms']);
        ?>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="margin: 0; font-size: 18px; color: var(--ink);">Classes & Gym Discovery</h3>
                <p style="margin: 4px 0 0; color: var(--muted); font-size: 13px;">Curated classes and partner gym locations based on your fitness goals.</p>
            </div>
            <a href="index.php?page=book_classes" class="explore-browse-btn">
                <span>Browse All Classes</span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
            </a>
        </div>

        <?php if ($hasExploreContent): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr)); gap: 20px;">
                <!-- Recommended Classes -->
                <?php if (!empty($recommendations['classes'])): ?>
                    <div class="panel missions-panel-card" style="padding: 22px;">
                        <h4 style="margin:0 0 18px; color:var(--ink); font-size: 16px; display:flex; align-items:center; gap:8px;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--lime);"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            Recommended Classes
                        </h4>
                        <div>
                        <?php foreach ($recommendations['classes'] as $cls): ?>
                            <div class="explore-card-item">
                                <div style="display: flex; gap: 14px; align-items: flex-start; flex: 1; min-width: 0;">
                                    <div style="width: 38px; height: 38px; border-radius: 10px; background: color-mix(in srgb, var(--lime) 15%, transparent); color: var(--lime); display: flex; align-items: center; justify-content: center; flex-shrink: 0; border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                                    </div>
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="font-weight: 700; font-size: 14.5px; color: var(--ink); margin-bottom: 2px;"><?= h($cls['class_name']) ?></div>
                                        <div style="font-size: 12px; color: var(--lime); font-weight: 600; margin-bottom: 6px; display: flex; align-items: center; gap: 4px;">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                            At <?= h($cls['gym_name']) ?>
                                        </div>
                                        <?php if (!empty($cls['description'])): ?>
                                            <div style="font-size: 12.5px; color: var(--muted); line-height: 1.4;"><?= h($cls['description']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <a href="index.php?page=book_classes" class="explore-action-btn btn-book">
                                    <span>Book</span>
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                                </a>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Recommended Gyms -->
                <?php if (!empty($recommendations['gyms'])): ?>
                    <div class="panel missions-panel-card" style="padding: 22px;">
                        <h4 style="margin:0 0 18px; color:var(--ink); font-size: 16px; display:flex; align-items:center; gap:8px;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #a855f7;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                            Partner Gyms
                        </h4>
                        <div>
                        <?php foreach ($recommendations['gyms'] as $gym): ?>
                            <div class="explore-card-item accent-gym">
                                <div style="display: flex; gap: 14px; align-items: flex-start; flex: 1; min-width: 0;">
                                    <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(168, 85, 247, 0.15); color: #a855f7; display: flex; align-items: center; justify-content: center; flex-shrink: 0; border: 1px solid rgba(168, 85, 247, 0.3);">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                                    </div>
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="font-weight: 700; font-size: 14.5px; color: var(--ink); margin-bottom: 2px;"><?= h($gym['name']) ?></div>
                                        <div style="font-size: 12px; color: var(--muted); font-weight: 500; display: flex; align-items: center; gap: 4px;">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 21.7C17.3 17 20 13 20 10a8 8 0 1 0-16 0c0 3 2.7 7 8 11.7z"/></svg>
                                            <?= h($gym['address'] ?? 'Partner Gym Location') ?>
                                        </div>
                                    </div>
                                </div>
                                <a href="index.php?page=view_gym&gym_id=<?= (int)$gym['gym_id'] ?>" class="explore-action-btn btn-view">
                                    <span>View</span>
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                                </a>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="panel missions-panel-card" style="text-align: center; padding: 48px 24px; border-radius: 14px;">
                <div style="width: 52px; height: 52px; border-radius: 14px; background: color-mix(in srgb, var(--lime) 15%, transparent); color: var(--lime); border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 16px;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
                </div>
                <h4 style="margin: 0 0 6px; font-size: 17px; color: var(--ink); font-weight: 700;">Set a Primary Goal to Unlock Recommendations</h4>
                <p style="margin: 0 0 20px; font-size: 13.5px; color: var(--muted); max-width: 440px; margin-left: auto; margin-right: auto;">We personalize classes and workout routines to your target fitness goal.</p>
                <a href="index.php?page=profile" class="explore-browse-btn" style="margin-top: 4px;">
                    <span>Update Profile Goal</span>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tab Switching & Activity Dashboard Controller JavaScript -->
    <script>
    let actCurrentData = <?= json_encode($actInitialData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    let actCurrentRange = 'day';
    window.actPeakChart = null;
    let actAutoRefreshTimer = null;

    function switchMemberDashboardTab(tabId) {
        const tabs = ['today', 'activity', 'missions', 'explore'];
        if (!tabs.includes(tabId)) tabId = 'today';

        tabs.forEach(t => {
            const btn = document.getElementById('tab-btn-' + t);
            const panel = document.getElementById('panel-' + t);
            if (btn) {
                if (t === tabId) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            }
            if (panel) {
                if (t === tabId) {
                    panel.classList.add('active');
                } else {
                    panel.classList.remove('active');
                }
            }
        });

        // Save preference in localStorage & URL hash
        try {
            localStorage.setItem('fit_member_dashboard_tab', tabId);
            window.history.replaceState(null, null, '#' + tabId);
        } catch (e) {}

        // When switching to activity, initialize or resize chart & restore subtab
        if (tabId === 'activity') {
            const savedSubtab = localStorage.getItem('fit_activity_subtab') || 'roster';
            actSwitchSubTab(savedSubtab);
        }
    }

    // Switch between Daily Attendance Overview and Peak Hours Analysis sub-tabs
    function actSwitchSubTab(tab) {
        const validTabs = ['roster', 'peak'];
        if (!validTabs.includes(tab)) tab = 'roster';

        validTabs.forEach(t => {
            const btn = document.getElementById('act-subtab-' + t);
            const panel = document.getElementById('act-subpanel-' + t);
            if (btn) {
                if (t === tab) {
                    btn.classList.add('active');
                    btn.setAttribute('aria-selected', 'true');
                } else {
                    btn.classList.remove('active');
                    btn.setAttribute('aria-selected', 'false');
                }
            }
            if (panel) {
                if (t === tab) {
                    panel.classList.add('active');
                } else {
                    panel.classList.remove('active');
                }
            }
        });

        try {
            localStorage.setItem('fit_activity_subtab', tab);
        } catch (e) {}

        if (tab === 'peak') {
            setTimeout(() => {
                if (!window.actPeakChart && actCurrentData && actCurrentData.chart) {
                    initOrUpdateActivityChart(actCurrentData.chart);
                } else if (window.actPeakChart) {
                    window.actPeakChart.resize();
                }
            }, 50);
        }
    }

    // Initialize or re-render Peak Hours Chart.js
    function initOrUpdateActivityChart(chartData) {
        const canvas = document.getElementById('act-peak-chart');
        if (!canvas || typeof Chart === 'undefined') return;

        const isLight = document.documentElement.getAttribute('data-theme') === 'light' || 
                        document.body.getAttribute('data-theme') === 'light';

        const peakBg = isLight ? '#16a34a' : '#c7ff22';
        const peakBorder = isLight ? '#15803d' : '#e6ff70';
        const normBg = isLight ? 'rgba(13, 148, 136, 0.45)' : 'rgba(45, 212, 191, 0.35)';
        const normBorder = isLight ? 'rgba(13, 148, 136, 0.8)' : 'rgba(45, 212, 191, 0.7)';
        const textColor = isLight ? '#475569' : '#94a3b8';
        const gridColor = isLight ? 'rgba(0,0,0,0.06)' : 'rgba(255,255,255,0.06)';

        const bgColors = [];
        const borderColors = [];
        const peakIndices = chartData.peak_indices || [];

        chartData.data.forEach((val, idx) => {
            if (peakIndices.includes(idx) && val > 0) {
                bgColors.push(peakBg);
                borderColors.push(peakBorder);
            } else {
                bgColors.push(normBg);
                borderColors.push(normBorder);
            }
        });

        if (window.actPeakChart) {
            window.actPeakChart.destroy();
            window.actPeakChart = null;
        }

        const maxVal = Math.max(3, ...(chartData.data || [0]));

        window.actPeakChart = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Check-ins',
                    data: chartData.data,
                    backgroundColor: bgColors,
                    borderColor: borderColors,
                    borderWidth: 1.5,
                    borderRadius: 6,
                    maxBarThickness: 38
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 400 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isLight ? 'rgba(15, 23, 42, 0.95)' : 'rgba(15, 20, 30, 0.96)',
                        titleColor: isLight ? '#f8fafc' : '#ffffff',
                        bodyColor: isLight ? '#e2e8f0' : '#cbd5e1',
                        borderColor: isLight ? '#334155' : 'rgba(255, 255, 255, 0.12)',
                        borderWidth: 1,
                        padding: 10,
                        displayColors: false,
                        callbacks: {
                            label: function(ctx) {
                                const val = ctx.parsed.y;
                                const isPeak = peakIndices.includes(ctx.dataIndex) && val > 0;
                                return ' ' + val + (val === 1 ? ' member checked in' : ' members checked in') + (isPeak ? ' (★ Peak Rush)' : '');
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        suggestedMax: maxVal + 1,
                        ticks: {
                            color: textColor,
                            font: { family: "'Inter', system-ui, sans-serif", size: 11, weight: '600' },
                            precision: 0,
                            stepSize: 1
                        },
                        grid: {
                            color: gridColor,
                            drawBorder: false
                        }
                    },
                    x: {
                        ticks: {
                            color: textColor,
                            font: { family: "'Inter', system-ui, sans-serif", size: 11, weight: '600' },
                            maxRotation: 45,
                            minRotation: 0
                        },
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // Set peak hours range (day, week, month)
    function actSetRange(range) {
        if (!['day', 'week', 'month'].includes(range)) range = 'day';
        actCurrentRange = range;

        ['day', 'week', 'month'].forEach(r => {
            const btn = document.getElementById('act-range-' + r);
            if (btn) {
                if (r === range) btn.classList.add('active');
                else btn.classList.remove('active');
            }
        });

        const dateInput = document.querySelector('.act-date-picker-native');
        const selectedDate = dateInput ? dateInput.value : '';
        actFetchData(selectedDate, actCurrentRange);
    }

    // Quick Date Pills (Today, Yesterday)
    function actSetQuickDate(type) {
        const today = new Date();
        let targetDate = new Date(today);

        if (type === 'yesterday') {
            targetDate.setDate(today.getDate() - 1);
        }

        const yyyy = targetDate.getFullYear();
        const mm = String(targetDate.getMonth() + 1).padStart(2, '0');
        const dd = String(targetDate.getDate()).padStart(2, '0');
        const dateStr = `${yyyy}-${mm}-${dd}`;

        document.querySelectorAll('.act-date-picker-native').forEach(el => el.value = dateStr);
        document.querySelectorAll('.act-calendar-btn').forEach(btn => btn.title = 'Select Date (' + dateStr + ')');

        actFetchData(dateStr, actCurrentRange);
    }

    // Prev / Next Day navigation
    function actStepDate(offset) {
        const dateInput = document.querySelector('.act-date-picker-native');
        if (!dateInput || !dateInput.value) return;

        const parts = dateInput.value.split('-');
        if (parts.length !== 3) return;

        const current = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        current.setDate(current.getDate() + offset);

        const today = new Date();
        today.setHours(23, 59, 59, 999);
        if (current > today) return; // Prevent picking future date

        const yyyy = current.getFullYear();
        const mm = String(current.getMonth() + 1).padStart(2, '0');
        const dd = String(current.getDate()).padStart(2, '0');
        const dateStr = `${yyyy}-${mm}-${dd}`;

        document.querySelectorAll('.act-date-picker-native').forEach(el => el.value = dateStr);
        document.querySelectorAll('.act-calendar-btn').forEach(btn => btn.title = 'Select Date (' + dateStr + ')');
        actFetchData(dateStr, actCurrentRange);
    }

    // When the user changes date via calendar icon picker
    function actOnDateChange(changedInput) {
        const val = changedInput ? changedInput.value : (document.querySelector('.act-date-picker-native') ? document.querySelector('.act-date-picker-native').value : '');
        if (!val) return;
        document.querySelectorAll('.act-date-picker-native').forEach(el => el.value = val);
        document.querySelectorAll('.act-calendar-btn').forEach(btn => btn.title = 'Select Date (' + val + ')');
        actFetchData(val, actCurrentRange);
    }

    // Manual Refresh button
    function actReloadData(btn) {
        const dateInput = document.querySelector('.act-date-picker-native');
        const selectedDate = dateInput ? dateInput.value : '';
        actFetchData(selectedDate, actCurrentRange, btn);
    }

    // Fetch Activity & Peak Hours data via AJAX
    function actFetchData(date, range, triggerBtn) {
        document.querySelectorAll('.act-refresh-btn').forEach(b => b.classList.add('loading'));

        const params = new URLSearchParams({
            page: 'dashboard',
            action: 'attendance_activity_api',
            date: date || '',
            range: range || 'day'
        });

        fetch('index.php?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => {
            if (!res.ok) throw new Error('Network response was not ok');
            return res.json();
        })
        .then(data => {
            actCurrentData = data;
            actUpdateUI(data);
        })
        .catch(err => {
            console.error('Failed to load attendance activity data:', err);
        })
        .finally(() => {
            document.querySelectorAll('.act-refresh-btn').forEach(b => b.classList.remove('loading'));
        });
    }

    // Update Dashboard DOM with fetched activity data
    function actUpdateUI(data) {
        // 1. Date input & calendar trigger button
        if (data.date) {
            document.querySelectorAll('.act-date-picker-native').forEach(el => el.value = data.date);
            document.querySelectorAll('.act-calendar-btn').forEach(btn => btn.title = 'Select Date (' + data.date + ')');
        }

        const todayStr = (new Date()).toISOString().split('T')[0];
        const yesterdayObj = new Date();
        yesterdayObj.setDate(yesterdayObj.getDate() - 1);
        const yesterdayStr = yesterdayObj.toISOString().split('T')[0];

        const todayBtn = document.getElementById('act-btn-today');
        const yesterdayBtn = document.getElementById('act-btn-yesterday');
        if (todayBtn) {
            if (data.date === todayStr) todayBtn.classList.add('active');
            else todayBtn.classList.remove('active');
        }
        if (yesterdayBtn) {
            if (data.date === yesterdayStr) yesterdayBtn.classList.add('active');
            else yesterdayBtn.classList.remove('active');
        }

        const nextBtn = document.getElementById('act-next-day-btn');
        if (nextBtn) {
            nextBtn.disabled = (data.date >= todayStr);
        }

        // Date labels
        document.querySelectorAll('.act-date-formatted-label').forEach(el => {
            el.textContent = data.date_formatted;
        });
        const shortDateLabel = document.getElementById('act-stat-date-label');
        if (shortDateLabel && data.date) {
            const dObj = new Date(data.date + 'T00:00:00');
            shortDateLabel.textContent = dObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }

        // 2. Status filter pill count badges & Peak stat
        const badgeCheckins = document.getElementById('act-badge-total-checkins');
        if (badgeCheckins && data.total_checkins !== undefined) {
            badgeCheckins.textContent = data.total_checkins;
        }

        const badgeInside = document.getElementById('act-badge-inside');
        if (badgeInside && data.currently_inside !== undefined) {
            badgeInside.textContent = data.currently_inside;
        }

        const badgeCheckouts = document.getElementById('act-badge-checkouts');
        if (badgeCheckouts && data.total_checkouts !== undefined) {
            badgeCheckouts.textContent = data.total_checkouts;
        }

        const statPeak = document.getElementById('act-stat-peakhour');
        if (statPeak && data.peak_hour_label) {
            statPeak.textContent = data.peak_hour_label;
            statPeak.title = data.peak_hour_label;
        }

        // 3. Peak Rush banner & Subtitle
        const peakBannerText = document.getElementById('act-peak-banner-text');
        if (peakBannerText) {
            if (data.peak_max_count > 0) {
                let rangePrefix = 'Peak check-ins occurred at ';
                if (data.range === 'week') {
                    rangePrefix = 'Over the past 7 days, peak rush occurred at ';
                } else if (data.range === 'month') {
                    rangePrefix = 'Across this month, peak rush occurred at ';
                }
                peakBannerText.innerHTML = '<strong>Peak Traffic Detected:</strong> ' + rangePrefix + '<strong>' + escapeHtml(data.peak_hour_label) + '</strong>. Plan your gym session to avoid busy hours or join the rush!';
            } else {
                peakBannerText.innerHTML = '<strong>No Check-in Activity:</strong> No attendance recorded yet for this time window.';
            }
        }

        const peakSub = document.getElementById('act-peak-panel-sub');
        if (peakSub) {
            if (data.range === 'week') {
                peakSub.textContent = 'Hourly traffic distribution aggregated over the past 7 days';
            } else if (data.range === 'month') {
                peakSub.textContent = 'Hourly traffic distribution aggregated across the month';
            } else {
                peakSub.textContent = 'Traffic distribution and gym congestion patterns for ' + (data.date_formatted || 'selected date');
            }
        }

        // 4. Update Chart
        if (data.chart) {
            initOrUpdateActivityChart(data.chart);
        }

        // 5. Update Roster Table & Empty State
        const rosterContainer = document.getElementById('act-roster-container');
        const emptyState = document.getElementById('act-empty-state');
        const tbody = document.getElementById('act-roster-tbody');
        const countBadge = document.getElementById('act-roster-count');
        const subtabBadge = document.getElementById('act-subtab-badge');

        const count = data.roster ? data.roster.length : 0;
        if (countBadge) {
            countBadge.textContent = count + (count === 1 ? ' Record' : ' Records');
        }
        if (subtabBadge) {
            subtabBadge.textContent = count;
        }

        if (data.roster && data.roster.length > 0) {
            if (emptyState) emptyState.style.display = 'none';
            if (rosterContainer) rosterContainer.style.display = '';

            if (tbody) {
                let rowsHtml = '';
                data.roster.forEach(m => {
                    const avatarHtml = m.profile_picture ? 
                        `<img src="${escapeHtml(m.profile_picture)}" alt="${escapeHtml(m.name)}" class="act-avatar">` : 
                        `<div class="act-avatar">${escapeHtml((m.name || 'M').charAt(0).toUpperCase())}</div>`;

                    const statusBadgeHtml = m.is_checked_in ? 
                        `<span class="act-status-badge act-status-in"><span class="act-badge-dot"></span>Checked In</span>` : 
                        `<span class="act-status-badge act-status-out"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>Checked Out</span>`;

                    const outTimeStyle = m.is_checked_in ? 'color: var(--muted); font-style: italic;' : '';

                    rowsHtml += `
                        <tr data-name="${escapeHtml((m.name || '').toLowerCase())}" data-status="${escapeHtml(m.status)}">
                            <td data-label="Member">
                                <div class="act-member-info">
                                    ${avatarHtml}
                                    <div>
                                        <div class="act-member-name">${escapeHtml(m.name)}</div>
                                        <div class="act-member-sub">${escapeHtml(m.email || '')}</div>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Check-In">
                                <div class="act-time-pill">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <span>${escapeHtml(m.check_in_formatted)}</span>
                                </div>
                            </td>
                            <td data-label="Check-Out">
                                <div class="act-time-pill">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <span style="${outTimeStyle}">${escapeHtml(m.check_out_formatted)}</span>
                                </div>
                            </td>
                            <td data-label="Duration">
                                <span class="act-duration-pill">${escapeHtml(m.duration)}</span>
                            </td>
                            <td data-label="Status">
                                ${statusBadgeHtml}
                            </td>
                        </tr>
                    `;
                });

                rowsHtml += `
                    <tr id="act-no-match-row" style="display: none;">
                        <td colspan="5" style="text-align: center; padding: 28px; color: var(--muted);">
                            No members match your search criteria.
                        </td>
                    </tr>
                `;

                tbody.innerHTML = rowsHtml;
            }
        } else {
            if (rosterContainer) rosterContainer.style.display = 'none';
            if (emptyState) emptyState.style.display = '';
        }

        // Re-apply client filter
        actApplyFilter();
    }

    let actCurrentPage = 1;
    let actPageSize = 5;
    let actCurrentStatusFilter = 'all';

    function actSetFilterStatus(status) {
        actCurrentStatusFilter = status;
        const filterInput = document.getElementById('act-status-filter');
        if (filterInput) filterInput.value = status;

        ['all', 'checked_in', 'checked_out'].forEach(s => {
            const btn = document.getElementById('act-pill-' + s);
            if (btn) {
                if (s === status) {
                    btn.classList.add('active');
                    btn.setAttribute('aria-selected', 'true');
                } else {
                    btn.classList.remove('active');
                    btn.setAttribute('aria-selected', 'false');
                }
            }
        });

        actApplyFilter(true);
    }

    function actChangePageSize(size) {
        actPageSize = parseInt(size, 10) || 5;
        actCurrentPage = 1;
        actApplyFilter(false);
    }

    function actGoToPage(page) {
        actCurrentPage = page;
        actApplyFilter(false);
    }

    // Client-side instant filter & pagination for search input & status pills
    function actApplyFilter(resetPage = true) {
        if (resetPage) {
            actCurrentPage = 1;
        }

        const searchInput = document.getElementById('act-search-input');
        const statusSelect = document.getElementById('act-status-filter');
        const q = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const st = actCurrentStatusFilter || (statusSelect ? statusSelect.value : 'all');

        const allRows = Array.from(document.querySelectorAll('#act-roster-tbody tr[data-name]'));
        
        // 1. Identify all matching rows
        const matchingRows = allRows.filter(tr => {
            const name = tr.getAttribute('data-name') || '';
            const status = tr.getAttribute('data-status') || '';
            const matchQuery = !q || name.includes(q);
            const matchStatus = (st === 'all') || (status === st);
            return matchQuery && matchStatus;
        });

        const totalMatches = matchingRows.length;
        const totalPages = Math.max(1, Math.ceil(totalMatches / actPageSize));

        // Clamp current page
        if (actCurrentPage > totalPages) actCurrentPage = totalPages;
        if (actCurrentPage < 1) actCurrentPage = 1;

        // 2. Hide all non-matching rows and apply pagination to matching rows
        const startIndex = (actCurrentPage - 1) * actPageSize;
        const endIndex = Math.min(startIndex + actPageSize, totalMatches);

        allRows.forEach(tr => {
            tr.classList.add('act-row-hidden');
            tr.style.setProperty('display', 'none', 'important');
        });

        for (let i = startIndex; i < endIndex; i++) {
            if (matchingRows[i]) {
                matchingRows[i].classList.remove('act-row-hidden');
                matchingRows[i].style.removeProperty('display');
                matchingRows[i].style.display = '';
            }
        }

        // 3. No match message
        const noMatchRow = document.getElementById('act-no-match-row');
        if (noMatchRow) {
            const shouldShow = (allRows.length > 0 && totalMatches === 0);
            noMatchRow.style.setProperty('display', shouldShow ? 'block' : 'none', 'important');
        }

        // 4. Update pagination controls
        actRenderPagination(totalMatches, totalPages, startIndex, endIndex);
    }

    function actRenderPagination(totalMatches, totalPages, startIndex, endIndex) {
        const paginationEl = document.getElementById('act-pagination');
        if (!paginationEl) return;

        if (totalMatches === 0) {
            paginationEl.style.display = 'none';
            return;
        }

        paginationEl.style.display = 'flex';

        const startEl = document.getElementById('act-page-start');
        const endEl = document.getElementById('act-page-end');
        const totalEl = document.getElementById('act-page-total');

        if (startEl) startEl.textContent = totalMatches > 0 ? (startIndex + 1) : 0;
        if (endEl) endEl.textContent = endIndex;
        if (totalEl) totalEl.textContent = totalMatches;

        const nav = document.getElementById('act-pagination-nav');
        if (!nav) return;

        let navHtml = '';

        // Prev Button
        const prevDisabled = (actCurrentPage <= 1) ? 'disabled' : '';
        navHtml += `
            <button type="button" class="act-page-btn" onclick="actGoToPage(${actCurrentPage - 1})" ${prevDisabled} title="Previous Page">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 19l-7-7 7-7"/></svg>
            </button>
        `;

        // Page Numbers
        for (let p = 1; p <= totalPages; p++) {
            if (totalPages > 7) {
                if (p !== 1 && p !== totalPages && Math.abs(p - actCurrentPage) > 1) {
                    if (p === 2 || p === totalPages - 1) {
                        navHtml += `<span class="act-page-dots">...</span>`;
                    }
                    continue;
                }
            }

            const activeClass = (p === actCurrentPage) ? 'active' : '';
            navHtml += `
                <button type="button" class="act-page-btn ${activeClass}" onclick="actGoToPage(${p})">
                    ${p}
                </button>
            `;
        }

        // Next Button
        const nextDisabled = (actCurrentPage >= totalPages) ? 'disabled' : '';
        navHtml += `
            <button type="button" class="act-page-btn" onclick="actGoToPage(${actCurrentPage + 1})" ${nextDisabled} title="Next Page">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 5l7 7-7 7"/></svg>
            </button>
        `;

        nav.innerHTML = navHtml;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Restore tab from hash or localStorage on page load
    document.addEventListener('DOMContentLoaded', function() {
        let initialTab = 'today';
        const hash = window.location.hash.replace('#', '');
        const savedTab = localStorage.getItem('fit_member_dashboard_tab');

        if (['today', 'activity', 'missions', 'explore'].includes(hash)) {
            initialTab = hash;
        } else if (['today', 'activity', 'missions', 'explore'].includes(savedTab)) {
            initialTab = savedTab;
        }

        switchMemberDashboardTab(initialTab);
        actApplyFilter();

        // Auto-refresh timer every 60s when on today's date
        setInterval(() => {
            const activeTabBtn = document.querySelector('.member-dash-tab.active');
            const dateInput = document.getElementById('act-date-input');
            const todayStr = (new Date()).toISOString().split('T')[0];
            if (activeTabBtn && activeTabBtn.id === 'tab-btn-activity' && dateInput && dateInput.value === todayStr) {
                actFetchData(todayStr, actCurrentRange);
            }
        }, 60000);
    });
    </script>

    <?php
    $wAtt = (int) get_setting('engagement_weight_attendance', '40');
    $wCls = (int) get_setting('engagement_weight_classes', '20');
    $wCon = (int) get_setting('engagement_weight_consistency', '20');
    $wWrk = (int) get_setting('engagement_weight_workouts', '10');
    $wPrg = (int) get_setting('engagement_weight_progress', '10');
    $high = (int) get_setting('engagement_threshold_high', '75');
    $mod = (int) get_setting('engagement_threshold_moderate', '40');
    $mod_end = $high - 1;
    $risk_end = $mod - 1;

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
                <li><strong>{$wAtt}% Attendance:</strong> Check into the gym regularly (up to 7 visits / 30 days).</li>
                <li><strong>{$wCls}% Classes:</strong> Participate in group fitness classes (up to 4 classes / 30 days).</li>
                <li><strong>{$wCon}% Consistency:</strong> Stay active every week. We look at your weekly streaks!</li>
                <li><strong>{$wWrk}% Daily Completed Workout:</strong> Complete your scheduled exercises (up to 8 days / 30 days).</li>
                <li><strong>{$wPrg}% Progress:</strong> Log your workout progress at least once every 60 days.</li>
            </ul>
            
            <p style="color:var(--muted); font-size:14px; margin-bottom:12px; line-height:1.6;">
                <strong>Engagement Categories:</strong>
            </p>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(min(100%, 150px), 1fr)); gap:12px; font-size:13px; margin-bottom: 24px;">
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px; border-left: 3px solid var(--lime);"><strong>{$high} - 100:</strong> Highly Engaged</div>
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px; border-left: 3px solid #f59e0b;"><strong>{$mod} - {$mod_end}:</strong> Moderately Engaged</div>
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px; border-left: 3px solid #fb923c;"><strong>0 - {$risk_end}:</strong> Needs a Boost</div>
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

