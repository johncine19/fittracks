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
            if (function_exists('release_user_equipment_on_checkout')) {
                release_user_equipment_on_checkout((int)$user['user_id']);
            }
            
            $rating = isset($_POST['rating']) ? (int) $_POST['rating'] : 0;
            $comment = isset($_POST['comment']) ? mb_substr(trim((string)$_POST['comment']), 0, 1000) : null;

            if ($rating >= 1 && $rating <= 5) {
                try {
                    db()->exec("CREATE TABLE IF NOT EXISTS checkout_ratings (rating_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, attendance_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, rating TINYINT UNSIGNED NOT NULL, comment TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_attendance (attendance_id))");
                    db()->prepare('INSERT INTO checkout_ratings (attendance_id, user_id, rating, comment) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment)')->execute([$attendanceId, $user['user_id'], $rating, $comment ?: null]);
                } catch (Throwable) {}

                // Save to gym_ratings so checkout ratings contribute to the gym's overall rating
                $gymId = (int) scalar('SELECT gym_id FROM attendance WHERE attendance_id = ?', [$attendanceId]);
                if (!$gymId) {
                    $gymId = (int) ($user['gym_id'] ?? 0);
                }
                if (!$gymId) {
                    $gymId = (int) scalar('SELECT gym_id FROM gym_members WHERE user_id = ? LIMIT 1', [$user['user_id']]);
                }
                if (!$gymId) {
                    $gymId = (int) scalar('SELECT mp.gym_id FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = ? AND m.status = "active" LIMIT 1', [$user['user_id']]);
                }
                if ($gymId > 0) {
                    save_gym_rating((int)$user['user_id'], $gymId, $rating, $comment ?: null);
                }
            }
            flash('You have successfully checked out' . ($rating >= 1 ? ' and your gym review has been published!' : '.'), 'success');
        }
        // Safe redirect: never trust HTTP_REFERER as a redirect target
        header('Location: index.php?page=dashboard');
        exit;
    }

    // --- Switch Active Gym for Members with Multi-Gym Affiliations ---
    if (isset($_GET['switch_active_gym'])) {
        $user = current_user();
        if ($user && ($user['role'] ?? '') === 'member') {
            $targetGymId = (int) $_GET['switch_active_gym'];
            if ($targetGymId > 0) {
                // Verify member has affiliation or active plan at this gym
                $isAffiliated = (bool) scalar('SELECT 1 FROM gym_members WHERE user_id = ? AND gym_id = ? LIMIT 1', [$user['user_id'], $targetGymId]);
                if (!$isAffiliated) {
                    $isAffiliated = (bool) scalar('SELECT 1 FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = ? AND mp.gym_id = ? AND m.status = "active" LIMIT 1', [$user['user_id'], $targetGymId]);
                    if ($isAffiliated) {
                        db()->prepare('INSERT IGNORE INTO gym_members (user_id, gym_id) VALUES (?, ?)')->execute([$user['user_id'], $targetGymId]);
                    }
                }
                if ($isAffiliated) {
                    $_SESSION['current_gym_id'] = $targetGymId;
                    $gName = scalar('SELECT name FROM gyms WHERE gym_id = ?', [$targetGymId]);
                    flash('Switched active facility to ' . ($gName ?: 'gym') . '.', 'success');
                }
            }
            $targetPage = in_array($_GET['page'] ?? '', ['dashboard', 'equipment', 'book_classes', 'trainers', 'gym_equipment'], true) ? $_GET['page'] : 'dashboard';
            redirect($targetPage);
        }
    }

    // --- Member Rating for Gym Action ---
    if (isset($_POST['submit_gym_rating'])) {
        $user = current_user();
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
        
        if (!$user || $user['role'] !== 'member') {
            if ($isAjax) {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Only active gym members can submit gym ratings.']);
                exit;
            }
            flash('Only active gym members can submit gym ratings.', 'danger');
            redirect('dashboard');
        }

        $gymId = (int) ($_POST['gym_id'] ?? 0);
        $rating = (int) ($_POST['rating'] ?? 0);
        $review = isset($_POST['review']) ? trim((string)$_POST['review']) : null;

        if ($gymId <= 0 || $rating < 1 || $rating > 5) {
            if ($isAjax) {
                header('Content-Type: application/json');
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Please provide a valid rating between 1 and 5 stars.']);
                exit;
            }
            flash('Please select a star rating between 1 and 5.', 'warning');
            redirect("index.php?page=view_gym&gym_id={$gymId}");
        }

        if (!can_user_review_gym((int) $user['user_id'], $gymId)) {
            if ($isAjax) {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'You must be enrolled in or have visited this gym to submit a rating.']);
                exit;
            }
            flash('You must be enrolled in or have visited this gym to submit a review.', 'warning');
            redirect("index.php?page=view_gym&gym_id={$gymId}");
        }

        $res = save_gym_rating((int) $user['user_id'], $gymId, $rating, $review);
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($res);
            exit;
        }
        flash($res['message'], $res['success'] ? 'success' : 'danger');
        redirect("index.php?page=view_gym&gym_id={$gymId}");
    }

    // --- Gym Owner Rating for FitTrack Platform Action ---
    if (isset($_POST['submit_platform_review'])) {
        $user = current_user();
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
        
        if (!$user || !in_array($user['role'] ?? '', ['gym_owner', 'admin'], true)) {
            if ($isAjax) {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Only verified gym owners can review the FitTrack platform.']);
                exit;
            }
            flash('Only verified gym owners can review the FitTrack platform.', 'danger');
            redirect('index.php?page=profile&tab=ratings_feedback');
        }

        $rating = (int) ($_POST['rating'] ?? 0);
        $review = isset($_POST['review']) ? trim((string)$_POST['review']) : null;
        $systemExp = isset($_POST['system_experience']) && (int)$_POST['system_experience'] >= 1 ? (int)$_POST['system_experience'] : null;
        $features = isset($_POST['features_rating']) && (int)$_POST['features_rating'] >= 1 ? (int)$_POST['features_rating'] : null;
        $service = isset($_POST['service_rating']) && (int)$_POST['service_rating'] >= 1 ? (int)$_POST['service_rating'] : null;

        $targetRedirect = !empty($_POST['redirect_to']) ? (string)$_POST['redirect_to'] : 'index.php?page=profile&tab=ratings_feedback#platform-feedback-card';

        if ($rating < 1 || $rating > 5) {
            if ($isAjax) {
                header('Content-Type: application/json');
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Please provide a valid platform rating between 1 and 5 stars.']);
                exit;
            }
            flash('Please select an overall platform rating between 1 and 5 stars.', 'warning');
            redirect($targetRedirect);
        }

        $res = save_platform_review((int) $user['user_id'], null, $rating, $review, $systemExp, $features, $service);
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($res);
            exit;
        }
        flash($res['message'], $res['success'] ? 'success' : 'danger');
        redirect($targetRedirect);
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
        'setup_review' => ['file' => 'pages/auth/setup_review.php', 'handler' => 'setup_review_page'],
        'pending_gym' => ['file' => 'pages/auth/pending_gym.php', 'handler' => 'pending_gym_page'],
        
        'gym_onboarding' => ['file' => 'pages/gym_owner/gym_onboarding.php', 'handler' => 'gym_onboarding_page'],
        'gym_pending' => ['file' => 'pages/gym_owner/gym_pending.php', 'handler' => 'gym_pending_page'],
        'gym_rejected' => ['file' => 'pages/gym_owner/gym_rejected.php', 'handler' => 'gym_rejected_page'],
        'gym_subscription' => ['file' => 'pages/gym_owner/gym_subscription.php', 'handler' => 'gym_subscription_page'],

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
        'log_macros' => ['file' => 'pages/member/log_macros.php', 'handler' => 'log_macros_page'],
        'food_lookup' => ['file' => 'pages/member/food_lookup.php', 'handler' => 'food_lookup_page'],
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
        'food_library' => ['file' => 'pages/admin/food_library.php', 'handler' => 'food_library_page'],
        'gym_equipment' => ['file' => 'pages/admin/equipment.php', 'handler' => 'gym_equipment_page'],
        'equipment' => ['file' => 'pages/member/equipment.php', 'handler' => 'member_equipment_page'],
        'equipment_api' => ['file' => 'pages/shared/equipment_api.php', 'handler' => 'equipment_api_handler'],
        'audit_logs' => ['file' => 'pages/admin/audit_logs.php', 'handler' => 'audit_logs_page'],
        'admin_workouts' => ['file' => 'pages/admin/workout_plans.php', 'handler' => 'admin_workouts_page'],
        'gym_applications' => ['file' => 'pages/admin/gym_applications.php', 'handler' => 'gym_applications_page'],
        'gyms' => ['file' => 'pages/admin/gyms.php', 'handler' => 'gyms_page'],
        'platform_plans' => ['file' => 'pages/platform_admin/subscription_plans.php', 'handler' => 'platform_subscription_plans_page'],
        'gym_profile' => ['file' => 'pages/admin/gym_profile.php', 'handler' => 'gym_profile_page'],
        'view_gym' => ['file' => 'pages/member/view_gym.php', 'handler' => 'view_gym_page'],
        'gym_selection' => ['file' => 'pages/member/gym_selection.php', 'handler' => 'gym_selection_page'],
        'settings' => ['file' => 'pages/admin/settings.php', 'handler' => 'settings_page'],
        'announcements' => ['file' => 'pages/admin/announcements.php', 'handler' => 'announcements_page'],
        'terms' => ['file' => 'pages/shared/terms.php', 'handler' => 'terms_page'],
        'privacy' => ['file' => 'pages/shared/privacy.php', 'handler' => 'privacy_page'],
        'view_permit' => ['file' => 'pages/admin/view_permit.php', 'handler' => 'view_permit_handler'],
    ];

    $route = $routes[$page] ?? $routes['dashboard'];
    require_once __DIR__ . '/' . $route['file'];
    $route['handler']();

    if (ob_get_level() > 0) {
        ob_end_flush();
    }
} catch (Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean(); // discard any partial page that was buffered
    }
    if (function_exists('setup_error')) {
        setup_error($e);
    } else {
        http_response_code(500);
        error_log('Fatal App Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        echo '<!doctype html><html><body style="font-family:sans-serif;padding:40px;background:#090b10;color:#fff;">';
        echo '<h1>Service Temporarily Unavailable</h1>';
        echo '<p style="color:#ef4444;"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        echo '</body></html>';
    }
}

// Poor man's cron: process a few queue jobs at the end of every request.
// Since Render web services don't have background cron, this ensures emails are sent.
register_shutdown_function(function() {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    try {
        Queue::work(3); // Process up to 3 jobs per request to avoid blocking for too long
    } catch (Throwable $e) {
        error_log("Background Queue Error: " . $e->getMessage());
    }
});
