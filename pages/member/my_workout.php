<?php

declare(strict_types=1);

function my_workout_page(): void
{
    $user = require_roles(['member']);
    $pdo = db();
    $userId = (int) $user['user_id'];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $hasTrainerPlan = scalar('SELECT 1 FROM training_plans WHERE member_user_id = ? AND trainer_id IS NOT NULL AND status = "active" LIMIT 1', [$userId]);
        if ($hasTrainerPlan) {
            flash('Your trainer has published a workout plan for you. You cannot overwrite it.', 'danger');
        } else {
            generate_workout_plan($userId);
            notify_user($userId, 'system', 'Workout Plan Generated', 'Your weekly exercise schedule has been refreshed based on your current profile.');
            flash('Workout plan generated successfully!', 'success');
        }
        redirect('my_workout');
    }

    render_header('My Workout', $user);

    // Fetch active training plan for this member
    $stmt = $pdo->prepare(
        'SELECT p.*, tp.user_id AS t_user_id, u.first_name AS t_first, u.last_name AS t_last 
         FROM training_plans p 
         LEFT JOIN trainer_profiles tp ON p.trainer_id = tp.trainer_id 
         LEFT JOIN users u ON u.user_id = tp.user_id 
         WHERE p.member_user_id = ? AND p.status = "active" 
         ORDER BY p.plan_id DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
    $plan = $stmt->fetch();

    $hasTrainerPlan = !empty($plan['trainer_id']);
    $trainerName = $hasTrainerPlan ? h(trim(($plan['t_first'] ?? '') . ' ' . ($plan['t_last'] ?? ''))) : 'Self-Guided Workout';

    // Timezone and Month Setup
    $today = date('Y-m-d');
    $currentMonthStr = date('Y-m');

    $monthParam = (string) ($_GET['month'] ?? $currentMonthStr);
    if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
        $monthParam = $currentMonthStr;
    }

    $firstDayOfMonth = $monthParam . '-01';
    $daysInMonth = (int) date('t', strtotime($firstDayOfMonth));
    $lastDayOfMonth = date('Y-m-d', strtotime($monthParam . '-' . str_pad((string)$daysInMonth, 2, '0', STR_PAD_LEFT)));
    $monthDisplay = date('F Y', strtotime($firstDayOfMonth));

    $prevMonth = date('Y-m', strtotime('-1 month', strtotime($firstDayOfMonth)));
    $nextMonth = date('Y-m', strtotime('+1 month', strtotime($firstDayOfMonth)));

    // Fetch plan exercises grouped by day_of_week (1 = Monday ... 7 = Sunday)
    $planExercises = [];
    $exercisesByDow = [];
    if ($plan) {
        $stmtEx = $pdo->prepare(
            'SELECT tpe.plan_exercise_id, tpe.exercise_id, tpe.day_of_week, tpe.sequence_order, 
                    tpe.sets, tpe.reps, tpe.target_weight_kg, tpe.rest_seconds, tpe.notes, 
                    e.name, e.category, e.muscle_group, e.animation_url, e.difficulty_level 
             FROM training_plan_exercises tpe 
             JOIN exercises e ON e.exercise_id = tpe.exercise_id 
             WHERE tpe.plan_id = ? 
             ORDER BY tpe.day_of_week, tpe.sequence_order'
        );
        $stmtEx->execute([(int)$plan['plan_id']]);
        $planExercises = $stmtEx->fetchAll(PDO::FETCH_ASSOC);

        foreach ($planExercises as $ex) {
            $dow = (int) $ex['day_of_week'];
            $exercisesByDow[$dow][] = $ex;
        }
    }

    // Calendar grid calculations
    $firstDow = (int) date('N', strtotime($firstDayOfMonth)); // 1 (Mon) to 7 (Sun)
    $leadingDaysCount = $firstDow - 1;

    $lastDow = (int) date('N', strtotime($lastDayOfMonth));
    $trailingDaysCount = 7 - $lastDow;

    $gridStartDate = date('Y-m-d', strtotime("-{$leadingDaysCount} days", strtotime($firstDayOfMonth)));
    $gridEndDate = date('Y-m-d', strtotime("+{$trailingDaysCount} days", strtotime($lastDayOfMonth)));

    // Fetch all completions in grid range for this user & plan
    $completionsByDate = [];
    if ($plan) {
        $stmtComp = $pdo->prepare(
            'SELECT exercise_id, completed_date 
             FROM exercise_completions 
             WHERE user_id = ? AND plan_id = ? AND completed_date >= ? AND completed_date <= ?'
        );
        $stmtComp->execute([$userId, (int)$plan['plan_id'], $gridStartDate, $gridEndDate]);
        $compRows = $stmtComp->fetchAll(PDO::FETCH_ASSOC);
        foreach ($compRows as $c) {
            $cDate = $c['completed_date'];
            $completionsByDate[$cDate][] = (int) $c['exercise_id'];
        }
    }

    // Build day map for calendar & client-side rendering
    $calendarDays = [];
    $totalDaysToRender = $leadingDaysCount + $daysInMonth + $trailingDaysCount;

    // Monthly Metrics Counters (strictly for dates in this month)
    $monthCompletedCount = 0;
    $monthRemainingCount = 0;
    $monthScheduledPastCount = 0;
    $monthCompletedPastCount = 0;

    for ($i = 0; $i < $totalDaysToRender; $i++) {
        $dateStr = date('Y-m-d', strtotime("+{$i} days", strtotime($gridStartDate)));
        $dayNum = (int) date('j', strtotime($dateStr));
        $dow = (int) date('N', strtotime($dateStr));
        $isCurrentMonth = (date('Y-m', strtotime($dateStr)) === $monthParam);
        $isToday = ($dateStr === $today);
        $isPast = ($dateStr < $today);
        $isFuture = ($dateStr > $today);

        $scheduled = $exercisesByDow[$dow] ?? [];
        $scheduledCount = count($scheduled);
        $hasWorkout = $scheduledCount > 0;

        $completedExIds = $completionsByDate[$dateStr] ?? [];
        $completedCount = 0;
        $allDone = $hasWorkout;

        $exercisesForDay = [];
        foreach ($scheduled as $ex) {
            $isComp = in_array((int)$ex['exercise_id'], $completedExIds, true);
            if ($isComp) {
                $completedCount++;
            } else {
                $allDone = false;
            }
            $exercisesForDay[] = [
                'exercise_id' => (int)$ex['exercise_id'],
                'name' => $ex['name'],
                'category' => $ex['category'],
                'muscle_group' => $ex['muscle_group'],
                'sets' => (int)($ex['sets'] ?? 3),
                'reps' => $ex['reps'] ?? '10',
                'target_weight_kg' => $ex['target_weight_kg'] !== null ? (float)$ex['target_weight_kg'] : null,
                'rest_seconds' => (int)($ex['rest_seconds'] ?? 60),
                'notes' => $ex['notes'] ?? '',
                'animation_url' => $ex['animation_url'] ?? '',
                'is_completed' => $isComp
            ];
        }

        // Status Determination
        $status = 'rest';
        if (!$hasWorkout) {
            $status = 'rest';
        } else {
            if ($allDone) {
                $status = 'completed';
            } elseif ($isPast) {
                $status = 'missed';
            } elseif ($isToday) {
                $status = ($completedCount > 0) ? 'today_in_progress' : 'today_scheduled';
            } else {
                $status = 'future_scheduled';
            }
        }

        // Focus & Duration
        $focusTitle = 'Rest & Recovery';
        $estDurationMinutes = 0;
        if ($hasWorkout) {
            $muscles = array_filter(array_unique(array_map(function ($e) {
                return ucwords((string)($e['muscle_group'] ?? ''));
            }, $scheduled)));
            if (!empty($muscles)) {
                $focusTitle = implode(' & ', array_slice($muscles, 0, 2)) . ' Focus';
            } else {
                $focusTitle = 'Daily Training';
            }

            $totalSeconds = 0;
            foreach ($scheduled as $e) {
                $sets = (int)($e['sets'] ?? 3);
                $rest = (int)($e['rest_seconds'] ?? 60);
                $totalSeconds += $sets * (45 + $rest);
            }
            $estDurationMinutes = max(15, (int)round($totalSeconds / 60));
        }

        // Tally Monthly Metrics if inside selected month
        if ($isCurrentMonth && $hasWorkout) {
            if ($allDone) {
                $monthCompletedCount++;
            }
            if ($isFuture || ($isToday && !$allDone)) {
                $monthRemainingCount++;
            }
            if ($isPast || ($isToday && $allDone)) {
                $monthScheduledPastCount++;
                if ($allDone) {
                    $monthCompletedPastCount++;
                }
            } elseif ($isToday && !$allDone) {
                $monthScheduledPastCount++;
            }
        }

        $calendarDays[$dateStr] = [
            'date' => $dateStr,
            'dayNum' => $dayNum,
            'dow' => $dow,
            'dayName' => date('l', strtotime($dateStr)),
            'formattedDate' => date('l, F j, Y', strtotime($dateStr)),
            'isCurrentMonth' => $isCurrentMonth,
            'isToday' => $isToday,
            'isPast' => $isPast,
            'isFuture' => $isFuture,
            'hasWorkout' => $hasWorkout,
            'status' => $status,
            'focusTitle' => $focusTitle,
            'estDurationMinutes' => $estDurationMinutes,
            'scheduledCount' => $scheduledCount,
            'completedCount' => $completedCount,
            'allDone' => $allDone,
            'exercises' => $exercisesForDay
        ];
    }

    $completionRate = ($monthScheduledPastCount > 0)
        ? (int) round(($monthCompletedPastCount / $monthScheduledPastCount) * 100)
        : 100;

    // Default selected date: Today if in current month, else first day of selected month
    $initialSelectedDate = ($monthParam === $currentMonthStr) ? $today : $firstDayOfMonth;
    if (!isset($calendarDays[$initialSelectedDate])) {
        $initialSelectedDate = array_key_first($calendarDays);
    }
?>
    <style>
        /* Contextual Action Strip */
        .workout-action-strip {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            margin-bottom: 22px;
            background: var(--panel);
            border: 1px solid var(--line);
            padding: 12px 18px;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }
        .workout-strip-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            min-width: 0;
        }
        .workout-goal-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: color-mix(in srgb, var(--lime) 12%, transparent);
            color: var(--ink);
            border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            max-width: 100%;
            min-width: 0;
        }
        .workout-goal-pill .goal-text {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .workout-assigned-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--panel-soft);
            color: var(--ink);
            border: 1px solid var(--line);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
        }
        .workout-action-form {
            margin: 0;
            flex-shrink: 0;
        }
        .btn-workout-action {
            background: var(--panel-soft);
            color: var(--ink);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .btn-workout-action:hover {
            border-color: var(--lime);
            color: var(--lime);
        }

        /* 2-Column Above-the-fold Layout */
        .workout-dashboard-grid {
            display: grid;
            grid-template-columns: 360px 1fr;
            gap: 22px;
            align-items: start;
            margin-bottom: 30px;
        }

        /* Left Column: Progress & Calendar */
        .workout-left-col {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        /* Monthly Progress Card */
        .monthly-progress-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 16px 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }
        .monthly-progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .monthly-progress-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin: 0;
        }
        .monthly-progress-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            text-align: center;
        }
        .stat-mini-box {
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 8px;
        }
        .stat-mini-val {
            font-size: 18px;
            font-weight: 800;
            color: var(--ink);
            line-height: 1.2;
            display: block;
        }
        .stat-mini-val.lime-val {
            color: var(--lime);
        }
        .stat-mini-label {
            font-size: 11px;
            color: var(--muted);
            font-weight: 600;
            margin-top: 4px;
            display: block;
            line-height: 1.2;
        }

        /* Compact Calendar Card */
        .compact-calendar-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 16px 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }
        .calendar-nav-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--line);
        }
        .calendar-month-title {
            font-size: 15px;
            font-weight: 800;
            color: var(--ink);
            margin: 0;
        }
        .calendar-nav-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .cal-nav-btn {
            background: var(--panel-soft);
            border: 1px solid var(--line);
            color: var(--ink);
            width: 30px;
            height: 30px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .cal-nav-btn:hover {
            border-color: var(--lime);
            color: var(--lime);
        }
        .cal-today-btn {
            background: color-mix(in srgb, var(--lime) 12%, transparent);
            border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
            color: var(--ink);
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .cal-today-btn:hover {
            background: var(--lime);
            color: var(--lime-btn-text, #090b10);
        }

        /* Calendar Grid */
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
        }
        .calendar-dow-header {
            font-size: 11px;
            font-weight: 700;
            color: var(--muted);
            text-align: center;
            padding-bottom: 8px;
            text-transform: uppercase;
        }
        .cal-day-cell {
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 6px 2px;
            min-height: 52px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: all 0.15s ease;
            position: relative;
            user-select: none;
            text-decoration: none;
        }
        .cal-day-cell:hover {
            border-color: var(--lime);
            transform: translateY(-1px);
        }
        .cal-day-cell.is-other-month {
            opacity: 0.35;
        }
        .cal-day-cell.is-selected {
            border-color: var(--lime) !important;
            box-shadow: 0 0 0 2px color-mix(in srgb, var(--lime) 35%, transparent);
            background: color-mix(in srgb, var(--lime) 8%, var(--bg));
        }
        .cal-day-cell.is-today {
            border-color: var(--lime);
        }
        .cal-day-num {
            font-size: 12px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1;
        }
        .cal-today-badge {
            font-size: 8px;
            font-weight: 800;
            text-transform: uppercase;
            background: var(--lime);
            color: var(--lime-btn-text, #090b10);
            padding: 1px 4px;
            border-radius: 4px;
            margin-top: 1px;
            line-height: 1.1;
        }
        .cal-status-badge {
            font-size: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 14px;
            line-height: 1;
        }
        .cal-badge-completed {
            color: #2df0a5;
            font-weight: 900;
        }
        .cal-badge-missed {
            color: #ef4444;
            font-weight: 900;
        }
        .cal-badge-scheduled {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--lime);
            display: inline-block;
        }
        .cal-badge-rest {
            color: var(--muted);
            font-size: 9px;
            opacity: 0.7;
        }

        /* Calendar Legend */
        .calendar-legend {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid var(--line);
            flex-wrap: wrap;
            font-size: 11px;
            color: var(--muted);
        }
        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Right Column: Selected Workout Panel */
        .selected-workout-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            min-height: 480px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .workout-card-top {
            margin-bottom: 20px;
        }
        .workout-date-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .workout-date-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
            margin: 0;
        }
        .workout-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }
        .workout-title-text {
            font-size: 22px;
            font-weight: 800;
            color: var(--ink);
            margin: 0 0 6px 0;
            line-height: 1.25;
        }
        .workout-meta-line {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13px;
            color: var(--muted);
            flex-wrap: wrap;
        }
        .workout-meta-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Action Buttons Area */
        .workout-action-banner {
            margin-top: 14px;
            margin-bottom: 22px;
        }
        .btn-live-workout {
            background: var(--accent, #7c5cfc);
            color: #ffffff;
            border: none;
            padding: 13px 26px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            cursor: pointer;
            box-shadow: 0 4px 18px rgba(124,92,252,0.35);
            transition: transform 0.15s ease, opacity 0.15s ease;
            width: 100%;
        }
        .btn-live-workout:hover {
            transform: translateY(-2px);
            opacity: 0.95;
        }
        .btn-live-workout:active {
            transform: translateY(0);
        }

        /* Status Callout Banners */
        .callout-completed {
            background: rgba(45, 240, 165, 0.1);
            border: 1px solid rgba(45, 240, 165, 0.25);
            padding: 14px 18px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #2df0a5;
        }
        .callout-missed {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.25);
            padding: 14px 18px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #ef4444;
        }
        .callout-future {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--line);
            padding: 14px 18px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--muted);
        }
        .callout-rest {
            background: rgba(255, 255, 255, 0.03);
            border: 1px dashed var(--line);
            padding: 40px 20px;
            border-radius: 12px;
            text-align: center;
            color: var(--muted);
            margin: 20px 0;
        }

        /* Exercise Items List */
        .exercise-list-container {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .exercise-item-card {
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.15s ease;
        }
        .exercise-item-card.is-completed {
            opacity: 0.7;
            background: color-mix(in srgb, var(--bg) 60%, transparent);
        }
        .exercise-item-info {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }
        .exercise-idx-badge {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: var(--panel-soft);
            border: 1px solid var(--line);
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .exercise-item-card.is-completed .exercise-idx-badge {
            background: rgba(45, 240, 165, 0.15);
            border-color: rgba(45, 240, 165, 0.3);
            color: #2df0a5;
        }
        .exercise-details-wrap {
            min-width: 0;
        }
        .exercise-name-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--ink);
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .exercise-item-card.is-completed .exercise-name-title {
            text-decoration: line-through;
        }
        .exercise-specs-text {
            font-size: 12px;
            color: var(--muted);
            margin-top: 2px;
        }
        .btn-mark-exercise {
            background: var(--panel-soft);
            border: 1px solid var(--line);
            color: var(--ink);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .btn-mark-exercise:hover {
            background: var(--lime);
            color: var(--lime-btn-text, #090b10);
            border-color: var(--lime);
        }

        /* Responsive Breakpoint */
        @media (max-width: 900px) {
            .workout-dashboard-grid {
                grid-template-columns: 1fr;
            }
            .workout-action-strip {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 12px !important;
                padding: 14px 16px !important;
                margin-bottom: 18px !important;
            }
            .workout-strip-meta {
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
                width: 100% !important;
                gap: 8px !important;
            }
            .workout-action-form {
                width: 100% !important;
            }
            .btn-workout-action {
                width: 100% !important;
                padding: 11px 16px !important;
                font-size: 14px !important;
                border-radius: 10px !important;
            }
        }
        @media (max-width: 520px) {
            .workout-strip-meta {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 8px !important;
            }
            .workout-goal-pill,
            .workout-assigned-pill {
                width: 100% !important;
                box-sizing: border-box !important;
                justify-content: flex-start !important;
                font-size: 12.5px !important;
                padding: 6px 12px !important;
            }
            .stat-mini-val {
                font-size: 16px;
            }
            .cal-day-cell {
                min-height: 46px;
            }
        }
    </style>

    <div style="max-width: 1200px; margin: 0 auto; padding-bottom: 60px;">
        <!-- Contextual Action Strip -->
        <div class="workout-action-strip">
            <div class="workout-strip-meta">
                <?php if ($plan && !empty($plan['goal'])): ?>
                    <span class="workout-goal-pill">
                        <span style="width: 7px; height: 7px; border-radius: 50%; background: var(--lime); display: inline-block; flex-shrink: 0;"></span>
                        <span style="color: var(--muted); font-weight: 500;">Goal:</span>
                        <strong class="goal-text" style="color: var(--ink); font-weight: 700;"><?= h(ucwords(str_replace('_', ' ', $plan['goal']))) ?></strong>
                    </span>
                <?php endif; ?>

                <span class="workout-assigned-pill">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span style="color: var(--muted); font-weight: 500;"><?= $hasTrainerPlan ? 'Assigned by:' : 'Mode:' ?></span>
                    <strong style="color: var(--ink); font-weight: 600;"><?= $trainerName ?></strong>
                </span>
            </div>

            <?php if (!$hasTrainerPlan): ?>
                <form id="regenerate-plan-form" class="workout-action-form" method="post">
                    <?= csrf_field() ?>
                    <button type="submit" data-confirm="<?= $plan ? 'This will archive your current plan and generate a new one based on your profile. Continue?' : 'Generate a new AI workout plan?' ?>" data-confirm-btn="<?= $plan ? 'Yes, Regenerate Plan' : 'Yes, Generate Plan' ?>" class="btn btn-workout-action">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.92-10.26l5.67-5.67"/></svg>
                        <span><?= $plan ? 'Regenerate Plan' : 'Generate Plan' ?></span>
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($plan): ?>
            <!-- Above-the-fold 2-column Dashboard Grid -->
            <div class="workout-dashboard-grid">
                
                <!-- Left Column: Monthly Progress & Calendar -->
                <div class="workout-left-col">
                    
                    <!-- Monthly Progress Card -->
                    <div class="monthly-progress-card">
                        <div class="monthly-progress-header">
                            <h3 class="monthly-progress-title"><?= date('M Y', strtotime($firstDayOfMonth)) ?> Progress</h3>
                            <span style="font-size: 12px; color: var(--muted); font-weight: 600;">Real-time</span>
                        </div>
                        <div class="monthly-progress-stats">
                            <div class="stat-mini-box">
                                <span class="stat-mini-val lime-val"><?= $monthCompletedCount ?></span>
                                <span class="stat-mini-label">Completed</span>
                            </div>
                            <div class="stat-mini-box">
                                <span class="stat-mini-val"><?= $monthRemainingCount ?></span>
                                <span class="stat-mini-label">Remaining</span>
                            </div>
                            <div class="stat-mini-box">
                                <span class="stat-mini-val"><?= $completionRate ?>%</span>
                                <span class="stat-mini-label">Completion</span>
                            </div>
                        </div>
                    </div>

                    <!-- Compact Monthly Calendar Card -->
                    <div class="compact-calendar-card">
                        <div class="calendar-nav-bar">
                            <h3 class="calendar-month-title"><?= $monthDisplay ?></h3>
                            <div class="calendar-nav-actions">
                                <a href="index.php?page=my_workout&month=<?= $prevMonth ?>" class="cal-nav-btn" title="Previous Month" aria-label="Previous Month">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                                </a>
                                <?php if ($monthParam !== $currentMonthStr): ?>
                                    <a href="index.php?page=my_workout&month=<?= $currentMonthStr ?>" class="cal-today-btn">Today</a>
                                <?php else: ?>
                                    <button type="button" onclick="selectDate('<?= $today ?>')" class="cal-today-btn">Today</button>
                                <?php endif; ?>
                                <a href="index.php?page=my_workout&month=<?= $nextMonth ?>" class="cal-nav-btn" title="Next Month" aria-label="Next Month">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                                </a>
                            </div>
                        </div>

                        <!-- 7 Days Grid -->
                        <div class="calendar-grid">
                            <div class="calendar-dow-header">Mon</div>
                            <div class="calendar-dow-header">Tue</div>
                            <div class="calendar-dow-header">Wed</div>
                            <div class="calendar-dow-header">Thu</div>
                            <div class="calendar-dow-header">Fri</div>
                            <div class="calendar-dow-header">Sat</div>
                            <div class="calendar-dow-header">Sun</div>

                            <?php foreach ($calendarDays as $day): 
                                $cellClass = 'cal-day-cell';
                                if (!$day['isCurrentMonth']) $cellClass .= ' is-other-month';
                                if ($day['isToday']) $cellClass .= ' is-today';
                                if ($day['date'] === $initialSelectedDate) $cellClass .= ' is-selected';
                            ?>
                                <div class="<?= $cellClass ?>" 
                                     id="cal-cell-<?= $day['date'] ?>"
                                     onclick="selectDate('<?= $day['date'] ?>')"
                                     role="button"
                                     tabindex="0"
                                     aria-label="<?= $day['formattedDate'] ?> - <?= ucfirst($day['status']) ?>">
                                    <span class="cal-day-num"><?= $day['dayNum'] ?></span>
                                    
                                    <?php if ($day['isToday']): ?>
                                        <span class="cal-today-badge">Today</span>
                                    <?php endif; ?>

                                    <div class="cal-status-badge">
                                        <?php if ($day['status'] === 'completed'): ?>
                                            <span class="cal-badge-completed" title="Workout Completed">&#10003;</span>
                                        <?php elseif ($day['status'] === 'missed'): ?>
                                            <span class="cal-badge-missed" title="Missed Workout">!</span>
                                        <?php elseif ($day['status'] === 'today_scheduled' || $day['status'] === 'future_scheduled'): ?>
                                            <span class="cal-badge-scheduled" title="Workout Scheduled"></span>
                                        <?php elseif ($day['status'] === 'today_in_progress'): ?>
                                            <span class="cal-badge-scheduled" style="background:#38bdf8;" title="In Progress"></span>
                                        <?php else: ?>
                                            <span class="cal-badge-rest" title="Rest Day">&mdash;</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Calendar Legend -->
                        <div class="calendar-legend">
                            <span class="legend-item"><span class="cal-badge-completed" style="font-size:12px;">&#10003;</span> Completed</span>
                            <span class="legend-item"><span class="cal-badge-scheduled"></span> Scheduled</span>
                            <span class="legend-item"><span class="cal-badge-rest">&mdash;</span> Rest</span>
                            <span class="legend-item"><span class="cal-badge-missed">!</span> Missed</span>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Selected Day Workout Panel -->
                <div class="workout-right-col">
                    <div id="selected-workout-card" class="selected-workout-card">
                        <!-- Content dynamically rendered via JS -->
                    </div>
                </div>

            </div>

            <!-- Live Workout Player Modal (Preserved for Today's Workout) -->
            <div id="workout-player-modal" class="workout-player-modal" style="display: none;">
                <div class="player-container">
                    <button class="player-close" onclick="closeLiveWorkout()">&times;</button>

                    <div id="player-progress" class="player-progress-bar">
                        <div class="progress-fill" style="width: 0%;"></div>
                    </div>

                    <div class="player-content">
                        <div id="player-header">
                            <span id="player-exercise-count" class="player-pill">Exercise 1 of X</span>
                            <h2 id="player-exercise-name">Exercise Name</h2>
                            <p id="player-exercise-target" style="color: var(--muted); font-size: 18px; margin-top: 8px;">3 Sets &times; 10 Reps</p>

                            <div id="player-animation-container" style="display: none; justify-content: center; margin-top: 24px; position: relative; min-height: 200px; align-items: center; background: rgba(0,0,0,0.2); border: 1px solid var(--line); border-radius: 12px; overflow: hidden;">
                                <video id="player-animation-video" width="100%" style="max-height: 250px; border-radius: 12px; object-fit: contain; display: none;" autoplay loop muted playsinline></video>
                                <img id="player-animation-img" style="max-height: 250px; width: 100%; border-radius: 12px; object-fit: contain; display: none;" alt="Exercise animation">
                                <div id="player-animation-fallback" style="display: none; color: var(--muted); text-align: center; font-size: 14px; position: absolute; padding: 16px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:8px; opacity:0.5;">
                                        <polygon points="23 7 16 12 23 17 23 7" />
                                        <rect x="1" y="5" width="15" height="14" rx="2" ry="2" />
                                    </svg><br>
                                    Animation not available at the moment
                                </div>
                            </div>
                        </div>

                        <div id="player-timer-screen" style="display: none; text-align: center; margin-top: 40px;">
                            <h3 style="color: var(--lime); margin-bottom: 10px;">Rest</h3>
                            <div class="timer-ring">
                                <span id="player-timer-text">60</span>
                            </div>
                            <button onclick="skipRest()" class="player-btn-secondary" style="margin-top: 20px;">Skip Rest</button>
                        </div>

                        <div id="player-controls" style="margin-top: 60px; text-align: center;">
                            <button id="btn-complete-set" onclick="completeSet()" class="player-btn-primary">Complete Set 1</button>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                const CURRENT_USER_ID = <?= (int)$userId ?>;
                const PLAN_ID = <?= (int)$plan['plan_id'] ?>;
                const TODAY_DATE = <?= json_encode($today) ?>;
                const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
                const CALENDAR_DATA = <?= json_encode($calendarDays) ?>;
                let selectedDate = <?= json_encode($initialSelectedDate) ?>;

                // Live player state
                let liveExercises = [];
                let currentExIndex = 0;
                let currentSet = 1;
                let restTimer = null;

                function selectDate(dateStr) {
                    if (!CALENDAR_DATA[dateStr]) return;
                    selectedDate = dateStr;

                    // Update calendar day selected styling
                    document.querySelectorAll('.cal-day-cell').forEach(cell => {
                        cell.classList.remove('is-selected');
                    });
                    const targetCell = document.getElementById('cal-cell-' + dateStr);
                    if (targetCell) {
                        targetCell.classList.add('is-selected');
                    }

                    renderWorkoutDetails(dateStr);
                }

                function renderWorkoutDetails(dateStr) {
                    const day = CALENDAR_DATA[dateStr];
                    const container = document.getElementById('selected-workout-card');
                    if (!day || !container) return;

                    let statusBadgeHtml = '';
                    if (day.status === 'completed') {
                        statusBadgeHtml = '<span class="workout-status-pill" style="background: rgba(45,240,165,0.15); color: #2df0a5; border: 1px solid rgba(45,240,165,0.3);">&#10003; Completed</span>';
                    } else if (day.status === 'missed') {
                        statusBadgeHtml = '<span class="workout-status-pill" style="background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3);">! Missed</span>';
                    } else if (day.isToday) {
                        statusBadgeHtml = '<span class="workout-status-pill" style="background: color-mix(in srgb, var(--lime) 15%, transparent); color: var(--lime); border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);">Today\'s Workout</span>';
                    } else if (day.isFuture && day.hasWorkout) {
                        statusBadgeHtml = '<span class="workout-status-pill" style="background: var(--panel-soft); color: var(--muted); border: 1px solid var(--line);">&#9679; Scheduled</span>';
                    } else {
                        statusBadgeHtml = '<span class="workout-status-pill" style="background: var(--panel-soft); color: var(--muted); border: 1px solid var(--line);">&mdash; Rest Day</span>';
                    }

                    let actionBannerHtml = '';
                    if (day.hasWorkout) {
                        if (day.isToday) {
                            if (day.allDone) {
                                actionBannerHtml = `
                                    <div class="callout-completed">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                        <div>
                                            <strong style="font-size: 15px; display: block;">Workout Complete!</strong>
                                            <span style="font-size: 13px; opacity: 0.85;">You crushed all your assigned exercises for today.</span>
                                        </div>
                                    </div>
                                `;
                            } else {
                                const pendingCount = day.exercises.filter(e => !e.is_completed).length;
                                actionBannerHtml = `
                                    <div class="workout-action-banner">
                                        <button onclick="startLiveWorkoutForToday()" class="btn-live-workout">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <polygon points="5 3 19 12 5 21 5 3"></polygon>
                                            </svg>
                                            <span>${day.completedCount > 0 ? 'Resume Live Workout' : 'Start Live Workout'} (${pendingCount} left)</span>
                                        </button>
                                    </div>
                                `;
                            }
                        } else if (day.isPast) {
                            if (day.allDone) {
                                actionBannerHtml = `
                                    <div class="callout-completed">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                        <div>
                                            <strong>Workout Finished</strong> &bull; Completed on ${escapeHtml(day.formattedDate)}
                                        </div>
                                    </div>
                                `;
                            } else {
                                actionBannerHtml = `
                                    <div class="callout-missed">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                        <div>
                                            <strong>Workout Missed</strong> &bull; Scheduled for ${escapeHtml(day.formattedDate)}
                                        </div>
                                    </div>
                                `;
                            }
                        } else if (day.isFuture) {
                            actionBannerHtml = `
                                <div class="callout-future">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <div>
                                        <strong>Upcoming Workout</strong> &bull; Unlocks on ${escapeHtml(day.formattedDate)}
                                    </div>
                                </div>
                            `;
                        }
                    }

                    // Exercises list HTML
                    let exercisesHtml = '';
                    if (!day.hasWorkout) {
                        exercisesHtml = `
                            <div class="callout-rest">
                                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="1.75" style="margin-bottom: 12px; opacity: 0.85;">
                                    <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                                </svg>
                                <h4 style="font-size: 16px; color: var(--ink); margin: 0 0 6px 0;">Rest &amp; Recovery Day</h4>
                                <p style="margin: 0; font-size: 13px; max-width: 320px; margin: 0 auto;">Your muscles rebuild and recover on rest days. Stay hydrated and prioritize good sleep!</p>
                            </div>
                        `;
                    } else {
                        exercisesHtml = '<div class="exercise-list-container">';
                        day.exercises.forEach((ex, idx) => {
                            const isComp = ex.is_completed;
                            let completeBtnHtml = '';
                            if (day.isToday && !isComp) {
                                completeBtnHtml = `<button type="button" class="btn-mark-exercise" onclick="quickCompleteExercise(${ex.exercise_id})">Complete</button>`;
                            } else if (isComp) {
                                completeBtnHtml = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2df0a5" stroke-width="3" style="flex-shrink:0;"><polyline points="20 6 9 17 4 12"/></svg>`;
                            }

                            exercisesHtml += `
                                <div class="exercise-item-card ${isComp ? 'is-completed' : ''}">
                                    <div class="exercise-item-info">
                                        <div class="exercise-idx-badge">${idx + 1}</div>
                                        <div class="exercise-details-wrap">
                                            <h4 class="exercise-name-title">${escapeHtml(ex.name)}</h4>
                                            <div class="exercise-specs-text">
                                                ${ex.sets} Sets &times; ${escapeHtml(ex.reps)}
                                                ${ex.target_weight_kg ? ` &bull; ${ex.target_weight_kg} kg` : ''}
                                                &bull; Rest ${ex.rest_seconds}s
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        ${completeBtnHtml}
                                    </div>
                                </div>
                            `;
                        });
                        exercisesHtml += '</div>';
                    }

                    container.innerHTML = `
                        <div>
                            <div class="workout-card-top">
                                <div class="workout-date-row">
                                    <span class="workout-date-label">${escapeHtml(day.formattedDate)}</span>
                                    ${statusBadgeHtml}
                                </div>
                                <h3 class="workout-title-text">${escapeHtml(day.focusTitle)}</h3>
                                ${day.hasWorkout ? `
                                    <div class="workout-meta-line">
                                        <span class="workout-meta-item">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 4v16M10 4v16M6 12h4M14 4v16M18 4v16M14 12h4"/></svg>
                                            ${day.scheduledCount} Exercises
                                        </span>
                                        <span class="workout-meta-item">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                            ~${day.estDurationMinutes} mins
                                        </span>
                                        <span class="workout-meta-item">
                                            <span style="color:var(--lime);font-weight:700;">${day.completedCount}/${day.scheduledCount}</span> Done
                                        </span>
                                    </div>
                                ` : ''}
                            </div>
                            ${actionBannerHtml}
                            <div style="margin-top: 14px;">
                                <h4 style="font-size: 13px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 10px 0;">
                                    ${day.hasWorkout ? 'Assigned Exercises' : 'Day Summary'}
                                </h4>
                                ${exercisesHtml}
                            </div>
                        </div>
                    `;
                }

                function escapeHtml(str) {
                    if (!str) return '';
                    const div = document.createElement('div');
                    div.innerText = str;
                    return div.innerHTML;
                }

                function quickCompleteExercise(exerciseId) {
                    Swal.fire({
                        title: 'Complete Exercise?',
                        text: 'Mark this exercise as finished for today?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: 'var(--lime)',
                        cancelButtonColor: 'var(--line)',
                        confirmButtonText: '<span style="color:var(--lime-btn-text, #090b10);font-weight:800;">Yes, Crushed It!</span>',
                        cancelButtonText: 'Cancel',
                        background: 'var(--panel)',
                        color: 'var(--ink)'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            fetch('index.php?page=complete_exercise', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: `csrf_token=${encodeURIComponent(CSRF_TOKEN)}&plan_id=${PLAN_ID}&exercise_id=${exerciseId}`
                            })
                            .then(r => r.json())
                            .then(data => {
                                if (data && data.success) {
                                    // Update local state
                                    const todayData = CALENDAR_DATA[TODAY_DATE];
                                    if (todayData) {
                                        const ex = todayData.exercises.find(e => e.exercise_id === exerciseId);
                                        if (ex) ex.is_completed = true;
                                        todayData.completedCount++;
                                        if (todayData.completedCount >= todayData.scheduledCount) {
                                            todayData.allDone = true;
                                            todayData.status = 'completed';
                                            const badgeEl = document.querySelector(`#cal-cell-${TODAY_DATE} .cal-status-badge`);
                                            if (badgeEl) badgeEl.innerHTML = '<span class="cal-badge-completed" title="Workout Completed">&#10003;</span>';
                                        }
                                    }
                                    renderWorkoutDetails(selectedDate);
                                    if (window.playNotifSound) window.playNotifSound('success');
                                } else {
                                    Swal.fire('Error', data.message || 'Could not record completion.', 'error');
                                }
                            })
                            .catch(err => {
                                console.error(err);
                                Swal.fire('Error', 'Network error occurred.', 'error');
                            });
                        }
                    });
                }

                // LIVE WORKOUT PLAYER LOGIC
                function startLiveWorkoutForToday() {
                    const todayData = CALENDAR_DATA[TODAY_DATE];
                    if (!todayData || !todayData.hasWorkout) return;

                    liveExercises = todayData.exercises.filter(e => !e.is_completed);
                    if (liveExercises.length === 0) {
                        Swal.fire({
                            icon: 'success',
                            title: 'All Done!',
                            text: 'You have finished all exercises for today.',
                            confirmButtonColor: 'var(--lime)'
                        });
                        return;
                    }

                    document.getElementById('workout-player-modal').style.display = 'flex';
                    currentExIndex = 0;
                    currentSet = 1;
                    renderLiveExercise();
                }

                function closeLiveWorkout() {
                    if (confirm('Exit live workout? Progress is saved per exercise.')) {
                        document.getElementById('workout-player-modal').style.display = 'none';
                        clearInterval(restTimer);
                        window.location.reload();
                    }
                }

                function renderLiveExercise() {
                    if (currentExIndex >= liveExercises.length) {
                        finishLiveWorkout();
                        return;
                    }

                    document.getElementById('player-timer-screen').style.display = 'none';
                    document.getElementById('player-controls').style.display = 'block';

                    const ex = liveExercises[currentExIndex];
                    const totalEx = liveExercises.length;

                    document.getElementById('player-exercise-count').innerText = `Exercise ${currentExIndex + 1} of ${totalEx}`;
                    document.getElementById('player-exercise-name').innerText = ex.name;
                    document.getElementById('player-exercise-target').innerHTML = `${ex.sets} Sets &times; ${ex.reps} Reps <br><span style="font-size:14px; opacity:0.7;">Rest: ${ex.rest_seconds}s</span>`;

                    document.getElementById('btn-complete-set').innerText = `Complete Set ${currentSet} of ${ex.sets}`;

                    const videoEl = document.getElementById('player-animation-video');
                    const imgEl = document.getElementById('player-animation-img');
                    const animationContainer = document.getElementById('player-animation-container');
                    const fallbackDiv = document.getElementById('player-animation-fallback');

                    animationContainer.style.display = 'flex';
                    videoEl.style.display = 'none';
                    if (imgEl) imgEl.style.display = 'none';
                    fallbackDiv.style.display = 'none';

                    let rawUrl = (ex.animation_url || '').trim();
                    let primaryUrl = '';
                    if (rawUrl) {
                        if (rawUrl.startsWith('http://') || rawUrl.startsWith('https://')) {
                            primaryUrl = rawUrl;
                        } else if (rawUrl.startsWith('assets/')) {
                            primaryUrl = rawUrl;
                        } else if (rawUrl.startsWith('/assets/')) {
                            primaryUrl = rawUrl.substring(1);
                        } else {
                            primaryUrl = 'assets/exercise_animations/' + rawUrl;
                        }
                    }

                    const cleanName = ex.name.toLowerCase().replace(/[^a-z0-9]/g, '_').replace(/_+/g, '_');
                    const spacedName = ex.name.toLowerCase().replace(/ /g, '_');
                    const candidateUrls = [];
                    if (primaryUrl) candidateUrls.push(primaryUrl);
                    candidateUrls.push(`assets/exercise_animations/${cleanName}.mp4`);
                    candidateUrls.push(`assets/exercise_animations/${cleanName}.gif`);
                    candidateUrls.push(`assets/exercise_animations/${cleanName}.webp`);
                    candidateUrls.push(`assets/exercise animation/${spacedName}.mp4`);
                    candidateUrls.push(`assets/exercise animation/${cleanName}.mp4`);
                    candidateUrls.push(`assets/${cleanName}.mp4`);

                    function isImageFormat(url) {
                        if (!url) return false;
                        const clean = url.split('?')[0].toLowerCase();
                        return clean.endsWith('.gif') || clean.endsWith('.webp') || clean.endsWith('.png') || clean.endsWith('.jpg') || clean.endsWith('.jpeg');
                    }

                    function loadNextCandidate(candidates) {
                        if (!candidates || candidates.length === 0) {
                            videoEl.style.display = 'none';
                            if (imgEl) imgEl.style.display = 'none';
                            fallbackDiv.style.display = 'block';
                            return;
                        }

                        const currentUrl = candidates[0];
                        const remaining = candidates.slice(1);

                        if (isImageFormat(currentUrl)) {
                            videoEl.style.display = 'none';
                            try { videoEl.pause(); } catch (e) {}

                            if (!imgEl) {
                                loadNextCandidate(remaining);
                                return;
                            }
                            imgEl.onload = () => {
                                imgEl.style.display = 'block';
                                videoEl.style.display = 'none';
                                fallbackDiv.style.display = 'none';
                            };
                            imgEl.onerror = () => {
                                imgEl.style.display = 'none';
                                loadNextCandidate(remaining);
                            };
                            imgEl.src = currentUrl;
                        } else {
                            if (imgEl) imgEl.style.display = 'none';
                            videoEl.muted = true;
                            videoEl.setAttribute('playsinline', '');
                            videoEl.setAttribute('muted', '');

                            let loaded = false;
                            const onReady = () => {
                                if (loaded) return;
                                loaded = true;
                                videoEl.style.display = 'block';
                                if (imgEl) imgEl.style.display = 'none';
                                fallbackDiv.style.display = 'none';
                                videoEl.play().catch(() => {});
                            };

                            videoEl.onloadeddata = onReady;
                            videoEl.oncanplay = onReady;
                            videoEl.onloadedmetadata = onReady;

                            videoEl.onerror = () => {
                                videoEl.style.display = 'none';
                                loadNextCandidate(remaining);
                            };
                            videoEl.src = currentUrl;
                            try { videoEl.load(); } catch (e) {}
                        }
                    }

                    loadNextCandidate(candidateUrls);

                    const progress = ((currentExIndex) / totalEx) * 100;
                    document.querySelector('.progress-fill').style.width = progress + '%';
                }

                function completeSet() {
                    const ex = liveExercises[currentExIndex];

                    if (currentSet < ex.sets) {
                        currentSet++;
                        startRest(ex.rest_seconds);
                    } else {
                        const btn = document.getElementById('btn-complete-set');
                        btn.innerText = 'Saving...';
                        btn.disabled = true;

                        fetch('index.php?page=complete_exercise', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `csrf_token=${encodeURIComponent(CSRF_TOKEN)}&plan_id=${PLAN_ID}&exercise_id=${ex.exercise_id}`
                        }).then(() => {
                            btn.disabled = false;
                            // Update local data
                            const todayData = CALENDAR_DATA[TODAY_DATE];
                            if (todayData) {
                                const found = todayData.exercises.find(e => e.exercise_id === ex.exercise_id);
                                if (found) found.is_completed = true;
                                todayData.completedCount++;
                            }

                            currentExIndex++;
                            currentSet = 1;

                            if (currentExIndex < liveExercises.length) {
                                startRest(ex.rest_seconds);
                            } else {
                                finishLiveWorkout();
                            }
                        });
                    }
                }

                function startRest(seconds) {
                    document.getElementById('player-controls').style.display = 'none';
                    const timerScreen = document.getElementById('player-timer-screen');
                    timerScreen.style.display = 'block';

                    const videoEl = document.getElementById('player-animation-video');
                    if (videoEl) {
                        videoEl.pause();
                        videoEl.style.opacity = '0.5';
                    }

                    let remaining = seconds;
                    document.getElementById('player-timer-text').innerText = remaining;

                    clearInterval(restTimer);
                    restTimer = setInterval(() => {
                        remaining--;
                        document.getElementById('player-timer-text').innerText = remaining;
                        if (remaining <= 0) {
                            skipRest();
                        }
                    }, 1000);
                }

                function skipRest() {
                    clearInterval(restTimer);
                    renderLiveExercise();
                }

                function finishLiveWorkout() {
                    document.querySelector('.progress-fill').style.width = '100%';
                    document.getElementById('player-header').innerHTML = `<h2 style="color:var(--lime); font-size:36px; margin-top:40px;">🎉 Workout Complete!</h2><p style="color:var(--muted); font-size:18px;">Incredible job today!</p>`;
                    document.getElementById('player-timer-screen').style.display = 'none';
                    document.getElementById('player-controls').innerHTML = `<button class="player-btn-primary" onclick="window.location.reload()">Finish</button>`;

                    const todayData = CALENDAR_DATA[TODAY_DATE];
                    if (todayData) {
                        todayData.allDone = true;
                        todayData.status = 'completed';
                    }
                }

                // Initial render on page load: automatically loads initialSelectedDate
                document.addEventListener('DOMContentLoaded', () => {
                    selectDate(selectedDate);
                });
            </script>

        <?php else: ?>
            <div class="panel" style="text-align: center; padding: 50px 20px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--line)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 16px;">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                    <line x1="3" y1="9" x2="21" y2="9" />
                    <line x1="9" y1="21" x2="9" y2="9" />
                </svg>
                <h2 style="color: var(--muted); margin-bottom: 10px;">No Active Workout Plan</h2>
                <p style="color: var(--muted); margin-bottom: 20px;">You don't have an active training schedule yet. Generate one now to start tracking your daily workouts on the calendar!</p>
                <form method="post" style="margin: 0; display: inline-block;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn" style="background: var(--lime); color: var(--lime-btn-text, #090b10); font-weight: 800; padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer;">
                        Generate Workout Plan
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

<?php
    render_footer();
}
