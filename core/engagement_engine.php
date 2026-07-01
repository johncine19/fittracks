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
    
    // 1. Attendance Frequency (Max 40 points for 12+ visits in 30 days)
    $attendanceCount = (int) scalar('SELECT COUNT(*) FROM attendance WHERE user_id = ? AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$userId]);
    $attendanceScore = min(40, ($attendanceCount / 12) * 40);
    
    // 2. Class Participation (Max 30 points for 4+ classes in 30 days)
    $classCount = (int) scalar('SELECT COUNT(*) FROM class_bookings WHERE user_id = ? AND booking_status = "attended" AND booked_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$userId]);
    $classScore = min(30, ($classCount / 4) * 30);
    
    // 3. Consistency (Max 20 points based on active weeks out of last 4 weeks)
    $activeWeeks = (int) scalar('SELECT COUNT(DISTINCT WEEK(check_in_time)) FROM attendance WHERE user_id = ? AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$userId]);
    $consistencyScore = min(20, ($activeWeeks / 4) * 20);
    
    // 4. Progress Updates (Max 10 points for at least 1 update in 60 days)
    $progressCount = (int) scalar('SELECT COUNT(*) FROM progress_logs WHERE user_id = ? AND log_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)', [$userId]);
    $progressScore = min(10, $progressCount > 0 ? 10 : 0);
    
    return (int) round($attendanceScore + $classScore + $consistencyScore + $progressScore);
}

function get_engagement_category(int $score): string
{
    if ($score >= 75) return 'Highly Engaged';
    if ($score >= 40) return 'Moderately Engaged';
    return 'At-Risk';
}

function get_inactive_members(int $daysInactive = 14): array
{
    $pdo = db();
    return query_all(
        'SELECT u.user_id, u.first_name, u.last_name, u.email, 
                MAX(a.check_in_time) as last_checkin, 
                DATEDIFF(CURDATE(), MAX(a.check_in_time)) as days_inactive
         FROM users u
         LEFT JOIN attendance a ON u.user_id = a.user_id
         WHERE u.role = "member" AND u.status = "active"
         GROUP BY u.user_id
         HAVING (last_checkin IS NULL OR days_inactive >= ?)
         ORDER BY days_inactive DESC
         LIMIT 10',
        [$daysInactive]
    );
}
