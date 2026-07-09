<?php
declare(strict_types=1);

/**
 * Calculates a Member Engagement Score (0-100) based on:
 * - Attendance frequency (last 30 days) - 40%
 * - Class participation (last 30 days) - 30%
 * - Consistency (days active / total days) - 20%
 * - Fitness progress updates (last 60 days) - 10%
 * - Daily Completed Workout (last 30 days) - 10%
 */
function calculate_engagement_score(int $userId): int
{
    $pdo = db();
    
    $wAttendance = (int) get_setting('engagement_weight_attendance', '40');
    $wClasses = (int) get_setting('engagement_weight_classes', '20');
    $wConsistency = (int) get_setting('engagement_weight_consistency', '20');
    $wWorkouts = (int) get_setting('engagement_weight_workouts', '10');
    $wProgress = (int) get_setting('engagement_weight_progress', '10');
    
    // 1. Attendance Frequency (Max wAttendance points for 7+ visits in 30 days)
    $attendanceCount = (int) scalar('SELECT COUNT(*) FROM attendance WHERE user_id = ? AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$userId]);
    $attendanceScore = (int) round(min($wAttendance, ($attendanceCount / 7) * $wAttendance));
    
    // 2. Class Participation (Max wClasses points for 4+ classes in 30 days)
    $classCount = (int) scalar('SELECT COUNT(*) FROM class_bookings WHERE user_id = ? AND booking_status = "attended" AND booked_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$userId]);
    $classScore = (int) round(min($wClasses, ($classCount / 4) * $wClasses));
    
    // 3. Consistency (Max wConsistency points based on active weeks out of last 4 weeks)
    $activeWeeks = (int) scalar('SELECT COUNT(DISTINCT WEEK(check_in_time)) FROM attendance WHERE user_id = ? AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$userId]);
    $consistencyScore = (int) round(min($wConsistency, ($activeWeeks / 4) * $wConsistency));
    
    // 4. Daily Completed Workout (Max wWorkouts points for 8+ distinct days with completed exercises in 30 days)
    $workoutDays = (int) scalar('SELECT COUNT(DISTINCT completed_date) FROM exercise_completions WHERE user_id = ? AND completed_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$userId]);
    $workoutScore = (int) round(min($wWorkouts, ($workoutDays / 8) * $wWorkouts));
    
    // 5. Progress Updates (Max wProgress points for at least 1 update in 60 days)
    $progressCount = (int) scalar('SELECT COUNT(*) FROM progress_logs WHERE user_id = ? AND log_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)', [$userId]);
    $progressScore = $progressCount > 0 ? $wProgress : 0;
    
    $score = $attendanceScore + $classScore + $consistencyScore + $workoutScore + $progressScore;
    
    $pdo->prepare('UPDATE users SET engagement_score = ?, engagement_computed_at = NOW() WHERE user_id = ?')
        ->execute([$score, $userId]);
        
    return $score;
}

/**
 * Returns structured missions data showing each engagement task,
 * current progress, target, earned points, and completion status.
 */
function get_engagement_missions(int $userId): array
{
    $wAttendance = (int) get_setting('engagement_weight_attendance', '40');
    $wClasses = (int) get_setting('engagement_weight_classes', '20');
    $wConsistency = (int) get_setting('engagement_weight_consistency', '20');
    $wWorkouts = (int) get_setting('engagement_weight_workouts', '10');
    $wProgress = (int) get_setting('engagement_weight_progress', '10');

    // Attendance
    $attendanceCount = (int) scalar('SELECT COUNT(*) FROM attendance WHERE user_id = ? AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$userId]);
    $attendanceTarget = 7;
    $attendanceCurrent = min($attendanceCount, $attendanceTarget);
    $attendanceEarned = (int) round(min($wAttendance, ($attendanceCount / $attendanceTarget) * $wAttendance));

    // Classes
    $classCount = (int) scalar('SELECT COUNT(*) FROM class_bookings WHERE user_id = ? AND booking_status = "attended" AND booked_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$userId]);
    $classTarget = 4;
    $classCurrent = min($classCount, $classTarget);
    $classEarned = (int) round(min($wClasses, ($classCount / $classTarget) * $wClasses));

    // Consistency
    $activeWeeks = (int) scalar('SELECT COUNT(DISTINCT WEEK(check_in_time)) FROM attendance WHERE user_id = ? AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$userId]);
    $consistencyTarget = 4;
    $consistencyCurrent = min($activeWeeks, $consistencyTarget);
    $consistencyEarned = (int) round(min($wConsistency, ($activeWeeks / $consistencyTarget) * $wConsistency));

    // Workouts
    $workoutDays = (int) scalar('SELECT COUNT(DISTINCT completed_date) FROM exercise_completions WHERE user_id = ? AND completed_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$userId]);
    $workoutTarget = 8;
    $workoutCurrent = min($workoutDays, $workoutTarget);
    $workoutEarned = (int) round(min($wWorkouts, ($workoutDays / $workoutTarget) * $wWorkouts));

    // Progress
    $progressCount = (int) scalar('SELECT COUNT(*) FROM progress_logs WHERE user_id = ? AND log_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)', [$userId]);
    $progressTarget = 1;
    $progressCurrent = min($progressCount, $progressTarget);
    $progressEarned = $progressCount > 0 ? $wProgress : 0;

    return [
        [
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v16"/><path d="M10 4v16"/><path d="M6 12h4"/><path d="M14 4v16"/><path d="M18 4v16"/><path d="M14 12h4"/></svg>',
            'title' => 'Check in to the gym',
            'description' => 'Visit the gym 7 times this month',
            'current' => $attendanceCurrent,
            'target' => $attendanceTarget,
            'maxPoints' => $wAttendance,
            'earnedPoints' => $attendanceEarned,
            'completed' => $attendanceCurrent >= $attendanceTarget,
        ],
        [
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'title' => 'Attend group classes',
            'description' => 'Join 4 classes this month',
            'current' => $classCurrent,
            'target' => $classTarget,
            'maxPoints' => $wClasses,
            'earnedPoints' => $classEarned,
            'completed' => $classCurrent >= $classTarget,
        ],
        [
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
            'title' => 'Keep your weekly streak',
            'description' => 'Be active in 4 different weeks',
            'current' => $consistencyCurrent,
            'target' => $consistencyTarget,
            'maxPoints' => $wConsistency,
            'earnedPoints' => $consistencyEarned,
            'completed' => $consistencyCurrent >= $consistencyTarget,
        ],
        [
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
            'title' => 'Complete daily workouts',
            'description' => 'Finish workouts on 8 different days',
            'current' => $workoutCurrent,
            'target' => $workoutTarget,
            'maxPoints' => $wWorkouts,
            'earnedPoints' => $workoutEarned,
            'completed' => $workoutCurrent >= $workoutTarget,
        ],
        [
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
            'title' => 'Log your progress',
            'description' => 'Record your progress at least once (60 days)',
            'current' => $progressCurrent,
            'target' => $progressTarget,
            'maxPoints' => $wProgress,
            'earnedPoints' => $progressEarned,
            'completed' => $progressCurrent >= $progressTarget,
        ],
    ];
}

function get_cached_engagement_score(int $userId, int $maxAgeMinutes = 1440): int
{
    $row = db()->query(
        'SELECT engagement_score, engagement_computed_at FROM users WHERE user_id = ' . (int)$userId
    )->fetch();
    
    if ($row && $row['engagement_score'] !== null && $row['engagement_computed_at']) {
        $computedTime = strtotime($row['engagement_computed_at']);
        if (time() - $computedTime < $maxAgeMinutes * 60) {
            return (int) $row['engagement_score'];
        }
    }
    
    return calculate_engagement_score($userId);
}

function recompute_all_engagement_scores(): void
{
    $users = query_all('SELECT user_id FROM users WHERE role = "member" AND status = "active"');
    foreach ($users as $u) {
        calculate_engagement_score((int) $u['user_id']);
    }
}

function get_engagement_category(int $score): string
{
    $high = (int) get_setting('engagement_threshold_high', '75');
    $moderate = (int) get_setting('engagement_threshold_moderate', '40');
    if ($score >= $high) return 'Highly Engaged';
    if ($score >= $moderate) return 'Moderately Engaged';
    return 'At-Risk';
}

function get_inactive_members(int $limit = 5): array
{
    $pdo = db();
    $users = query_all(
        'SELECT u.user_id, u.first_name, u.last_name, u.email, u.profile_picture, u.created_at,
                MAX(a.check_in_time) as last_checkin, 
                COALESCE(DATEDIFF(CURDATE(), MAX(a.check_in_time)), DATEDIFF(CURDATE(), u.created_at)) as days_inactive
         FROM users u
         LEFT JOIN attendance a ON u.user_id = a.user_id
         WHERE u.role = "member" AND u.status = "active"
         GROUP BY u.user_id'
    );

    $atRisk = [];
    foreach ($users as $u) {
        if ((int)$u['days_inactive'] < 1) {
            continue; // Skip members who checked in today (0 days inactive)
        }
        $score = get_cached_engagement_score((int) $u['user_id']);
        if (get_engagement_category($score) === 'At-Risk') {
            $atRisk[] = $u;
        }
    }

    usort($atRisk, fn($a, $b) => ($b['days_inactive'] ?? 9999) <=> ($a['days_inactive'] ?? 9999));
    return array_slice($atRisk, 0, $limit);
}

function process_automated_at_risk_notifications(): void
{
    $pdo = db();
    $atRiskMembers = get_inactive_members(99999);
    
    $inactivityThreshold = (int) get_setting('at_risk_inactivity_days', '3');
    $cooldownDays = (int) get_setting('at_risk_notification_cooldown', '14');
    
    foreach ($atRiskMembers as $member) {
        if (($member['days_inactive'] ?? 0) >= $inactivityThreshold) {
            $userId = (int) $member['user_id'];
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'system' AND title = 'We miss you at the gym! 💪' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmt->execute([$userId, $cooldownDays]);
            $recentNotifs = (int) $stmt->fetchColumn();
            
            if ($recentNotifs === 0) {
                notify_user($userId, 'system', 'We miss you at the gym! 💪', 'It\'s been a few days since your last activity. Check out this week\'s classes or your new workout plan to get back on track!');
            }
        }
    }
}
