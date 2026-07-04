<?php
declare(strict_types=1);

/**
 * Calculates a Member Engagement Score (0-100) based on:
 * - Attendance frequency (last 30 days) - 40%
 * - Class participation (last 30 days) - 30%
 * - Consistency (days active / total days) - 20%
 * - Fitness progress updates (last 60 days) - 10%
 */
function calculate_engagement_score(int $userId): int
{
    $pdo = db();
    
    $wAttendance = (int) get_setting('engagement_weight_attendance', '40');
    $wClasses = (int) get_setting('engagement_weight_classes', '30');
    $wConsistency = (int) get_setting('engagement_weight_consistency', '20');
    $wProgress = (int) get_setting('engagement_weight_progress', '10');
    
    // 1. Attendance Frequency (Max wAttendance points for 7+ visits in 30 days)
    $attendanceCount = (int) scalar('SELECT COUNT(*) FROM attendance WHERE user_id = ? AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$userId]);
    $attendanceScore = min($wAttendance, ($attendanceCount / 7) * $wAttendance);
    
    // 2. Class Participation (Max wClasses points for 4+ classes in 30 days)
    $classCount = (int) scalar('SELECT COUNT(*) FROM class_bookings WHERE user_id = ? AND booking_status = "attended" AND booked_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$userId]);
    $classScore = min($wClasses, ($classCount / 4) * $wClasses);
    
    // 3. Consistency (Max wConsistency points based on active weeks out of last 4 weeks)
    $activeWeeks = (int) scalar('SELECT COUNT(DISTINCT WEEK(check_in_time)) FROM attendance WHERE user_id = ? AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$userId]);
    $consistencyScore = min($wConsistency, ($activeWeeks / 4) * $wConsistency);
    
    // 4. Progress Updates (Max wProgress points for at least 1 update in 60 days)
    $progressCount = (int) scalar('SELECT COUNT(*) FROM progress_logs WHERE user_id = ? AND log_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)', [$userId]);
    $progressScore = min($wProgress, $progressCount > 0 ? $wProgress : 0);
    
    $score = (int) round($attendanceScore + $classScore + $consistencyScore + $progressScore);
    
    $pdo->prepare('UPDATE users SET engagement_score = ?, engagement_computed_at = NOW() WHERE user_id = ?')
        ->execute([$score, $userId]);
        
    return $score;
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
        'SELECT u.user_id, u.first_name, u.last_name, u.email, u.profile_picture,
                MAX(a.check_in_time) as last_checkin, 
                DATEDIFF(CURDATE(), MAX(a.check_in_time)) as days_inactive
         FROM users u
         LEFT JOIN attendance a ON u.user_id = a.user_id
         WHERE u.role = "member" AND u.status = "active"
         GROUP BY u.user_id'
    );

    $atRisk = [];
    foreach ($users as $u) {
        $score = get_cached_engagement_score((int) $u['user_id']);
        if (get_engagement_category($score) === 'At-Risk') {
            $atRisk[] = $u;
        }
    }

    usort($atRisk, fn($a, $b) => ($b['days_inactive'] ?? 9999) <=> ($a['days_inactive'] ?? 9999));
    return array_slice($atRisk, 0, $limit);
}
