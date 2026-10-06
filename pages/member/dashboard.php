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

    <link rel="stylesheet" href="<?= h(asset_url('css/pages/member_dashboard.css')) ?>">

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
    window.MEMBER_DASHBOARD_CONFIG = {
        initialData: <?= json_encode($actInitialData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
    };
    </script>
    <script src="<?= h(asset_url('js/pages/member_dashboard.js')) ?>"></script>

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

