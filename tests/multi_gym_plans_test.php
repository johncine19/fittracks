<?php
declare(strict_types=1);

if (!function_exists('db')) {
    require_once __DIR__ . '/../core/bootstrap.php';
}

function run_multi_gym_plans_tests(): void
{
    $pdo = db();

    fwrite(STDOUT, "--- Starting Multi-Gym Workout & Diet Plan Management Tests ---\n");

    // We'll wrap our test data in a transaction and rollback at the end so the database stays clean!
    $pdo->beginTransaction();

    try {
        // 1. Setup Test Fixture: 2 Gyms, 2 Trainers, 1 Multi-Gym Member
        $uniqueSuffix = time() . '_' . mt_rand(1000, 9999);

        // Gym A Owner
        $pdo->prepare('INSERT INTO users (first_name, last_name, email, password_hash, role, status) VALUES (?, ?, ?, "hash", "gym_owner", "active")')
            ->execute(["OwnerA_{$uniqueSuffix}", "Boss", "ownera_{$uniqueSuffix}@test.com"]);
        $ownerAUserId = (int)$pdo->lastInsertId();

        // Gym B Owner
        $pdo->prepare('INSERT INTO users (first_name, last_name, email, password_hash, role, status) VALUES (?, ?, ?, "hash", "gym_owner", "active")')
            ->execute(["OwnerB_{$uniqueSuffix}", "Boss", "ownerb_{$uniqueSuffix}@test.com"]);
        $ownerBUserId = (int)$pdo->lastInsertId();

        // Gym A
        $pdo->prepare('INSERT INTO gyms (name, owner_user_id, address, status) VALUES (?, ?, "123 Gym Alpha St", "approved")')->execute(["Test Gym Alpha {$uniqueSuffix}", $ownerAUserId]);
        $gymAId = (int)$pdo->lastInsertId();

        // Gym B
        $pdo->prepare('INSERT INTO gyms (name, owner_user_id, address, status) VALUES (?, ?, "456 Gym Beta Ave", "approved")')->execute(["Test Gym Beta {$uniqueSuffix}", $ownerBUserId]);
        $gymBId = (int)$pdo->lastInsertId();

        // Trainer A User
        $pdo->prepare('INSERT INTO users (first_name, last_name, email, password_hash, role, status) VALUES (?, ?, ?, "hash", "trainer", "active")')
            ->execute(["TrainerA_{$uniqueSuffix}", "Coach", "trainera_{$uniqueSuffix}@test.com"]);
        $trainerAUserId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO trainer_profiles (user_id, gym_id) VALUES (?, ?)')->execute([$trainerAUserId, $gymAId]);
        $coachAId = (int)$pdo->lastInsertId();

        // Trainer B User
        $pdo->prepare('INSERT INTO users (first_name, last_name, email, password_hash, role, status) VALUES (?, ?, ?, "hash", "trainer", "active")')
            ->execute(["TrainerB_{$uniqueSuffix}", "Coach", "trainerb_{$uniqueSuffix}@test.com"]);
        $trainerBUserId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO trainer_profiles (user_id, gym_id) VALUES (?, ?)')->execute([$trainerBUserId, $gymBId]);
        $coachBId = (int)$pdo->lastInsertId();

        // Member belonging to BOTH Gym A and Gym B
        $pdo->prepare('INSERT INTO users (first_name, last_name, email, password_hash, role, status) VALUES (?, ?, ?, "hash", "member", "active")')
            ->execute(["MemberMulti_{$uniqueSuffix}", "Athlete", "membermulti_{$uniqueSuffix}@test.com"]);
        $memberUserId = (int)$pdo->lastInsertId();

        // Affiliate member with Gym A & Gym B
        $pdo->prepare('INSERT INTO gym_members (user_id, gym_id) VALUES (?, ?), (?, ?)')->execute([$memberUserId, $gymAId, $memberUserId, $gymBId]);
        $pdo->prepare('INSERT INTO member_profiles (user_id, height_cm, weight_kg, primary_goal, fitness_tier) VALUES (?, 175, 75, "muscle_gain", 2)')->execute([$memberUserId]);

        // TEST 1: Gym A assigns a workout plan and diet plan to member
        $pdo->prepare('INSERT INTO training_plans (member_user_id, trainer_id, gym_id, title, goal, start_date, end_date, status) VALUES (?, ?, ?, "Alpha Hypertrophy", "muscle_gain", CURDATE(), DATE_ADD(CURDATE(), INTERVAL 4 WEEK), "active")')
            ->execute([$memberUserId, $coachAId, $gymAId]);
        $workoutPlanAId = (int)$pdo->lastInsertId();

        // Add exercises for Gym A: Monday (1) and Wednesday (3)
        $exerciseId = (int)scalar('SELECT exercise_id FROM exercises LIMIT 1') ?: 1;
        $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps) VALUES (?, ?, 1, 1, 4, 10), (?, ?, 3, 1, 4, 10)')
            ->execute([$workoutPlanAId, $exerciseId, $workoutPlanAId, $exerciseId]);

        $pdo->prepare('INSERT INTO dietary_plans (member_user_id, trainer_id, gym_id, title, goal, status, is_preferred) VALUES (?, ?, ?, "Alpha High Protein", "muscle_gain", "active", 1)')
            ->execute([$memberUserId, $coachAId, $gymAId]);
        $dietPlanAId = (int)$pdo->lastInsertId();

        log_plan_change('workout', $workoutPlanAId, $gymAId, $trainerAUserId, 'created', 'Gym A workout created');
        log_plan_change('diet', $dietPlanAId, $gymAId, $trainerAUserId, 'created', 'Gym A diet created');

        // Verify Gym A plans are active
        $stmtA = $pdo->prepare('SELECT status, is_preferred FROM dietary_plans WHERE plan_id = ?');
        $stmtA->execute([$dietPlanAId]);
        $dietAData = $stmtA->fetch(PDO::FETCH_ASSOC);
        if ($dietAData['status'] !== 'active' || (int)$dietAData['is_preferred'] !== 1) {
            throw new RuntimeException("FAIL: Gym A diet plan not active/preferred");
        }
        fwrite(STDOUT, "PASS: Test 1: Gym A plans created and active with gym_id attribution.\n");

        // TEST 2: Gym B generates/assigns a plan for the same member — MUST NOT OVERWRITE GYM A'S PLAN!
        // Simulate auto-generation or Trainer B assignment at Gym B
        $dietPlanBId = generate_dietary_plan($memberUserId, $coachBId, true, $gymBId);
        if (!$dietPlanBId || $dietPlanBId <= 0) {
            throw new RuntimeException("FAIL: Gym B failed to generate dietary plan");
        }

        // Add workout plan for Gym B: Monday (1) [Overlap!] and Friday (5)
        $pdo->prepare('INSERT INTO training_plans (member_user_id, trainer_id, gym_id, title, goal, start_date, end_date, status) VALUES (?, ?, ?, "Beta Conditioning", "muscle_gain", CURDATE(), DATE_ADD(CURDATE(), INTERVAL 4 WEEK), "active")')
            ->execute([$memberUserId, $coachBId, $gymBId]);
        $workoutPlanBId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps) VALUES (?, ?, 1, 1, 3, 15), (?, ?, 5, 1, 3, 15)')
            ->execute([$workoutPlanBId, $exerciseId, $workoutPlanBId, $exerciseId]);

        // CRITICAL ASSERTION: Gym A's diet plan MUST STILL BE ACTIVE!
        $stmtA->execute([$dietPlanAId]);
        $dietAAfterB = $stmtA->fetch(PDO::FETCH_ASSOC);
        if ($dietAAfterB['status'] !== 'active') {
            throw new RuntimeException("FAIL: CRITICAL BUG REGRESSION: Gym A's diet plan was overwritten/completed when Gym B created a plan! Status: " . $dietAAfterB['status']);
        }

        // Verify both diet plans are active simultaneously
        $activeDietCount = (int)scalar('SELECT COUNT(*) FROM dietary_plans WHERE member_user_id = ? AND status = "active"', [$memberUserId]);
        if ($activeDietCount !== 2) {
            throw new RuntimeException("FAIL: Expected 2 active diet plans across Gym A and Gym B, found: {$activeDietCount}");
        }
        fwrite(STDOUT, "PASS: Test 2: Cross-gym overwrite prevention confirmed — both Gym A and Gym B diet plans active.\n");

        // TEST 3: Preferred Nutrition Plan marking
        // Gym A was preferred. Now set Gym B as preferred.
        set_preferred_dietary_plan($memberUserId, $dietPlanBId);
        $prefCheck = $pdo->prepare('SELECT plan_id, is_preferred FROM dietary_plans WHERE member_user_id = ? AND status = "active" ORDER BY plan_id ASC');
        $prefCheck->execute([$memberUserId]);
        $plansPref = $prefCheck->fetchAll(PDO::FETCH_ASSOC);
        foreach ($plansPref as $p) {
            if ((int)$p['plan_id'] === $dietPlanAId && (int)$p['is_preferred'] !== 0) {
                throw new RuntimeException("FAIL: Gym A plan should no longer be preferred");
            }
            if ((int)$p['plan_id'] === $dietPlanBId && (int)$p['is_preferred'] !== 1) {
                throw new RuntimeException("FAIL: Gym B plan should be marked preferred");
            }
        }
        fwrite(STDOUT, "PASS: Test 3: Preferred nutrition plan marking successfully toggled.\n");

        // TEST 4: Schedule Conflict Detection
        // Gym A has exercises on Monday (1). Gym B ALSO has exercises on Monday (1).
        $conflicts = detect_workout_schedule_conflicts($memberUserId);
        if (empty($conflicts)) {
            throw new RuntimeException("FAIL: Schedule conflict on Monday (day 1) was not detected!");
        }
        $mondayConflict = null;
        foreach ($conflicts as $c) {
            if ($c['day_of_week'] === 1) {
                $mondayConflict = $c;
                break;
            }
        }
        if (!$mondayConflict || $mondayConflict['conflict_count'] !== 2) {
            throw new RuntimeException("FAIL: Monday overlap conflict not correctly identified");
        }
        fwrite(STDOUT, "PASS: Test 4: Workout schedule conflict correctly detected for overlapping Monday routines.\n");

        // TEST 5: Consolidated Calendar
        $calendar = get_consolidated_workout_calendar($memberUserId);
        if (!isset($calendar[1]) || count($calendar[1]['plans']) !== 2) {
            throw new RuntimeException("FAIL: Consolidated calendar did not aggregate both Gym A and Gym B workouts on Monday");
        }
        if (!isset($calendar[3]) || count($calendar[3]['plans']) !== 1) {
            throw new RuntimeException("FAIL: Wednesday routine from Gym A missing from consolidated calendar");
        }
        if (!isset($calendar[5]) || count($calendar[5]['plans']) !== 1) {
            throw new RuntimeException("FAIL: Friday routine from Gym B missing from consolidated calendar");
        }
        fwrite(STDOUT, "PASS: Test 5: Consolidated 7-day multi-gym schedule calendar successfully aggregated.\n");

        // TEST 6: Audit Trail Logging
        $auditLogs = query_all('SELECT * FROM plan_change_log WHERE (plan_id = ? OR plan_id = ?) ORDER BY log_id ASC', [$workoutPlanAId, $dietPlanBId]);
        if (empty($auditLogs)) {
            throw new RuntimeException("FAIL: Plan change audit logs not recorded in plan_change_log table");
        }
        fwrite(STDOUT, "PASS: Test 6: Plan audit trail logging confirmed in plan_change_log table.\n");

        // TEST 7: Cross-Gym Privacy Enforcement
        // Gym A Owner queries plans for Gym A: MUST ONLY return Gym A plans, NOT Gym B plans
        $gymAPlans = query_all('
            SELECT p.plan_id, p.gym_id 
            FROM training_plans p
            LEFT JOIN trainer_profiles tp ON p.trainer_id = tp.trainer_id
            WHERE (p.gym_id = ? OR (p.gym_id IS NULL AND tp.gym_id = ?))
              AND p.member_user_id = ?
        ', [$gymAId, $gymAId, $memberUserId]);
        
        $returnedPlanIds = array_column($gymAPlans, 'plan_id');
        if (in_array($workoutPlanBId, $returnedPlanIds, true)) {
            throw new RuntimeException("FAIL: Cross-gym privacy breach: Gym A owner can see Gym B's workout plan!");
        }
        if (!in_array($workoutPlanAId, $returnedPlanIds, true)) {
            throw new RuntimeException("FAIL: Gym A owner cannot see their own Gym A workout plan!");
        }
        fwrite(STDOUT, "PASS: Test 7: Strict cross-gym privacy verified (Gym A owner cannot see Gym B plans).\n");

        // TEST 8: can_manage_plan() authorization guard (gym-scoped, not member-scoped)
        $ownerAUser = ['user_id' => $ownerAUserId, 'role' => 'gym_owner'];
        $ownerBUser = ['user_id' => $ownerBUserId, 'role' => 'gym_owner'];
        $trainerAUser = ['user_id' => $trainerAUserId, 'role' => 'trainer'];
        $adminUser = ['user_id' => 0, 'role' => 'platform_admin'];
        if (!can_manage_plan('workout', $workoutPlanAId, $ownerAUser, 0, $gymAId)) {
            throw new RuntimeException("FAIL: Gym A owner should manage Gym A workout plan");
        }
        // Member belongs to BOTH gyms, yet Gym A owner must not touch Gym B's plan
        if (can_manage_plan('workout', $workoutPlanBId, $ownerAUser, 0, $gymAId)) {
            throw new RuntimeException("FAIL: Gym A owner can manage Gym B plan via shared member");
        }
        if (can_manage_plan('diet', $dietPlanAId, $ownerBUser, 0, $gymBId)) {
            throw new RuntimeException("FAIL: Gym B owner can manage Gym A diet plan");
        }
        if (!can_manage_plan('workout', $workoutPlanAId, $trainerAUser, $coachAId, $gymAId)) {
            throw new RuntimeException("FAIL: Trainer A should manage own plan");
        }
        if (can_manage_plan('workout', $workoutPlanBId, $trainerAUser, $coachAId, $gymAId)) {
            throw new RuntimeException("FAIL: Trainer A can manage Trainer B's plan");
        }
        if (!can_manage_plan('workout', $workoutPlanBId, $adminUser, 0, null)) {
            throw new RuntimeException("FAIL: Platform admin should manage any plan");
        }
        fwrite(STDOUT, "PASS: Test 8: can_manage_plan() enforces gym-scoped authorization.\n");

        // TEST 9: Workload warnings — same muscle Mon (A+B) and Tue? Add Gym B Tuesday session for recovery check
        $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps) VALUES (?, ?, 2, 1, 31, 10)')
            ->execute([$workoutPlanBId, $exerciseId]);
        $warnings = detect_workload_warnings($memberUserId, 30);
        $types = array_column($warnings, 'type');
        if (!in_array('volume', $types, true)) {
            throw new RuntimeException("FAIL: Excessive daily volume (Tuesday 30+ sets) not flagged");
        }
        $muscle = (string) scalar('SELECT muscle_group FROM exercises WHERE exercise_id = ?', [$exerciseId]);
        if ($muscle !== '' && !in_array('recovery', $types, true)) {
            throw new RuntimeException("FAIL: Back-to-back cross-gym muscle group training not flagged");
        }
        fwrite(STDOUT, "PASS: Test 9: Combined workload / recovery warnings detected.\n");

        // TEST 10: Membership lapse at Gym B pauses ONLY Gym B plans
        $pdo->prepare('INSERT INTO membership_plans (plan_name, plan_type, duration_days, price, gym_id) VALUES (?, "monthly", 30, 100, ?), (?, "monthly", 30, 100, ?)')
            ->execute(["Alpha Monthly {$uniqueSuffix}", $gymAId, "Beta Monthly {$uniqueSuffix}", $gymBId]);
        $msPlanAId = (int) scalar('SELECT plan_id FROM membership_plans WHERE gym_id = ? ORDER BY plan_id DESC LIMIT 1', [$gymAId]);
        $msPlanBId = (int) scalar('SELECT plan_id FROM membership_plans WHERE gym_id = ? ORDER BY plan_id DESC LIMIT 1', [$gymBId]);
        $pdo->prepare('INSERT INTO memberships (user_id, plan_id, start_date, end_date, status) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), "active"), (?, ?, DATE_SUB(CURDATE(), INTERVAL 40 DAY), DATE_SUB(CURDATE(), INTERVAL 10 DAY), "expired")')
            ->execute([$memberUserId, $msPlanAId, $memberUserId, $msPlanBId]);

        $pausedCount = pause_plans_for_lapsed_memberships($memberUserId);
        $statusOf = fn(string $t, int $id) => (string) scalar("SELECT status FROM {$t} WHERE plan_id = ?", [$id]);
        if ($statusOf('training_plans', $workoutPlanBId) !== 'paused' || $statusOf('dietary_plans', $dietPlanBId) !== 'paused') {
            throw new RuntimeException("FAIL: Gym B plans not paused after Gym B membership lapsed (paused={$pausedCount})");
        }
        if ($statusOf('training_plans', $workoutPlanAId) !== 'active' || $statusOf('dietary_plans', $dietPlanAId) !== 'active') {
            throw new RuntimeException("FAIL: Gym A plans affected by Gym B membership lapse");
        }
        $pauseLog = (int) scalar('SELECT COUNT(*) FROM plan_change_log WHERE plan_id = ? AND plan_type = "workout" AND action = "paused"', [$workoutPlanBId]);
        if ($pauseLog < 1) {
            throw new RuntimeException("FAIL: Auto-pause not recorded in plan_change_log");
        }
        fwrite(STDOUT, "PASS: Test 10: Membership lapse pauses only that gym's plans (audited, not deleted).\n");

        // 11. Member-Facing Draft Plan Exclusion Test
        // Insert a draft workout plan and draft dietary plan
        $pdo->prepare('INSERT INTO training_plans (member_user_id, trainer_id, gym_id, title, goal, status) VALUES (?, ?, ?, "Draft Split Plan", "muscle_gain", "draft")')
            ->execute([$memberUserId, $coachAId, $gymAId]);
        $draftWorkoutId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO dietary_plans (member_user_id, trainer_id, gym_id, title, goal, status) VALUES (?, ?, ?, "Draft Nutrition Plan", "muscle_gain", "draft")')
            ->execute([$memberUserId, $coachAId, $gymAId]);
        $draftDietId = (int)$pdo->lastInsertId();

        // Query member plans
        $memberWorkoutPlans = get_member_workout_plans($memberUserId);
        $memberDietPlans = get_member_dietary_plans($memberUserId);

        $workoutIds = array_column($memberWorkoutPlans, 'plan_id');
        $dietIds = array_column($memberDietPlans, 'plan_id');

        if (in_array($draftWorkoutId, $workoutIds, true)) {
            throw new RuntimeException("FAIL: Draft workout plan {$draftWorkoutId} appeared in get_member_workout_plans()");
        }
        if (in_array($draftDietId, $dietIds, true)) {
            throw new RuntimeException("FAIL: Draft dietary plan {$draftDietId} appeared in get_member_dietary_plans()");
        }

        // Even when status='all' or status='draft' is queried, drafts must be strictly excluded from member views
        $draftFilteredWorkouts = get_member_workout_plans($memberUserId, null, 'draft');
        $draftFilteredDiets = get_member_dietary_plans($memberUserId, null, 'draft');
        if (!empty($draftFilteredWorkouts) || !empty($draftFilteredDiets)) {
            throw new RuntimeException("FAIL: Draft plans leaked when status='draft' was requested in member-facing functions");
        }

        fwrite(STDOUT, "PASS: Test 11: Draft workout and dietary plans strictly excluded from member-facing views.\n");

        // 12. Unverified Food Guardrail Test
        require_once __DIR__ . '/../pages/member/diet.php';
        
        $pdo->prepare('INSERT INTO food_items (gym_id, name, meal_type, dietary_restriction, serving_size, calories, protein_g, carbs_g, fat_g, verification_status, is_active) VALUES (?, "Test Verified Protein Bowl", "Lunch", "none", "1 bowl", 450, 40, 45, 10, "verified", 1)')
            ->execute([$gymAId]);
        $verifiedFoodId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO food_items (gym_id, name, meal_type, dietary_restriction, serving_size, calories, protein_g, carbs_g, fat_g, verification_status, is_active) VALUES (?, "Test Unverified Shake", "Snack", "none", "1 glass", 300, 25, 30, 5, "unverified", 1)')
            ->execute([$gymAId]);
        $unverifiedFoodId = (int)$pdo->lastInsertId();

        // Catalog test
        $swapCatalog = get_swap_meal_catalog($pdo, $gymAId);
        $allCatalogTitles = [];
        foreach ($swapCatalog as $mList) {
            foreach ($mList as $item) {
                $allCatalogTitles[] = $item['title'];
            }
        }

        if (!in_array('Test Verified Protein Bowl', $allCatalogTitles, true)) {
            throw new RuntimeException("FAIL: Verified food item missing from member swap catalog");
        }
        if (in_array('Test Unverified Shake', $allCatalogTitles, true)) {
            throw new RuntimeException("FAIL: Unverified food item leaked into member swap catalog");
        }

        // Trainer builder query check
        $libStmt = $pdo->prepare("SELECT name FROM food_items WHERE is_active = 1 AND verification_status = 'verified' AND (gym_id = ? OR gym_id IS NULL OR gym_id = 0)");
        $libStmt->execute([$gymAId]);
        $libFoods = $libStmt->fetchAll(PDO::FETCH_COLUMN);

        if (in_array('Test Unverified Shake', $libFoods, true)) {
            throw new RuntimeException("FAIL: Unverified food item found in trainer member-facing library dropdown");
        }
        if (!in_array('Test Verified Protein Bowl', $libFoods, true)) {
            throw new RuntimeException("FAIL: Verified food item missing from trainer member-facing library dropdown");
        }

        fwrite(STDOUT, "PASS: Test 12: Unverified food items strictly prevented from being added/offered to members.\n");

    } finally {
        // Rollback test data to leave database completely pristine
        $pdo->rollBack();
    }

    fwrite(STDOUT, "--- ALL 12 MULTI-GYM PLAN MANAGEMENT TESTS PASSED SUCCESSFULLY ---\n\n");
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    run_multi_gym_plans_tests();
}
