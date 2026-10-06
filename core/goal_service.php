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

    // Determine status & specific KPIs based on model
    switch ($model) {
        case 'strength':
            $targetExercise = !empty($profile['target_exercise']) ? (string)$profile['target_exercise'] : 'Primary Compound Lift';
            $currMax = !empty($profile['current_strength_max_kg']) ? (float)$profile['current_strength_max_kg'] : 0.0;
            $targetMax = !empty($profile['target_strength_max_kg']) ? (float)$profile['target_strength_max_kg'] : 0.0;

            $liftPct = null;
            if ($targetMax > 0 && $currMax > 0) {
                $liftPct = (int)min(100, round(($currMax / $targetMax) * 100));
            }

            $prsCount = max(1, (int)floor($exercisesCompleted30d / 6));
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
                    'label' => 'Personal Records',
                    'current' => "+{$prsCount}",
                    'target' => null,
                    'unit' => 'PRs',
                    'badge' => 'This month',
                    'type' => 'good',
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
    $result['milestones'] = evaluate_and_award_goal_milestones($memberId, $profile, $progressLogs);

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
 * Evaluates and awards goal milestone badges into member_badges, returning structured milestone items.
 */
function evaluate_and_award_goal_milestones(int $memberId, array $profile, array $progressLogs = []): array
{
    if ($memberId <= 0) {
        return [];
    }

    $defs = get_all_goal_milestone_definitions();
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

    // Week streak calculation: distinct calendar weeks in progress logs
    $logWeeks = [];
    foreach ($progressLogs as $pl) {
        if (!empty($pl['log_date'])) {
            $logWeeks[date('Y-W', strtotime($pl['log_date']))] = true;
        }
    }
    $totalWeeksLogged = count($logWeeks);

    // 2. Evaluate criteria for each milestone
    $qualifies = [];

    // Milestone 1: Journey Begun
    $qualifies['first_log'] = [
        'met' => count($progressLogs) >= 1,
        'label' => count($progressLogs) >= 1 ? 'Logged' : '0 / 1 log',
        'pct' => count($progressLogs) >= 1 ? 100 : 0
    ];

    // Milestone 2: Waist Sculptor (drop >= 3.0 cm)
    $waistDrop = ($baseWaist > 0 && $currWaist > 0) ? round($baseWaist - $currWaist, 1) : 0.0;
    $qualifies['waist_trimmer'] = [
        'met' => $waistDrop >= 3.0,
        'label' => max(0, $waistDrop) . ' / 3.0 cm',
        'pct' => (int)min(100, max(0, round(($waistDrop / 3.0) * 100)))
    ];

    // Milestone 3: Lean Machine (drop >= 2.5% body fat)
    $bfDrop = ($baseBf !== null && $currBf !== null) ? round($baseBf - $currBf, 1) : 0.0;
    $qualifies['lean_warrior'] = [
        'met' => $bfDrop >= 2.5,
        'label' => max(0, $bfDrop) . ' / 2.5%',
        'pct' => (int)min(100, max(0, round(($bfDrop / 2.5) * 100)))
    ];

    // Milestone 4: Arm Builder (gain >= 1.5 cm)
    $armGain = ($baseArm > 0 && $currArm > 0) ? round($currArm - $baseArm, 1) : 0.0;
    $qualifies['arm_builder'] = [
        'met' => $armGain >= 1.5,
        'label' => max(0, $armGain) . ' / 1.5 cm',
        'pct' => (int)min(100, max(0, round(($armGain / 1.5) * 100)))
    ];

    // Milestone 5: Chest Armor (gain >= 2.0 cm)
    $chestGain = ($baseChest > 0 && $currChest > 0) ? round($currChest - $baseChest, 1) : 0.0;
    $qualifies['chest_sculptor'] = [
        'met' => $chestGain >= 2.0,
        'label' => max(0, $chestGain) . ' / 2.0 cm',
        'pct' => (int)min(100, max(0, round(($chestGain / 2.0) * 100)))
    ];

    // Milestone 6: Target Achieved
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

    // Milestone 7: Habit Master (4+ unique weeks)
    $qualifies['consistency_streak'] = [
        'met' => $totalWeeksLogged >= 4,
        'label' => min(4, $totalWeeksLogged) . ' / 4 wks',
        'pct' => (int)min(100, round(($totalWeeksLogged / 4) * 100))
    ];

    // 3. Persist newly met milestones into member_badges
    $badgesToInsert = [];
    foreach ($qualifies as $key => $status) {
        if ($status['met']) {
            $badgesToInsert[] = 'milestone_' . $key;
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
    }

    // 4. Query current user's unlocked badges
    $stmt = $pdo->prepare('SELECT badge_type, unlocked_at FROM member_badges WHERE user_id = ? AND badge_type LIKE "milestone_%"');
    $stmt->execute([$memberId]);
    $unlockedMap = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cleanKey = str_replace('milestone_', '', $row['badge_type']);
        $unlockedMap[$cleanKey] = date('M j, Y', strtotime($row['unlocked_at']));
    }

    // 5. Build final structured milestones list
    $milestones = [];
    foreach ($defs as $key => $d) {
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
                    <?php if (!$isTrainer): ?>
                        <button type="button" class="btn-change-goal" onclick="if(typeof openProfileModal === 'function'){ openProfileModal('goal'); } else { window.location.href='index.php?page=profile#goal'; }" title="Change goal">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            <span>Change Goal</span>
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Headline Summary -->
        <?php if (!empty($goalProgress['headline_summary'])): ?>
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
