<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/pages/member/complete_exercise.php';
require __DIR__ . '/pages/admin/exercises.php';

try {
    seed_reference_data_if_empty();
    verify_csrf();

    $page = $_GET['page'] ?? (current_user() ? 'dashboard' : 'login');

    $routes = [
        'login' => 'handle_login',
        'logout' => 'handle_logout',
        'register' => 'handle_register',
        'forgot_password' => 'handle_forgot_password',
        'reset_password' => 'handle_reset_password',
        'verify_email' => 'verify_email_page',
        'setup_profile' => 'setup_profile_page',

        'notification_action' => 'handle_notification_actions',
        'notifications' => 'notifications_page',
        'dashboard' => 'dashboard',
        'users' => 'users_page',
        'trainer_assignments' => 'trainer_assignments_page',
        'plans' => 'plans_page',
        'memberships' => 'memberships_page',
        'payments' => 'payments_page',
        'classes' => 'classes_page',
        'attendance' => 'attendance_page',
        'profile' => 'profile_page',
        'my_workout' => 'my_workout_page',
        'book_classes' => 'book_classes_page',
        'progress' => 'progress_page',
        'trainer_members' => 'trainer_members_page',
        'training' => 'training_page',
        'messages' => 'messages_page',
        'reports' => 'reports_page',
        'walk_ins' => 'walk_ins_page',
        'qr_attendance' => 'qr_attendance_page',
        'scanner' => 'scanner_page',
        'complete_exercise' => 'complete_exercise_action',
        'exercises' => 'exercises_page',
    ];

    ($routes[$page] ?? $routes['dashboard'])();
    ob_end_flush();
} catch (Throwable $e) {
    ob_end_clean(); // discard any partial page that was buffered
    setup_error($e);
}
