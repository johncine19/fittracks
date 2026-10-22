<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

function test_dummy_successful_job(array $payload): void {
    $GLOBALS['dummy_job_ran'] = ($GLOBALS['dummy_job_ran'] ?? 0) + 1;
}

function test_dummy_failing_job(array $payload): void {
    throw new RuntimeException("Simulated job failure for testing");
}

echo "Running Queue Worker failure & locking tests...\n";
$pdo = db();

// Clean existing jobs for test isolation
$pdo->exec("DELETE FROM jobs WHERE job_class LIKE 'test_dummy_%'");

// Test 1: Successful job processing
$GLOBALS['dummy_job_ran'] = 0;
Queue::push('test_dummy_successful_job', ['test' => 123]);
$processed = Queue::work(5);
assert($processed >= 1, "Expected at least 1 job processed");
assert(($GLOBALS['dummy_job_ran'] ?? 0) === 1, "Expected dummy successful job to run");
$remaining = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE job_class = 'test_dummy_successful_job'")->fetchColumn();
assert($remaining === 0, "Expected successful job to be deleted from jobs table");
echo "PASS: Queue Test 1: Successful job executed and deleted cleanly\n";

// Test 2: Failing job increments attempts and applies backoff without rolling back attempts
Queue::push('test_dummy_failing_job', ['val' => 1]);
$jobRow = $pdo->query("SELECT id, attempts, available_at FROM jobs WHERE job_class = 'test_dummy_failing_job'")->fetch(PDO::FETCH_ASSOC);
$jobId = (int)$jobRow['id'];

$processed = Queue::work(5);
$updatedJob = $pdo->query("SELECT attempts, available_at FROM jobs WHERE id = $jobId")->fetch(PDO::FETCH_ASSOC);

assert((int)$updatedJob['attempts'] === 1, "Expected attempts to be incremented to 1 (not rolled back)");
assert((int)$updatedJob['available_at'] > time(), "Expected available_at to be backed off into the future");
echo "PASS: Queue Test 2: Failing job incremented attempts and backed off into future\n";

// Test 3: Job reaching max attempts (3) is discarded to prevent infinite poison pill loop
$pdo->prepare("UPDATE jobs SET attempts = 2, available_at = ? WHERE id = ?")->execute([time() - 1, $jobId]);
$processed = Queue::work(5);
$deadJob = $pdo->query("SELECT COUNT(*) FROM jobs WHERE id = $jobId")->fetchColumn();
assert((int)$deadJob === 0, "Expected job with 3rd failure to be discarded from queue");
echo "PASS: Queue Test 3: Poison job reaching max attempts was safely purged\n";

// Clean up
$pdo->exec("DELETE FROM jobs WHERE job_class LIKE 'test_dummy_%'");
echo "ALL QUEUE WORKER TESTS PASSED SUCCESSFULLY.\n";
