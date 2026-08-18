<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

try {
    verify_csrf();

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
        'landing' => ['file' => 'pages/landing.php', 'handler' => 'landing_page'],
        'login' => ['file' => 'pages/auth/login.php', 'handler' => 'handle_login'],
        'logout' => ['file' => 'pages/auth/login.php', 'handler' => 'handle_logout'],
        'register' => ['file' => 'pages/auth/register.php', 'handler' => 'handle_register'],
        'forgot_password' => ['file' => 'pages/auth/forgot_password.php', 'handler' => 'handle_forgot_password'],
        'reset_password' => ['file' => 'pages/auth/reset_password.php', 'handler' => 'handle_reset_password'],
        'verify_email' => ['file' => 'pages/auth/verify_email.php', 'handler' => 'verify_email_page'],
        'setup_profile' => ['file' => 'pages/auth/setup_profile.php', 'handler' => 'setup_profile_page'],
        'setup_goal' => ['file' => 'pages/auth/setup_goal.php', 'handler' => 'setup_goal_page'],
        'pending_gym' => ['file' => 'pages/auth/pending_gym.php', 'handler' => 'pending_gym_page'],

        'notification_action' => ['file' => 'pages/shared/notifications.php', 'handler' => 'handle_notification_actions'],
        'notification_click'  => ['file' => 'pages/shared/notifications.php', 'handler' => 'handle_notification_click'],
        'notifications' => ['file' => 'pages/shared/notifications.php', 'handler' => 'notifications_page'],
        
        'dashboard' => ['file' => 'pages/admin/dashboard.php', 'handler' => 'dashboard'],
        'users' => ['file' => 'pages/admin/users.php', 'handler' => 'users_page'],
        'trainer_assignments' => ['file' => 'pages/admin/trainer_assignments.php', 'handler' => 'trainer_assignments_page'],
        'plans' => ['file' => 'pages/admin/plans.php', 'handler' => 'plans_page'],
        'memberships' => ['file' => 'pages/admin/memberships.php', 'handler' => 'memberships_page'],
        'payments' => ['file' => 'pages/admin/payments.php', 'handler' => 'payments_page'],
        'commissions' => ['file' => 'pages/admin/commissions.php', 'handler' => 'commissions_page'],
        'classes' => ['file' => 'pages/admin/classes.php', 'handler' => 'classes_page'],
        'attendance' => ['file' => 'pages/admin/attendance.php', 'handler' => 'attendance_page'],
        'profile' => ['file' => 'pages/member/profile.php', 'handler' => 'profile_page'],
        'my_workout' => ['file' => 'pages/member/my_workout.php', 'handler' => 'my_workout_page'],
        'diet' => ['file' => 'pages/member/diet.php', 'handler' => 'diet_page'],
        'my_commissions' => ['file' => 'pages/member/my_commissions.php', 'handler' => 'my_commissions_page'],
        'trainers' => ['file' => 'pages/member/trainers.php', 'handler' => 'trainers_page'],
        'book_classes' => ['file' => 'pages/member/book_classes.php', 'handler' => 'book_classes_page'],
        'progress' => ['file' => 'pages/member/progress.php', 'handler' => 'progress_page'],
        'trainer_members' => ['file' => 'pages/trainer/trainer.php', 'handler' => 'trainer_members_page'],
        'trainer_assessment' => ['file' => 'pages/trainer/trainer_assessment.php', 'handler' => 'trainer_assessment_page'],
        'diet_builder' => ['file' => 'pages/trainer/diet_builder.php', 'handler' => 'diet_builder_page'],
        'workout_builder' => ['file' => 'pages/trainer/workout_builder.php', 'handler' => 'workout_builder_page'],
        'training' => ['file' => 'pages/trainer/training.php', 'handler' => 'training_page'],
        'messages' => ['file' => 'pages/shared/messages.php', 'handler' => 'messages_page'],
        'reports' => ['file' => 'pages/admin/reports.php', 'handler' => 'reports_page'],
        'walk_ins' => ['file' => 'pages/admin/walk_ins.php', 'handler' => 'walk_ins_page'],
        'qr_attendance' => ['file' => 'pages/member/qr_attendance.php', 'handler' => 'qr_attendance_page'],
        'scanner' => ['file' => 'pages/admin/scanner.php', 'handler' => 'scanner_page'],
        'complete_exercise' => ['file' => 'pages/member/complete_exercise.php', 'handler' => 'complete_exercise_action'],
        'exercises' => ['file' => 'pages/admin/exercises.php', 'handler' => 'exercises_page'],
        'audit_logs' => ['file' => 'pages/admin/audit_logs.php', 'handler' => 'audit_logs_page'],
        'admin_workouts' => ['file' => 'pages/admin/workout_plans.php', 'handler' => 'admin_workouts_page'],
        'gym_applications' => ['file' => 'pages/admin/gym_applications.php', 'handler' => 'gym_applications_page'],
        'gyms' => ['file' => 'pages/admin/gyms.php', 'handler' => 'gyms_page'],
        'gym_profile' => ['file' => 'pages/admin/gym_profile.php', 'handler' => 'gym_profile_page'],
        'view_gym' => ['file' => 'pages/member/view_gym.php', 'handler' => 'view_gym_page'],
        'gym_selection' => ['file' => 'pages/member/gym_selection.php', 'handler' => 'gym_selection_page'],
        'settings' => ['file' => 'pages/admin/settings.php', 'handler' => 'settings_page'],
        'announcements' => ['file' => 'pages/admin/announcements.php', 'handler' => 'announcements_page'],
        'member_transfers' => ['file' => 'pages/admin/member_transfers.php', 'handler' => 'member_transfers_page'],
        'gym_owner_transfers' => ['file' => 'pages/gym_owner/transfers.php', 'handler' => 'gym_owner_transfers_page'],
    ];

    $route = $routes[$page] ?? $routes['dashboard'];
    require_once __DIR__ . '/' . $route['file'];
    $route['handler']();

    ob_end_flush();
} catch (Throwable $e) {
    ob_end_clean(); // discard any partial page that was buffered
    setup_error($e);
}
