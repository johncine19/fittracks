<?php
declare(strict_types=1);

session_start();
ob_start(); // Buffer all output so setup_error() can set HTTP headers even mid-render

require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

header('Content-Type: text/html; charset=UTF-8');

require __DIR__ . '/../config/config.php';
require __DIR__ . '/database.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/csrf.php';
require __DIR__ . '/validators.php';
require __DIR__ . '/rate_limiter.php';
require __DIR__ . '/file_handler.php';
require __DIR__ . '/email_verification.php';
require __DIR__ . '/engagement_engine.php';
require __DIR__ . '/notifications.php';
require __DIR__ . '/../views/layout.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/seeds.php';
require __DIR__ . '/../views/components.php';

// --- Auth ---
require __DIR__ . '/../pages/auth/login.php';
require __DIR__ . '/../pages/auth/register.php';
require __DIR__ . '/../pages/auth/forgot_password.php';
require __DIR__ . '/../pages/auth/reset_password.php';
require __DIR__ . '/../pages/auth/verify_email.php';
require __DIR__ . '/../pages/auth/setup_profile.php';


// --- Shared (used by multiple roles) ---
require __DIR__ . '/../pages/shared/exercise.php';
require __DIR__ . '/../pages/shared/workouts.php';
require __DIR__ . '/../pages/shared/messages.php';
require __DIR__ . '/../pages/shared/notifications.php';

// --- Dashboard ---
require __DIR__ . '/../pages/admin/dashboard.php';

// --- Admin: Users & Plans ---
require __DIR__ . '/../pages/admin/users.php';
require __DIR__ . '/../pages/admin/plans.php';

// --- Admin: Members & Finance ---
require __DIR__ . '/../pages/admin/memberships.php';
require __DIR__ . '/../pages/admin/payments.php';
require __DIR__ . '/../pages/admin/commissions.php';

// --- Admin: Trainer Assignments ---
require __DIR__ . '/../pages/admin/trainer_assignments.php';

// --- Admin: Classes, Attendance & Operations ---
require __DIR__ . '/../pages/admin/classes.php';
require __DIR__ . '/../pages/admin/attendance.php';
require __DIR__ . '/../pages/admin/reports.php';
require __DIR__ . '/../pages/admin/walk_ins.php';
require __DIR__ . '/../pages/admin/scanner.php';
require __DIR__ . '/../pages/admin/audit_logs.php';

// --- Trainer portal ---
require __DIR__ . '/../pages/trainer/trainer.php';
require __DIR__ . '/../pages/trainer/training.php';
require __DIR__ . '/../pages/trainer/workout_builder.php';
require __DIR__ . '/../pages/trainer/trainer_assessment.php';
require __DIR__ . '/../pages/trainer/diet_builder.php';

// --- Member portal ---
require __DIR__ . '/../pages/member/profile.php';
require __DIR__ . '/../pages/member/my_workout.php';
require __DIR__ . '/../pages/member/progress.php';
require __DIR__ . '/../pages/member/book_classes.php';
require __DIR__ . '/../pages/member/qr_attendance.php';
require __DIR__ . '/../pages/member/my_commissions.php';
require __DIR__ . '/../pages/member/diet.php';
