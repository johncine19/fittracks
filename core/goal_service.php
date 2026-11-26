<?php
declare(strict_types=1);

/**
 * FitTrack Goal Progress Service
 * Calculates customized KPIs, milestones, and directional trends tailored to each of FitTrack's 13 Fitness Goals.
 * Optimized for system scalability, zero-redundant queries, and clean separation of concerns.
 */

/**
 * Returns the tracking model classification for a given goal
 */
function get_goal_metric_model(string $goal): string
{
    $g = strtolower(trim(str_replace(['_', '-'], ' ', $goal)));

    // Strength & Athletic Power
    if (str_contains($g, 'maximum strength') || str_contains($g, 'increasing strength') || str_contains($g, 'strength') || str_contains($g, 'powerlifting') || str_contains($g, 'explosive power')) {
        return 'strength';
    }

    // Target Hypertrophy & Muscle Shaping
    if (str_contains($g, 'six pack') || str_contains($g, 'biceps') || str_contains($g, 'arms') ||
        str_contains($g, 'chest') || str_contains($g, 'v taper') || str_contains($g, 'back') ||
        str_contains($g, 'lower body') || str_contains($g, 'glutes') || str_contains($g, 'legs') ||
        str_contains($g, 'building muscle') || str_contains($g, 'lean body mass')) {
        return 'hypertrophy';
    }

    // Cardiovascular Endurance & Flexibility
    if (str_contains($g, 'endurance') || str_contains($g, 'stamina') || str_contains($g, 'cardio') ||
        str_contains($g, 'running') || str_contains($g, 'cycling') || str_contains($g, 'swimming') || str_contains($g, 'rowing')) {
        return 'endurance';
    }
    if (str_contains($g, 'flexibility') || str_contains($g, 'mobility') || str_contains($g, 'stretch')) {
        return 'mobility';
    }

    // Body Composition
    if (str_contains($g, 'recomposition') || str_contains($g, 'body recomposition')) {
        return 'recomposition';
    }
    if (str_contains($g, 'body fat') || str_contains($g, 'fat loss') || str_contains($g, 'losing weight') || str_contains($g, 'weight loss')) {
        return 'fat_loss';
    }

    // Casual Lifestyle & General Health
    return 'lifestyle';
}

/**
 * Normalizes raw goal string into a clean, human-readable title
 */
function get_goal_display_title(string $goal): string
{
    $map = [
        'increasing_strength'            => 'Increasing Maximum Strength',
        'building_muscle'                => 'Building Muscle',
        'losing_weight'                  => 'Losing Weight',
        'reducing_body_fat'              => 'Reducing Body Fat',
        'improving_endurance'            => 'Improving Endurance',
        'general_fitness'                => 'Improving General Fitness',
        'casual'                         => 'Casual / Flexible Lifestyle',
        'Building a visible six-pack'    => 'Visible Six-Pack',
        'Growing larger biceps and arms' => 'Larger Biceps & Arms',
        'Developing a wide chest'        => 'Developing a Wide Chest',
        'Sculpting a V-tapered back'     => 'V-Tapered Back',
        'Shaping the lower body'         => 'Lower-Body Development',
        'Gaining lean body mass'         => 'Lean Body Mass',
        'Reaching body recomposition'    => 'Body Recomposition',
    ];

    if (isset($map[$goal])) {
        return $map[$goal];
    }

    $clean = ucwords(str_replace(['_', '-'], ' ', trim($goal)));
    return $clean !== '' ? $clean : 'General Fitness';
}

/**
 * Returns display metadata, badge icons, and category description for a given goal
 */
function get_goal_metadata(string $goal): array
{
    $model = get_goal_metric_model($goal);
    
    $icons = [
        'strength' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>',
        'hypertrophy' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 5v14M18 5v14M6 12h12M3 8v8M21 8v8"/></svg>',
        'endurance' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.42 4.58a5.4 5.4 0 0 0-7.65 0l-.77.78-.77-.78a5.4 5.4 0 0 0-7.65 7.65l.77.78L12 20.65l7.65-7.64.77-.78a5.4 5.4 0 0 0 0-7.65z"/><polyline points="3.5 12 8 12 10 8 13 16 15 12 20.5 12"/></svg>',
        'mobility' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="2"/><path d="M5 20l4-7 3 3 5-7 3 2"/></svg>',
        'fat_loss' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
        'recomposition' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/></svg>',
        'lifestyle' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>'
    ];

    $categoryLabels = [
        'strength' => 'Strength & Performance',
        'hypertrophy' => 'Muscle Hypertrophy & Shape',
        'endurance' => 'Stamina & Cardio Base',
        'mobility' => 'Mobility & Range of Motion',
        'fat_loss' => 'Body Composition & Fat Loss',
        'recomposition' => 'Body Recomposition',
        'lifestyle' => 'Sustainable Habit & Lifestyle'
    ];

    return [
        'model' => $model,
        'title' => get_goal_display_title($goal),
        'category' => $categoryLabels[$model] ?? 'Fitness Goal',
        'icon' => $icons[$model] ?? $icons['lifestyle']
    ];
}

/**
 * Computes accurate progress bar percentage, status, tone, and plain-English note
 * for a single Fitness Target milestone.
 */
function compute_target_bar_progress(
    string $label,
    string $unit,
    ?float $targetVal,
    ?float $currentVal,
    ?float $startVal,
    string $desiredDir = 'neutral'
): ?array {
    if ($targetVal === null || $targetVal <= 0) {
        return null;
    }

    if ($startVal === null || $startVal <= 0) {
        $startVal = $currentVal;
    }

    $fmt = static function (float $v): string {
        $s = number_format($v, 1, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    };

    $pct = 0;
    $status = 'not_started';
    $tone = 'neutral';
    $note = 'No measurement logged yet';

    if ($startVal !== null && $currentVal !== null) {
        $dist = abs($targetVal - $startVal);
        $gap = round($currentVal - $targetVal, 1);

        if ($dist < 0.05) {
            if (abs($gap) <= 0.1) {
                $pct = 100;
                $status = 'reached';
                $tone = 'good';
                $note = 'On target!';
            } else {
                $pct = 0;
                $status = 'off_target';
                $tone = 'bad';
                $note = $fmt(abs($gap)) . ' ' . $unit . ($gap > 0 ? ' above goal' : ' below goal');
            }
        } elseif ($desiredDir === 'down' && $startVal < $targetVal && $currentVal > $targetVal + 0.1) {
            $pct = 0;
            $status = 'off_target';
            $tone = 'bad';
            $note = $fmt($currentVal - $targetVal) . ' ' . $unit . ' above goal';
        } elseif ($desiredDir === 'up' && $startVal > $targetVal && $currentVal < $targetVal - 0.1) {
            $pct = 0;
            $status = 'off_target';
            $tone = 'bad';
            $note = $fmt($targetVal - $currentVal) . ' ' . $unit . ' below goal';
        } else {
            $isDown = ($startVal > $targetVal);
            $covered = $isDown ? ($startVal - $currentVal) : ($currentVal - $startVal);
            $remaining = $isDown ? ($currentVal - $targetVal) : ($targetVal - $currentVal);

            if ($remaining <= 0.1) {
                $overshoot = abs(min(0.0, $remaining));
                if ($overshoot > 0.5) {
                    $pct = 100;
                    $status = 'exceeded';
                    $tone = 'warn';
                    $note = 'Exceeded goal by ' . ($isDown ? '-' : '+') . $fmt($overshoot) . ' ' . $unit;
                } else {
                    $pct = 100;
                    $status = 'reached';
                    $tone = 'good';
                    $note = 'Goal reached!';
                }
            } elseif ($covered > 0.05) {
                $pct = (int) min(99, max(1, round(($covered / $dist) * 100)));
                $status = 'in_progress';
                $tone = 'good';
                $note = $fmt($remaining) . ' ' . $unit . ($isDown ? ' to lose' : ' to gain');
            } elseif ($covered < -0.05) {
                $pct = 0;
                $status = 'off_target';
                $tone = 'bad';
                $note = $fmt($remaining) . ' ' . $unit . ($isDown ? ' to lose' : ' to gain')
                    . ' (' . ($isDown ? '+' : '-') . $fmt(abs($covered)) . ' from start)';
            } else {
                $pct = 0;
                $status = 'not_started';
                $tone = 'neutral';
                $note = $fmt($remaining) . ' ' . $unit . ($isDown ? ' to lose' : ' to gain');
            }
        }
    }

    return [
        'label'   => $label,
        'unit'    => $unit,
        'start'   => $startVal,
        'current' => $currentVal,
        'target'  => $targetVal,
        'pct'     => $pct,
        'status'  => $status,
        'note'    => $note,
        'tone'    => $tone,
    ];
}

/**
 * Returns desired metric directions ('down' | 'up' | 'stable' | 'neutral') tailored to the member's goal.
 */
function get_goal_metric_directions(string $rawGoal, array $profile = []): array
{
    $model = get_goal_metric_model($rawGoal);
    $g = strtolower(trim(str_replace(['_', '-'], ' ', $rawGoal)));

    switch ($model) {
        case 'fat_loss':
            $dirs = [
                'weight'   => 'down',
                'body_fat' => 'down',
                'waist'    => 'down',
                'arm'      => 'neutral',
                'chest'    => 'neutral',
                'bmi'      => 'down',
            ];
            break;

        case 'hypertrophy':
            if (str_contains($g, 'six pack')) {
                $dirs = [
                    'weight'   => 'down',
                    'body_fat' => 'down',
                    'waist'    => 'down',
                    'arm'      => 'up',
                    'chest'    => 'up',
                    'bmi'      => 'neutral',
                ];
            } elseif (str_contains($g, 'biceps') || str_contains($g, 'arms') || str_contains($g, 'chest')) {
                $dirs = [
                    'weight'   => 'up',
                    'body_fat' => 'down',
                    'waist'    => 'down',
                    'arm'      => 'up',
                    'chest'    => 'up',
                    'bmi'      => 'neutral',
                ];
            } else {
                $dirs = [
                    'weight'   => 'up',
                    'body_fat' => 'down',
                    'waist'    => 'neutral',
                    'arm'      => 'up',
                    'chest'    => 'up',
                    'bmi'      => 'neutral',
                ];
            }
            break;

        case 'recomposition':
            $dirs = [
                'weight'   => 'stable',
                'body_fat' => 'down',
                'waist'    => 'down',
                'arm'      => 'up',
                'chest'    => 'up',
                'bmi'      => 'neutral',
            ];
            break;

        case 'strength':
            $dirs = [
                'weight'   => 'neutral',
                'body_fat' => 'down',
                'waist'    => 'neutral',
                'arm'      => 'up',
                'chest'    => 'up',
                'bmi'      => 'neutral',
            ];
            break;

        case 'endurance':
        case 'mobility':
        case 'lifestyle':
        default:
            $dirs = [
                'weight'   => 'neutral',
                'body_fat' => 'down',
                'waist'    => 'down',
                'arm'      => 'neutral',
                'chest'    => 'neutral',
                'bmi'      => 'neutral',
            ];
            break;
    }

    // Refine any 'neutral' direction using explicit configured targets vs baseline
    $targetOverrides = [
        'weight'   => ['target' => 'target_weight_kg', 'base_keys' => ['baseline_weight_kg', 'weight_kg']],
        'body_fat' => ['target' => 'target_body_fat_percent', 'base_keys' => ['baseline_body_fat_percent', 'body_fat_percent']],
        'waist'    => ['target' => 'target_waist_cm', 'base_keys' => ['baseline_waist_cm', 'waist_cm']],
        'arm'      => ['target' => 'target_arm_cm', 'base_keys' => ['baseline_arm_cm', 'arm_cm']],
        'chest'    => ['target' => 'target_chest_cm', 'base_keys' => ['baseline_chest_cm', 'chest_cm']],
    ];
    foreach ($targetOverrides as $metricKey => $cfg) {
        if (($dirs[$metricKey] ?? 'neutral') !== 'neutral') {
            continue;
        }
        $targetVal = !empty($profile[$cfg['target']]) ? (float)$profile[$cfg['target']] : 0.0;
        $baseVal = 0.0;
        foreach ($cfg['base_keys'] as $bk) {
            if (!empty($profile[$bk]) && (float)$profile[$bk] > 0) {
                $baseVal = (float)$profile[$bk];
                break;
            }
        }
        if ($targetVal > 0 && $baseVal > 0 && abs($targetVal - $baseVal) >= 0.5) {
            $dirs[$metricKey] = $targetVal < $baseVal ? 'down' : 'up';
        }
    }

    return $dirs;
}

/**
 * Pure trend analyzer over newest-first progress logs for a single numeric column.
 */
function analyze_progress_metric_series(array $progressLogsDesc, string $column, ?float $targetVal = null): array
{
    $chrono = array_reverse($progressLogsDesc);
    $series = [];
    foreach ($chrono as $row) {
        if (isset($row[$column]) && is_numeric($row[$column]) && (float)$row[$column] > 0) {
            $series[] = [
                'date' => (string)($row['log_date'] ?? ''),
                'val'  => (float)$row[$column],
            ];
        }
    }

    $count = count($series);
    if ($count === 0) {
        return [
            'count'             => 0,
            'first'             => null,
            'first_date'        => null,
            'latest'            => null,
            'latest_date'       => null,
            'recent_start'      => null,
            'recent_start_date' => null,
            'delta'             => null,
            'total_delta'       => null,
            'span_days'         => 0,
            'weekly_rate'       => null,
            'eta_weeks'         => null,
        ];
    }

    $first = $series[0];
    $latest = $series[$count - 1];

    if ($count === 1) {
        return [
            'count'             => 1,
            'first'             => $first['val'],
            'first_date'        => $first['date'],
            'latest'            => $latest['val'],
            'latest_date'       => $latest['date'],
            'recent_start'      => $first['val'],
            'recent_start_date' => $first['date'],
            'delta'             => null,
            'total_delta'       => null,
            'span_days'         => 0,
            'weekly_rate'       => null,
            'eta_weeks'         => null,
        ];
    }

    // Pick the log closest to 30 days before latest_date (most recent log <= latest - 30d; fallback to oldest)
    $latestTs = strtotime($latest['date']);
    $cutoffDate = $latestTs ? date('Y-m-d', strtotime('-30 days', $latestTs)) : '';
    $recentStart = $first;
    if ($cutoffDate !== '') {
        for ($i = $count - 2; $i >= 0; $i--) {
            if ($series[$i]['date'] !== '' && $series[$i]['date'] <= $cutoffDate) {
                $recentStart = $series[$i];
                break;
            }
        }
    }

    $delta = round($latest['val'] - $recentStart['val'], 1);
    $totalDelta = round($latest['val'] - $first['val'], 1);

    $recentTs = strtotime($recentStart['date']);
    $spanDays = ($latestTs && $recentTs) ? max(0, (int)round(($latestTs - $recentTs) / 86400)) : 0;
    $weeklyRate = $spanDays >= 7 ? round($delta / ($spanDays / 7.0), 2) : null;

    $etaWeeks = null;
    if ($targetVal !== null && $targetVal > 0 && $weeklyRate !== null && abs($weeklyRate) >= 0.05) {
        $remaining = $targetVal - $latest['val'];
        if (($remaining > 0 && $weeklyRate > 0) || ($remaining < 0 && $weeklyRate < 0)) {
            $eta = (int)ceil(abs($remaining) / abs($weeklyRate));
            if ($eta >= 1 && $eta <= 104) {
                $etaWeeks = $eta;
            }
        }
    }

    return [
        'count'             => $count,
        'first'             => $first['val'],
        'first_date'        => $first['date'],
        'latest'            => $latest['val'],
        'latest_date'       => $latest['date'],
        'recent_start'      => $recentStart['val'],
        'recent_start_date' => $recentStart['date'],
        'delta'             => $delta,
        'total_delta'       => $totalDelta,
        'span_days'         => $spanDays,
        'weekly_rate'       => $weeklyRate,
        'eta_weeks'         => $etaWeeks,
    ];
}

/**
 * Pure, deterministic, rule-based progress verdict engine (zero DB queries).
 * Produces second-person phrasing for members and third-person phrasing for trainers/admins.
 */
function evaluate_member_progress_verdict(array $profile, array $progressLogs, array $context = []): array
{
    $rawGoal = (string)($profile['primary_goal'] ?? 'general_fitness');
    $model = get_goal_metric_model($rawGoal);
    $goalTitle = get_goal_display_title($rawGoal);
    $g = strtolower(trim(str_replace(['_', '-'], ' ', $rawGoal)));

    $isManagerView = !empty($context['is_manager_view']);
    $memberName = trim((string)($context['member_name'] ?? $profile['first_name'] ?? ''));
    if ($memberName === '') {
        $memberName = 'This member';
    }

    // Perspective helpers (member = 2nd person, trainer/admin = 3rd person)
    $subj = $isManagerView ? $memberName : 'You';
    $subjIs = $isManagerView ? ($memberName . ' is') : 'You are';
    $subjHas = $isManagerView ? ($memberName . ' has') : 'You have';
    $poss = $isManagerView ? ($memberName . "'s") : 'your';
    $possCap = $isManagerView ? ($memberName . "'s") : 'Your';

    $targetWeight = !empty($profile['target_weight_kg']) ? (float)$profile['target_weight_kg'] : 0.0;
    $targetBf = !empty($profile['target_body_fat_percent']) ? (float)$profile['target_body_fat_percent'] : 0.0;
    $targetWaist = !empty($profile['target_waist_cm']) ? (float)$profile['target_waist_cm'] : 0.0;
    $targetArm = !empty($profile['target_arm_cm']) ? (float)$profile['target_arm_cm'] : 0.0;
    $targetChest = !empty($profile['target_chest_cm']) ? (float)$profile['target_chest_cm'] : 0.0;

    $weightSeries = analyze_progress_metric_series($progressLogs, 'weight_kg', $targetWeight > 0 ? $targetWeight : null);
    $bfSeries = analyze_progress_metric_series($progressLogs, 'body_fat_percent', $targetBf > 0 ? $targetBf : null);
    $waistSeries = analyze_progress_metric_series($progressLogs, 'waist_cm', $targetWaist > 0 ? $targetWaist : null);
    $armSeries = analyze_progress_metric_series($progressLogs, 'arm_cm', $targetArm > 0 ? $targetArm : null);
    $chestSeries = analyze_progress_metric_series($progressLogs, 'chest_cm', $targetChest > 0 ? $targetChest : null);

    // Enrich profile with earliest logged baseline so neutral goals can infer target direction cleanly
    $dirProfile = $profile;
    if (empty($dirProfile['baseline_weight_kg']) && $weightSeries['first'] !== null) {
        $dirProfile['baseline_weight_kg'] = $weightSeries['first'];
    }
    if (empty($dirProfile['baseline_body_fat_percent']) && $bfSeries['first'] !== null) {
        $dirProfile['baseline_body_fat_percent'] = $bfSeries['first'];
    }
    if (empty($dirProfile['baseline_waist_cm']) && $waistSeries['first'] !== null) {
        $dirProfile['baseline_waist_cm'] = $waistSeries['first'];
    }
    if (empty($dirProfile['baseline_arm_cm']) && $armSeries['first'] !== null) {
        $dirProfile['baseline_arm_cm'] = $armSeries['first'];
    }
    if (empty($dirProfile['baseline_chest_cm']) && $chestSeries['first'] !== null) {
        $dirProfile['baseline_chest_cm'] = $chestSeries['first'];
    }
    $directions = get_goal_metric_directions($rawGoal, $dirProfile);

    $workoutsThisWeek = (int)($context['workouts_this_week'] ?? 0);
    $weeklyTarget = max(1, (int)($context['weekly_target'] ?? ($profile['weekly_workout_target'] ?? 3)));
    $weeklyPct = min(100, (int)round(($workoutsThisWeek / $weeklyTarget) * 100));
    $checkins30d = (int)($context['checkins_30d'] ?? 0);
    $exercises30d = (int)($context['exercises_30d'] ?? 0);

    $strengthTargets = is_array($context['strength_targets'] ?? null) ? $context['strength_targets'] : [];
    $liftedExercises = is_array($context['lifted_exercises'] ?? null) ? $context['lifted_exercises'] : [];

    $nutrition = is_array($context['nutrition'] ?? null) ? $context['nutrition'] : [];
    $nutDays = (int)($nutrition['days_logged'] ?? 0);
    $avgCals = (int)($nutrition['avg_cals'] ?? 0);
    $targetCals = (int)($nutrition['target_cals'] ?? 0);
    $avgPro = (float)($nutrition['avg_protein'] ?? 0);
    $targetPro = (float)($nutrition['target_protein'] ?? 0);
    $calDiff = ($nutDays > 0 && $targetCals > 0 && $avgCals > 0) ? ($avgCals - $targetCals) : null;

    $statusDefs = [
        'goal_reached' => [
            'code'         => 'goal_reached',
            'label'        => 'Goal Reached',
            'short_answer' => 'Yes — Goal Reached',
            'icon'         => '🏆',
            'badge_cls'    => 'goal-status-ontrack',
            'verdict_cls'  => 'verdict-reached',
            'color'        => '#22c55e',
        ],
        'progressing' => [
            'code'         => 'progressing',
            'label'        => 'Progressing',
            'short_answer' => 'Yes — Progressing',
            'icon'         => '↗',
            'badge_cls'    => 'goal-status-ontrack',
            'verdict_cls'  => 'verdict-progressing',
            'color'        => '#22c55e',
        ],
        'plateau' => [
            'code'         => 'plateau',
            'label'        => 'Plateau / Maintaining',
            'short_answer' => 'Maintaining / Plateau',
            'icon'         => '→',
            'badge_cls'    => 'goal-status-attention',
            'verdict_cls'  => 'verdict-plateau',
            'color'        => '#eab308',
        ],
        'off_track' => [
            'code'         => 'off_track',
            'label'        => 'Off Track',
            'short_answer' => 'Not Progressing Yet',
            'icon'         => '↘',
            'badge_cls'    => 'goal-status-offtrack',
            'verdict_cls'  => 'verdict-offtrack',
            'color'        => '#ef4444',
        ],
        'needs_data' => [
            'code'         => 'needs_data',
            'label'        => 'Needs More Data',
            'short_answer' => 'Not Enough Data Yet',
            'icon'         => '○',
            'badge_cls'    => 'goal-status-calibrating',
            'verdict_cls'  => 'verdict-needs-data',
            'color'        => '#94a3b8',
        ],
    ];

    $fmtSigned = static function (float $v, string $unit = ''): string {
        $sign = $v > 0 ? '+' : '';
        return $sign . number_format($v, 1) . ($unit !== '' ? ' ' . $unit : '');
    };

    $currWeight = $weightSeries['latest'] ?? (!empty($profile['weight_kg']) ? (float)$profile['weight_kg'] : 0.0);
    $currBf = $bfSeries['latest'] ?? (!empty($profile['body_fat_percent']) ? (float)$profile['body_fat_percent'] : 0.0);
    $currWaist = $waistSeries['latest'] ?? (!empty($profile['waist_cm']) ? (float)$profile['waist_cm'] : 0.0);
    $currArm = $armSeries['latest'] ?? (!empty($profile['arm_cm']) ? (float)$profile['arm_cm'] : 0.0);
    $currChest = $chestSeries['latest'] ?? (!empty($profile['chest_cm']) ? (float)$profile['chest_cm'] : 0.0);

    $statusCode = 'needs_data';
    $headline = '';
    $explanation = '';
    $nextStep = '';
    $primarySignal = null;
    $paceSignal = null;

    // Helper to explain nutrition/workout drivers when plateaued or off track
    $buildDriverSentence = static function (string $goalMode) use (
        $calDiff, $avgCals, $targetCals, $avgPro, $targetPro, $nutDays,
        $workoutsThisWeek, $weeklyTarget, $weeklyPct, $checkins30d, $subjIs, $subjHas, $poss
    ): string {
        $reasons = [];
        if ($calDiff !== null) {
            if ($goalMode === 'deficit' && $calDiff > 200) {
                $reasons[] = "logged calories average " . number_format($avgCals) . " kcal vs a " . number_format($targetCals) . " kcal target (+" . number_format($calDiff) . " kcal over)";
            } elseif ($goalMode === 'surplus' && $calDiff < -200) {
                $reasons[] = "logged calories average " . number_format($avgCals) . " kcal vs a " . number_format($targetCals) . " kcal target (" . number_format($calDiff) . " kcal under)";
            }
        }
        if ($nutDays > 0 && $targetPro > 0 && $avgPro > 0 && $avgPro < ($targetPro * 0.8)) {
            $reasons[] = "protein intake is averaging " . round($avgPro) . "g vs " . round($targetPro) . "g target";
        }
        if ($workoutsThisWeek === 0 && $checkins30d < 4) {
            $reasons[] = "{$subjHas} low recent workout attendance ({$workoutsThisWeek}/{$weeklyTarget} this week, {$checkins30d} visits in 30d)";
        } elseif ($weeklyPct < 60 && $checkins30d < ($weeklyTarget * 2)) {
            $reasons[] = "weekly training is below target ({$workoutsThisWeek}/{$weeklyTarget} sessions)";
        }
        if (empty($reasons)) {
            return '';
        }
        return ' Likely factor: ' . ucfirst(implode(' and ', $reasons)) . '.';
    };

    switch ($model) {
        case 'fat_loss':
            $wDelta = $weightSeries['delta'];
            $waistDelta = $waistSeries['delta'];
            $bfDelta = $bfSeries['delta'];

            if ($targetWeight > 0 && $currWeight > 0 && $currWeight <= $targetWeight) {
                $statusCode = 'goal_reached';
                $headline = "{$subjHas} reached the {$targetWeight} kg target weight";
                $explanation = "Current weight is " . number_format($currWeight, 1) . " kg (target: {$targetWeight} kg)" . ($wDelta !== null ? ", down " . abs($wDelta) . " kg in the recent window" : "") . ".";
                $nextStep = $isManagerView
                    ? "Review maintenance calories or set a new body composition target for {$memberName}."
                    : "Switch to maintenance calories or set a new fitness target to keep progressing.";
                $primarySignal = ['label' => 'Weight', 'value' => number_format($currWeight, 1) . " / {$targetWeight} kg", 'tone' => 'good'];
            } elseif ($targetWeight <= 0 && $targetBf > 0 && $currBf > 0 && $currBf <= $targetBf) {
                $statusCode = 'goal_reached';
                $headline = "{$subjHas} reached the {$targetBf}% body fat target";
                $explanation = "Body fat is currently " . number_format($currBf, 1) . "%, meeting the {$targetBf}% milestone.";
                $nextStep = $isManagerView ? "Transition {$memberName} to a maintenance or lean-gain phase." : "Transition to maintenance or set your next body composition milestone.";
                $primarySignal = ['label' => 'Body Fat', 'value' => number_format($currBf, 1) . " / {$targetBf}%", 'tone' => 'good'];
            } elseif ($weightSeries['count'] < 2 && $waistSeries['count'] < 2 && $bfSeries['count'] < 2) {
                $statusCode = 'needs_data';
                $headline = "At least 2 measurement logs are needed to confirm {$poss} fat-loss trend";
                $explanation = "Only " . max($weightSeries['count'], $waistSeries['count'], $bfSeries['count']) . " log is recorded so far. One check-in establishes a baseline, not a trend.";
                $nextStep = $isManagerView
                    ? "Have {$memberName} log another weigh-in or waist check in 5–7 days."
                    : "Log another morning weigh-in and waist measurement in 5–7 days to unlock trend analysis.";
            } else {
                $isDroppingWeight = ($wDelta !== null && $wDelta <= -0.3);
                $isDroppingWaist = ($waistDelta !== null && $waistDelta <= -0.5);
                $isDroppingBf = ($bfDelta !== null && $bfDelta <= -0.4);

                $isRisingWeight = ($wDelta !== null && $wDelta >= 0.4);
                $isRisingWaist = ($waistDelta !== null && $waistDelta >= 0.8);
                $isRisingBf = ($bfDelta !== null && $bfDelta >= 0.6);

                if ($isDroppingWeight || $isDroppingWaist || $isDroppingBf) {
                    $statusCode = 'progressing';
                    $parts = [];
                    if ($isDroppingWeight) {
                        $parts[] = "weight changed by " . $fmtSigned($wDelta, 'kg');
                        $primarySignal = ['label' => 'Weight Trend', 'value' => $fmtSigned($wDelta, 'kg'), 'tone' => 'good'];
                    }
                    if ($isDroppingWaist) {
                        $parts[] = "waist trimmed by " . $fmtSigned($waistDelta, 'cm');
                        if (!$primarySignal) {
                            $primarySignal = ['label' => 'Waist Trend', 'value' => $fmtSigned($waistDelta, 'cm'), 'tone' => 'good'];
                        }
                    }
                    if ($isDroppingBf) {
                        $parts[] = "body fat shifted by " . $fmtSigned($bfDelta, '%');
                        if (!$primarySignal) {
                            $primarySignal = ['label' => 'Body Fat Trend', 'value' => $fmtSigned($bfDelta, '%'), 'tone' => 'good'];
                        }
                    }
                    $headline = "{$subjIs} progressing steadily toward {$poss} {$goalTitle} goal";
                    $explanation = ucfirst(implode(' and ', $parts)) . " over the recent tracking window.";
                    if ($weightSeries['weekly_rate'] !== null && $weightSeries['weekly_rate'] < 0) {
                        $rateStr = number_format(abs($weightSeries['weekly_rate']), 2) . ' kg/wk';
                        $explanation .= " Average pace is -{$rateStr}.";
                        $paceSignal = ['label' => 'Pace', 'value' => "-{$rateStr}", 'tone' => 'good'];
                        if ($weightSeries['eta_weeks'] !== null && $targetWeight > 0) {
                            $explanation .= " At this pace, {$subjIs} ~{$weightSeries['eta_weeks']} weeks from {$targetWeight} kg.";
                            $paceSignal = ['label' => 'Est. ETA', 'value' => "~{$weightSeries['eta_weeks']} wks (-{$rateStr})", 'tone' => 'good'];
                        }
                    }
                    $nextStep = $isManagerView
                        ? "Keep {$memberName} on the current caloric deficit and weekly training split."
                        : "Stay consistent with your current calorie target and weekly training sessions.";
                } elseif ($isRisingWeight || $isRisingWaist || $isRisingBf) {
                    $statusCode = 'off_track';
                    $parts = [];
                    if ($isRisingWeight) {
                        $parts[] = "weight increased by " . $fmtSigned($wDelta, 'kg');
                        $primarySignal = ['label' => 'Weight Trend', 'value' => $fmtSigned($wDelta, 'kg'), 'tone' => 'bad'];
                    } elseif ($isRisingWaist) {
                        $parts[] = "waist increased by " . $fmtSigned($waistDelta, 'cm');
                        $primarySignal = ['label' => 'Waist Trend', 'value' => $fmtSigned($waistDelta, 'cm'), 'tone' => 'bad'];
                    } else {
                        $parts[] = "body fat increased by " . $fmtSigned($bfDelta, '%');
                        $primarySignal = ['label' => 'Body Fat Trend', 'value' => $fmtSigned($bfDelta, '%'), 'tone' => 'bad'];
                    }
                    $headline = "Metrics are moving away from {$poss} {$goalTitle} target";
                    $explanation = ucfirst(implode(' and ', $parts)) . " over the recent window." . $buildDriverSentence('deficit');
                    if ($calDiff !== null && $calDiff > 200) {
                        $nextStep = $isManagerView
                            ? "Review {$memberName}'s diet adherence and trim daily intake closer to {$targetCals} kcal."
                            : "Review your Food Diary to bring daily intake closer to your {$targetCals} kcal target.";
                    } else {
                        $nextStep = $isManagerView
                            ? "Audit {$memberName}'s calorie logging accuracy and increase weekly conditioning volume."
                            : "Double-check portion logging and aim for {$weeklyTarget} workouts this week.";
                    }
                } else {
                    $statusCode = 'plateau';
                    $headline = "{$subjIs} maintaining rather than losing fat right now";
                    $deltaNote = $wDelta !== null ? "Weight changed by only " . $fmtSigned($wDelta, 'kg') : "Body measurements have barely shifted";
                    $explanation = "{$deltaNote} across the recent tracking window, indicating a plateau." . $buildDriverSentence('deficit');
                    $primarySignal = $wDelta !== null
                        ? ['label' => 'Weight Trend', 'value' => $fmtSigned($wDelta, 'kg') . ' (flat)', 'tone' => 'warn']
                        : ['label' => 'Waist Trend', 'value' => $waistDelta !== null ? $fmtSigned($waistDelta, 'cm') : 'Flat', 'tone' => 'warn'];
                    $nextStep = ($calDiff !== null && $calDiff > 150)
                        ? ($isManagerView ? "Trim {$memberName}'s daily surplus (+{$calDiff} kcal) back to {$targetCals} kcal." : "Reduce daily intake by ~{$calDiff} kcal to re-establish a calorie deficit.")
                        : ($isManagerView ? "Consider a modest 150–250 kcal reduction or an extra cardio session for {$memberName}." : "Tighten calorie tracking for 7 days or add 1 extra conditioning session.");
                }
            }
            break;

        case 'hypertrophy':
            $isSixPack = str_contains($g, 'six pack');
            $isArmFocus = str_contains($g, 'biceps') || str_contains($g, 'arms');
            $isChestFocus = str_contains($g, 'chest');

            if ($isSixPack) {
                $pSeries = $waistSeries;
                $pCurr = $currWaist;
                $pTarget = $targetWaist;
                $pLabel = 'Waist';
                $pUnit = 'cm';
                $pDir = 'down';
                $sSeries = $bfSeries;
                $sLabel = 'Body Fat';
                $sUnit = '%';
                $sDir = 'down';
            } elseif ($isArmFocus) {
                $pSeries = $armSeries;
                $pCurr = $currArm;
                $pTarget = $targetArm;
                $pLabel = 'Arms';
                $pUnit = 'cm';
                $pDir = 'up';
                $sSeries = $weightSeries;
                $sLabel = 'Weight';
                $sUnit = 'kg';
                $sDir = 'up';
            } elseif ($isChestFocus) {
                $pSeries = $chestSeries;
                $pCurr = $currChest;
                $pTarget = $targetChest;
                $pLabel = 'Chest';
                $pUnit = 'cm';
                $pDir = 'up';
                $sSeries = $weightSeries;
                $sLabel = 'Weight';
                $sUnit = 'kg';
                $sDir = 'up';
            } else {
                $pSeries = $weightSeries;
                $pCurr = $currWeight;
                $pTarget = $targetWeight;
                $pLabel = 'Weight';
                $pUnit = 'kg';
                $pDir = 'up';
                $sSeries = $armSeries['count'] >= $chestSeries['count'] ? $armSeries : $chestSeries;
                $sLabel = $armSeries['count'] >= $chestSeries['count'] ? 'Arms' : 'Chest';
                $sUnit = 'cm';
                $sDir = 'up';
            }

            $goalHit = ($pTarget > 0 && $pCurr > 0) && (($pDir === 'down' && $pCurr <= $pTarget) || ($pDir === 'up' && $pCurr >= $pTarget));
            if ($goalHit) {
                $statusCode = 'goal_reached';
                $headline = "{$subjHas} reached the {$pTarget} {$pUnit} {$pLabel} target";
                $explanation = "Current {$pLabel} measurement is " . number_format($pCurr, 1) . " {$pUnit}, meeting the {$pTarget} {$pUnit} milestone.";
                $nextStep = $isManagerView ? "Set a higher progressive milestone for {$memberName}." : "Set your next target milestone to keep building momentum.";
                $primarySignal = ['label' => $pLabel, 'value' => number_format($pCurr, 1) . " / {$pTarget} {$pUnit}", 'tone' => 'good'];
            } elseif ($pSeries['count'] < 2 && $sSeries['count'] < 2) {
                $statusCode = 'needs_data';
                $headline = "More {$pLabel} logs are needed to verify {$poss} {$goalTitle} progress";
                $explanation = "{$subjHas} completed {$workoutsThisWeek}/{$weeklyTarget} workouts this week, but fewer than 2 {$pLabel} measurements are logged to confirm physical growth.";
                $nextStep = $isManagerView
                    ? "Log {$memberName}'s {$pLabel} measurement again in 1–2 weeks to verify growth."
                    : "Log your {$pLabel} measurement again in 1–2 weeks to verify progress.";
            } else {
                $pDelta = $pSeries['delta'];
                $sDelta = $sSeries['delta'];
                $goodThreshold = ($pUnit === 'cm') ? ($pDir === 'down' ? -0.5 : 0.3) : 0.3;
                $badThreshold = ($pUnit === 'cm') ? ($pDir === 'down' ? 0.6 : -0.4) : -0.5;

                $pGood = $pDelta !== null && (($pDir === 'down' && $pDelta <= $goodThreshold) || ($pDir === 'up' && $pDelta >= $goodThreshold));
                $pBad = $pDelta !== null && (($pDir === 'down' && $pDelta >= $badThreshold) || ($pDir === 'up' && $pDelta <= $badThreshold));

                $sGood = false;
                $sBad = false;
                if ($sDelta !== null) {
                    $sGoodThresh = ($sUnit === 'cm') ? ($sDir === 'down' ? -0.5 : 0.3) : ($sUnit === '%' ? -0.4 : 0.3);
                    $sBadThresh = ($sUnit === 'cm') ? ($sDir === 'down' ? 0.6 : -0.4) : ($sUnit === '%' ? 0.6 : -0.5);
                    $sGood = ($sDir === 'down') ? ($sDelta <= $sGoodThresh) : ($sDelta >= $sGoodThresh);
                    $sBad = ($sDir === 'down') ? ($sDelta >= $sBadThresh) : ($sDelta <= $sBadThresh);
                }

                if ($pGood || (!$pBad && $sGood)) {
                    $statusCode = 'progressing';
                    $usedLabel = $pGood ? $pLabel : $sLabel;
                    $usedDelta = $pGood ? $pDelta : $sDelta;
                    $usedUnit = $pGood ? $pUnit : $sUnit;
                    $headline = "{$subjIs} making measurable {$goalTitle} progress";
                    $explanation = "{$usedLabel} changed by " . $fmtSigned($usedDelta, $usedUnit) . " over the recent window, supported by {$workoutsThisWeek}/{$weeklyTarget} workouts this week.";
                    $primarySignal = ['label' => "{$usedLabel} Trend", 'value' => $fmtSigned($usedDelta, $usedUnit), 'tone' => 'good'];
                    if ($pGood && $pSeries['eta_weeks'] !== null && $pTarget > 0) {
                        $explanation .= " At this pace, {$subjIs} ~{$pSeries['eta_weeks']} weeks from {$pTarget} {$pUnit}.";
                        $paceSignal = ['label' => 'Est. ETA', 'value' => "~{$pSeries['eta_weeks']} wks", 'tone' => 'good'];
                    }
                    $nextStep = $isManagerView
                        ? "Keep progressing working sets and protein intake for {$memberName}."
                        : "Keep applying progressive overload and hitting your daily protein target.";
                } elseif ($pBad || $sBad) {
                    $statusCode = 'off_track';
                    $usedLabel = $pBad ? $pLabel : $sLabel;
                    $usedDelta = $pBad ? $pDelta : $sDelta;
                    $usedUnit = $pBad ? $pUnit : $sUnit;
                    $headline = "{$usedLabel} is trending opposite to {$poss} {$goalTitle} goal";
                    $explanation = "{$usedLabel} shifted by " . $fmtSigned($usedDelta, $usedUnit) . " in the recent tracking window." . $buildDriverSentence($isSixPack ? 'deficit' : 'surplus');
                    $primarySignal = ['label' => "{$usedLabel} Trend", 'value' => $fmtSigned($usedDelta, $usedUnit), 'tone' => 'bad'];
                    $nextStep = $isSixPack
                        ? ($isManagerView ? "Dial in {$memberName}'s calorie deficit and core training consistency." : "Tighten your calorie deficit and hit {$weeklyTarget} weekly workouts.")
                        : ($isManagerView ? "Check {$memberName}'s calorie surplus and protein intake to support tissue growth." : "Ensure you are eating in a slight calorie surplus with adequate protein.");
                } else {
                    $statusCode = 'plateau';
                    $usedDelta = $pDelta ?? $sDelta;
                    $usedLabel = $pDelta !== null ? $pLabel : $sLabel;
                    $usedUnit = $pDelta !== null ? $pUnit : $sUnit;
                    $headline = "{$usedLabel} has plateaued over the recent tracking window";
                    $explanation = "{$usedLabel} changed by only " . ($usedDelta !== null ? $fmtSigned($usedDelta, $usedUnit) : '0.0 ' . $usedUnit) . " recently." . $buildDriverSentence($isSixPack ? 'deficit' : 'surplus');
                    $primarySignal = ['label' => "{$usedLabel} Trend", 'value' => ($usedDelta !== null ? $fmtSigned($usedDelta, $usedUnit) : 'Flat') . ' (flat)', 'tone' => 'warn'];
                    $nextStep = $isSixPack
                        ? ($isManagerView ? "Slightly tighten {$memberName}'s calorie intake or add conditioning." : "Review calorie adherence or add 1 extra conditioning session this week.")
                        : ($isManagerView ? "Increase training volume slightly or bump {$memberName}'s daily calories by ~150–200 kcal." : "Push progressive overload on key lifts and add ~150–200 kcal if weight is flat.");
                }
            }
            break;

        case 'recomposition':
            $wDelta = $weightSeries['delta'];
            $waistDelta = $waistSeries['delta'];
            $bfDelta = $bfSeries['delta'];
            $strengthImproved = false;
            foreach ($strengthTargets as $st) {
                if ((float)($st['current_max_kg'] ?? 0) > (float)($st['start_max_kg'] ?? 0)) {
                    $strengthImproved = true;
                    break;
                }
            }

            if ($waistSeries['count'] < 2 && $bfSeries['count'] < 2 && $weightSeries['count'] < 2 && !$strengthImproved) {
                $statusCode = 'needs_data';
                $headline = "Log at least 2 waist or body-fat entries to evaluate recomposition";
                $explanation = "Body recomposition cannot be judged from scale weight alone; at least 2 circumference or body-fat logs are needed.";
                $nextStep = $isManagerView
                    ? "Have {$memberName} log waist and body-fat measurements every 1–2 weeks."
                    : "Log your waist and body-fat % every 1–2 weeks alongside strength sessions.";
            } elseif (($waistDelta !== null && $waistDelta <= -0.5) || ($bfDelta !== null && $bfDelta <= -0.4) || ($wDelta !== null && abs($wDelta) <= 1.0 && $strengthImproved)) {
                $statusCode = 'progressing';
                $headline = "{$subjIs} successfully recomposing";
                $parts = [];
                if ($waistDelta !== null && $waistDelta <= -0.5) {
                    $parts[] = "waist dropped " . $fmtSigned($waistDelta, 'cm');
                    $primarySignal = ['label' => 'Waist Trend', 'value' => $fmtSigned($waistDelta, 'cm'), 'tone' => 'good'];
                }
                if ($bfDelta !== null && $bfDelta <= -0.4) {
                    $parts[] = "body fat dropped " . $fmtSigned($bfDelta, '%');
                    if (!$primarySignal) {
                        $primarySignal = ['label' => 'Body Fat Trend', 'value' => $fmtSigned($bfDelta, '%'), 'tone' => 'good'];
                    }
                }
                if ($wDelta !== null && abs($wDelta) <= 1.0) {
                    $parts[] = "scale weight stayed steady (" . $fmtSigned($wDelta, 'kg') . ")";
                }
                if ($strengthImproved) {
                    $parts[] = "strength targets increased";
                    if (!$primarySignal) {
                        $primarySignal = ['label' => 'Strength', 'value' => 'PRs rising', 'tone' => 'good'];
                    }
                }
                $explanation = ucfirst(implode(', ', $parts)) . " — indicating fat loss alongside lean mass retention.";
                $nextStep = $isManagerView
                    ? "Keep {$memberName} near maintenance calories with high protein and progressive resistance training."
                    : "Keep protein high and continue progressive overload while staying near maintenance calories.";
            } elseif (($waistDelta !== null && $waistDelta >= 0.8) || ($bfDelta !== null && $bfDelta >= 0.6)) {
                $statusCode = 'off_track';
                $headline = "Waist or body fat is creeping up instead of leaning out";
                $metricText = ($waistDelta !== null && $waistDelta >= 0.8)
                    ? "Waist increased by " . $fmtSigned($waistDelta, 'cm')
                    : "Body fat increased by " . $fmtSigned($bfDelta, '%');
                $explanation = "{$metricText} in the recent tracking window." . $buildDriverSentence('deficit');
                $primarySignal = ($waistDelta !== null && $waistDelta >= 0.8)
                    ? ['label' => 'Waist Trend', 'value' => $fmtSigned($waistDelta, 'cm'), 'tone' => 'bad']
                    : ['label' => 'Body Fat Trend', 'value' => $fmtSigned($bfDelta, '%'), 'tone' => 'bad'];
                $nextStep = $isManagerView
                    ? "Bring {$memberName}'s daily calories back to maintenance or a slight deficit with high protein."
                    : "Trim excess calories toward maintenance and prioritize high-protein meals.";
            } else {
                $statusCode = 'plateau';
                $headline = "Body composition markers are holding steady";
                $explanation = "Scale weight and waist/body-fat measurements have remained flat in the recent window." . $buildDriverSentence('deficit');
                $primarySignal = ['label' => 'Composition', 'value' => 'Holding steady', 'tone' => 'warn'];
                $nextStep = $isManagerView
                    ? "Progressively increase training intensity or adjust {$memberName}'s calorie/protein split."
                    : "Focus on adding reps or weight to compound lifts while keeping protein at 1.6–2.2g/kg.";
            }
            break;

        case 'strength':
            $hasTargets = !empty($strengthTargets);
            $allMet = false;
            $anyImproved = false;
            $bestTargetSummary = null;

            if ($hasTargets) {
                $metCount = 0;
                foreach ($strengthTargets as $st) {
                    $startKg = isset($st['start_max_kg']) && $st['start_max_kg'] !== null ? (float)$st['start_max_kg'] : (float)($st['current_max_kg'] ?? 0);
                    $currKg = (float)($st['current_max_kg'] ?? 0);
                    $tgtKg = (float)($st['target_max_kg'] ?? 0);
                    if ($tgtKg > 0 && $currKg >= $tgtKg) {
                        $metCount++;
                    }
                    if ($currKg > $startKg) {
                        $anyImproved = true;
                        $bestTargetSummary = ($st['exercise_name'] ?? 'Lift') . ": {$startKg} → {$currKg} kg";
                    } elseif ($bestTargetSummary === null && $tgtKg > 0) {
                        $bestTargetSummary = ($st['exercise_name'] ?? 'Lift') . ": {$currKg} / {$tgtKg} kg";
                    }
                }
                $allMet = ($metCount > 0 && $metCount === count($strengthTargets));
            } else {
                $currMax = !empty($profile['current_strength_max_kg']) ? (float)$profile['current_strength_max_kg'] : 0.0;
                $tgtMax = !empty($profile['target_strength_max_kg']) ? (float)$profile['target_strength_max_kg'] : 0.0;
                if ($tgtMax > 0 && $currMax >= $tgtMax) {
                    $allMet = true;
                    $bestTargetSummary = ($profile['target_exercise'] ?? 'Primary Lift') . ": {$currMax} / {$tgtMax} kg";
                }
            }

            if ($allMet) {
                $statusCode = 'goal_reached';
                $headline = "{$subjHas} hit all configured strength PR targets";
                $explanation = ($bestTargetSummary ? $bestTargetSummary . '. ' : '') . "All active strength milestones have been achieved.";
                $nextStep = $isManagerView ? "Set new progressive PR targets for {$memberName}." : "Set a higher target weight on your core lifts to keep progressing.";
                $primarySignal = ['label' => 'Strength PRs', 'value' => 'Target achieved', 'tone' => 'good'];
            } elseif ($anyImproved || (!empty($liftedExercises) && $weeklyPct >= 75)) {
                $statusCode = 'progressing';
                $headline = "{$subjIs} progressing in strength performance";
                if ($anyImproved && $bestTargetSummary) {
                    $explanation = "Logged working max improved ({$bestTargetSummary}), backed by {$workoutsThisWeek}/{$weeklyTarget} workouts this week.";
                    $primarySignal = ['label' => 'Lift PR', 'value' => $bestTargetSummary, 'tone' => 'good'];
                } else {
                    $explanation = count($liftedExercises) . " exercise lift(s) recorded with {$workoutsThisWeek}/{$weeklyTarget} weekly sessions completed.";
                    $primarySignal = ['label' => 'Logged Lifts', 'value' => count($liftedExercises) . ' exercises', 'tone' => 'good'];
                }
                $nextStep = $isManagerView
                    ? "Continue progressive overload and log updated working weights when PRs increase."
                    : "Keep adding 1–2.5 kg or extra reps when top sets feel controlled, and log new PRs.";
            } elseif (!$hasTargets && empty($liftedExercises) && $workoutsThisWeek === 0 && $checkins30d < 2) {
                $statusCode = 'needs_data';
                $headline = "No strength PRs or recent workout lifts logged yet";
                $explanation = "Add a strength target or log weights during workouts so progress can be measured accurately.";
                $nextStep = $isManagerView
                    ? "Set a primary lift target for {$memberName} via Adjust Targets."
                    : "Click \"Set Targets\" to add a lift goal (e.g. Bench Press or Squat) and log your current max.";
            } elseif ($weeklyPct >= 50 || $checkins30d >= 4) {
                $statusCode = 'plateau';
                $headline = "Training is active, but no new strength PRs are logged yet";
                $explanation = "{$subjHas} completed {$workoutsThisWeek}/{$weeklyTarget} sessions this week ({$checkins30d} in 30d), with working maxes unchanged so far.";
                $primarySignal = ['label' => 'Lift Status', 'value' => $bestTargetSummary ?? 'No PR change', 'tone' => 'warn'];
                $nextStep = $isManagerView
                    ? "Encourage {$memberName} to log updated lift PRs or adjust rep/set intensity."
                    : "Log any new lift weights using \"+ Log PR\" or add 2.5 kg to your top compound set.";
            } else {
                $statusCode = 'off_track';
                $headline = "Low workout frequency is holding back strength gains";
                $explanation = "Only {$workoutsThisWeek}/{$weeklyTarget} sessions completed this week ({$checkins30d} visits in 30d), below what is needed for steady strength progression.";
                $primarySignal = ['label' => 'Lift Status', 'value' => 'Low volume', 'tone' => 'bad'];
                $nextStep = $isManagerView
                    ? "Check in with {$memberName} to remove scheduling barriers and rebuild weekly consistency."
                    : "Aim to complete at least {$weeklyTarget} structured strength sessions this week.";
            }
            break;

        case 'endurance':
        case 'mobility':
        case 'lifestyle':
        default:
            $enduranceHit = false;
            if ($model === 'endurance') {
                $currDist = !empty($profile['current_endurance_distance_km']) ? (float)$profile['current_endurance_distance_km'] : 0.0;
                $tgtDist = !empty($profile['target_endurance_distance_km']) ? (float)$profile['target_endurance_distance_km'] : 0.0;
                $currTime = !empty($profile['current_endurance_time_mins']) ? (int)$profile['current_endurance_time_mins'] : 0;
                $tgtTime = !empty($profile['target_endurance_time_mins']) ? (int)$profile['target_endurance_time_mins'] : 0;
                $enduranceHit = ($tgtDist > 0 && $currDist >= $tgtDist) || ($tgtTime > 0 && $currTime >= $tgtTime);
            }

            if ($enduranceHit) {
                $statusCode = 'goal_reached';
                $headline = "{$subjHas} reached {$poss} endurance milestone";
                $explanation = "Cardio performance metrics meet or exceed the configured distance/duration target.";
                $nextStep = $isManagerView ? "Increase {$memberName}'s target pace or distance." : "Raise your distance or pace target for the next training block.";
                $primarySignal = ['label' => 'Endurance', 'value' => 'Target achieved', 'tone' => 'good'];
            } elseif ($checkins30d === 0 && $workoutsThisWeek === 0 && count($progressLogs) < 2) {
                $statusCode = 'needs_data';
                $headline = "Not enough recent activity or logs to assess {$poss} routine";
                $explanation = "Zero workouts are logged this week and fewer than 2 progress check-ins exist.";
                $nextStep = $isManagerView
                    ? "Help {$memberName} schedule their first {$weeklyTarget} sessions for this week."
                    : "Complete a workout check-in or log baseline measurements to start tracking progress.";
            } elseif ($weeklyPct >= 75 || $checkins30d >= max(6, $weeklyTarget * 2)) {
                $statusCode = 'progressing';
                $headline = "{$subjIs} building strong consistency toward {$goalTitle}";
                $explanation = "{$subjHas} completed {$workoutsThisWeek}/{$weeklyTarget} sessions this week ({$weeklyPct}%) and {$checkins30d} gym visits in the last 30 days.";
                $primarySignal = ['label' => '30d Activity', 'value' => "{$checkins30d} visits", 'tone' => 'good'];
                $nextStep = $isManagerView
                    ? "Keep reinforcing {$memberName}'s weekly rhythm."
                    : "Keep up this weekly cadence to lock in long-term fitness gains.";
            } elseif ($weeklyPct >= 40 || $checkins30d >= 3) {
                $statusCode = 'plateau';
                $headline = "Routine is moderate — room to improve weekly consistency";
                $explanation = "{$workoutsThisWeek}/{$weeklyTarget} workouts logged this week and {$checkins30d} visits in the last 30 days. Consistency is below the full weekly target.";
                $primarySignal = ['label' => '30d Activity', 'value' => "{$checkins30d} visits", 'tone' => 'warn'];
                $nextStep = $isManagerView
                    ? "Encourage {$memberName} to hit {$weeklyTarget} sessions per week consistently."
                    : "Schedule one more session this week to reach {$weeklyTarget}/{$weeklyTarget} workouts.";
            } else {
                $statusCode = 'off_track';
                $headline = "Recent activity has dropped below {$poss} weekly target";
                $explanation = "Only {$workoutsThisWeek}/{$weeklyTarget} workouts completed this week and {$checkins30d} check-ins over the past 30 days.";
                $primarySignal = ['label' => '30d Activity', 'value' => "{$checkins30d} visits (low)", 'tone' => 'bad'];
                $nextStep = $isManagerView
                    ? "Reach out to {$memberName} to restart a manageable weekly schedule."
                    : "Start with 1–2 short sessions this week to rebuild momentum.";
            }
            break;
    }

    // Build Contributing Signals Pills (2-4 concise chips)
    $signals = [];
    if ($primarySignal !== null) {
        $signals[] = $primarySignal;
    }
    $workoutTone = $weeklyPct >= 75 ? 'good' : ($weeklyPct >= 40 ? 'warn' : 'bad');
    $signals[] = [
        'label' => 'Workouts',
        'value' => "{$workoutsThisWeek}/{$weeklyTarget} this wk ({$weeklyPct}%)",
        'tone'  => $workoutTone,
    ];
    if ($calDiff !== null) {
        $isFatLossGoal = ($model === 'fat_loss' || str_contains($g, 'six pack'));
        $isSurplusGoal = ($model === 'hypertrophy' && !$isFatLossGoal);
        if ($isFatLossGoal) {
            $calTone = $calDiff > 200 ? 'bad' : ($calDiff <= 150 && $calDiff >= -500 ? 'good' : 'warn');
        } elseif ($isSurplusGoal) {
            $calTone = $calDiff < -200 ? 'bad' : ($calDiff >= -100 && $calDiff <= 350 ? 'good' : 'warn');
        } else {
            $calTone = abs($calDiff) <= 200 ? 'good' : 'warn';
        }
        $diffText = $calDiff > 0 ? ('+' . number_format($calDiff) . ' kcal over') : ($calDiff < 0 ? (number_format($calDiff) . ' kcal under') : 'On target');
        $signals[] = [
            'label' => 'Calories',
            'value' => number_format($avgCals) . " vs " . number_format($targetCals) . " ({$diffText})",
            'tone'  => $calTone,
        ];
    }
    if ($paceSignal !== null) {
        $signals[] = $paceSignal;
    }

    $def = $statusDefs[$statusCode] ?? $statusDefs['needs_data'];

    return [
        'code'         => $def['code'],
        'label'        => $def['label'],
        'short_answer' => $def['short_answer'],
        'icon'         => $def['icon'],
        'badge_cls'    => $def['badge_cls'],
        'verdict_cls'  => $def['verdict_cls'],
        'color'        => $def['color'],
        'headline'     => $headline,
        'explanation'  => $explanation,
        'next_step'    => $nextStep,
        'signals'      => $signals,
        'directions'   => $directions,
    ];
}

/**
 * Calculates tailored KPIs, directional progress status, and milestone data for a member
 * Highly scalable: Reuses pre-computed metrics from $additionalContext whenever available.
 */
function calculate_member_goal_progress(int $memberId, array $profile, array $progressLogs = [], array $additionalContext = []): array
{
    $rawGoal = (string)($profile['primary_goal'] ?? 'general_fitness');
    $meta = get_goal_metadata($rawGoal);
    $model = $meta['model'];

    // 1. Gather baseline and recent log metrics
    $latestLog = !empty($progressLogs) ? $progressLogs[0] : null;
    $earliestLog = !empty($progressLogs) ? $progressLogs[count($progressLogs) - 1] : null;

    $currWeight = $latestLog && !empty($latestLog['weight_kg']) ? (float)$latestLog['weight_kg'] : (float)($profile['weight_kg'] ?? 0);
    $currWaist  = $latestLog && !empty($latestLog['waist_cm']) ? (float)$latestLog['waist_cm'] : (float)($profile['waist_cm'] ?? 0);
    $currArm    = $latestLog && !empty($latestLog['arm_cm']) ? (float)$latestLog['arm_cm'] : (float)($profile['arm_cm'] ?? 0);
    $currChest  = $latestLog && !empty($latestLog['chest_cm']) ? (float)$latestLog['chest_cm'] : (float)($profile['chest_cm'] ?? 0);

    $baseWeight = $earliestLog && !empty($earliestLog['weight_kg']) ? (float)$earliestLog['weight_kg'] : (float)($profile['weight_kg'] ?? 0);
    $baseWaist  = $earliestLog && !empty($earliestLog['waist_cm']) ? (float)$earliestLog['waist_cm'] : (float)($profile['waist_cm'] ?? 0);
    $baseArm    = $earliestLog && !empty($earliestLog['arm_cm']) ? (float)$earliestLog['arm_cm'] : (float)($profile['arm_cm'] ?? 0);

    // 2. Attendance & Consistency (Reuse pre-computed to avoid redundant queries)
    if (isset($additionalContext['checkins_30d'])) {
        $checkins30d = (int)$additionalContext['checkins_30d'];
    } elseif ($memberId > 0) {
        $checkins30d = (int) scalar(
            'SELECT COUNT(*) FROM attendance WHERE user_id = ? AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)',
            [$memberId]
        );
    } else {
        $checkins30d = 0;
    }

    if (isset($additionalContext['workouts_this_week'])) {
        $workoutsDoneThisWeek = (int)$additionalContext['workouts_this_week'];
    } elseif ($memberId > 0) {
        $workoutsDoneThisWeek = (int) scalar(
            'SELECT COUNT(DISTINCT DATE(check_in_time)) FROM attendance WHERE user_id = ? AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)',
            [$memberId]
        );
    } else {
        $workoutsDoneThisWeek = 0;
    }

    if (isset($additionalContext['exercises_30d'])) {
        $exercisesCompleted30d = (int)$additionalContext['exercises_30d'];
    } elseif ($memberId > 0) {
        $exercisesCompleted30d = (int) scalar(
            'SELECT COUNT(*) FROM exercise_completions WHERE user_id = ? AND completed_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)',
            [$memberId]
        );
    } else {
        $exercisesCompleted30d = 0;
    }

    // Weekly Targets
    $weeklyTarget = !empty($profile['weekly_workout_target']) ? (int)$profile['weekly_workout_target'] : 3;
    $weeklyPct = $weeklyTarget > 0 ? min(100, (int)round(($workoutsDoneThisWeek / $weeklyTarget) * 100)) : 0;

    // 3. Initialize return structure
    $result = [
        'goal_title' => $meta['title'],
        'raw_goal' => $rawGoal,
        'goal_category' => $meta['category'],
        'goal_icon' => $meta['icon'],
        'model' => $model,
        'status' => [
            'code' => 'improving',
            'label' => 'Improving',
            'icon' => '↗',
            'badge_cls' => 'goal-status-improving',
            'color' => '#38bdf8'
        ],
        'progress_pct' => null,
        'headline_summary' => '',
        'metrics' => [],
        'habits' => [
            'workouts_done' => $workoutsDoneThisWeek,
            'workouts_target' => $weeklyTarget,
            'weekly_pct' => $weeklyPct,
            'checkins_30d' => $checkins30d
        ],
        'milestone_note' => ''
    ];

    // Strength targets (reuse pre-loaded context when available to avoid redundant queries)
    $strengthTargets = $additionalContext['strength_targets'] ?? null;
    if (!is_array($strengthTargets)) {
        $strengthTargets = ($memberId > 0 && function_exists('get_member_strength_targets'))
            ? get_member_strength_targets($memberId)
            : [];
    }
    $liftedExercises = is_array($additionalContext['lifted_exercises'] ?? null)
        ? $additionalContext['lifted_exercises']
        : [];

    // Determine status & specific KPIs based on model
    switch ($model) {
        case 'strength':
            $targetExercise = !empty($profile['target_exercise']) ? (string)$profile['target_exercise'] : 'Primary Compound Lift';
            $currMax = !empty($profile['current_strength_max_kg']) ? (float)$profile['current_strength_max_kg'] : 0.0;
            $targetMax = !empty($profile['target_strength_max_kg']) ? (float)$profile['target_strength_max_kg'] : 0.0;

            if (!empty($strengthTargets)) {
                $firstTarget = $strengthTargets[0];
                $targetExercise = (string)($firstTarget['exercise_name'] ?? $targetExercise);
                $currMax = $firstTarget['current_max_kg'] !== null ? (float)$firstTarget['current_max_kg'] : $currMax;
                $targetMax = $firstTarget['target_max_kg'] !== null ? (float)$firstTarget['target_max_kg'] : $targetMax;
            }

            $liftPct = null;
            if ($targetMax > 0 && $currMax > 0) {
                $liftPct = (int)min(100, round(($currMax / $targetMax) * 100));
            }

            $prsCount = 0;
            foreach ($strengthTargets as $st) {
                $stStart = isset($st['start_max_kg']) && $st['start_max_kg'] !== null ? (float)$st['start_max_kg'] : (float)($st['current_max_kg'] ?? 0);
                $stCurr = (float)($st['current_max_kg'] ?? 0);
                if ($stCurr > $stStart) {
                    $prsCount++;
                }
            }
            if ($prsCount === 0 && !empty($liftedExercises)) {
                $prsCount = count($liftedExercises);
            }
            $result['progress_pct'] = $liftPct;

            if ($liftPct && $liftPct >= 95) {
                $result['status'] = ['code' => 'on_track', 'label' => 'Goal Met / On Track', 'icon' => '🟢', 'badge_cls' => 'goal-status-ontrack', 'color' => '#22c55e'];
            } elseif ($workoutsDoneThisWeek >= $weeklyTarget || $exercisesCompleted30d >= 4) {
                $result['status'] = ['code' => 'improving', 'label' => 'Progressing PRs', 'icon' => '↗', 'badge_cls' => 'goal-status-improving', 'color' => '#38bdf8'];
            } else {
                $result['status'] = ['code' => 'calibrating', 'label' => 'Calibrating Load', 'icon' => '⚪', 'badge_cls' => 'goal-status-calibrating', 'color' => '#94a3b8'];
            }

            $result['headline_summary'] = $targetMax > 0 ? "Targeting {$targetMax} kg working load on {$targetExercise}" : "Progressing strength volume & compound mechanical tension";
            $result['metrics'] = [
                [
                    'label' => $targetExercise,
                    'current' => $currMax > 0 ? (string)$currMax : 'Tracking',
                    'target' => $targetMax > 0 ? (string)$targetMax : null,
                    'unit' => 'kg',
                    'badge' => $targetMax > 0 ? "{$liftPct}% reached" : "Active lift",
                    'type' => 'good',
                    'progress_pct' => $liftPct
                ],
                [
                    'label' => !empty($strengthTargets) ? 'Improved Targets' : 'Logged Exercises',
                    'current' => (string)$prsCount,
                    'target' => !empty($strengthTargets) ? (string)count($strengthTargets) : null,
                    'unit' => !empty($strengthTargets) ? 'PRs' : 'lifts',
                    'badge' => $prsCount > 0 ? 'Active gains' : 'Log a PR',
                    'type' => $prsCount > 0 ? 'good' : 'neutral',
                    'progress_pct' => null
                ],
                [
                    'label' => 'Strength Workouts',
                    'current' => (string)$workoutsDoneThisWeek,
                    'target' => (string)$weeklyTarget,
                    'unit' => 'sessions',
                    'badge' => "{$weeklyPct}% weekly target",
                    'type' => $weeklyPct >= 75 ? 'good' : 'neutral',
                    'progress_pct' => $weeklyPct
                ]
            ];
            $result['milestone_note'] = "Focus on clean progressive overload and adequate 48h muscle recovery between heavy compound sets.";
            break;

        case 'hypertrophy':
            $g = strtolower(str_replace(['_', '-'], ' ', $rawGoal));
            $muscleFocus = 'Target Muscle Groups';
            $targetMetricLabel = 'Working Weight';
            $targetVal = null;
            $currentVal = 'Progressing';
            $unit = 'kg';

            if (str_contains($g, 'six pack')) {
                $muscleFocus = 'Core & Abdominal Wall';
                $targetMetricLabel = 'Waist Circumference';
                $currentVal = $currWaist > 0 ? (string)$currWaist : 'Tracking';
                $targetVal = !empty($profile['target_waist_cm']) ? (string)$profile['target_waist_cm'] : null;
                $unit = 'cm';
            } elseif (str_contains($g, 'biceps') || str_contains($g, 'arms')) {
                $muscleFocus = 'Upper Arms (Biceps/Triceps)';
                $targetMetricLabel = 'Arm Circumference';
                $currentVal = $currArm > 0 ? (string)$currArm : 'Tracking';
                $targetVal = !empty($profile['target_arm_cm']) ? (string)$profile['target_arm_cm'] : null;
                $unit = 'cm';
            } elseif (str_contains($g, 'chest')) {
                $muscleFocus = 'Pectorals & Chest';
                $targetMetricLabel = 'Chest Circumference';
                $currentVal = $currChest > 0 ? (string)$currChest : 'Tracking';
                $targetVal = !empty($profile['target_chest_cm']) ? (string)$profile['target_chest_cm'] : null;
                $unit = 'cm';
            } elseif (str_contains($g, 'v taper') || str_contains($g, 'back')) {
                $muscleFocus = 'Lats & Upper Back V-Taper';
                $targetMetricLabel = 'Pull-up / Lat Pulldown';
                $currentVal = 'Volume load';
                $targetVal = 'Progressive';
                $unit = 'reps/load';
            } elseif (str_contains($g, 'lower body') || str_contains($g, 'glutes') || str_contains($g, 'legs')) {
                $muscleFocus = 'Glutes, Quads & Hamstrings';
                $targetMetricLabel = 'Squat / Leg Press Volume';
                $currentVal = 'Working sets';
                $targetVal = 'Progressive';
                $unit = 'load';
            } elseif (str_contains($g, 'lean body mass') || str_contains($g, 'building muscle')) {
                $muscleFocus = 'Full-Body Muscular Hypertrophy';
                $targetMetricLabel = 'Target Body Weight';
                $currentVal = $currWeight > 0 ? (string)$currWeight : 'Tracking';
                $targetVal = !empty($profile['target_weight_kg']) ? (string)$profile['target_weight_kg'] : null;
                $unit = 'kg';
            }

            $weeklySets = max(3, $workoutsDoneThisWeek * 4);
            $statusLabel = $workoutsDoneThisWeek >= $weeklyTarget ? 'On Track' : ($workoutsDoneThisWeek > 0 ? 'Improving' : 'Building Routine');
            $statusCls = $workoutsDoneThisWeek >= $weeklyTarget ? 'goal-status-ontrack' : 'goal-status-improving';
            $result['status'] = [
                'code' => $workoutsDoneThisWeek >= $weeklyTarget ? 'on_track' : 'improving',
                'label' => $statusLabel,
                'icon' => $workoutsDoneThisWeek >= $weeklyTarget ? '🟢' : '↗',
                'badge_cls' => $statusCls,
                'color' => $workoutsDoneThisWeek >= $weeklyTarget ? '#22c55e' : '#38bdf8'
            ];

            $result['headline_summary'] = "Targeting hypertrophy & mechanical tension for {$muscleFocus}";
            $result['metrics'] = [
                [
                    'label' => $targetMetricLabel,
                    'current' => (string)$currentVal,
                    'target' => $targetVal,
                    'unit' => $unit,
                    'badge' => $targetVal ? "Target: {$targetVal} {$unit}" : 'Progressive load',
                    'type' => 'good',
                    'progress_pct' => null
                ],
                [
                    'label' => 'Target Volume',
                    'current' => (string)$weeklySets,
                    'target' => '12-16',
                    'unit' => 'sets/wk',
                    'badge' => "Active stimulus",
                    'type' => 'good',
                    'progress_pct' => min(100, (int)round(($weeklySets / 14) * 100))
                ],
                [
                    'label' => 'Workout Consistency',
                    'current' => (string)$workoutsDoneThisWeek,
                    'target' => (string)$weeklyTarget,
                    'unit' => 'done',
                    'badge' => "{$weeklyPct}% consistency",
                    'type' => $weeklyPct >= 75 ? 'good' : 'neutral',
                    'progress_pct' => $weeklyPct
                ]
            ];
            $result['milestone_note'] = "Ensure training within 1-2 reps in reserve (RIR) and consuming adequate protein (1.6-2.2g/kg) to maximize muscle protein synthesis.";
            break;

        case 'endurance':
            $activity = !empty($profile['endurance_activity']) ? (string)$profile['endurance_activity'] : 'Cardio Session';
            $currDist = !empty($profile['current_endurance_distance_km']) ? (float)$profile['current_endurance_distance_km'] : 3.0;
            $targetDist = !empty($profile['target_endurance_distance_km']) ? (float)$profile['target_endurance_distance_km'] : 5.0;
            $currTime = !empty($profile['current_endurance_time_mins']) ? (int)$profile['current_endurance_time_mins'] : 25;
            $targetTime = !empty($profile['target_endurance_time_mins']) ? (int)$profile['target_endurance_time_mins'] : 35;

            $endurancePct = (int)min(100, round(($currTime / max(1, $targetTime)) * 100));
            $result['progress_pct'] = $endurancePct;
            $result['status'] = [
                'code' => $endurancePct >= 80 ? 'on_track' : 'improving',
                'label' => $endurancePct >= 80 ? 'Aerobic On Track' : 'Building Stamina',
                'icon' => $endurancePct >= 80 ? '🟢' : '↗',
                'badge_cls' => $endurancePct >= 80 ? 'goal-status-ontrack' : 'goal-status-improving',
                'color' => '#22c55e'
            ];

            $result['headline_summary'] = "Building aerobic base & cardiovascular stamina via {$activity}";
            $result['metrics'] = [
                [
                    'label' => 'Cardio Duration',
                    'current' => (string)$currTime,
                    'target' => (string)$targetTime,
                    'unit' => 'min',
                    'badge' => "{$endurancePct}% target duration",
                    'type' => 'good',
                    'progress_pct' => $endurancePct
                ],
                [
                    'label' => 'Target Distance',
                    'current' => (string)$currDist,
                    'target' => (string)$targetDist,
                    'unit' => 'km',
                    'badge' => "Pace pacing",
                    'type' => 'good',
                    'progress_pct' => (int)min(100, round(($currDist / max(1, $targetDist)) * 100))
                ],
                [
                    'label' => 'Weekly Consistency',
                    'current' => (string)$workoutsDoneThisWeek,
                    'target' => (string)$weeklyTarget,
                    'unit' => 'sessions',
                    'badge' => "{$weeklyPct}% achieved",
                    'type' => 'neutral',
                    'progress_pct' => $weeklyPct
                ]
            ];
            $result['milestone_note'] = "Focus on sustainable Zone 2 aerobic pacing (nasal breathing) before ramping up high-intensity intervals.";
            break;

        case 'mobility':
            $result['status'] = [
                'code' => 'improving',
                'label' => 'Active Mobility',
                'icon' => '↗',
                'badge_cls' => 'goal-status-improving',
                'color' => '#38bdf8'
            ];
            $result['headline_summary'] = "Extending functional joint range of motion & injury-free movement quality";
            $result['metrics'] = [
                [
                    'label' => 'Mobility Sessions',
                    'current' => (string)max(1, $workoutsDoneThisWeek),
                    'target' => '4',
                    'unit' => 'this week',
                    'badge' => 'Routine streak',
                    'type' => 'good',
                    'progress_pct' => min(100, (int)round((max(1, $workoutsDoneThisWeek) / 4) * 100))
                ],
                [
                    'label' => 'Movement Quality',
                    'current' => 'Smooth',
                    'target' => 'Full ROM',
                    'unit' => '',
                    'badge' => 'Joint mobility',
                    'type' => 'good',
                    'progress_pct' => null
                ],
                [
                    'label' => 'Stretch Adherence',
                    'current' => (string)$weeklyPct,
                    'target' => '100',
                    'unit' => '%',
                    'badge' => 'Weekly target',
                    'type' => 'neutral',
                    'progress_pct' => $weeklyPct
                ]
            ];
            $result['milestone_note'] = "Perform deep thoracic spine and hip flexor openers post-workout for optimal flexibility retention.";
            break;

        case 'fat_loss':
            $targetWeight = !empty($profile['target_weight_kg']) ? (float)$profile['target_weight_kg'] : 0.0;
            $weightDiff = ($targetWeight > 0 && $currWeight > 0) ? round($currWeight - $targetWeight, 1) : null;
            $weightLossSoFar = ($baseWeight > 0 && $currWeight > 0) ? round($baseWeight - $currWeight, 1) : 0.0;

            $statusText = 'On Track';
            $statusIcon = '🟢';
            $statusCls = 'goal-status-ontrack';

            if ($weightLossSoFar > 0.5) {
                $statusText = 'Dropping Steady';
                $statusIcon = '↗';
                $statusCls = 'goal-status-ontrack';
            } elseif ($checkins30d < 3 && $workoutsDoneThisWeek === 0) {
                $statusText = 'Needs Routine';
                $statusIcon = '🟡';
                $statusCls = 'goal-status-attention';
            }

            $result['status'] = [
                'code' => $statusCls === 'goal-status-ontrack' ? 'on_track' : 'needs_attention',
                'label' => $statusText,
                'icon' => $statusIcon,
                'badge_cls' => $statusCls,
                'color' => $statusCls === 'goal-status-ontrack' ? '#22c55e' : '#eab308'
            ];

            $result['headline_summary'] = $targetWeight > 0 ? "Targeting {$targetWeight} kg via caloric deficit and regular conditioning" : "Focusing on fat reduction, core conditioning & energy deficit";
            $result['metrics'] = [
                [
                    'label' => 'Current Weight',
                    'current' => $currWeight > 0 ? (string)$currWeight : 'Logged',
                    'target' => $targetWeight > 0 ? (string)$targetWeight : null,
                    'unit' => 'kg',
                    'badge' => $weightDiff !== null ? ($weightDiff > 0 ? "{$weightDiff} kg to go" : "Target reached! 🎉") : 'Tracking',
                    'type' => 'good',
                    'progress_pct' => null
                ],
                [
                    'label' => 'Waist Circumference',
                    'current' => $currWaist > 0 ? (string)$currWaist : 'Tracking',
                    'target' => !empty($profile['target_waist_cm']) ? (string)$profile['target_waist_cm'] : null,
                    'unit' => 'cm',
                    'badge' => ($baseWaist > 0 && $currWaist > 0 && $currWaist < $baseWaist) ? '-' . round($baseWaist - $currWaist, 1) . ' cm drop' : 'Waist metric',
                    'type' => 'good',
                    'progress_pct' => null
                ],
                [
                    'label' => 'Habit Consistency',
                    'current' => (string)$weeklyPct,
                    'target' => '100',
                    'unit' => '%',
                    'badge' => "{$workoutsDoneThisWeek}/{$weeklyTarget} workouts",
                    'type' => $weeklyPct >= 75 ? 'good' : 'neutral',
                    'progress_pct' => $weeklyPct
                ]
            ];
            $result['milestone_note'] = "Maintain high protein intake (1.6-2.2g/kg) during deficit to preserve lean contractile tissue.";
            break;

        case 'recomposition':
            $result['status'] = [
                'code' => 'on_track',
                'label' => 'Recomposing',
                'icon' => '🟢',
                'badge_cls' => 'goal-status-ontrack',
                'color' => '#22c55e'
            ];
            $result['headline_summary'] = "Simultaneously building contractile tissue while burning adipose fat";
            $result['metrics'] = [
                [
                    'label' => 'Strength Progression',
                    'current' => 'Increasing',
                    'target' => 'PRs',
                    'unit' => '',
                    'badge' => 'Progressive overload',
                    'type' => 'good',
                    'progress_pct' => null
                ],
                [
                    'label' => 'Waist Trend',
                    'current' => $currWaist > 0 ? (string)$currWaist : 'Tracking',
                    'target' => !empty($profile['target_waist_cm']) ? (string)$profile['target_waist_cm'] : null,
                    'unit' => 'cm',
                    'badge' => 'Leaning out',
                    'type' => 'good',
                    'progress_pct' => null
                ],
                [
                    'label' => 'Workout Consistency',
                    'current' => (string)$workoutsDoneThisWeek,
                    'target' => (string)$weeklyTarget,
                    'unit' => 'sessions',
                    'badge' => "{$weeklyPct}% this week",
                    'type' => 'good',
                    'progress_pct' => $weeklyPct
                ]
            ];
            $result['milestone_note'] = "Scale weight may remain steady while clothes fit significantly looser — the hallmark of true body recomposition.";
            break;

        case 'lifestyle':
        default:
            $isConsistent = $weeklyPct >= 65 || $checkins30d >= 8;
            $result['status'] = [
                'code' => $isConsistent ? 'on_track' : 'improving',
                'label' => $isConsistent ? 'Consistent Routine' : 'Building Habits',
                'icon' => $isConsistent ? '🟢' : '↗',
                'badge_cls' => $isConsistent ? 'goal-status-ontrack' : 'goal-status-improving',
                'color' => $isConsistent ? '#22c55e' : '#38bdf8'
            ];
            $result['headline_summary'] = "Sustainable nutrition, energetic wellness, and positive movement habits";
            $result['metrics'] = [
                [
                    'label' => 'Weekly Workouts',
                    'current' => (string)$workoutsDoneThisWeek,
                    'target' => (string)$weeklyTarget,
                    'unit' => 'done',
                    'badge' => $weeklyPct >= 100 ? 'Target achieved! 🎉' : "{$weeklyPct}% of goal",
                    'type' => $weeklyPct >= 75 ? 'good' : 'neutral',
                    'progress_pct' => $weeklyPct
                ],
                [
                    'label' => 'Gym Visits (30d)',
                    'current' => (string)$checkins30d,
                    'target' => '10+',
                    'unit' => 'visits',
                    'badge' => $checkins30d >= 8 ? 'Active gymgoer' : 'Building streak',
                    'type' => 'good',
                    'progress_pct' => min(100, (int)round(($checkins30d / 10) * 100))
                ],
                [
                    'label' => 'Flexible Adherence',
                    'current' => 'Balanced',
                    'target' => 'Habit',
                    'unit' => '',
                    'badge' => 'No burnout',
                    'type' => 'good',
                    'progress_pct' => null
                ]
            ];
            $result['milestone_note'] = "Consistency beats intensity. Maintaining a moderate routine week after week delivers lifetime health.";
            break;
    }

    // Phase 3: Evaluate and attach goal milestones
    $milestoneContext = array_merge($additionalContext, [
        'checkins_30d' => $checkins30d,
        'workouts_this_week' => $workoutsDoneThisWeek,
        'exercises_30d' => $exercisesCompleted30d,
        'weekly_target' => $weeklyTarget,
        'raw_goal' => $rawGoal,
        'model' => $model
    ]);
    $result['milestones'] = evaluate_and_award_goal_milestones($memberId, $profile, $progressLogs, $rawGoal, $milestoneContext);

    // Phase 4: Evaluate rule-based progress verdict (why & next step)
    $verdictContext = array_merge($additionalContext, [
        'workouts_this_week' => $workoutsDoneThisWeek,
        'weekly_target'      => $weeklyTarget,
        'checkins_30d'       => $checkins30d,
        'exercises_30d'      => $exercisesCompleted30d,
        'strength_targets'   => $strengthTargets,
        'lifted_exercises'   => $liftedExercises,
    ]);
    $verdict = evaluate_member_progress_verdict($profile, $progressLogs, $verdictContext);
    $result['status'] = [
        'code'      => $verdict['code'],
        'label'     => $verdict['label'],
        'icon'      => $verdict['icon'],
        'badge_cls' => $verdict['badge_cls'],
        'color'     => $verdict['color'],
    ];
    $result['verdict'] = $verdict;
    $result['directions'] = $verdict['directions'];

    return $result;
}

/**
 * Returns complete catalogue of milestone definitions
 */
function get_all_goal_milestone_definitions(): array
{
    return [
        'first_log' => [
            'name' => 'Journey Begun',
            'desc' => 'Logged initial baseline body measurements',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>',
        ],
        'waist_trimmer' => [
            'name' => 'Waist Sculptor',
            'desc' => 'Reduced waist circumference by ≥ 3.0 cm',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>',
        ],
        'lean_warrior' => [
            'name' => 'Lean Machine',
            'desc' => 'Reduced body fat percentage by ≥ 2.5%',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
        ],
        'arm_builder' => [
            'name' => 'Arm Builder',
            'desc' => 'Gained ≥ 1.5 cm on arm circumference',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 5v14M18 5v14M6 12h12M3 8v8M21 8v8"/></svg>',
        ],
        'chest_sculptor' => [
            'name' => 'Chest Armor',
            'desc' => 'Gained ≥ 2.0 cm on chest circumference',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        ],
        'deficit_discipline' => [
            'name' => 'Deficit Discipline',
            'desc' => 'Completed 8+ workouts during active fat loss',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
        ],
        'core_builder' => [
            'name' => 'Core Sculptor',
            'desc' => 'Completed 8+ dedicated workouts toward core definition',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg>',
        ],
        'hypertrophy_sets' => [
            'name' => 'Volume Overload',
            'desc' => 'Completed 8+ muscle-building hypertrophy sessions',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/></svg>',
        ],
        'pr_crusher' => [
            'name' => 'PR Crusher',
            'desc' => 'Logged 3+ compound personal records or strength progressions',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
        ],
        'load_master' => [
            'name' => 'Load Master',
            'desc' => 'Reached ≥ 90% of target working weight load',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>',
        ],
        'iron_discipline' => [
            'name' => 'Iron Discipline',
            'desc' => 'Completed 8+ dedicated strength training sessions',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>',
        ],
        'aerobic_base' => [
            'name' => 'Aerobic Engine',
            'desc' => 'Reached ≥ 30 min duration or 80%+ of target endurance duration',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        ],
        'distance_crusher' => [
            'name' => 'Distance Pioneer',
            'desc' => 'Reached ≥ 5.0 km or 80%+ of target endurance distance',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>',
        ],
        'stamina_streak' => [
            'name' => 'Stamina Streak',
            'desc' => 'Completed 8+ cardio sessions or gym visits',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20.42 4.58a5.4 5.4 0 0 0-7.65 0l-.77.78-.77-.78a5.4 5.4 0 0 0-7.65 7.65l.77.78L12 20.65l7.65-7.64.77-.78a5.4 5.4 0 0 0 0-7.65z"/></svg>',
        ],
        'mobility_pioneer' => [
            'name' => 'Range of Motion',
            'desc' => 'Completed 6+ mobility or stretching sessions',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="5" r="2"/><path d="M5 20l4-7 3 3 5-7 3 2"/></svg>',
        ],
        'flex_streak' => [
            'name' => 'Fluid Mover',
            'desc' => 'Maintained mobility routine across 3+ unique weeks',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M2 12h20M2 6h20M2 18h20"/></svg>',
        ],
        'recomp_builder' => [
            'name' => 'Tone & Definition',
            'desc' => 'Completed 6+ workouts preserving muscle while leaning out',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>',
        ],
        'gym_regular' => [
            'name' => 'Active Gymgoer',
            'desc' => 'Logged 8+ gym visits in the last 30 days',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        ],
        'healthy_rhythm' => [
            'name' => 'Balanced Rhythm',
            'desc' => 'Maintained positive fitness habit across 3+ unique weeks',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
        ],
        'weight_goal_met' => [
            'name' => 'Target Achieved',
            'desc' => 'Reached or passed target body weight',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>',
        ],
        'consistency_streak' => [
            'name' => 'Habit Master',
            'desc' => 'Consistent logging across 4+ unique weeks',
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
        ]
    ];
}

/**
 * Returns tailored milestone keys for a specific fitness goal
 */
function get_milestone_keys_for_goal(string $goal, array $profile = []): array
{
    $model = get_goal_metric_model($goal);
    $g = strtolower(trim(str_replace(['_', '-'], ' ', $goal)));
    $hasTargetWeight = !empty($profile['target_weight_kg']) && (float)$profile['target_weight_kg'] > 0;

    switch ($model) {
        case 'fat_loss':
            $keys = ['first_log', 'waist_trimmer', 'lean_warrior', 'deficit_discipline'];
            if ($hasTargetWeight) {
                $keys[] = 'weight_goal_met';
            }
            $keys[] = 'consistency_streak';
            return $keys;

        case 'hypertrophy':
            if (str_contains($g, 'six pack')) {
                return ['first_log', 'waist_trimmer', 'lean_warrior', 'core_builder', 'consistency_streak'];
            }
            if (str_contains($g, 'biceps') || str_contains($g, 'arms')) {
                $keys = ['first_log', 'arm_builder', 'hypertrophy_sets'];
                if ($hasTargetWeight) {
                    $keys[] = 'weight_goal_met';
                }
                $keys[] = 'consistency_streak';
                return $keys;
            }
            if (str_contains($g, 'chest')) {
                $keys = ['first_log', 'chest_sculptor', 'hypertrophy_sets'];
                if ($hasTargetWeight) {
                    $keys[] = 'weight_goal_met';
                }
                $keys[] = 'consistency_streak';
                return $keys;
            }
            // General hypertrophy, lean body mass, back/v-taper, legs
            $keys = ['first_log', 'arm_builder', 'chest_sculptor', 'hypertrophy_sets'];
            if ($hasTargetWeight) {
                $keys[] = 'weight_goal_met';
            }
            $keys[] = 'consistency_streak';
            return $keys;

        case 'strength':
            $keys = ['first_log', 'pr_crusher', 'load_master', 'iron_discipline'];
            if ($hasTargetWeight) {
                $keys[] = 'weight_goal_met';
            }
            $keys[] = 'consistency_streak';
            return $keys;

        case 'endurance':
            return ['first_log', 'aerobic_base', 'distance_crusher', 'stamina_streak', 'consistency_streak'];

        case 'mobility':
            return ['first_log', 'mobility_pioneer', 'flex_streak', 'consistency_streak'];

        case 'recomposition':
            $keys = ['first_log', 'waist_trimmer', 'lean_warrior', 'recomp_builder'];
            if ($hasTargetWeight) {
                $keys[] = 'weight_goal_met';
            }
            $keys[] = 'consistency_streak';
            return $keys;

        case 'lifestyle':
        default:
            $keys = ['first_log', 'gym_regular', 'healthy_rhythm'];
            if ($hasTargetWeight) {
                $keys[] = 'weight_goal_met';
            }
            $keys[] = 'consistency_streak';
            return $keys;
    }
}

/**
 * Evaluates and awards goal milestone badges into member_badges, returning structured milestone items tailored to the active goal.
 */
function evaluate_and_award_goal_milestones(int $memberId, array $profile, array $progressLogs = [], string $rawGoal = '', array $context = []): array
{
    if ($memberId <= 0) {
        return [];
    }

    if ($rawGoal === '') {
        $rawGoal = (string)($profile['primary_goal'] ?? 'general_fitness');
    }

    $defs = get_all_goal_milestone_definitions();
    $activeKeys = get_milestone_keys_for_goal($rawGoal, $profile);
    $pdo = db();

    // 1. Gather baseline and latest values
    $latest = !empty($progressLogs) ? $progressLogs[0] : null;
    $earliest = !empty($progressLogs) ? $progressLogs[count($progressLogs) - 1] : null;

    $currWeight = $latest && !empty($latest['weight_kg']) ? (float)$latest['weight_kg'] : (float)($profile['weight_kg'] ?? 0);
    $baseWeight = $earliest && !empty($earliest['weight_kg']) ? (float)$earliest['weight_kg'] : (float)($profile['weight_kg'] ?? 0);
    $targetWeight = !empty($profile['target_weight_kg']) ? (float)$profile['target_weight_kg'] : null;

    $currWaist = $latest && !empty($latest['waist_cm']) ? (float)$latest['waist_cm'] : (float)($profile['waist_cm'] ?? 0);
    $baseWaist = $earliest && !empty($earliest['waist_cm']) ? (float)$earliest['waist_cm'] : (float)($profile['waist_cm'] ?? 0);

    $currBf = $latest && !empty($latest['body_fat_percent']) ? (float)$latest['body_fat_percent'] : null;
    $baseBf = $earliest && !empty($earliest['body_fat_percent']) ? (float)$earliest['body_fat_percent'] : null;

    $currArm = $latest && !empty($latest['arm_cm']) ? (float)$latest['arm_cm'] : (float)($profile['arm_cm'] ?? 0);
    $baseArm = $earliest && !empty($earliest['arm_cm']) ? (float)$earliest['arm_cm'] : (float)($profile['arm_cm'] ?? 0);

    $currChest = $latest && !empty($latest['chest_cm']) ? (float)$latest['chest_cm'] : (float)($profile['chest_cm'] ?? 0);
    $baseChest = $earliest && !empty($earliest['chest_cm']) ? (float)$earliest['chest_cm'] : (float)($profile['chest_cm'] ?? 0);

    $checkins30d = (int)($context['checkins_30d'] ?? 0);
    $exercisesCompleted30d = (int)($context['exercises_30d'] ?? 0);
    $totalLogsCount = count($progressLogs);
    $completedWorkoutDays30d = 0;
    try {
        $completedWorkoutDays30d = (int) scalar(
            'SELECT COUNT(DISTINCT completed_date) FROM exercise_completions WHERE user_id = ? AND completed_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)',
            [$memberId]
        );
    } catch (Throwable) {}
    $doneWorkouts = max($checkins30d, $completedWorkoutDays30d);

    // Week streak calculation: distinct calendar weeks in progress logs
    $logWeeks = [];
    foreach ($progressLogs as $pl) {
        if (!empty($pl['log_date'])) {
            $logWeeks[date('Y-W', strtotime($pl['log_date']))] = true;
        }
    }
    $totalWeeksLogged = count($logWeeks);

    // 2. Evaluate criteria for all milestones
    $qualifies = [];

    // Milestone: Journey Begun
    $qualifies['first_log'] = [
        'met' => $totalLogsCount >= 1,
        'label' => $totalLogsCount >= 1 ? 'Logged' : '0 / 1 log',
        'pct' => $totalLogsCount >= 1 ? 100 : 0
    ];

    // Milestone: Waist Sculptor (drop >= 3.0 cm)
    $waistDrop = ($baseWaist > 0 && $currWaist > 0) ? round($baseWaist - $currWaist, 1) : 0.0;
    $qualifies['waist_trimmer'] = [
        'met' => $waistDrop >= 3.0,
        'label' => max(0, $waistDrop) . ' / 3.0 cm',
        'pct' => (int)min(100, max(0, round(($waistDrop / 3.0) * 100)))
    ];

    // Milestone: Lean Machine (drop >= 2.5% body fat)
    $bfDrop = ($baseBf !== null && $currBf !== null) ? round($baseBf - $currBf, 1) : 0.0;
    $qualifies['lean_warrior'] = [
        'met' => $bfDrop >= 2.5,
        'label' => max(0, $bfDrop) . ' / 2.5%',
        'pct' => (int)min(100, max(0, round(($bfDrop / 2.5) * 100)))
    ];

    // Milestone: Arm Builder (gain >= 1.5 cm)
    $armGain = ($baseArm > 0 && $currArm > 0) ? round($currArm - $baseArm, 1) : 0.0;
    $qualifies['arm_builder'] = [
        'met' => $armGain >= 1.5,
        'label' => max(0, $armGain) . ' / 1.5 cm',
        'pct' => (int)min(100, max(0, round(($armGain / 1.5) * 100)))
    ];

    // Milestone: Chest Armor (gain >= 2.0 cm)
    $chestGain = ($baseChest > 0 && $currChest > 0) ? round($currChest - $baseChest, 1) : 0.0;
    $qualifies['chest_sculptor'] = [
        'met' => $chestGain >= 2.0,
        'label' => max(0, $chestGain) . ' / 2.0 cm',
        'pct' => (int)min(100, max(0, round(($chestGain / 2.0) * 100)))
    ];

    // Milestone: Deficit Discipline (Fat Loss workouts)
    $qualifies['deficit_discipline'] = [
        'met' => $doneWorkouts >= 8,
        'label' => min(8, $doneWorkouts) . ' / 8 sessions',
        'pct' => (int)min(100, round(($doneWorkouts / 8) * 100))
    ];

    // Milestone: Core Sculptor (Six-Pack workouts)
    $qualifies['core_builder'] = [
        'met' => $doneWorkouts >= 8,
        'label' => min(8, $doneWorkouts) . ' / 8 sessions',
        'pct' => (int)min(100, round(($doneWorkouts / 8) * 100))
    ];

    // Milestone: Volume Overload (Hypertrophy sets/sessions)
    $qualifies['hypertrophy_sets'] = [
        'met' => $doneWorkouts >= 8,
        'label' => min(8, $doneWorkouts) . ' / 8 sessions',
        'pct' => (int)min(100, round(($doneWorkouts / 8) * 100))
    ];

    // Milestone: PR Crusher (Strength personal records)
    $prsCount = (int)floor($exercisesCompleted30d / 6);
    $prProgress = min(3, max(0, $prsCount));
    $qualifies['pr_crusher'] = [
        'met' => $prsCount >= 3,
        'label' => $prProgress . ' / 3 PRs',
        'pct' => (int)min(100, round(($prProgress / 3) * 100))
    ];

    // Milestone: Load Master (Strength working max load)
    $currStrengthMax = !empty($profile['current_strength_max_kg']) ? (float)$profile['current_strength_max_kg'] : 0.0;
    $targetStrengthMax = !empty($profile['target_strength_max_kg']) ? (float)$profile['target_strength_max_kg'] : 0.0;
    if ($targetStrengthMax > 0) {
        $liftPct = (int)min(100, round(($currStrengthMax / $targetStrengthMax) * 100));
        $qualifies['load_master'] = [
            'met' => $liftPct >= 90,
            'label' => $liftPct . '% / 90%',
            'pct' => (int)min(100, round(($liftPct / 90) * 100))
        ];
    } else {
        $qualifies['load_master'] = [
            'met' => $currStrengthMax >= 60.0,
            'label' => $currStrengthMax > 0 ? "{$currStrengthMax} kg" : 'Target not set',
            'pct' => $currStrengthMax > 0 ? 70 : 0
        ];
    }

    // Milestone: Iron Discipline (Strength consistency)
    $qualifies['iron_discipline'] = [
        'met' => $doneWorkouts >= 8,
        'label' => min(8, $doneWorkouts) . ' / 8 sessions',
        'pct' => (int)min(100, round(($doneWorkouts / 8) * 100))
    ];

    // Milestone: Aerobic Engine (Endurance duration)
    $currEnduranceTime = !empty($profile['current_endurance_time_mins']) ? (int)$profile['current_endurance_time_mins'] : 25;
    $targetEnduranceTime = !empty($profile['target_endurance_time_mins']) ? (int)$profile['target_endurance_time_mins'] : 35;
    $timeGoal = max(25, $targetEnduranceTime > 0 ? (int)round($targetEnduranceTime * 0.8) : 30);
    $qualifies['aerobic_base'] = [
        'met' => $currEnduranceTime >= $timeGoal,
        'label' => min($timeGoal, $currEnduranceTime) . ' / ' . $timeGoal . ' min',
        'pct' => (int)min(100, round(($currEnduranceTime / max(1, $timeGoal)) * 100))
    ];

    // Milestone: Distance Pioneer (Endurance distance)
    $currEnduranceDist = !empty($profile['current_endurance_distance_km']) ? (float)$profile['current_endurance_distance_km'] : 3.0;
    $targetEnduranceDist = !empty($profile['target_endurance_distance_km']) ? (float)$profile['target_endurance_distance_km'] : 5.0;
    $distGoal = max(3.0, $targetEnduranceDist > 0 ? round($targetEnduranceDist * 0.8, 1) : 5.0);
    $qualifies['distance_crusher'] = [
        'met' => $currEnduranceDist >= $distGoal,
        'label' => min($distGoal, $currEnduranceDist) . ' / ' . $distGoal . ' km',
        'pct' => (int)min(100, round(($currEnduranceDist / max(0.1, $distGoal)) * 100))
    ];

    // Milestone: Stamina Streak (Endurance consistency)
    $qualifies['stamina_streak'] = [
        'met' => $doneWorkouts >= 8,
        'label' => min(8, $doneWorkouts) . ' / 8 sessions',
        'pct' => (int)min(100, round(($doneWorkouts / 8) * 100))
    ];

    // Milestone: Range of Motion (Mobility sessions)
    $qualifies['mobility_pioneer'] = [
        'met' => $doneWorkouts >= 6,
        'label' => min(6, $doneWorkouts) . ' / 6 sessions',
        'pct' => (int)min(100, round(($doneWorkouts / 6) * 100))
    ];

    // Milestone: Fluid Mover (Mobility streak)
    $qualifies['flex_streak'] = [
        'met' => $totalWeeksLogged >= 3,
        'label' => min(3, $totalWeeksLogged) . ' / 3 wks',
        'pct' => (int)min(100, round(($totalWeeksLogged / 3) * 100))
    ];

    // Milestone: Tone & Definition (Recomposition)
    $qualifies['recomp_builder'] = [
        'met' => $doneWorkouts >= 6,
        'label' => min(6, $doneWorkouts) . ' / 6 sessions',
        'pct' => (int)min(100, round(($doneWorkouts / 6) * 100))
    ];

    // Milestone: Active Gymgoer (Lifestyle)
    $qualifies['gym_regular'] = [
        'met' => $checkins30d >= 8,
        'label' => min(8, $checkins30d) . ' / 8 visits',
        'pct' => (int)min(100, round(($checkins30d / 8) * 100))
    ];

    // Milestone: Balanced Rhythm (Lifestyle)
    $qualifies['healthy_rhythm'] = [
        'met' => $totalWeeksLogged >= 3,
        'label' => min(3, $totalWeeksLogged) . ' / 3 wks',
        'pct' => (int)min(100, round(($totalWeeksLogged / 3) * 100))
    ];

    // Milestone: Target Achieved (Weight)
    $weightMet = false;
    $weightProgressPct = 0;
    if ($targetWeight > 0 && $baseWeight > 0 && $currWeight > 0) {
        if ($targetWeight <= $baseWeight) { // weight loss
            $weightMet = $currWeight <= $targetWeight;
            $totalNeed = $baseWeight - $targetWeight;
            $done = $baseWeight - $currWeight;
            $weightProgressPct = $totalNeed > 0 ? (int)min(100, max(0, round(($done / $totalNeed) * 100))) : 0;
        } else { // weight gain
            $weightMet = $currWeight >= $targetWeight;
            $totalNeed = $targetWeight - $baseWeight;
            $done = $currWeight - $baseWeight;
            $weightProgressPct = $totalNeed > 0 ? (int)min(100, max(0, round(($done / $totalNeed) * 100))) : 0;
        }
    }
    $qualifies['weight_goal_met'] = [
        'met' => $weightMet,
        'label' => $targetWeight ? ($weightMet ? 'Achieved!' : $currWeight . ' / ' . $targetWeight . ' kg') : 'Target not set',
        'pct' => $weightMet ? 100 : $weightProgressPct
    ];

    // Milestone: Habit Master (4+ unique weeks)
    $qualifies['consistency_streak'] = [
        'met' => $totalWeeksLogged >= 4,
        'label' => min(4, $totalWeeksLogged) . ' / 4 wks',
        'pct' => (int)min(100, round(($totalWeeksLogged / 4) * 100))
    ];

    // Query existing unlocked badges to detect newly achieved milestones
    $existingBadgesStmt = $pdo->prepare('SELECT badge_type FROM member_badges WHERE user_id = ? AND badge_type LIKE "milestone_%"');
    $existingBadgesStmt->execute([$memberId]);
    $alreadyUnlockedSet = array_flip($existingBadgesStmt->fetchAll(PDO::FETCH_COLUMN));

    // 3. Persist all newly met milestones into member_badges
    $badgesToInsert = [];
    $newlyUnlockedKeys = [];
    foreach ($qualifies as $key => $status) {
        if ($status['met']) {
            $bType = 'milestone_' . $key;
            $badgesToInsert[] = $bType;
            if (!isset($alreadyUnlockedSet[$bType])) {
                $newlyUnlockedKeys[] = $key;
            }
        }
    }

    if (!empty($badgesToInsert)) {
        $placeholders = [];
        $params = [];
        foreach ($badgesToInsert as $bt) {
            $placeholders[] = '(?, ?)';
            $params[] = $memberId;
            $params[] = $bt;
        }
        $sql = 'INSERT IGNORE INTO member_badges (user_id, badge_type) VALUES ' . implode(', ', $placeholders);
        $pdo->prepare($sql)->execute($params);

        // Send in-app notifications for newly achieved targets & milestones
        if (!empty($newlyUnlockedKeys) && function_exists('notify_user')) {
            $trainerUserId = function_exists('get_member_active_trainer_user_id')
                ? get_member_active_trainer_user_id($memberId)
                : null;
            $clientName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')) ?: 'Your client';

            foreach ($newlyUnlockedKeys as $newKey) {
                $mTitle = $defs[$newKey]['name'] ?? 'Goal Milestone';
                $mDesc = $defs[$newKey]['desc'] ?? 'Congratulations on reaching a new milestone!';

                if ($newKey === 'weight_goal_met') {
                    $notifTitle = '🎯 Target Weight Achieved!';
                    $notifMsg = "Congratulations! You officially reached your target weight of {$targetWeight} kg. Your hard work and consistency paid off!";
                    notify_user($memberId, 'milestone', $notifTitle, $notifMsg);

                    if ($trainerUserId) {
                        notify_user(
                            $trainerUserId,
                            'milestone',
                            '🎯 Client Target Reached!',
                            "{$clientName} officially reached their target weight of {$targetWeight} kg! Congratulate them on this milestone achievement.",
                            $memberId
                        );
                    }
                } else {
                    $notifTitle = '🏆 Milestone Unlocked: ' . $mTitle;
                    $notifMsg = "You unlocked the \"{$mTitle}\" milestone: {$mDesc}";
                    notify_user($memberId, 'milestone', $notifTitle, $notifMsg);
                }
            }
        }
    }

    // 4. Query current user's unlocked badges
    $stmt = $pdo->prepare('SELECT badge_type, unlocked_at FROM member_badges WHERE user_id = ? AND badge_type LIKE "milestone_%"');
    $stmt->execute([$memberId]);
    $unlockedMap = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cleanKey = str_replace('milestone_', '', $row['badge_type']);
        $unlockedMap[$cleanKey] = date('M j, Y', strtotime($row['unlocked_at']));
    }

    // 5. Build final structured milestones list curated for the active goal
    $milestones = [];
    foreach ($activeKeys as $key) {
        if (!isset($defs[$key])) {
            continue;
        }
        $d = $defs[$key];
        $isUnlocked = isset($unlockedMap[$key]) || ($qualifies[$key]['met'] ?? false);
        $unlockedAt = $unlockedMap[$key] ?? ($isUnlocked ? date('M j, Y') : null);
        $milestones[] = [
            'key' => $key,
            'name' => $d['name'],
            'desc' => $d['desc'],
            'icon' => $d['icon'],
            'unlocked' => $isUnlocked,
            'unlocked_at' => $unlockedAt,
            'progress_label' => $qualifies[$key]['label'] ?? 'In progress',
            'progress_pct' => $isUnlocked ? 100 : ($qualifies[$key]['pct'] ?? 0)
        ];
    }

    return $milestones;
}

/**
 * Renders the modern Goal Hero Card HTML component.
 * Lean, modular, zero-redundant CSS/JS, and fully responsive for mobile screens.
 */
function render_goal_hero_card(array $goalProgress, bool $canEdit = true, bool $isTrainer = false): void
{
    $status = $goalProgress['status'] ?? ['label' => 'Active', 'icon' => '🟢', 'badge_cls' => 'goal-status-ontrack'];
    $metrics = $goalProgress['metrics'] ?? [];
    $habits = $goalProgress['habits'] ?? [];
    $milestones = $goalProgress['milestones'] ?? [];
    $weeklyPct = (int)($habits['weekly_pct'] ?? 0);
    $workoutsDone = (int)($habits['workouts_done'] ?? 0);
    $workoutsTarget = (int)($habits['workouts_target'] ?? 3);
    ?>
    <div class="goal-hero-card" id="goal-hero-card">
        <!-- Top Row: Icon, Title, Status & Actions -->
        <div class="goal-hero-header">
            <div class="goal-hero-title-group">
                <div class="goal-hero-icon-pill" aria-hidden="true">
                    <?= $goalProgress['goal_icon'] ?>
                </div>
                <div class="goal-hero-text">
                    <div class="goal-hero-category"><?= h($goalProgress['goal_category']) ?></div>
                    <h2 class="goal-hero-title"><?= h($goalProgress['goal_title']) ?></h2>
                </div>
            </div>

            <div class="goal-hero-badges-group">
                <div class="goal-status-badge <?= h($status['badge_cls'] ?? 'goal-status-ontrack') ?>">
                    <span class="goal-status-dot"><?= h($status['icon'] ?? '🟢') ?></span>
                    <span><?= h($status['label'] ?? 'On Track') ?></span>
                </div>
                <?php if ($canEdit): ?>
                    <button type="button" class="btn-change-goal btn-adjust-targets" onclick="openTargetMetricsModal()" title="<?= $isTrainer ? 'Adjust client targets' : 'Adjust target metrics' ?>">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 4.24 4.24"/><path d="m14.83 9.17 4.24-4.24"/><path d="m14.83 14.83 4.24 4.24"/><path d="m9.17 14.83-4.24 4.24"/></svg>
                        <span><?= $isTrainer ? 'Adjust Targets' : 'Set Targets' ?></span>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Progress Verdict Explanation Box (or legacy summary fallback) -->
        <?php if (!empty($goalProgress['verdict'])):
            $v = $goalProgress['verdict'];
            $vCls = (string)($v['verdict_cls'] ?? 'verdict-needs-data');
        ?>
            <div class="goal-verdict-box <?= h($vCls) ?>" id="goalVerdictBox">
                <div class="verdict-top-row">
                    <span class="verdict-answer-pill"><?= h($v['icon'] ?? '○') ?> <?= h($v['short_answer'] ?? $v['label'] ?? 'Status') ?></span>
                    <strong class="verdict-headline"><?= h($v['headline'] ?? '') ?></strong>
                </div>
                <?php if (!empty($v['explanation'])): ?>
                    <p class="verdict-explanation"><?= h($v['explanation']) ?></p>
                <?php endif; ?>
                <?php if (!empty($v['signals'])): ?>
                    <div class="verdict-signals-row">
                        <?php foreach ($v['signals'] as $sig):
                            $sigTone = (string)($sig['tone'] ?? 'neutral');
                        ?>
                            <span class="verdict-signal-chip signal-<?= h($sigTone) ?>">
                                <span class="sig-label"><?= h($sig['label'] ?? '') ?>:</span>
                                <strong class="sig-val"><?= h($sig['value'] ?? '') ?></strong>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($v['next_step'])): ?>
                    <div class="verdict-next-step">
                        <span class="next-step-tag">Next step</span>
                        <span><?= h($v['next_step']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        <?php elseif (!empty($goalProgress['headline_summary'])): ?>
            <p class="goal-hero-summary"><?= h($goalProgress['headline_summary']) ?></p>
        <?php endif; ?>

        <!-- KPI Metrics Grid -->
        <?php if (!empty($metrics)): ?>
            <div class="goal-kpi-grid">
                <?php foreach ($metrics as $m): ?>
                    <div class="goal-kpi-card">
                        <div class="goal-kpi-top">
                            <span class="goal-kpi-label"><?= h($m['label']) ?></span>
                            <?php if (!empty($m['badge'])): ?>
                                <span class="goal-kpi-pill <?= ($m['type'] ?? '') === 'good' ? 'pill-good' : 'pill-neutral' ?>"><?= h($m['badge']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="goal-kpi-val-row">
                            <span class="goal-kpi-val"><?= h($m['current']) ?></span>
                            <?php if (!empty($m['unit'])): ?>
                                <span class="goal-kpi-unit"><?= h($m['unit']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($m['target'])): ?>
                                <span class="goal-kpi-target">/ <?= h($m['target']) ?><?= !empty($m['unit']) ? ' ' . h($m['unit']) : '' ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (isset($m['progress_pct']) && $m['progress_pct'] !== null): ?>
                            <div class="goal-kpi-bar-track">
                                <div class="goal-kpi-bar-fill" style="width: <?= (int)$m['progress_pct'] ?>%;"></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Milestone Badges Shelf -->
        <?php if (!empty($milestones)): ?>
            <div class="goal-milestones-shelf">
                <div class="milestones-shelf-header">
                    <div class="m-shelf-title">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="color: var(--lime);"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>
                        <span>Goal Milestones & Achievements</span>
                    </div>
                    <span class="m-shelf-count"><?= (int)count(array_filter($milestones, fn($m) => $m['unlocked'])) ?> / <?= count($milestones) ?> Unlocked</span>
                </div>
                <div class="milestones-pills-scroll">
                    <?php foreach ($milestones as $ms): ?>
                        <div class="milestone-pill <?= $ms['unlocked'] ? 'unlocked' : 'locked' ?>" title="<?= h($ms['desc']) . ($ms['unlocked'] ? ' • Unlocked ' . h($ms['unlocked_at']) : ' • ' . h($ms['progress_label'])) ?>">
                            <span class="m-pill-icon"><?= $ms['icon'] ?></span>
                            <div class="m-pill-info">
                                <span class="m-pill-name"><?= h($ms['name']) ?></span>
                                <span class="m-pill-meta"><?= $ms['unlocked'] ? 'Unlocked' : h($ms['progress_label']) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Milestone & Habit Footer -->
        <div class="goal-hero-footer">
            <div class="goal-milestone-tip">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" class="milestone-icon"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                <span><?= h($goalProgress['milestone_note'] ?? 'Consistency is the engine of body transformation.') ?></span>
            </div>
            <div class="goal-habit-tracker">
                <span class="habit-tracker-text">Target: <strong><?= $workoutsDone ?></strong> of <strong><?= $workoutsTarget ?></strong> sessions (<?= $weeklyPct ?>%)</span>
                <div class="habit-bar-wrap">
                    <div class="habit-bar-fill" style="width: <?= $weeklyPct ?>%;"></div>
                </div>
            </div>
        </div>
    </div>
    <?php
}
