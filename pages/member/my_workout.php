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

    // Automatically acknowledge/mark workout plan notifications as read when member opens workouts page
    try {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0 AND (title LIKE "%Workout Plan%" OR message LIKE "%training routine%")')->execute([$userId]);
    } catch (Throwable) {}

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
                    tpe.sets, tpe.reps, tpe.target_weight_kg, tpe.rest_seconds, tpe.notes, tpe.tempo, tpe.rpe,
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
                'tempo' => $ex['tempo'] ?? '',
                'rpe' => $ex['rpe'] ?? '',
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
            background: linear-gradient(135deg, var(--lime) 0%, var(--lime-dark, var(--lime)) 100%);
            color: #ffffff;
            border: none;
            padding: 15px 24px;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: 0.02em;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            cursor: pointer;
            box-shadow: 0 8px 24px rgba(39, 242, 70, 0.28), inset 0 1px 0 rgba(255, 255, 255, 0.4);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            width: 100%;
            position: relative;
            overflow: hidden;
            -webkit-tap-highlight-color: transparent;
        }
        .btn-live-workout:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(39, 242, 70, 0.38), inset 0 1px 0 rgba(255, 255, 255, 0.5);
            filter: brightness(1.05);
        }
        .btn-live-workout:active {
            transform: translateY(0) scale(0.98);
        }
        .btn-live-workout .pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #090b10;
            display: inline-block;
            box-shadow: 0 0 0 0 rgba(9, 11, 16, 0.7);
            animation: pulse-ring 1.8s infinite;
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

        /* ============================================================
           LIVE WORKOUT PLAYER (IMMERSIVE MOBILE & DESKTOP COMPANION)
           ============================================================ */
        .workout-player-modal {
            position: fixed;
            inset: 0;
            z-index: 10000000;
            background: rgba(6, 9, 14, 0.88);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
            opacity: 0;
            transition: opacity 0.25s ease;
        }
        .workout-player-modal.is-active {
            opacity: 1;
        }
        .swal2-container {
            z-index: 20000000 !important;
        }
        .player-container {
            width: 100%;
            max-width: 620px;
            max-height: 94vh;
            background: var(--panel, #121820);
            border: 1px solid var(--line, rgba(255, 255, 255, 0.12));
            border-radius: 24px;
            box-shadow: 0 25px 60px -10px rgba(0, 0, 0, 0.75), 0 0 35px rgba(39, 242, 70, 0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
            animation: playerModalIn 0.28s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes playerModalIn {
            from { transform: translateY(24px) scale(0.96); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }

        /* Top Bar */
        .player-top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            border-bottom: 1px solid var(--line);
            background: rgba(0, 0, 0, 0.2);
            flex-shrink: 0;
        }
        .player-exit-btn,
        .player-audio-btn {
            background: var(--panel-soft, rgba(255, 255, 255, 0.06));
            border: 1px solid var(--line);
            color: var(--muted);
            border-radius: 10px;
            width: 38px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer !important;
            transition: all 0.15s ease;
            position: relative;
            z-index: 15;
            pointer-events: auto !important;
        }
        .player-exit-btn:hover,
        .player-audio-btn:hover {
            color: var(--ink);
            border-color: var(--muted);
            background: rgba(255, 255, 255, 0.1);
        }
        .player-chronograph-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-variant-numeric: tabular-nums;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 14.5px;
            font-weight: 700;
            color: var(--ink);
            background: var(--bg);
            padding: 6px 14px;
            border-radius: 20px;
            border: 1px solid var(--line);
            letter-spacing: 0.04em;
        }
        .player-chronograph-badge .pulse-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--lime);
            box-shadow: 0 0 8px var(--lime);
            animation: pulse-ring 1.8s infinite;
        }

        /* Progress Bar */
        .player-progress-bar {
            height: 4px;
            background: rgba(255, 255, 255, 0.08);
            width: 100%;
            position: relative;
            overflow: hidden;
            flex-shrink: 0;
        }
        .player-progress-bar .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b92f, var(--lime, #27f246));
            transition: width 0.35s ease;
            box-shadow: 0 0 10px rgba(39, 242, 70, 0.6);
        }

        /* Scrollable Body */
        .player-scrollable-body {
            flex: 1;
            overflow-y: auto;
            padding: 20px 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            -webkit-overflow-scrolling: touch;
        }
        .player-meta-strip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .player-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 4px 10px;
            border-radius: 6px;
            background: color-mix(in srgb, var(--lime) 15%, transparent);
            color: var(--lime);
            border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
        }
        .player-status-tag {
            font-size: 12.5px;
            color: var(--muted);
            font-weight: 700;
        }
        .player-exercise-title {
            font-size: 24px;
            font-weight: 800;
            color: var(--ink);
            margin: 0;
            line-height: 1.25;
            letter-spacing: -0.01em;
        }
        .player-metric-chips-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .player-metric-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--bg);
            border: 1px solid var(--line);
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--ink);
        }
        .player-metric-chip.highlight-lime {
            border-color: color-mix(in srgb, var(--lime) 40%, transparent);
            background: color-mix(in srgb, var(--lime) 8%, transparent);
            color: var(--lime);
        }
        .player-metric-chip.highlight-orange {
            border-color: rgba(245, 158, 11, 0.4);
            background: rgba(245, 158, 11, 0.08);
            color: #f59e0b;
        }
        .player-notes-callout {
            background: rgba(255, 255, 255, 0.03);
            border-left: 3px solid var(--lime);
            padding: 10px 14px;
            border-radius: 4px 10px 10px 4px;
            font-size: 13px;
            color: var(--muted);
            font-style: italic;
            line-height: 1.4;
        }

        /* Sets Tracker Section */
        .player-sets-section {
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 12px 14px;
        }
        .player-section-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
            margin-bottom: 8px;
        }
        .player-sets-chips-container {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 2px;
            -webkit-overflow-scrolling: touch;
        }
        .set-chip {
            flex: 1;
            min-width: 82px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 8px 10px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 3px;
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
            transition: all 0.2s ease;
        }
        .set-chip.completed {
            background: rgba(45, 240, 165, 0.12);
            border-color: rgba(45, 240, 165, 0.45);
            color: #2df0a5;
        }
        .set-chip.active {
            background: color-mix(in srgb, var(--lime) 18%, transparent);
            border-color: var(--lime);
            color: var(--ink);
            box-shadow: 0 0 16px color-mix(in srgb, var(--lime) 25%, transparent);
            transform: translateY(-1px);
        }
        .set-chip .set-badge-icon {
            font-size: 13px;
            line-height: 1;
        }
        .set-chip .set-label-text {
            font-size: 11.5px;
            letter-spacing: 0.02em;
        }

        /* Visual Media Stage */
        /* Visual Media / Technique Stage */
        .player-stage-box {
            width: 100%;
            border-radius: 16px;
            overflow: hidden;
            background: var(--panel-soft, #f1f5f9);
            border: 1px solid var(--line);
            position: relative;
            min-height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.25s ease;
        }
        .player-stage-box.has-media {
            min-height: 190px;
            max-height: 250px;
            background: #090b10;
        }
        .player-stage-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            z-index: 2;
            background: var(--panel, #ffffff);
            border: 1px solid var(--line);
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            letter-spacing: 0.04em;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        .player-stage-media {
            width: 100%;
            max-height: 250px;
            object-fit: contain;
        }
        .player-stage-fallback {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 22px 20px;
            text-align: center;
            color: var(--muted);
            gap: 6px;
            width: 100%;
        }
        .player-fallback-icon-wrap {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: color-mix(in srgb, var(--lime) 15%, transparent);
            border: 1px solid color-mix(in srgb, var(--lime) 28%, transparent);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 4px;
            color: var(--lime);
        }
        .player-stage-fallback strong {
            font-size: 14.5px;
            font-weight: 800;
            color: var(--ink);
        }
        .player-stage-fallback span {
            font-size: 13px;
            color: var(--muted);
            max-width: 320px;
            line-height: 1.45;
        }

        /* Rest Timer Screen */
        .player-rest-overlay {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px;
            text-align: center;
        }
        .player-rest-content {
            width: 100%;
            max-width: 360px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .player-rest-badge {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--lime);
            background: color-mix(in srgb, var(--lime) 15%, transparent);
            border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
            padding: 4px 12px;
            border-radius: 20px;
            margin-bottom: 10px;
        }
        .player-rest-title {
            font-size: 20px;
            font-weight: 800;
            color: var(--ink);
            margin: 0 0 20px 0;
        }
        .player-circular-timer {
            position: relative;
            width: 170px;
            height: 170px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
        }
        .timer-svg {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            transform: rotate(-90deg);
        }
        .timer-bg-circle {
            fill: none;
            stroke: rgba(255, 255, 255, 0.08);
            stroke-width: 6;
        }
        .timer-ring-circle {
            fill: none;
            stroke: var(--lime, #27f246);
            stroke-width: 6;
            stroke-linecap: round;
            stroke-dasharray: 276.46;
            stroke-dashoffset: 0;
            transition: stroke-dashoffset 0.9s linear, stroke 0.3s ease;
            filter: drop-shadow(0 0 6px rgba(39, 242, 70, 0.5));
        }
        .timer-inner-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 2;
        }
        .timer-digits {
            font-size: 48px;
            font-weight: 900;
            line-height: 1;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            color: var(--ink);
            font-variant-numeric: tabular-nums;
        }
        .timer-unit {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0.12em;
            color: var(--muted);
            margin-top: 4px;
        }
        .player-timer-adjust-row {
            display: flex;
            gap: 12px;
            margin-bottom: 18px;
        }
        .btn-timer-adjust {
            background: var(--panel-soft, rgba(255, 255, 255, 0.06));
            border: 1px solid var(--line);
            color: var(--ink);
            padding: 7px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-timer-adjust:hover {
            border-color: var(--lime);
            color: var(--lime);
            transform: scale(1.04);
        }
        .btn-timer-adjust:active {
            transform: scale(0.96);
        }
        .player-next-up-box {
            width: 100%;
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px 14px;
            margin-bottom: 18px;
            text-align: center;
        }
        .next-up-label {
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: 0.08em;
            color: var(--muted);
            margin-bottom: 2px;
        }
        .next-up-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--ink);
        }
        .player-btn-skip-rest {
            background: transparent;
            border: 1px solid var(--line);
            color: var(--ink);
            padding: 10px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .player-btn-skip-rest:hover {
            border-color: var(--lime);
            color: var(--lime);
            background: rgba(39, 242, 70, 0.06);
        }

        /* Celebration Screen */
        .player-celebration-view {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 30px 24px;
            text-align: center;
        }
        .celebration-trophy-icon {
            font-size: 64px;
            line-height: 1;
            margin-bottom: 14px;
            animation: bounceIn 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            filter: drop-shadow(0 10px 20px rgba(39, 242, 70, 0.35));
        }
        @keyframes bounceIn {
            0% { transform: scale(0.3); opacity: 0; }
            50% { transform: scale(1.15); }
            100% { transform: scale(1); opacity: 1; }
        }
        .celebration-title {
            font-size: 28px;
            font-weight: 900;
            color: var(--ink);
            margin: 0 0 6px 0;
            letter-spacing: -0.01em;
        }
        .celebration-subtitle {
            font-size: 14px;
            color: var(--muted);
            margin: 0 0 24px 0;
            max-width: 320px;
        }
        .celebration-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            width: 100%;
            max-width: 420px;
            margin-bottom: 24px;
        }
        .celebration-stat-box {
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 3px;
        }
        .celeb-stat-num {
            font-size: 18px;
            font-weight: 800;
            color: var(--lime);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
        .celeb-stat-lbl {
            font-size: 11px;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
        }
        .celeb-upgrade-card {
            background: rgba(45, 240, 165, 0.12);
            border: 1px solid rgba(45, 240, 165, 0.4);
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            color: #2df0a5;
            font-weight: 700;
            font-size: 14px;
            width: 100%;
            max-width: 420px;
            box-sizing: border-box;
        }

        /* Bottom Action Dock */
        .player-bottom-dock {
            padding: 14px 20px;
            border-top: 1px solid var(--line);
            background: var(--panel);
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex-shrink: 0;
        }
        .player-btn-primary {
            background: linear-gradient(135deg, var(--lime) 0%, var(--lime-dark, var(--lime)) 100%);
            color: #ffffff;
            font-weight: 800;
            font-size: 16px;
            border: none;
            border-radius: 14px;
            padding: 15px 20px;
            cursor: pointer;
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 8px 24px color-mix(in srgb, var(--lime) 35%, transparent), inset 0 1px 0 rgba(255, 255, 255, 0.4);
            transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
            letter-spacing: 0.02em;
            -webkit-tap-highlight-color: transparent;
        }
        .player-btn-primary:hover {
            filter: brightness(1.06);
            transform: translateY(-1px);
            box-shadow: 0 10px 28px color-mix(in srgb, var(--lime) 45%, transparent);
        }
        .player-btn-primary:active {
            transform: scale(0.98);
        }
        .player-btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .player-sub-actions-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        .player-sub-btn {
            background: transparent;
            border: 1px solid var(--line);
            color: var(--muted);
            padding: 8px 14px;
            border-radius: 9px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer !important;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
            position: relative;
            z-index: 15;
            pointer-events: auto !important;
        }
        .player-sub-btn:hover:not(:disabled) {
            color: var(--ink);
            border-color: var(--muted);
            background: rgba(255, 255, 255, 0.04);
        }
        .player-sub-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        /* In-Player Confirmation Dialog Overlay */
        .player-confirm-overlay {
            position: absolute;
            inset: 0;
            z-index: 1000;
            background: rgba(6, 9, 14, 0.82);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
            animation: fadeIn 0.15s ease;
        }
        .player-confirm-card {
            background: var(--panel, #ffffff);
            border: 1px solid var(--line);
            border-radius: 20px;
            padding: 26px 22px;
            max-width: 380px;
            width: 100%;
            text-align: center;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.6);
            animation: popIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes popIn {
            from { transform: scale(0.92); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .confirm-icon-wrap {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: color-mix(in srgb, var(--lime) 15%, transparent);
            border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
            color: var(--lime);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px auto;
        }
        .player-confirm-card h3 {
            font-size: 20px;
            font-weight: 800;
            color: var(--ink);
            margin: 0 0 8px 0;
        }
        .player-confirm-card p {
            font-size: 13.5px;
            color: var(--muted);
            margin: 0 0 22px 0;
            line-height: 1.45;
        }
        .player-confirm-actions {
            display: flex;
            gap: 10px;
        }
        .btn-cancel-exit {
            flex: 1;
            background: var(--panel-soft, #f1f5f9);
            border: 1px solid var(--line);
            color: var(--ink);
            font-size: 14px;
            font-weight: 700;
            padding: 12px 14px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-confirm-exit {
            flex: 1;
            background: linear-gradient(135deg, var(--lime) 0%, var(--lime-dark, var(--lime)) 100%);
            border: none;
            color: #ffffff;
            font-size: 14px;
            font-weight: 800;
            padding: 12px 14px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.15s ease;
            box-shadow: 0 4px 14px color-mix(in srgb, var(--lime) 30%, transparent);
        }
        .btn-cancel-exit:hover {
            filter: brightness(0.95);
        }
        .btn-confirm-exit:hover {
            filter: brightness(1.08);
        }

        /* Mobile Viewport Optimization (<= 768px) */
        @media (max-width: 768px) {
            .workout-player-modal {
                padding: 0 !important;
                background: var(--bg) !important;
            }
            .player-container {
                max-width: 100vw !important;
                width: 100vw !important;
                height: 100dvh !important;
                max-height: 100dvh !important;
                border-radius: 0 !important;
                border: none !important;
                box-shadow: none !important;
            }
            .player-top-bar {
                padding: max(10px, env(safe-area-inset-top)) 16px 10px 16px !important;
            }
            .player-scrollable-body {
                padding: 14px 16px 16px 16px !important;
                gap: 14px !important;
            }
            .player-exercise-title {
                font-size: 21px !important;
            }
            .player-stage-box {
                min-height: 160px !important;
                max-height: 200px !important;
            }
            .player-circular-timer {
                width: 150px !important;
                height: 150px !important;
            }
            .timer-digits {
                font-size: 42px !important;
            }
            .player-bottom-dock {
                padding: 10px 16px max(14px, env(safe-area-inset-bottom)) 16px !important;
            }
            .player-btn-primary {
                padding: 14px 18px !important;
                font-size: 15.5px !important;
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

            <!-- Live Workout Player Modal (Full-Screen Immersive Companion) -->
            <div id="workout-player-modal" class="workout-player-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="player-exercise-name">
                <div class="player-container">
                    
                    <!-- Player Top Chronograph Bar -->
                    <div class="player-top-bar">
                        <button type="button" class="player-exit-btn" onclick="closeLiveWorkout()" title="Exit Live Workout" aria-label="Exit Live Workout">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        </button>
                        
                        <div class="player-chronograph-badge">
                            <span class="pulse-dot"></span>
                            <span id="player-elapsed-time">00:00</span>
                        </div>

                        <button type="button" id="player-audio-toggle-btn" class="player-audio-btn" onclick="togglePlayerAudio()" title="Sound On / Off" aria-label="Toggle Sound">
                            <svg id="player-audio-icon-on" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path></svg>
                            <svg id="player-audio-icon-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><line x1="23" y1="9" x2="17" y2="15"></line><line x1="17" y1="9" x2="23" y2="15"></line></svg>
                        </button>
                    </div>

                    <!-- Visual Progress Bar -->
                    <div id="player-progress" class="player-progress-bar">
                        <div class="progress-fill" style="width: 0%;"></div>
                    </div>

                    <!-- Scrollable Workout Player Body -->
                    <div id="player-main-view" class="player-scrollable-body">
                        
                        <!-- Exercise Meta Strip -->
                        <div class="player-meta-strip">
                            <span id="player-exercise-count" class="player-pill">EXERCISE 1 OF 5</span>
                            <span id="player-sets-status-summary" class="player-status-tag">Set 1 of 3</span>
                        </div>

                        <!-- Exercise Title -->
                        <h2 id="player-exercise-name" class="player-exercise-title">Exercise Name</h2>

                        <!-- Metric Badges Row -->
                        <div id="player-metric-tags" class="player-metric-chips-row">
                            <!-- Populated dynamically -->
                        </div>

                        <!-- Notes Callout (if any) -->
                        <div id="player-exercise-notes" class="player-notes-callout" style="display: none;"></div>

                        <!-- Interactive Set Progression Tracker -->
                        <div class="player-sets-section">
                            <div class="player-section-label">Set Progression</div>
                            <div id="player-sets-chips" class="player-sets-chips-container">
                                <!-- Dynamic set chips -->
                            </div>
                        </div>

                        <!-- Video / Animation Demo Stage -->
                        <div id="player-animation-container" class="player-stage-box">
                            <div class="player-stage-badge">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                                <span>Form Guide</span>
                            </div>
                            <video id="player-animation-video" autoplay loop muted playsinline class="player-stage-media" style="display: none;"></video>
                            <img id="player-animation-img" class="player-stage-media" style="display: none;" alt="Exercise demonstration">
                            <div id="player-animation-fallback" class="player-stage-fallback" style="display: none;">
                                <div class="player-fallback-icon-wrap">
                                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M6 4v16M10 4v16M6 12h4M14 4v16M18 4v16M14 12h4"/>
                                    </svg>
                                </div>
                                <strong>Standard Form &amp; Technique</strong>
                                <span>Focus on smooth tempo, full range of motion, and breathing.</span>
                            </div>
                        </div>

                    </div>

                    <!-- Dedicated Rest Countdown Screen -->
                    <div id="player-timer-screen" class="player-rest-overlay" style="display: none;">
                        <div class="player-rest-content">
                            <span class="player-rest-badge">Active Recovery</span>
                            <h3 class="player-rest-title">Rest &amp; Recover</h3>
                            
                            <!-- Circular SVG Timer -->
                            <div class="player-circular-timer">
                                <svg class="timer-svg" viewBox="0 0 100 100">
                                    <circle class="timer-bg-circle" cx="50" cy="50" r="44"></circle>
                                    <circle id="timer-progress-ring" class="timer-ring-circle" cx="50" cy="50" r="44"></circle>
                                </svg>
                                <div class="timer-inner-content">
                                    <span id="player-timer-text" class="timer-digits">60</span>
                                    <span class="timer-unit">SECONDS</span>
                                </div>
                            </div>

                            <!-- Quick Adjust Buttons -->
                            <div class="player-timer-adjust-row">
                                <button type="button" class="btn-timer-adjust" onclick="adjustRest(-15)">-15s</button>
                                <button type="button" class="btn-timer-adjust" onclick="adjustRest(15)">+15s</button>
                            </div>

                            <!-- Next Up Preview Box -->
                            <div id="player-next-up-card" class="player-next-up-box">
                                <div class="next-up-label">NEXT UP</div>
                                <div id="player-next-up-text" class="next-up-title">Set 2 &bull; 10 Reps</div>
                            </div>

                            <button type="button" onclick="skipRest()" class="player-btn-skip-rest">
                                <span>Skip Rest &amp; Continue</span>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="5 4 15 12 5 20 5 4"></polygon><line x1="19" y1="5" x2="19" y2="19"></line></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Celebration Finished Screen -->
                    <div id="player-celebration-screen" class="player-celebration-view" style="display: none;">
                        <div class="celebration-trophy-icon">🏆</div>
                        <h2 class="celebration-title">Workout Crushed!</h2>
                        <p class="celebration-subtitle">Outstanding work! You finished today's training session.</p>

                        <div class="celebration-stats-grid">
                            <div class="celebration-stat-box">
                                <span id="celeb-time-val" class="celeb-stat-num">00:00</span>
                                <span class="celeb-stat-lbl">Total Time</span>
                            </div>
                            <div class="celebration-stat-box">
                                <span id="celeb-ex-val" class="celeb-stat-num">0</span>
                                <span class="celeb-stat-lbl">Exercises</span>
                            </div>
                            <div class="celebration-stat-box">
                                <span id="celeb-sets-val" class="celeb-stat-num">0</span>
                                <span class="celeb-stat-lbl">Sets Done</span>
                            </div>
                        </div>

                        <div id="celeb-tier-upgrade-notice" style="display: none;" class="celeb-upgrade-card"></div>

                        <button type="button" class="player-btn-primary" onclick="closeCelebrationAndFinish()">
                            <span>Finish &amp; Return to Calendar</span>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        </button>
                    </div>

                    <!-- Sticky Bottom Action Dock -->
                    <div id="player-bottom-dock" class="player-bottom-dock">
                        <button type="button" id="btn-complete-set" onclick="completeSet()" class="player-btn-primary">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            <span id="btn-complete-set-text">Complete Set 1</span>
                        </button>

                        <div class="player-sub-actions-row">
                            <button type="button" id="btn-prev-exercise" onclick="prevLiveExercise()" class="player-sub-btn" title="Previous Exercise">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                                <span>Prev Exercise</span>
                            </button>
                            <button type="button" id="btn-skip-exercise" onclick="skipLiveExercise()" class="player-sub-btn" title="Skip to Next Exercise">
                                <span>Skip Exercise</span>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Built-in In-Player Exit Confirmation Overlay -->
                    <div id="player-exit-confirm" class="player-confirm-overlay" style="display: none;">
                        <div class="player-confirm-card">
                            <div class="confirm-icon-wrap">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                            </div>
                            <h3 id="player-confirm-title">Pause Workout?</h3>
                            <p id="player-confirm-desc">You can resume your remaining exercises later today. Saved exercises will not be lost.</p>
                            <div class="player-confirm-actions">
                                <button type="button" class="btn-cancel-exit" onclick="cancelExitWorkout()">Keep Training</button>
                                <button type="button" id="btn-confirm-exit-action" class="btn-confirm-exit" onclick="confirmExitWorkout()">Yes, Pause &amp; Exit</button>
                            </div>
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
                                            <span class="pulse-dot"></span>
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                                <polygon points="5 3 19 12 5 21 5 3"></polygon>
                                            </svg>
                                            <span>${day.completedCount > 0 ? 'Resume Live Workout' : 'Start Live Workout'} (${pendingCount} remaining)</span>
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
                                                ${ex.target_weight_kg ? ` &bull; <strong style="color:var(--orange, #f59e0b);">${ex.target_weight_kg} kg</strong>` : ''}
                                                &bull; Rest ${ex.rest_seconds}s
                                                ${ex.tempo ? ` &bull; <span style="color:var(--lime); font-weight:600;">⚡ ${escapeHtml(ex.tempo)}</span>` : ''}
                                                ${ex.rpe ? ` &bull; <span style="color:var(--orange, #f59e0b); font-weight:600;">🔥 ${escapeHtml(ex.rpe)}</span>` : ''}
                                            </div>
                                            ${ex.notes ? `<div style="font-size:12px; color:var(--muted); margin-top:4px; font-style:italic;">"${escapeHtml(ex.notes)}"</div>` : ''}
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

                // ============================================================
                // LIVE WORKOUT PLAYER ENGINE
                // ============================================================
                let workoutElapsedSeconds = 0;
                let workoutStopwatchInterval = null;
                let totalRestSeconds = 60;
                let remainingRest = 60;
                let playerAudioMuted = false;
                let audioCtxInstance = null;
                let tierUpgradedData = null;
                const TIMER_CIRCUMFERENCE = 276.46; // 2 * Math.PI * 44

                function getPlayerAudioContext() {
                    if (!audioCtxInstance && (window.AudioContext || window.webkitAudioContext)) {
                        try {
                            audioCtxInstance = new (window.AudioContext || window.webkitAudioContext)();
                        } catch (e) {}
                    }
                    if (audioCtxInstance && audioCtxInstance.state === 'suspended') {
                        audioCtxInstance.resume().catch(() => {});
                    }
                    return audioCtxInstance;
                }

                function playPlayerTone(freq, duration, type = 'sine') {
                    if (playerAudioMuted) return;
                    try {
                        const ctx = getPlayerAudioContext();
                        if (!ctx) return;
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = type;
                        osc.frequency.setValueAtTime(freq, ctx.currentTime);
                        gain.gain.setValueAtTime(0.001, ctx.currentTime);
                        gain.gain.linearRampToValueAtTime(0.18, ctx.currentTime + 0.01);
                        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + duration);
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.start(ctx.currentTime);
                        osc.stop(ctx.currentTime + duration);
                    } catch (e) {}
                }

                function togglePlayerAudio() {
                    playerAudioMuted = !playerAudioMuted;
                    const onIcon = document.getElementById('player-audio-icon-on');
                    const offIcon = document.getElementById('player-audio-icon-off');
                    if (onIcon && offIcon) {
                        onIcon.style.display = playerAudioMuted ? 'none' : 'block';
                        offIcon.style.display = playerAudioMuted ? 'block' : 'none';
                    }
                }

                function formatElapsed(sec) {
                    const m = Math.floor(sec / 60);
                    const s = sec % 60;
                    if (m >= 60) {
                        const h = Math.floor(m / 60);
                        const remM = m % 60;
                        return `${h}:${remM.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
                    }
                    return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
                }

                function startLiveWorkoutForToday() {
                    const todayData = CALENDAR_DATA[TODAY_DATE];
                    if (!todayData || !todayData.hasWorkout) return;

                    liveExercises = todayData.exercises.filter(e => !e.is_completed);
                    if (liveExercises.length === 0) {
                        Swal.fire({
                            icon: 'success',
                            title: 'All Caught Up!',
                            text: 'You have completed all scheduled exercises for today. Great dedication!',
                            confirmButtonColor: 'var(--lime)'
                        });
                        return;
                    }

                    const modal = document.getElementById('workout-player-modal');
                    modal.style.display = 'flex';
                    requestAnimationFrame(() => {
                        modal.classList.add('is-active');
                    });
                    document.body.style.overflow = 'hidden';

                    // Suppress any floating background ratings while workout player is active
                    const ratingModal = document.getElementById('ft-floating-rating-modal');
                    if (ratingModal) ratingModal.style.display = 'none';

                    currentExIndex = 0;
                    currentSet = 1;
                    tierUpgradedData = null;

                    // Initialize workout chronograph
                    if (!workoutStopwatchInterval) {
                        workoutElapsedSeconds = 0;
                        const elapsedEl = document.getElementById('player-elapsed-time');
                        if (elapsedEl) elapsedEl.innerText = '00:00';
                        workoutStopwatchInterval = setInterval(() => {
                            workoutElapsedSeconds++;
                            if (elapsedEl) elapsedEl.innerText = formatElapsed(workoutElapsedSeconds);
                        }, 1000);
                    }

                    // Pre-warm audio context
                    getPlayerAudioContext();

                    renderLiveExercise();
                }

                function closeLiveWorkout() {
                    const confirmOverlay = document.getElementById('player-exit-confirm');
                    if (confirmOverlay) {
                        const titleEl = document.getElementById('player-confirm-title');
                        const descEl = document.getElementById('player-confirm-desc');
                        const actBtn = document.getElementById('btn-confirm-exit-action');
                        if (titleEl) titleEl.innerText = 'Pause Workout?';
                        if (descEl) descEl.innerText = 'You can resume your remaining exercises later today. Saved exercises will not be lost.';
                        if (actBtn) {
                            actBtn.innerText = 'Yes, Pause & Exit';
                            actBtn.onclick = confirmExitWorkout;
                        }
                        confirmOverlay.style.display = 'flex';
                    } else {
                        cleanupPlayerModal();
                        window.location.reload();
                    }
                }

                function cancelExitWorkout() {
                    const confirmOverlay = document.getElementById('player-exit-confirm');
                    if (confirmOverlay) confirmOverlay.style.display = 'none';
                }

                function confirmExitWorkout() {
                    cleanupPlayerModal();
                    window.location.reload();
                }

                function cleanupPlayerModal() {
                    const modal = document.getElementById('workout-player-modal');
                    if (modal) {
                        modal.classList.remove('is-active');
                        modal.style.display = 'none';
                    }
                    const confirmOverlay = document.getElementById('player-exit-confirm');
                    if (confirmOverlay) confirmOverlay.style.display = 'none';
                    document.body.style.overflow = '';
                    const ratingModal = document.getElementById('ft-floating-rating-modal');
                    if (ratingModal) ratingModal.style.display = '';
                    clearInterval(restTimer);
                    clearInterval(workoutStopwatchInterval);
                    workoutStopwatchInterval = null;
                }

                function renderSetChips(ex, activeSet) {
                    const container = document.getElementById('player-sets-chips');
                    if (!container) return;
                    let html = '';
                    const totalSets = Math.max(1, parseInt(ex.sets, 10) || 1);
                    for (let s = 1; s <= totalSets; s++) {
                        let chipClass = 'set-chip';
                        let badgeIcon = `${s}`;
                        let statusText = `Set ${s}`;
                        if (s < activeSet) {
                            chipClass += ' completed';
                            badgeIcon = '✓';
                            statusText = `Set ${s} Done`;
                        } else if (s === activeSet) {
                            chipClass += ' active';
                            badgeIcon = '⚡';
                            statusText = `Set ${s} Active`;
                        }
                        html += `
                            <div class="${chipClass}">
                                <span class="set-badge-icon">${badgeIcon}</span>
                                <span class="set-label-text">${statusText}</span>
                            </div>
                        `;
                    }
                    container.innerHTML = html;
                }

                function renderLiveExercise() {
                    if (currentExIndex >= liveExercises.length) {
                        finishLiveWorkout();
                        return;
                    }

                    // Reset views
                    document.getElementById('player-main-view').style.display = 'flex';
                    document.getElementById('player-timer-screen').style.display = 'none';
                    document.getElementById('player-celebration-screen').style.display = 'none';
                    document.getElementById('player-bottom-dock').style.display = 'flex';

                    const ex = liveExercises[currentExIndex];
                    const totalEx = liveExercises.length;
                    const totalSets = Math.max(1, parseInt(ex.sets, 10) || 1);

                    // Update header and meta
                    document.getElementById('player-exercise-count').innerText = `EXERCISE ${currentExIndex + 1} OF ${totalEx}`;
                    document.getElementById('player-sets-status-summary').innerText = `Set ${currentSet} of ${totalSets}`;
                    document.getElementById('player-exercise-name').innerText = ex.name;

                    // Metric Badges
                    const metricContainer = document.getElementById('player-metric-tags');
                    let metricsHtml = `
                        <div class="player-metric-chip highlight-lime">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span>${totalSets} Sets</span>
                        </div>
                        <div class="player-metric-chip">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            <span>${escapeHtml(ex.reps)} Reps</span>
                        </div>
                    `;
                    if (ex.target_weight_kg) {
                        metricsHtml += `
                            <div class="player-metric-chip highlight-orange">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 4v16M10 4v16M6 12h4M14 4v16M18 4v16M14 12h4"/></svg>
                                <span>${ex.target_weight_kg} kg</span>
                            </div>
                        `;
                    }
                    metricsHtml += `
                        <div class="player-metric-chip">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <span>${ex.rest_seconds}s Rest</span>
                        </div>
                    `;
                    if (ex.tempo) {
                        metricsHtml += `
                            <div class="player-metric-chip highlight-lime">
                                <span>⚡ Tempo: ${escapeHtml(ex.tempo)}</span>
                            </div>
                        `;
                    }
                    if (ex.rpe) {
                        metricsHtml += `
                            <div class="player-metric-chip highlight-orange">
                                <span>🔥 ${escapeHtml(ex.rpe)}</span>
                            </div>
                        `;
                    }
                    metricContainer.innerHTML = metricsHtml;

                    // Notes Callout
                    const notesBox = document.getElementById('player-exercise-notes');
                    if (ex.notes && ex.notes.trim()) {
                        notesBox.style.display = 'block';
                        notesBox.innerHTML = `💬 "${escapeHtml(ex.notes.trim())}"`;
                    } else {
                        notesBox.style.display = 'none';
                    }

                    // Render interactive Set Chips
                    renderSetChips(ex, currentSet);

                    // Primary Button Text
                    const btnText = document.getElementById('btn-complete-set-text');
                    if (currentSet < totalSets) {
                        btnText.innerText = `Complete Set ${currentSet} of ${totalSets} ✓`;
                    } else {
                        btnText.innerText = `Finish Exercise & Save ✓`;
                    }

                    // Navigation Sub-buttons
                    const prevBtn = document.getElementById('btn-prev-exercise');
                    if (prevBtn) {
                        prevBtn.disabled = (currentExIndex === 0);
                    }

                    // Media loader
                    const videoEl = document.getElementById('player-animation-video');
                    const imgEl = document.getElementById('player-animation-img');
                    const fallbackDiv = document.getElementById('player-animation-fallback');

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
                            fallbackDiv.style.display = 'flex';
                            animationContainer.classList.remove('has-media');
                            const badgeEl = animationContainer.querySelector('.player-stage-badge span');
                            if (badgeEl) badgeEl.innerText = 'Technique & Form';
                            const titleEl = fallbackDiv.querySelector('strong');
                            const descEl = fallbackDiv.querySelector('span');
                            if (titleEl) titleEl.innerText = `${ex.name} Focus`;
                            if (descEl) descEl.innerText = ex.notes ? ex.notes : 'Focus on controlled breathing, posture, and steady tempo through full range of motion.';
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
                                animationContainer.classList.add('has-media');
                                const badgeEl = animationContainer.querySelector('.player-stage-badge span');
                                if (badgeEl) badgeEl.innerText = 'Form Guide';
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
                                animationContainer.classList.add('has-media');
                                const badgeEl = animationContainer.querySelector('.player-stage-badge span');
                                if (badgeEl) badgeEl.innerText = 'Form Guide';
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

                    // Update Top Progress Bar
                    const progress = (currentExIndex / totalEx) * 100;
                    const fillEl = document.querySelector('.player-progress-bar .progress-fill');
                    if (fillEl) fillEl.style.width = progress + '%';
                }

                function completeSet() {
                    const ex = liveExercises[currentExIndex];
                    const totalSets = Math.max(1, parseInt(ex.sets, 10) || 1);

                    if (currentSet < totalSets) {
                        currentSet++;
                        playPlayerTone(660, 0.12, 'sine');
                        if (navigator.vibrate) navigator.vibrate([60]);
                        startRest(ex.rest_seconds || 60, 'next_set');
                    } else {
                        const btn = document.getElementById('btn-complete-set');
                        const btnText = document.getElementById('btn-complete-set-text');
                        btn.disabled = true;
                        btnText.innerText = 'Logging Exercise...';

                        fetch('index.php?page=complete_exercise', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `csrf_token=${encodeURIComponent(CSRF_TOKEN)}&plan_id=${PLAN_ID}&exercise_id=${ex.exercise_id}`
                        })
                        .then(r => r.json())
                        .then(data => {
                            btn.disabled = false;
                            if (data && data.tier_upgraded) {
                                tierUpgradedData = data.tier_upgraded;
                            }

                            // Update local calendar dataset
                            const todayData = CALENDAR_DATA[TODAY_DATE];
                            if (todayData) {
                                const found = todayData.exercises.find(e => e.exercise_id === ex.exercise_id);
                                if (found) found.is_completed = true;
                                todayData.completedCount++;
                                if (todayData.completedCount >= todayData.scheduledCount) {
                                    todayData.allDone = true;
                                    todayData.status = 'completed';
                                    const badgeEl = document.querySelector(`#cal-cell-${TODAY_DATE} .cal-status-badge`);
                                    if (badgeEl) badgeEl.innerHTML = '<span class="cal-badge-completed" title="Workout Completed">&#10003;</span>';
                                }
                            }

                            if (window.playNotifSound) window.playNotifSound('success');
                            if (navigator.vibrate) navigator.vibrate([100, 50, 100]);

                            currentExIndex++;
                            currentSet = 1;

                            if (currentExIndex < liveExercises.length) {
                                startRest(ex.rest_seconds || 60, 'next_exercise');
                            } else {
                                finishLiveWorkout();
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            btn.disabled = false;
                            btnText.innerText = 'Finish Exercise & Save ✓';
                            Swal.fire('Error', 'Network issue recording exercise completion.', 'error');
                        });
                    }
                }

                function startRest(seconds, mode = 'next_set') {
                    document.getElementById('player-main-view').style.display = 'none';
                    document.getElementById('player-bottom-dock').style.display = 'none';
                    const timerScreen = document.getElementById('player-timer-screen');
                    timerScreen.style.display = 'flex';

                    // Pause video in background to save battery
                    const videoEl = document.getElementById('player-animation-video');
                    if (videoEl) {
                        try { videoEl.pause(); } catch (e) {}
                    }

                    totalRestSeconds = Math.max(5, parseInt(seconds, 10) || 60);
                    remainingRest = totalRestSeconds;

                    // Update Next Up preview card
                    const nextUpText = document.getElementById('player-next-up-text');
                    if (mode === 'next_set') {
                        const currentEx = liveExercises[currentExIndex];
                        nextUpText.innerHTML = `<strong>Set ${currentSet} of ${currentEx.sets}</strong> &bull; ${escapeHtml(currentEx.reps)} Reps ${currentEx.target_weight_kg ? `(${currentEx.target_weight_kg} kg)` : ''}`;
                    } else if (mode === 'next_exercise' && currentExIndex < liveExercises.length) {
                        const nextEx = liveExercises[currentExIndex];
                        nextUpText.innerHTML = `<strong>${escapeHtml(nextEx.name)}</strong> &bull; ${nextEx.sets} Sets &times; ${escapeHtml(nextEx.reps)}`;
                    }

                    updateRestTimerDisplay();

                    clearInterval(restTimer);
                    restTimer = setInterval(() => {
                        remainingRest--;
                        updateRestTimerDisplay();

                        // Countdown sound feedback for last 3 seconds
                        if (remainingRest === 3 || remainingRest === 2 || remainingRest === 1) {
                            playPlayerTone(880, 0.08, 'sine');
                        }

                        if (remainingRest <= 0) {
                            clearInterval(restTimer);
                            if (window.playNotifSound) {
                                window.playNotifSound('ready');
                            } else {
                                playPlayerTone(1320, 0.25, 'sine');
                            }
                            if (navigator.vibrate) navigator.vibrate([100, 60, 140]);
                            skipRest();
                        }
                    }, 1000);
                }

                function updateRestTimerDisplay() {
                    const timerText = document.getElementById('player-timer-text');
                    const ring = document.getElementById('timer-progress-ring');
                    if (timerText) timerText.innerText = Math.max(0, remainingRest);

                    if (ring && totalRestSeconds > 0) {
                        const offset = TIMER_CIRCUMFERENCE * (1 - (remainingRest / totalRestSeconds));
                        ring.style.strokeDashoffset = Math.max(0, Math.min(TIMER_CIRCUMFERENCE, offset));

                        // Dynamic warning color for final seconds
                        if (remainingRest <= 3) {
                            ring.style.stroke = '#ef4444';
                        } else if (remainingRest <= 7) {
                            ring.style.stroke = '#f59e0b';
                        } else {
                            ring.style.stroke = 'var(--lime, #27f246)';
                        }
                    }
                }

                function adjustRest(delta) {
                    remainingRest = Math.max(0, remainingRest + delta);
                    totalRestSeconds = Math.max(totalRestSeconds, remainingRest);
                    if (remainingRest <= 1) {
                        skipRest();
                    } else {
                        updateRestTimerDisplay();
                        playPlayerTone(750, 0.06, 'triangle');
                    }
                }

                function skipRest() {
                    clearInterval(restTimer);
                    renderLiveExercise();
                }

                function prevLiveExercise() {
                    if (currentExIndex > 0) {
                        currentExIndex--;
                        currentSet = 1;
                        renderLiveExercise();
                    }
                }

                function skipLiveExercise() {
                    if (currentExIndex < liveExercises.length - 1) {
                        currentExIndex++;
                        currentSet = 1;
                        renderLiveExercise();
                        playPlayerTone(660, 0.08, 'triangle');
                    } else {
                        // On last exercise, offer to finish workout session
                        const confirmOverlay = document.getElementById('player-exit-confirm');
                        if (confirmOverlay) {
                            const titleEl = document.getElementById('player-confirm-title');
                            const descEl = document.getElementById('player-confirm-desc');
                            const actBtn = document.getElementById('btn-confirm-exit-action');
                            if (titleEl) titleEl.innerText = 'Finish Workout Session?';
                            if (descEl) descEl.innerText = 'This is the last scheduled exercise for today. Complete your session?';
                            if (actBtn) {
                                actBtn.innerText = 'Finish Workout';
                                actBtn.onclick = () => {
                                    cancelExitWorkout();
                                    finishLiveWorkout();
                                };
                            }
                            confirmOverlay.style.display = 'flex';
                        } else {
                            finishLiveWorkout();
                        }
                    }
                }

                function finishLiveWorkout() {
                    clearInterval(workoutStopwatchInterval);
                    clearInterval(restTimer);

                    document.getElementById('player-main-view').style.display = 'none';
                    document.getElementById('player-timer-screen').style.display = 'none';
                    document.getElementById('player-bottom-dock').style.display = 'none';
                    const celebScreen = document.getElementById('player-celebration-screen');
                    celebScreen.style.display = 'flex';

                    const fillEl = document.querySelector('.player-progress-bar .progress-fill');
                    if (fillEl) fillEl.style.width = '100%';

                    // Compute workout summary stats
                    let totalSetsCrushed = 0;
                    liveExercises.forEach(e => {
                        totalSetsCrushed += Math.max(1, parseInt(e.sets, 10) || 1);
                    });

                    document.getElementById('celeb-time-val').innerText = formatElapsed(workoutElapsedSeconds);
                    document.getElementById('celeb-ex-val').innerText = liveExercises.length;
                    document.getElementById('celeb-sets-val').innerText = totalSetsCrushed;

                    const upgradeCard = document.getElementById('celeb-tier-upgrade-notice');
                    if (tierUpgradedData) {
                        upgradeCard.style.display = 'block';
                        upgradeCard.innerHTML = `🎉 <strong>Level Up!</strong> Promoted to <strong>${escapeHtml(tierUpgradedData.new_tier_name)}</strong>!`;
                    } else {
                        upgradeCard.style.display = 'none';
                    }

                    if (window.playNotifSound) window.playNotifSound('ready');
                    if (navigator.vibrate) navigator.vibrate([150, 80, 200]);

                    const todayData = CALENDAR_DATA[TODAY_DATE];
                    if (todayData) {
                        todayData.allDone = true;
                        todayData.status = 'completed';
                    }
                }

                function closeCelebrationAndFinish() {
                    cleanupPlayerModal();
                    window.location.reload();
                }

                // Keyboard Accessibility
                document.addEventListener('keydown', (e) => {
                    const modal = document.getElementById('workout-player-modal');
                    if (!modal || modal.style.display === 'none') return;

                    if (e.key === 'Escape') {
                        closeLiveWorkout();
                    } else if (e.code === 'Space' && e.target.tagName !== 'BUTTON' && e.target.tagName !== 'INPUT') {
                        e.preventDefault();
                        const timerScreen = document.getElementById('player-timer-screen');
                        if (timerScreen && timerScreen.style.display !== 'none') {
                            skipRest();
                        } else {
                            completeSet();
                        }
                    }
                });

                // Initial render on page load: automatically loads initialSelectedDate
                document.addEventListener('DOMContentLoaded', () => {
                    selectDate(selectedDate);
                });

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
