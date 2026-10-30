<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

echo "--- Starting Phase 5 Normalization Tests ---\n";
$pdo = db();

// Test 1: Verify gym_equipment columns (redundant columns dropped)
$eqCols = array_column($pdo->query('SHOW COLUMNS FROM gym_equipment')->fetchAll(PDO::FETCH_ASSOC), 'Field');
$redundantCols = ['current_session_id', 'maintenance_reason', 'expected_return_date', 'last_maintenance_date', 'next_maintenance_date'];
foreach ($redundantCols as $col) {
    if (in_array($col, $eqCols, true)) {
        echo "FAIL: Column '{$col}' still exists on gym_equipment.\n";
        exit(1);
    }
}
echo "PASS: Test 1: All redundant and circular columns dropped from gym_equipment.\n";

// Test 2: Verify equipment_maintenance_logs has next_maintenance_date
$maintCols = array_column($pdo->query('SHOW COLUMNS FROM equipment_maintenance_logs')->fetchAll(PDO::FETCH_ASSOC), 'Field');
if (!in_array('next_maintenance_date', $maintCols, true)) {
    echo "FAIL: next_maintenance_date missing on equipment_maintenance_logs.\n";
    exit(1);
}
echo "PASS: Test 2: equipment_maintenance_logs includes next_maintenance_date.\n";

// Test 3: Test v_gym_equipment view and hydration
$vEq = $pdo->query('SELECT * FROM v_gym_equipment LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$vEq || !array_key_exists('current_session_id', $vEq) || !array_key_exists('maintenance_reason', $vEq)) {
    echo "FAIL: v_gym_equipment does not provide expected joined columns.\n";
    exit(1);
}
$hydrated = hydrate_equipment_row(['equipment_id' => (int)$vEq['equipment_id'], 'name' => $vEq['name']]);
if (!array_key_exists('current_session_id', $hydrated) || !array_key_exists('maintenance_reason', $hydrated)) {
    echo "FAIL: hydrate_equipment_row failed to supply decoupled fields.\n";
    exit(1);
}
echo "PASS: Test 3: v_gym_equipment and hydrate_equipment_row supply decoupled fields seamlessly.\n";

// Test 4: Verify assignment_groups and assignment_group_members tables exist with rows
$groupCount = (int)$pdo->query('SELECT COUNT(*) FROM assignment_groups')->fetchColumn();
$memberCount = (int)$pdo->query('SELECT COUNT(*) FROM assignment_group_members')->fetchColumn();
if ($groupCount < 1 || $memberCount < 1) {
    echo "FAIL: assignment_groups or assignment_group_members is empty (groups: $groupCount, members: $memberCount).\n";
    exit(1);
}
echo "PASS: Test 4: assignment_groups ($groupCount) and assignment_group_members ($memberCount) populated.\n";

// Test 5: Verify trigger sync on INSERT into trainer_assignments
$testGroupId = 'test_group_' . bin2hex(random_bytes(8));
$trainerId = (int)$pdo->query('SELECT trainer_id FROM trainer_profiles LIMIT 1')->fetchColumn();
$memberId = (int)$pdo->query('SELECT user_id FROM users WHERE role = "member" LIMIT 1')->fetchColumn();

$pdo->prepare('
    INSERT INTO trainer_assignments (group_id, trainer_id, member_user_id, assigned_date, activity_title, status)
    VALUES (?, ?, ?, NOW(), "Test Group Activity", "active")
')->execute([$testGroupId, $trainerId, $memberId]);

$newAssignmentId = (int)$pdo->lastInsertId();

$syncedGroup = $pdo->prepare('SELECT * FROM assignment_groups WHERE group_id = ?');
$syncedGroup->execute([$testGroupId]);
$grpRow = $syncedGroup->fetch(PDO::FETCH_ASSOC);

$syncedMember = $pdo->prepare('SELECT * FROM assignment_group_members WHERE assignment_id = ?');
$syncedMember->execute([$newAssignmentId]);
$memRow = $syncedMember->fetch(PDO::FETCH_ASSOC);

if (!$grpRow || $grpRow['activity_title'] !== 'Test Group Activity') {
    echo "FAIL: Trigger failed to sync new group to assignment_groups.\n";
    exit(1);
}
if (!$memRow || (int)$memRow['member_user_id'] !== $memberId || $memRow['status'] !== 'active') {
    echo "FAIL: Trigger failed to sync member to assignment_group_members.\n";
    exit(1);
}
echo "PASS: Test 5: Trigger successfully synced INSERT to assignment_groups and assignment_group_members.\n";

// Test 6: Verify trigger sync on UPDATE
$pdo->prepare('UPDATE trainer_assignments SET status = "ended", activity_title = "Updated Activity" WHERE assignment_id = ?')
    ->execute([$newAssignmentId]);

$memRowUpdated = $pdo->query("SELECT status FROM assignment_group_members WHERE assignment_id = {$newAssignmentId}")->fetch(PDO::FETCH_ASSOC);
$grpRowUpdated = $pdo->query("SELECT activity_title, status FROM assignment_groups WHERE group_id = '{$testGroupId}'")->fetch(PDO::FETCH_ASSOC);

if ($memRowUpdated['status'] !== 'ended' || $grpRowUpdated['activity_title'] !== 'Updated Activity') {
    echo "FAIL: Trigger failed to sync UPDATE.\n";
    exit(1);
}
echo "PASS: Test 6: Trigger successfully synced UPDATE across assignment tables.\n";

// Test 7: Verify trigger sync on DELETE
$pdo->prepare('DELETE FROM trainer_assignments WHERE assignment_id = ?')->execute([$newAssignmentId]);

$memRowDeleted = $pdo->query("SELECT 1 FROM assignment_group_members WHERE assignment_id = {$newAssignmentId}")->fetchColumn();
$grpRowDeleted = $pdo->query("SELECT 1 FROM assignment_groups WHERE group_id = '{$testGroupId}'")->fetchColumn();

if ($memRowDeleted || $grpRowDeleted) {
    echo "FAIL: Trigger failed to clean up member/group on DELETE.\n";
    exit(1);
}
echo "PASS: Test 7: Trigger successfully synced DELETE and cleaned up orphaned test group.\n";

// Test 8: Verify v_assignment_group_details view
$vDetails = $pdo->query('SELECT * FROM v_assignment_group_details LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$vDetails || empty($vDetails['trainer_name']) || empty($vDetails['member_name'])) {
    echo "FAIL: v_assignment_group_details view returned empty or incomplete data.\n";
    exit(1);
}
echo "PASS: Test 8: v_assignment_group_details correctly aggregates group, trainer, and member details.\n";

echo "--- ALL PHASE 5 TESTS PASSED SUCCESSFULLY ---\n";

