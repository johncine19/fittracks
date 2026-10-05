<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/engagement_engine.php';

$tests = [
    'no activity scores zero' => [
        0,
        [0, 0, 0, 0, false],
    ],
    'all targets reached score 100' => [
        100,
        [7, 4, 4, 8, true],
    ],
    'partial metrics are rounded and combined' => [
        58,
        [4, 2, 2, 4, true],
    ],
    'metrics above targets are capped' => [
        100,
        [70, 40, 40, 80, true],
    ],
    'configured weights are honored' => [
        100,
        [7, 4, 4, 8, true, [
            'attendance' => 20,
            'classes' => 30,
            'consistency' => 10,
            'workouts' => 25,
            'progress' => 15,
        ]],
    ],
];

$failed = 0;
foreach ($tests as $name => [$expected, $args]) {
    $actual = engagement_score_from_metrics(...$args);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$name}; expected {$expected}, got {$actual}\n");
        $failed++;
        continue;
    }
    fwrite(STDOUT, "PASS: {$name}\n");
}

if ($failed > 0) {
    fwrite(STDERR, "{$failed} test(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, count($tests) . " score arithmetic test(s) passed.\n\n");

// At-Risk Multi-Gym Affiliation & Batch Boundary Tests
require_once __DIR__ . '/at_risk_batch_test.php';
try {
    fwrite(STDOUT, "Running at-risk multi-gym batching and boundary tests...\n");
    run_at_risk_batch_multi_gym_tests();
    fwrite(STDOUT, "All at-risk batching and boundary tests passed.\n\n");
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
}

// Concurrency Distributed Locking & Release Tests
require_once __DIR__ . '/lock_concurrency_test.php';
try {
    fwrite(STDOUT, "Running concurrency lock acquisition, exclusion, and release tests...\n");
    run_lock_concurrency_tests();
    fwrite(STDOUT, "All lock concurrency tests passed.\n\n");
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "ALL TEST SUITES PASSED SUCCESSFULLY.\n");

