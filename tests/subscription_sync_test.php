<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

echo "Running Subscription Sync & Activation Tests...\n";

$pdo = db();

// Create dummy gym owner and gym if not existing, or use a test gym
$gym = $pdo->query("SELECT gym_id, owner_user_id, subscription_plan, subscription_status FROM gyms LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
    echo "SKIP: No gyms available for testing subscription sync.\n";
    return;
}

$gymId = (int)$gym['gym_id'];
$ownerId = (int)$gym['owner_user_id'];
$originalPlan = $gym['subscription_plan'];
$originalStatus = $gym['subscription_status'];

// Test 1: Insert pending test subscription payment
$testRef = 'TESTSYNC-' . bin2hex(random_bytes(8));
$stmt = $pdo->prepare('INSERT INTO gym_subscription_payments 
    (gym_id, owner_user_id, plan_name, amount, billing_cycle, payment_method, status, start_date, end_date, xendit_reference_id, xendit_session_id)
    VALUES (?, ?, "Starter", 1.00, "monthly", "online", "pending", CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 MONTH), ?, "TEST-SES-001")');
$stmt->execute([$gymId, $ownerId, $testRef]);
$paymentId = (int)$pdo->lastInsertId();

$pending = $pdo->query("SELECT * FROM gym_subscription_payments WHERE id = $paymentId")->fetch(PDO::FETCH_ASSOC);
if (!$pending || $pending['status'] !== 'pending') {
    echo "FAIL: Test payment was not inserted in pending state.\n";
    exit(1);
}
echo "PASS: Test 1: Pending subscription payment created ($testRef).\n";

// Test 2: Activate gym subscription via xendit_activate_gym_subscription
$receipt = xendit_activate_gym_subscription($pdo, $pending, 'TEST-PAY-12345');
if (empty($receipt) || !str_starts_with($receipt, 'SUB-')) {
    echo "FAIL: Expected receipt starting with SUB-, got '$receipt'.\n";
    exit(1);
}

$updatedGym = $pdo->query("SELECT subscription_plan, subscription_status, subscription_renewal_date FROM gyms WHERE gym_id = $gymId")->fetch(PDO::FETCH_ASSOC);
if ($updatedGym['subscription_plan'] !== 'Starter' || $updatedGym['subscription_status'] !== 'active') {
    echo "FAIL: Gym subscription plan was not updated to Starter/active.\n";
    exit(1);
}
echo "PASS: Test 2: Gym subscription successfully activated (Receipt: $receipt, Plan: Starter, Status: active).\n";

// Test 3: Idempotent call to xendit_activate_gym_subscription
$receipt2 = xendit_activate_gym_subscription($pdo, $pending, 'TEST-PAY-12345');
if ($receipt2 !== $receipt) {
    echo "FAIL: Expected identical receipt on idempotent activation, got '$receipt2' vs '$receipt'.\n";
    exit(1);
}
echo "PASS: Test 3: Idempotent activation confirmed without duplicate changes.\n";

// Test 4: xendit_sync_gym_subscription_payment returns paid status
$syncResult = xendit_sync_gym_subscription_payment($testRef, $gymId, $ownerId, $paymentId);
if (!$syncResult || ($syncResult['status'] ?? '') !== 'paid' || ($syncResult['receipt_number'] ?? '') !== $receipt) {
    echo "FAIL: xendit_sync_gym_subscription_payment failed to report paid status for synced payment.\n";
    exit(1);
}
echo "PASS: Test 4: xendit_sync_gym_subscription_payment verified paid status and receipt.\n";

// Cleanup test payment & revert gym state
$pdo->prepare("DELETE FROM gym_subscription_payments WHERE id = ?")->execute([$paymentId]);
$pdo->prepare("UPDATE gyms SET subscription_plan = ?, subscription_status = ? WHERE gym_id = ?")->execute([$originalPlan, $originalStatus, $gymId]);

echo "ALL SUBSCRIPTION SYNC & ACTIVATION TESTS PASSED SUCCESSFULLY.\n";

