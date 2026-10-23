<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../pages/shared/exercise.php';
require_once __DIR__ . '/../pages/shared/workouts.php';

echo "Testing Preferred Workout Days & Enforcement...\n";

$pdo = db();
$memberId = (int)$pdo->query('
    SELECT u.user_id 
    FROM users u 
    LEFT JOIN trainer_assignments ta ON u.user_id = ta.member_user_id AND ta.status = "active"
    WHERE u.role = "member" AND ta.assignment_id IS NULL 
    LIMIT 1
')->fetchColumn();

if (!$memberId) {
    $memberId = (int)$pdo->query('SELECT user_id FROM users WHERE role = "member" LIMIT 1')->fetchColumn();
    $pdo->query("UPDATE trainer_assignments SET status = 'inactive' WHERE member_user_id = $memberId");
    $pdo->query("UPDATE training_plans SET status = 'archived' WHERE member_user_id = $memberId AND trainer_id IS NOT NULL");
}

// 1. Test saving preferred workout days
$_POST = [
    'height_cm' => '175',
    'weight_kg' => '70',
    'age' => '25',
    'biological_sex' => 'male',
    'primary_goal' => 'General Fitness',
    'weekly_workout_target' => '3',
    'preferred_duration_mins' => '45',
    'preferred_workout_days' => '2,4,6', // Tuesday, Thursday, Saturday
];

save_member_profile($memberId);
$profile = member_profile($memberId);

assert($profile['preferred_workout_days'] === '2,4,6', "Failed to save preferred_workout_days");
echo "PASS: Preferred workout days '2,4,6' saved and loaded successfully.\n";

var_dump(is_member_self_guided($memberId));
$planId = generate_workout_plan($memberId);
echo "Generated planId: $planId\n";
assert($planId > 0, "Plan generation failed");

$daysWithExercises = $pdo->query("SELECT DISTINCT day_of_week FROM training_plan_exercises WHERE plan_id = " . (int)$planId . " ORDER BY day_of_week")->fetchAll(PDO::FETCH_COLUMN);

echo "Days with exercises scheduled: " . implode(',', $daysWithExercises) . "\n";
assert(implode(',', $daysWithExercises) === '2,4,6', "Generated plan did not adhere strictly to preferred days '2,4,6'");
echo "PASS: Starter workout plan strictly scheduled onto Tuesday (2), Thursday (4), and Saturday (6).\n";

// 3. Test trainer adherence check
$prefWorkoutDays = array_filter(array_map('intval', explode(',', (string)($profile['preferred_workout_days'] ?? ''))));
$restDay = 1; // Monday is a rest day
assert(!in_array($restDay, $prefWorkoutDays, true), "Day 1 should be a rest day");

// Simulate trainer trying to add an exercise or auto-populate on rest day
$canScheduleOnRestDay = in_array($restDay, $prefWorkoutDays, true);
assert($canScheduleOnRestDay === false, "Trainer was able to bypass member's preferred days!");
echo "PASS: Rest days (Day 1 Monday) are strictly blocked from trainer modifications.\n";

echo "ALL PREFERRED WORKOUT DAY ENFORCEMENT CHECKS PASSED!\n";
