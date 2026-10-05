<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/engagement_engine.php';

function run_lock_concurrency_tests(): void
{
    $lockFile = sys_get_temp_dir() . '/fittracks_engagement_sweep.lock';
    @unlink($lockFile);

    // TEST 1: Worker 1 acquires lock
    $acquired1 = acquire_engagement_sweep_lock('worker-run-1', 3600);
    if (!$acquired1) {
        throw new RuntimeException("FAIL: Worker 1 failed to acquire initial sweep lock");
    }
    fwrite(STDOUT, "PASS: Lock Test 1: Worker 1 successfully acquires initial sweep lock\n");

    // TEST 2: Worker 2 attempts concurrent acquire while Worker 1 is active -> must be rejected
    $acquired2 = acquire_engagement_sweep_lock('worker-run-2', 3600);
    if ($acquired2) {
        throw new RuntimeException("FAIL: Worker 2 acquired lock while Worker 1 was still active!");
    }
    fwrite(STDOUT, "PASS: Lock Test 2: Worker 2 acquire correctly rejected (mutual exclusion enforced)\n");

    // TEST 3: Worker 1 updates progress
    update_engagement_sweep_lock('worker-run-1', 50);
    $lockState = get_engagement_sweep_lock();
    if (!$lockState || ($lockState['run_id'] ?? '') !== 'worker-run-1' || ($lockState['last_user_id'] ?? 0) !== 50) {
        throw new RuntimeException("FAIL: Worker 1 progress update not reflected: " . json_encode($lockState));
    }
    fwrite(STDOUT, "PASS: Lock Test 3: Worker 1 advances cursor progress in active lock\n");

    // TEST 4: Worker 2 attempts unauthorized release -> must be rejected (lock remains intact)
    release_engagement_sweep_lock('worker-run-2');
    $lockStateAfterUnauthorized = get_engagement_sweep_lock();
    if (!$lockStateAfterUnauthorized || ($lockStateAfterUnauthorized['run_id'] ?? '') !== 'worker-run-1') {
        throw new RuntimeException("FAIL: Worker 2 was able to release Worker 1's lock!");
    }
    fwrite(STDOUT, "PASS: Lock Test 4: Unauthorized release attempt by Worker 2 rejected\n");

    // TEST 5: Worker 1 authorized release -> lock released cleanly
    release_engagement_sweep_lock('worker-run-1');
    $lockStateAfterRelease = get_engagement_sweep_lock();
    if ($lockStateAfterRelease !== null) {
        throw new RuntimeException("FAIL: Lock should be released, but found: " . json_encode($lockStateAfterRelease));
    }
    fwrite(STDOUT, "PASS: Lock Test 5: Worker 1 authorized release clears lock\n");

    // TEST 6: Worker 3 acquires newly released lock -> succeeds
    $acquired3 = acquire_engagement_sweep_lock('worker-run-3', 3600);
    if (!$acquired3) {
        throw new RuntimeException("FAIL: Worker 3 failed to acquire released lock");
    }
    release_engagement_sweep_lock('worker-run-3');
    @unlink($lockFile);
    fwrite(STDOUT, "PASS: Lock Test 6: Worker 3 acquires newly available lock cleanly\n");
}
