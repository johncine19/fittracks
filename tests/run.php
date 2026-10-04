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

fwrite(STDOUT, count($tests) . " test(s) passed.\n");
