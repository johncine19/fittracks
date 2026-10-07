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
    $accountCreatedDate = !empty($user['created_at'])
        ? date('Y-m-d', strtotime((string) $user['created_at']))
        : $today;
    $planStartDate = !empty($plan['start_date'])
        ? substr((string) $plan['start_date'], 0, 10)
        : $accountCreatedDate;
    $workoutStartDate = max($accountCreatedDate, $planStartDate);

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

        // A recurring weekly schedule should not create missed workouts before
        // the member joined or before their current plan became active.
        $scheduled = $dateStr < $workoutStartDate ? [] : ($exercisesByDow[$dow] ?? []);
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
    <link rel="stylesheet" href="<?= h(asset_url('css/pages/my_workout.css')) ?>">

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

            <!-- Workout Plan Configuration & Script -->
            <script>
            window.WORKOUT_CONFIG = {
                userId: <?= (int)$userId ?>,
                planId: <?= (int)$plan['plan_id'] ?>,
                todayDate: <?= json_encode($today) ?>,
                csrfToken: <?= json_encode(csrf_token()) ?>,
                calendarData: <?= json_encode($calendarDays) ?>,
                initialSelectedDate: <?= json_encode($initialSelectedDate) ?>
            };
            </script>
            <script src="<?= h(asset_url('js/pages/my_workout.js')) ?>"></script>

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
