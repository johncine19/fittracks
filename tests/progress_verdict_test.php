<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/goal_service.php';

$failures = 0;
$assertions = 0;

function assert_true(bool $cond, string $message): void
{
    global $failures, $assertions;
    $assertions++;
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$message}\n");
        $failures++;
    }
}

function assert_eq($expected, $actual, string $message): void
{
    global $failures, $assertions;
    $assertions++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}; expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n");
        $failures++;
    }
}

// 1. Fat loss: progressing with ETA
$v = evaluate_member_progress_verdict(
    ['primary_goal' => 'losing_weight', 'target_weight_kg' => 68.0, 'first_name' => 'Juan'],
    [
        ['log_date' => '2026-11-25', 'weight_kg' => 72.0],
        ['log_date' => '2026-10-28', 'weight_kg' => 74.0],
    ],
    ['workouts_this_week' => 4, 'weekly_target' => 4, 'checkins_30d' => 14]
);
assert_eq('progressing', $v['code'], 'fat_loss progressing code');
assert_true(str_contains($v['explanation'], 'weeks from 68'), 'fat_loss progressing includes ETA');
assert_eq('down', $v['directions']['weight'], 'fat_loss weight direction is down');

// 2. Fat loss: goal reached
$v = evaluate_member_progress_verdict(
    ['primary_goal' => 'losing_weight', 'target_weight_kg' => 68.0],
    [
        ['log_date' => '2026-11-25', 'weight_kg' => 67.8],
        ['log_date' => '2026-10-25', 'weight_kg' => 71.0],
    ],
    ['workouts_this_week' => 3, 'weekly_target' => 4]
);
assert_eq('goal_reached', $v['code'], 'fat_loss goal_reached code');

// 3. Fat loss: plateau with high-calorie explanation (3rd-person manager view)
$v = evaluate_member_progress_verdict(
    ['primary_goal' => 'reducing_body_fat', 'target_weight_kg' => 68.0, 'first_name' => 'Juan'],
    [
        ['log_date' => '2026-11-25', 'weight_kg' => 73.1],
        ['log_date' => '2026-11-01', 'weight_kg' => 73.0],
    ],
    [
        'is_manager_view'    => true,
        'member_name'        => 'Juan',
        'workouts_this_week' => 3,
        'weekly_target'      => 4,
        'checkins_30d'       => 10,
        'nutrition'          => [
            'days_logged'    => 10,
            'avg_cals'       => 2250,
            'target_cals'    => 1900,
            'avg_protein'    => 140,
            'target_protein' => 150,
        ],
    ]
);
assert_eq('plateau', $v['code'], 'fat_loss plateau code');
assert_true(str_contains($v['explanation'], '+350 kcal over'), 'fat_loss plateau cites calorie overage');
assert_true(str_contains($v['headline'], 'Juan'), 'manager view uses member name in headline');
assert_true(!preg_match('/\b(you|your)\b/i', $v['headline'] . ' ' . $v['explanation'] . ' ' . $v['next_step']), 'manager view contains no 2nd-person pronouns');

// 4. Fat loss: off track (weight gain)
$v = evaluate_member_progress_verdict(
    ['primary_goal' => 'losing_weight', 'target_weight_kg' => 68.0],
    [
        ['log_date' => '2026-11-25', 'weight_kg' => 75.2],
        ['log_date' => '2026-11-01', 'weight_kg' => 74.0],
    ],
    ['workouts_this_week' => 1, 'weekly_target' => 4, 'checkins_30d' => 2]
);
assert_eq('off_track', $v['code'], 'fat_loss off_track on weight gain');
assert_true(str_contains($v['headline'] . ' ' . $v['explanation'] . ' ' . $v['next_step'], 'your'), 'member view uses 2nd-person phrasing');

// 5. Fat loss: needs data (< 2 logs)
$v = evaluate_member_progress_verdict(
    ['primary_goal' => 'losing_weight', 'target_weight_kg' => 68.0],
    [
        ['log_date' => '2026-11-25', 'weight_kg' => 74.0],
    ],
    ['workouts_this_week' => 2, 'weekly_target' => 4]
);
assert_eq('needs_data', $v['code'], 'fat_loss needs_data when < 2 logs');

// 6. Hypertrophy: muscle gain weight up = progressing, weight drop = off_track, arm gain = progressing
$vUp = evaluate_member_progress_verdict(
    ['primary_goal' => 'building_muscle', 'target_weight_kg' => 78.0],
    [
        ['log_date' => '2026-11-25', 'weight_kg' => 74.0],
        ['log_date' => '2026-11-01', 'weight_kg' => 72.5],
    ],
    ['workouts_this_week' => 4, 'weekly_target' => 4, 'checkins_30d' => 14]
);
assert_eq('up', $vUp['directions']['weight'], 'hypertrophy weight direction is up');
assert_eq('progressing', $vUp['code'], 'hypertrophy weight gain is progressing');

$vDrop = evaluate_member_progress_verdict(
    ['primary_goal' => 'building_muscle', 'target_weight_kg' => 78.0],
    [
        ['log_date' => '2026-11-25', 'weight_kg' => 71.2],
        ['log_date' => '2026-11-01', 'weight_kg' => 72.5],
    ],
    ['workouts_this_week' => 2, 'weekly_target' => 4]
);
assert_eq('off_track', $vDrop['code'], 'hypertrophy weight drop is off_track');

$vArms = evaluate_member_progress_verdict(
    ['primary_goal' => 'Growing larger biceps and arms', 'target_arm_cm' => 40.0],
    [
        ['log_date' => '2026-11-25', 'weight_kg' => 74.0, 'arm_cm' => 37.2],
        ['log_date' => '2026-11-01', 'weight_kg' => 73.8, 'arm_cm' => 36.4],
    ],
    ['workouts_this_week' => 4, 'weekly_target' => 4]
);
assert_eq('progressing', $vArms['code'], 'arm hypertrophy gain is progressing');

// 7. Recomposition: stable weight + waist drop = progressing; waist gain = off_track
$vRecompOk = evaluate_member_progress_verdict(
    ['primary_goal' => 'Reaching body recomposition'],
    [
        ['log_date' => '2026-11-25', 'weight_kg' => 74.1, 'waist_cm' => 81.0],
        ['log_date' => '2026-10-25', 'weight_kg' => 74.0, 'waist_cm' => 83.2],
    ],
    ['workouts_this_week' => 4, 'weekly_target' => 4]
);
assert_eq('progressing', $vRecompOk['code'], 'recomposition stable weight + waist drop is progressing');

$vRecompBad = evaluate_member_progress_verdict(
    ['primary_goal' => 'Reaching body recomposition'],
    [
        ['log_date' => '2026-11-25', 'weight_kg' => 75.5, 'waist_cm' => 85.0],
        ['log_date' => '2026-10-25', 'weight_kg' => 74.0, 'waist_cm' => 83.5],
    ],
    ['workouts_this_week' => 2, 'weekly_target' => 4]
);
assert_eq('off_track', $vRecompBad['code'], 'recomposition waist gain is off_track');

// 8. Strength: target increase = progressing, target met = goal_reached
$vStrProg = evaluate_member_progress_verdict(
    ['primary_goal' => 'increasing_strength'],
    [],
    [
        'workouts_this_week' => 3,
        'weekly_target'      => 4,
        'strength_targets'   => [
            ['exercise_name' => 'Bench Press', 'start_max_kg' => 60, 'current_max_kg' => 70, 'target_max_kg' => 80],
        ],
    ]
);
assert_eq('progressing', $vStrProg['code'], 'strength target increase is progressing');

$vStrMet = evaluate_member_progress_verdict(
    ['primary_goal' => 'increasing_strength'],
    [],
    [
        'workouts_this_week' => 4,
        'weekly_target'      => 4,
        'strength_targets'   => [
            ['exercise_name' => 'Bench Press', 'start_max_kg' => 60, 'current_max_kg' => 80, 'target_max_kg' => 80],
        ],
    ]
);
assert_eq('goal_reached', $vStrMet['code'], 'strength target met is goal_reached');

// 9. Lifestyle: high consistency = progressing, zero activity = needs_data, low activity = off_track
$vLifeOk = evaluate_member_progress_verdict(
    ['primary_goal' => 'general_fitness'],
    [],
    ['workouts_this_week' => 3, 'weekly_target' => 3, 'checkins_30d' => 10]
);
assert_eq('progressing', $vLifeOk['code'], 'lifestyle high consistency is progressing');

$vLifeNeed = evaluate_member_progress_verdict(
    ['primary_goal' => 'general_fitness'],
    [],
    ['workouts_this_week' => 0, 'weekly_target' => 3, 'checkins_30d' => 0]
);
assert_eq('needs_data', $vLifeNeed['code'], 'lifestyle zero activity is needs_data');

$vLifeOff = evaluate_member_progress_verdict(
    ['primary_goal' => 'general_fitness'],
    [],
    ['workouts_this_week' => 0, 'weekly_target' => 4, 'checkins_30d' => 1]
);
assert_eq('off_track', $vLifeOff['code'], 'lifestyle very low activity is off_track');

// 10. Short span (< 7 days) must not extrapolate wild weekly rate or ETA
$shortSeries = analyze_progress_metric_series(
    [
        ['log_date' => '2026-11-25', 'weight_kg' => 73.0],
        ['log_date' => '2026-11-23', 'weight_kg' => 73.5],
    ],
    'weight_kg',
    68.0
);
assert_eq(null, $shortSeries['weekly_rate'], 'span_days < 7 keeps weekly_rate null');
assert_eq(null, $shortSeries['eta_weeks'], 'span_days < 7 keeps eta_weeks null');

// 11. Target Bar Progress: Waist Start 80, Now 90, Goal 80 -> 0% off_target (not 100%)
$tbWaistOff = compute_target_bar_progress('Waist', 'cm', 80.0, 90.0, 80.0, 'down');
assert_eq(0, $tbWaistOff['pct'], 'Waist Start=80 Now=90 Goal=80 should be 0%');
assert_eq('off_target', $tbWaistOff['status'], 'Waist Start=80 Now=90 Goal=80 status is off_target');
assert_true(str_contains($tbWaistOff['note'], '10 cm above goal'), 'Waist off_target note mentions 10 cm above goal');

// 12. Target Bar Progress: Start == Goal == Now (80, 80, 80) -> 100% reached
$tbWaistMet = compute_target_bar_progress('Waist', 'cm', 80.0, 80.0, 80.0, 'down');
assert_eq(100, $tbWaistMet['pct'], 'Start == Goal == Now should be 100%');
assert_eq('reached', $tbWaistMet['status'], 'Start == Goal == Now status is reached');

// 13. Target Bar Progress: Reduced target snapshot (Weight Start 55, Now 55, Goal 53, down) -> 0% not_started
$tbWeightReduced = compute_target_bar_progress('Weight', 'kg', 53.0, 55.0, 55.0, 'down');
assert_eq(0, $tbWeightReduced['pct'], 'Reduced target Start=55 Now=55 Goal=53 should be 0%');
assert_eq('not_started', $tbWeightReduced['status'], 'Reduced target status is not_started');
assert_true(str_contains($tbWeightReduced['note'], '2 kg to lose'), 'Reduced target note mentions 2 kg to lose');

// 14. Target Bar Progress: Legacy row (Weight Start 51, Now 55, Goal 53, desiredDir down) -> 0% off_target
$tbWeightLegacy = compute_target_bar_progress('Weight', 'kg', 53.0, 55.0, 51.0, 'down');
assert_eq(0, $tbWeightLegacy['pct'], 'Legacy Start=51 Now=55 Goal=53 with down dir should be 0%');
assert_eq('off_target', $tbWeightLegacy['status'], 'Legacy direction mismatch status is off_target');
assert_true(str_contains($tbWeightLegacy['note'], '2 kg above goal'), 'Legacy mismatch note mentions 2 kg above goal');

// 15. Target Bar Progress: Overshoot on gain goal (Weight Start 51, Now 55, Goal 53, up) -> 100% exceeded
$tbWeightOver = compute_target_bar_progress('Weight', 'kg', 53.0, 55.0, 51.0, 'up');
assert_eq(100, $tbWeightOver['pct'], 'Gain goal overshoot pct is 100');
assert_eq('exceeded', $tbWeightOver['status'], 'Gain goal overshoot status is exceeded');
assert_true(str_contains($tbWeightOver['note'], 'Exceeded goal'), 'Gain goal overshoot note mentions Exceeded goal');

// 16. Target Bar Progress: Moved wrong direction (Weight Start 75, Now 77, Goal 70, down) -> 0% off_target
$tbWrongDir = compute_target_bar_progress('Weight', 'kg', 70.0, 77.0, 75.0, 'down');
assert_eq(0, $tbWrongDir['pct'], 'Wrong direction pct is 0');
assert_eq('off_target', $tbWrongDir['status'], 'Wrong direction status is off_target');
assert_true(str_contains($tbWrongDir['note'], 'from start'), 'Wrong direction note mentions from start');

// 17. Target Bar Progress: Normal halfway progress (Weight Start 55, Now 54, Goal 53, down) -> 50% in_progress
$tbHalf = compute_target_bar_progress('Weight', 'kg', 53.0, 54.0, 55.0, 'down');
assert_eq(50, $tbHalf['pct'], 'Halfway progress pct is 50');
assert_eq('in_progress', $tbHalf['status'], 'Halfway progress status is in_progress');
assert_true(str_contains($tbHalf['note'], '1 kg to lose'), 'Halfway note mentions 1 kg to lose');

// 18. Target Bar Progress: Not started gain (Arms Start 35, Now 35, Goal 70, up) -> 0% not_started
$tbArms = compute_target_bar_progress('Arms', 'cm', 70.0, 35.0, 35.0, 'up');
assert_eq(0, $tbArms['pct'], 'Arms not started pct is 0');
assert_eq('not_started', $tbArms['status'], 'Arms not started status is not_started');
assert_true(str_contains($tbArms['note'], '35 cm to gain'), 'Arms note mentions 35 cm to gain');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed out of {$assertions}.\n");
    exit(1);
}

fwrite(STDOUT, "PASS: All {$assertions} progress verdict assertions passed.\n");
