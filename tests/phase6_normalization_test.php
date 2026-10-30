<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

echo "--- Starting Phase 6 Normalization Tests ---\n";
$pdo = db();

// Test 1: Verify badges table and count
$badgeCount = (int)$pdo->query('SELECT COUNT(*) FROM badges')->fetchColumn();
if ($badgeCount < 20) {
    echo "FAIL: Expected at least 20 badges in badges table, found {$badgeCount}.\n";
    exit(1);
}
echo "PASS: Test 1: badges lookup table seeded with {$badgeCount} badges.\n";

// Test 2: Verify member_badges badge_id column and backfill
$unmappedBadges = (int)$pdo->query('SELECT COUNT(*) FROM member_badges WHERE badge_id IS NULL AND badge_type IS NOT NULL')->fetchColumn();
if ($unmappedBadges > 0) {
    echo "FAIL: Found {$unmappedBadges} member_badges rows with unmapped badge_id.\n";
    exit(1);
}
echo "PASS: Test 2: All existing member_badges successfully mapped to badge_id.\n";

// Test 3: Test member_badges bidirectional trigger sync
$testUserId = (int)$pdo->query('SELECT user_id FROM users WHERE role = "member" LIMIT 1')->fetchColumn();
$pdo->prepare('INSERT INTO member_badges (user_id, badge_type) VALUES (?, "milestone_first_log") ON DUPLICATE KEY UPDATE badge_type = VALUES(badge_type)')->execute([$testUserId]);
$insertedBadgeId = (int)$pdo->query("SELECT badge_id FROM member_badges WHERE user_id = {$testUserId} AND badge_type = 'milestone_first_log'")->fetchColumn();
if ($insertedBadgeId <= 0) {
    echo "FAIL: trg_member_badges_bi failed to auto-populate badge_id from badge_type.\n";
    exit(1);
}
echo "PASS: Test 3: member_badges triggers successfully auto-sync badge_id from badge_type.\n";

// Test 4: Verify exercise_categories and muscle_groups
$catCount = (int)$pdo->query('SELECT COUNT(*) FROM exercise_categories')->fetchColumn();
$mgCount = (int)$pdo->query('SELECT COUNT(*) FROM muscle_groups')->fetchColumn();
if ($catCount < 3 || $mgCount < 5) {
    echo "FAIL: Exercise categories ({$catCount}) or muscle groups ({$mgCount}) missing seeds.\n";
    exit(1);
}
echo "PASS: Test 4: exercise_categories ({$catCount}) and muscle_groups ({$mgCount}) populated.\n";

// Test 5: Verify exercises FK backfill and trigger sync
$unmappedExercises = (int)$pdo->query('SELECT COUNT(*) FROM exercises WHERE (category IS NOT NULL AND category_id IS NULL) OR (muscle_group IS NOT NULL AND muscle_group_id IS NULL)')->fetchColumn();
if ($unmappedExercises > 0) {
    echo "FAIL: Found {$unmappedExercises} exercises with unmapped category_id or muscle_group_id.\n";
    exit(1);
}
echo "PASS: Test 5: All exercises mapped to foreign keys category_id and muscle_group_id.\n";

// Test 6: Verify fitness_goals table
$goalCount = (int)$pdo->query('SELECT COUNT(*) FROM fitness_goals')->fetchColumn();
if ($goalCount < 6) {
    echo "FAIL: Expected at least 6 canonical goals, found {$goalCount}.\n";
    exit(1);
}
echo "PASS: Test 6: fitness_goals lookup table seeded with {$goalCount} canonical goals.\n";

// Test 7: Verify member_profiles, training_plans, dietary_plans goal_id backfill
$unmappedProfiles = (int)$pdo->query('SELECT COUNT(*) FROM member_profiles WHERE primary_goal IS NOT NULL AND goal_id IS NULL')->fetchColumn();
$unmappedTraining = (int)$pdo->query('SELECT COUNT(*) FROM training_plans WHERE goal IS NOT NULL AND goal_id IS NULL')->fetchColumn();
$unmappedDietary = (int)$pdo->query('SELECT COUNT(*) FROM dietary_plans WHERE goal IS NOT NULL AND goal_id IS NULL')->fetchColumn();

if ($unmappedProfiles > 0 || $unmappedTraining > 0 || $unmappedDietary > 0) {
    echo "FAIL: Unmapped goal_ids found (profiles: {$unmappedProfiles}, training: {$unmappedTraining}, dietary: {$unmappedDietary}).\n";
    exit(1);
}
echo "PASS: Test 7: All profiles, training plans, and dietary plans mapped to canonical goal_id.\n";

// Test 8: Verify views v_member_badges, v_exercises, v_member_profiles_unified
$vBadges = $pdo->query('SELECT * FROM v_member_badges LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$vEx = $pdo->query('SELECT * FROM v_exercises LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$vProf = $pdo->query('SELECT * FROM v_member_profiles_unified LIMIT 1')->fetch(PDO::FETCH_ASSOC);

if (!$vBadges || empty($vBadges['name']) || !$vEx || empty($vEx['category_name']) || !$vProf || empty($vProf['canonical_goal_name'])) {
    echo "FAIL: One or more Phase 6 views failed to return joined data.\n";
    exit(1);
}
echo "PASS: Test 8: Phase 6 joined views (v_member_badges, v_exercises, v_member_profiles_unified) verified.\n";

echo "--- ALL PHASE 6 TESTS PASSED SUCCESSFULLY ---\n";

