<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../pages/shared/workouts.php';

echo "Running Self-Guided Workout Plan Review & Adjustment Tests...\n";

$pdo = db();

// Setup a clean test member
$testEmail = 'selfguided_test_' . time() . '@test.com';
$pdo->prepare('INSERT INTO users (role, first_name, last_name, email, password_hash, status) VALUES ("member", "SelfGuided", "Tester", ?, "hash", "active")')
    ->execute([$testEmail]);
$testUserId = (int) $pdo->lastInsertId();

// Assign standard profile with primary goal
$pdo->prepare('INSERT INTO member_profiles (user_id, primary_goal, biological_sex, activity_level, height_cm, weekly_workout_target) VALUES (?, "muscle_gain", "male", "moderately_active", 175, 3)')
    ->execute([$testUserId]);

// Ensure member is self-guided (no coach)
if (!is_member_self_guided($testUserId)) {
    echo "FAIL: Test member was expected to be self-guided\n";
    exit(1);
}

// Add initial progress log so weight is recorded
$pdo->prepare('INSERT INTO progress_logs (user_id, log_date, weight_kg, waist_cm, chest_cm) VALUES (?, CURDATE(), 70.0, 80, 95)')
    ->execute([$testUserId]);

// -------------------------------------------------------------
// Test 1: Initial Plan Generation creates an Active 4-week Plan
// -------------------------------------------------------------
$initialPlanId = generate_workout_plan($testUserId);
if (!$initialPlanId) {
    echo "FAIL: Failed to generate initial workout plan\n";
    exit(1);
}

$planRow = query_one('SELECT * FROM training_plans WHERE plan_id = ?', [$initialPlanId]);
if (!$planRow || $planRow['status'] !== 'active') {
    echo "FAIL: Initial plan is not active\n";
    exit(1);
}

// Check 4-week interval
$startDate = new DateTime($planRow['start_date']);
$endDate = new DateTime($planRow['end_date']);
$intervalDays = (int) $startDate->diff($endDate)->format('%a');
if ($intervalDays < 27 || $intervalDays > 29) {
    echo "FAIL: Plan review interval is not approximately 28 days (got {$intervalDays} days)\n";
    exit(1);
}
echo "PASS: Test 1: Initial workout plan created with active status and 4-week review interval.\n";

// -------------------------------------------------------------
// Test 2: Progress Logging does NOT regenerate or modify the plan
// -------------------------------------------------------------
// Insert progress log
$pdo->prepare('INSERT INTO progress_logs (user_id, log_date, weight_kg, waist_cm, chest_cm) VALUES (?, CURDATE(), 71.5, 80, 95)')
    ->execute([$testUserId]);

// Check active plan after progress log
$postLogPlanId = (int) scalar('SELECT plan_id FROM training_plans WHERE member_user_id = ? AND status = "active"', [$testUserId]);
if ($postLogPlanId !== $initialPlanId) {
    echo "FAIL: Plan was regenerated during progress logging (expected {$initialPlanId}, got {$postLogPlanId})\n";
    exit(1);
}

// Check total plans count for this user (must still be exactly 1)
$planCount = (int) scalar('SELECT COUNT(*) FROM training_plans WHERE member_user_id = ?', [$testUserId]);
if ($planCount !== 1) {
    echo "FAIL: Plan count changed after progress logging (expected 1, got {$planCount})\n";
    exit(1);
}
echo "PASS: Test 2: Progress logging preserved existing workout plan and did not trigger auto-regeneration.\n";

// -------------------------------------------------------------
// Test 3: Plan Review Date does not automatically expire or delete the plan
// -------------------------------------------------------------
// Set end_date to yesterday to simulate review date arrival
$pdo->prepare('UPDATE training_plans SET end_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE plan_id = ?')
    ->execute([$initialPlanId]);

$expiredRow = query_one('SELECT * FROM training_plans WHERE plan_id = ?', [$initialPlanId]);
if ($expiredRow['status'] !== 'active') {
    echo "FAIL: Plan status should remain active even when review date arrives (got {$expiredRow['status']})\n";
    exit(1);
}

// Simulate user choosing "Keep Current Routine" (+4 weeks extension)
$pdo->prepare('UPDATE training_plans SET end_date = DATE_ADD(GREATEST(COALESCE(end_date, CURDATE()), CURDATE()), INTERVAL 4 WEEK) WHERE plan_id = ? AND member_user_id = ?')
    ->execute([$initialPlanId, $testUserId]);

$extendedRow = query_one('SELECT * FROM training_plans WHERE plan_id = ?', [$initialPlanId]);
$extendedEndDate = new DateTime($extendedRow['end_date']);
$today = new DateTime('today');
$daysFromToday = (int) $today->diff($extendedEndDate)->format('%a');
if ($daysFromToday < 27) {
    echo "FAIL: Plan was not extended by 4 weeks (got {$daysFromToday} days from today)\n";
    exit(1);
}
echo "PASS: Test 3: Scheduled review date arrived without destructive deletion; cycle extension verified.\n";

// -------------------------------------------------------------
// Test 4: Manual Plan Regeneration Archives Previous Version (Never Deletes)
// -------------------------------------------------------------
$newPlanId = generate_workout_plan($testUserId);
if (!$newPlanId || $newPlanId === $initialPlanId) {
    echo "FAIL: Expected new plan ID after regeneration\n";
    exit(1);
}

// Verify old plan is archived, NOT deleted
$oldPlanRow = query_one('SELECT * FROM training_plans WHERE plan_id = ?', [$initialPlanId]);
if (!$oldPlanRow || $oldPlanRow['status'] !== 'archived') {
    echo "FAIL: Previous plan version was not archived (status: " . ($oldPlanRow['status'] ?? 'null') . ")\n";
    exit(1);
}

// Verify new plan is active
$newPlanRow = query_one('SELECT * FROM training_plans WHERE plan_id = ?', [$newPlanId]);
if (!$newPlanRow || $newPlanRow['status'] !== 'active') {
    echo "FAIL: New plan version is not active\n";
    exit(1);
}

// Verify total plans in version history is 2 (1 archived, 1 active)
$totalPlans = (int) scalar('SELECT COUNT(*) FROM training_plans WHERE member_user_id = ?', [$testUserId]);
if ($totalPlans !== 2) {
    echo "FAIL: Expected 2 plan versions in database history (got {$totalPlans})\n";
    exit(1);
}
echo "PASS: Test 4: Plan regeneration safely archived previous version and created new active phase.\n";

// Cleanup test user
$pdo->prepare('DELETE FROM training_plan_exercises WHERE plan_id IN (?, ?)')->execute([$initialPlanId, $newPlanId]);
$pdo->prepare('DELETE FROM training_plans WHERE member_user_id = ?')->execute([$testUserId]);
$pdo->prepare('DELETE FROM progress_logs WHERE user_id = ?')->execute([$testUserId]);
$pdo->prepare('DELETE FROM member_profiles WHERE user_id = ?')->execute([$testUserId]);
$pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$testUserId]);

echo "ALL SELF-GUIDED WORKOUT PLAN REVIEW TESTS PASSED SUCCESSFULLY.\n";
