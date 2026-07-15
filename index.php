<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/pages/member/complete_exercise.php';
require __DIR__ . '/pages/member/trainers.php';
require __DIR__ . '/pages/admin/exercises.php';
require __DIR__ . '/pages/admin/workout_plans.php';
require __DIR__ . '/pages/admin/gym_applications.php';
require __DIR__ . '/pages/admin/gyms.php';
require __DIR__ . '/pages/admin/gym_profile.php';
require __DIR__ . '/pages/admin/settings.php';
require __DIR__ . '/pages/admin/announcements.php';
require __DIR__ . '/pages/admin/member_transfers.php';
require __DIR__ . '/pages/admin/gym_payouts.php';
require __DIR__ . '/pages/landing.php';

try {
    seed_reference_data_if_empty();
    verify_csrf();

    // --- Daily Background Tasks ---
    $lastScan = get_setting('last_at_risk_scan_date');
    if ($lastScan !== date('Y-m-d')) {
        db()->prepare('REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)')->execute(['last_at_risk_scan_date', date('Y-m-d')]);
        process_automated_at_risk_notifications();
    }

    // --- Global Actions ---
    if (isset($_POST['self_checkout'])) {
        $user = current_user();
        if ($user) {
            $attendanceId = (int) $_POST['attendance_id'];
            db()->prepare('UPDATE attendance SET check_out_time = NOW() WHERE attendance_id = ? AND user_id = ?')->execute([$attendanceId, $user['user_id']]);
            
            $rating = isset($_POST['rating']) ? (int) $_POST['rating'] : 0;
            if ($rating >= 1 && $rating <= 5) {
                try {
                    db()->exec("CREATE TABLE IF NOT EXISTS checkout_ratings (rating_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, attendance_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, rating TINYINT UNSIGNED NOT NULL, comment TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_attendance (attendance_id))");
                    db()->prepare('INSERT IGNORE INTO checkout_ratings (attendance_id, user_id, rating, comment) VALUES (?, ?, ?, ?)')->execute([$attendanceId, $user['user_id'], $rating, mb_substr(trim((string) ($_POST['comment'] ?? '')), 0, 1000) ?: null]);
                } catch (Throwable) {}
            }
            flash('You have successfully checked out.', 'success');
        }
        $redirectUrl = $_SERVER['HTTP_REFERER'] ?? 'index.php?page=dashboard';
        header('Location: ' . $redirectUrl);
        exit;
    }

    $page = $_GET['page'] ?? (current_user() ? 'dashboard' : 'landing');

    $routes = [
        'landing' => 'landing_page',
        'login' => 'handle_login',
        'logout' => 'handle_logout',
        'register' => 'handle_register',
        'forgot_password' => 'handle_forgot_password',
        'reset_password' => 'handle_reset_password',
        'verify_email' => 'verify_email_page',
        'setup_profile' => 'setup_profile_page',
        'setup_goal' => 'setup_goal_page',
        'pending_gym' => 'pending_gym_page',

        'notification_action' => 'handle_notification_actions',
        'notification_click'  => 'handle_notification_click',
        'notifications' => 'notifications_page',
        'dashboard' => 'dashboard',
        'users' => 'users_page',
        'trainer_assignments' => 'trainer_assignments_page',
        'plans' => 'plans_page',
        'memberships' => 'memberships_page',
        'payments' => 'payments_page',
        'commissions' => 'commissions_page',
        'classes' => 'classes_page',
        'attendance' => 'attendance_page',
        'profile' => 'profile_page',
        'my_workout' => 'my_workout_page',
        'diet' => 'diet_page',
        'my_commissions' => 'my_commissions_page',
        'trainers' => 'trainers_page',
        'book_classes' => 'book_classes_page',
        'progress' => 'progress_page',
        'trainer_members' => 'trainer_members_page',
        'trainer_assessment' => 'trainer_assessment_page',
        'diet_builder' => 'diet_builder_page',
        'workout_builder' => 'workout_builder_page',
        'training' => 'training_page',
        'messages' => 'messages_page',
        'reports' => 'reports_page',
        'walk_ins' => 'walk_ins_page',
        'qr_attendance' => 'qr_attendance_page',
        'scanner' => 'scanner_page',
        'complete_exercise' => 'complete_exercise_action',
        'exercises' => 'exercises_page',
        'audit_logs' => 'audit_logs_page',
        'admin_workouts' => 'admin_workouts_page',
        'gym_applications' => 'gym_applications_page',
        'gyms' => 'gyms_page',
        'gym_profile' => 'gym_profile_page',
        'view_gym' => 'view_gym_page',
        'gym_selection' => 'gym_selection_page',
        'settings' => 'settings_page',
        'announcements' => 'announcements_page',
        'member_transfers' => 'member_transfers_page',
        'gym_payouts' => 'gym_payouts_page',
    ];

    ($routes[$page] ?? $routes['dashboard'])();
    ob_end_flush();
} catch (Throwable $e) {
    ob_end_clean(); // discard any partial page that was buffered
    setup_error($e);
}
