<?php
declare(strict_types=1);

/**
 * Returns parameters for workouts based on member profile.
 */
function workout_days_for_frequency(int $daysPerWeek): array
{
    return match ($daysPerWeek) {
        1 => [1],
        2 => [1, 4],
        3 => [1, 3, 5],
        4 => [1, 2, 4, 5],
        5 => [1, 2, 3, 5, 6],
        6 => [1, 2, 3, 4, 5, 6],
        7 => [1, 2, 3, 4, 5, 6, 7],
        default => [1, 3, 5],
    };
}

/**
 * Resolve the gym whose exercise library is available to a member. The member's
 * gym assignment is authoritative; the supplied fallback is used only when
 * the member has not yet been linked to a gym.
 */
function workout_library_gym_id(PDO $pdo, int $memberUserId, int $fallbackGymId = 0): int
{
    $stmt = $pdo->prepare('SELECT gym_id FROM gym_members WHERE user_id = ? LIMIT 1');
    $stmt->execute([$memberUserId]);
    $gymId = (int) $stmt->fetchColumn();
    if ($gymId > 0) return $gymId;

    $stmt = $pdo->prepare(
        'SELECT mp.gym_id
         FROM memberships m
         JOIN membership_plans mp ON m.plan_id = mp.plan_id
         WHERE m.user_id = ? AND m.status = "active"
         ORDER BY m.membership_id DESC
         LIMIT 1'
    );
    $stmt->execute([$memberUserId]);
    $gymId = (int) $stmt->fetchColumn();

    return $gymId > 0 ? $gymId : $fallbackGymId;
}

function _get_secondary_focus_targets(?string $secFocus): array
{
    $targetMuscles = [];
    $targetCategories = [];
    $targetNames = [];

    $secFocus = trim((string)$secFocus);
    if ($secFocus !== '') {
        $secList = array_map('trim', explode(',', strtolower($secFocus)));
        foreach ($secList as $sec) {
            if (str_contains($sec, 'abs') || str_contains($sec, 'core')) {
                $targetMuscles[] = 'abdominals';
                $targetMuscles[] = 'obliques';
                $targetCategories[] = 'core';
            }
            if (str_contains($sec, 'arm') || str_contains($sec, 'bicep') || str_contains($sec, 'tricep')) {
                $targetMuscles[] = 'arms';
            }
            if (str_contains($sec, 'chest') || str_contains($sec, 'upper')) {
                $targetMuscles[] = 'chest';
                $targetMuscles[] = 'shoulders';
            }
            if (str_contains($sec, 'back') || str_contains($sec, 'lat')) {
                $targetMuscles[] = 'back';
            }
            if (str_contains($sec, 'glute') || str_contains($sec, 'leg') || str_contains($sec, 'lower')) {
                $targetMuscles[] = 'legs';
                $targetMuscles[] = 'posterior chain';
            }
            if (str_contains($sec, 'cardio') || str_contains($sec, 'stamina')) {
                $targetCategories[] = 'cardio';
            }
            if (str_contains($sec, 'mobility') || str_contains($sec, 'flexibility')) {
                $targetCategories[] = 'core';
                $targetMuscles[] = 'full body';
            }
            if (str_contains($sec, 'strength') || str_contains($sec, 'benchmarks')) {
                $targetCategories[] = 'strength';
                $targetNames = array_merge($targetNames, ['squat', 'bench', 'deadlift', 'overhead press']);
            }
        }
    }

    return [array_values(array_unique($targetMuscles)), array_values(array_unique($targetCategories)), array_values(array_unique($targetNames))];
}

function _select_daily_exercises(array $dayExercises, array $profile, string $goal, int $targetCount): array
{
    $secFocus = trim((string)($profile['secondary_focus'] ?? ''));
    if ($secFocus !== '') {
        [$targetMuscles, $targetCategories] = _get_secondary_focus_targets($secFocus);
        $assigned = [];
        $secExercises = [];
        $generalExercises = [];
        
        foreach ($dayExercises as $dx) {
            $dmg = strtolower($dx['muscle_group'] ?? '');
            $dcat = strtolower($dx['category'] ?? '');
            if (in_array($dmg, $targetMuscles, true) || in_array($dcat, $targetCategories, true)) {
                $secExercises[] = $dx;
            } else {
                $generalExercises[] = $dx;
            }
        }

        $secByGroup = [];
        foreach ($secExercises as $sx) {
            $grpKey = $sx['muscle_group'] ?? 'general';
            $secByGroup[$grpKey][] = $sx;
        }

        $maxPerGroup = count($secByGroup) > 1 ? 1 : 2;
        foreach ($secByGroup as $grp => $grpExs) {
            shuffle($grpExs);
            $pickCount = min(count($grpExs), $maxPerGroup);
            for ($i = 0; $i < $pickCount; $i++) {
                if (count($assigned) < $targetCount) {
                    $assigned[] = $grpExs[$i];
                }
            }
        }

        shuffle($generalExercises);
        foreach ($generalExercises as $gx) {
            if (count($assigned) >= $targetCount) break;
            if (!in_array($gx['exercise_id'], array_column($assigned, 'exercise_id'))) {
                $assigned[] = $gx;
            }
        }

        if (!empty($assigned)) {
            return $assigned;
        }
    }

    $numExercises = min(count($dayExercises), $targetCount);
    return array_slice($dayExercises, 0, $numExercises);
}

function _get_workout_parameters(array $profile, PDO $pdo): array
{
    $goal = map_detailed_goal_to_basic($profile['primary_goal']);
    $tier = (int) ($profile['fitness_tier'] ?? 1);
    
    // Map fitness_tier to experience_level (1=Starter, 2=Intermediate, 3=Advanced)
    $expLevel = 1;
    if ($tier >= 3 && $tier <= 4) $expLevel = 2;
    if ($tier >= 5) $expLevel = 3;

    // Fetch member's gym_id
    $memberGymId = (int) $pdo->query('SELECT gym_id FROM gym_members WHERE user_id = ' . (int)($profile['user_id'] ?? 0))->fetchColumn();

    // Filter exercises by difficulty_level <= experience_level AND gym_id
    $stmt = $pdo->prepare('SELECT * FROM exercises WHERE difficulty_level <= ? AND gym_id = ?');
    $stmt->execute([$expLevel, $memberGymId]);
    $exercises = $stmt->fetchAll();
    
    if (!$exercises) {
        $stmt = $pdo->prepare('SELECT * FROM exercises WHERE gym_id = ?');
        $stmt->execute([$memberGymId]);
        $exercises = $stmt->fetchAll();
    }

    // Secondary focus & primary goal prioritization
    [$targetMuscles, $targetCategories, $targetNames] = _get_secondary_focus_targets($profile['secondary_focus'] ?? null);

    if (!empty($targetMuscles) || !empty($targetCategories) || !empty($targetNames)) {
        usort($exercises, function($a, $b) use ($goal, $targetMuscles, $targetCategories, $targetNames) {
            $scoreA = 0;
            $scoreB = 0;
            $mga = strtolower($a['muscle_group'] ?? '');
            $mgb = strtolower($b['muscle_group'] ?? '');
            $cata = strtolower($a['category'] ?? '');
            $catb = strtolower($b['category'] ?? '');
            $namea = strtolower($a['name'] ?? '');
            $nameb = strtolower($b['name'] ?? '');

            if (in_array($mga, $targetMuscles, true)) $scoreA += 10;
            if (in_array($mgb, $targetMuscles, true)) $scoreB += 10;

            if (in_array($cata, $targetCategories, true)) $scoreA += 6;
            if (in_array($catb, $targetCategories, true)) $scoreB += 6;

            foreach ($targetNames as $tn) {
                if (str_contains($namea, $tn)) $scoreA += 8;
                if (str_contains($nameb, $tn)) $scoreB += 8;
            }

            if ($goal === 'muscle_gain' && $cata === 'strength') $scoreA += 2;
            if ($goal === 'muscle_gain' && $catb === 'strength') $scoreB += 2;
            if ($goal === 'fat_loss' && $cata === 'cardio') $scoreA += 2;
            if ($goal === 'fat_loss' && $catb === 'cardio') $scoreB += 2;

            return $scoreB <=> $scoreA;
        });
    } else {
        if ($goal === 'muscle_gain') {
            usort($exercises, fn($a, $b) => ($a['category'] === 'strength' ? 0 : 1) <=> ($b['category'] === 'strength' ? 0 : 1));
        } elseif ($goal === 'fat_loss') {
            usort($exercises, fn($a, $b) => ($a['category'] === 'cardio' ? 0 : 1) <=> ($b['category'] === 'cardio' ? 0 : 1));
        } else {
            shuffle($exercises);
        }
    }

    // Member's specific selected training days take absolute precedence
    $preferredDaysRaw = trim((string)($profile['preferred_workout_days'] ?? ''));
    $days = [];
    if ($preferredDaysRaw !== '') {
        $parsedDays = array_map('intval', explode(',', $preferredDaysRaw));
        $parsedDays = array_values(array_filter($parsedDays, fn($d) => $d >= 1 && $d <= 7));
        sort($parsedDays);
        if (!empty($parsedDays)) {
            $days = $parsedDays;
        }
    }

    if (empty($days)) {
        $weeklyTarget = (int) ($profile['weekly_workout_target'] ?? 0);
        if ($weeklyTarget >= 1 && $weeklyTarget <= 7) {
            $days = workout_days_for_frequency($weeklyTarget);
        } else {
            $days = match($profile['activity_level'] ?? 'lightly_active') {
                'sedentary' => [1, 3],
                'lightly_active' => [1, 3, 5],
                'moderately_active' => [1, 2, 4, 5],
                'very_active', 'extra_active' => [1, 2, 3, 5, 6],
                default => [1, 3, 5]
            };
        }
    }

    return [$exercises, $days, $expLevel, $goal];
}

function _assign_exercise_details(string $goal, int $expLevel, array $ex): array
{
    $cat = $ex['category'] ?? 'strength';
    
    if ($cat === 'cardio') {
        return [
            3,
            '15 mins',
            45,
            'Steady Pace',
            'RPE 7',
            'Keep cadence steady in target aerobic zone.'
        ];
    }

    if ($goal === 'strength' || $goal === 'strength_power') {
        if ($expLevel === 1) {
            return [3, '6-8', 120, '2-1-1-0', 'RPE 7-8', 'Focus on bar path, brace core tightly.'];
        } elseif ($expLevel === 2) {
            return [4, '5-6', 150, '2-1-1-0', 'RPE 8', 'Control the eccentric, explode upward with full intent.'];
        } else {
            return [5, '3-5', 180, '2-1-1-0', 'RPE 8-9', 'Competition form. Rest fully between work sets.'];
        }
    }

    if ($goal === 'muscle_gain' || $goal === 'hypertrophy') {
        if ($expLevel === 1) {
            return [3, '10-12', 60, '3-0-1-0', 'RPE 7', 'Control the negative (3s down), feel target muscle stretch.'];
        } elseif ($expLevel === 2) {
            return [4, '8-10', 90, '3-0-1-0', 'RPE 8', 'Squeeze peak contraction for 1 second on each rep.'];
        } else {
            return [4, '6-10', 90, '3-0-1-1', 'RPE 8-9', 'Train close to failure with strict form.'];
        }
    } elseif ($goal === 'fat_loss') {
        if ($expLevel === 1) {
            return [3, '12-15', 45, '2-0-2-0', 'RPE 6-7', 'Smooth continuous cadence, minimize rest.'];
        } elseif ($expLevel === 2) {
            return [4, '12-15', 60, '2-0-2-0', 'RPE 7-8', 'Maintain high output density and rhythmic breathing.'];
        } else {
            return [4, '10-12', 60, '2-0-1-0', 'RPE 8', 'High metabolic demand, short rest between sets.'];
        }
    } else {
        // General health / Maintenance
        if ($expLevel === 1) {
            return [3, '10-12', 60, '2-0-2-0', 'RPE 6', 'Focus on posture alignment and smooth technique.'];
        } elseif ($expLevel === 2) {
            return [3, '8-12', 60, '2-0-1-0', 'RPE 7', 'Solid mechanical tension and full range of motion.'];
        } else {
            return [4, '8-10', 90, '2-0-1-0', 'RPE 7-8', 'Maintain balanced strength across all planes.'];
        }
    }
}

function _assign_sets_reps(string $goal, int $expLevel, array $ex): array
{
    $details = _assign_exercise_details($goal, $expLevel, $ex);
    return [$details[0], $details[1], $details[2]];
}

/**
 * Checks whether a member is self-guided (not under an active personal trainer/coach).
 * A member is considered coached/trainer-guided if:
 *  1. They have an active trainer assignment in `trainer_assignments`, OR
 *  2. They have an active training plan created/published by a trainer (`trainer_id IS NOT NULL`).
 */
function is_member_self_guided(int $memberUserId): bool
{
    $pdo = db();

    // 1. Check for active trainer assignment
    $hasActiveAssignment = (bool) scalar(
        'SELECT 1 FROM trainer_assignments WHERE member_user_id = ? AND status = "active" LIMIT 1',
        [$memberUserId]
    );
    if ($hasActiveAssignment) {
        return false;
    }

    // 2. Check for active trainer-published workout plan
    $hasTrainerPlan = (bool) scalar(
        'SELECT 1 FROM training_plans WHERE member_user_id = ? AND trainer_id IS NOT NULL AND status = "active" LIMIT 1',
        [$memberUserId]
    );
    if ($hasTrainerPlan) {
        return false;
    }

    return true;
}

/**
 * Helper to fetch the user_id (in `users` table) of the active trainer assigned to a member.
 * Checks active trainer assignments first, then active trainer plans.
 */
function get_member_active_trainer_user_id(int $memberUserId): ?int
{
    $pdo = db();

    // Priority 1: From active trainer assignment
    $trainerUserId = scalar(
        'SELECT tp.user_id 
         FROM trainer_assignments ta
         JOIN trainer_profiles tp ON ta.trainer_id = tp.trainer_id
         WHERE ta.member_user_id = ? AND ta.status = "active"
         ORDER BY ta.assignment_id DESC LIMIT 1',
        [$memberUserId]
    );
    if ($trainerUserId) {
        return (int) $trainerUserId;
    }

    // Priority 2: From active trainer plan
    $planTrainerUserId = scalar(
        'SELECT tp.user_id 
         FROM training_plans p
         JOIN trainer_profiles tp ON p.trainer_id = tp.trainer_id
         WHERE p.member_user_id = ? AND p.trainer_id IS NOT NULL AND p.status = "active"
         ORDER BY p.plan_id DESC LIMIT 1',
        [$memberUserId]
    );
    if ($planTrainerUserId) {
        return (int) $planTrainerUserId;
    }

    return null;
}

/**
 * Returns true if automated workout recalculation is allowed for this member.
 * Only self-guided members are eligible for automated recalculation.
 */
function can_recalculate_workout(int $memberUserId): bool
{
    return is_member_self_guided($memberUserId);
}

function generate_workout_plan(int $memberUserId, ?int $coachId = null, ?int $gymId = null): int
{
    $pdo = db();

    // Resolve gymId if not explicitly passed
    if ($gymId === null || $gymId <= 0) {
        if ($coachId !== null) {
            $gymId = (int) scalar('SELECT gym_id FROM trainer_profiles WHERE trainer_id = ? LIMIT 1', [$coachId]);
        }
        if (!$gymId && !empty($_SESSION['current_gym_id'])) {
            $gymId = (int) $_SESSION['current_gym_id'];
        }
        if (!$gymId) {
            $gymId = (int) scalar('SELECT gym_id FROM gym_members WHERE user_id = ? LIMIT 1', [$memberUserId]);
        }
    }
    $scopedGymId = ($gymId > 0) ? $gymId : null;
    
    // Guard: Automated/system updates ($coachId === null) MUST NOT overwrite plans for coached members
    if ($coachId === null && !is_member_self_guided($memberUserId)) {
        $guardSql = 'SELECT plan_id FROM training_plans WHERE member_user_id = ? AND status = "active"';
        $guardParams = [$memberUserId];
        if ($scopedGymId !== null) {
            $guardSql .= ' AND gym_id = ?';
            $guardParams[] = $scopedGymId;
        }
        $guardSql .= ' ORDER BY plan_id DESC LIMIT 1';
        $existingPlanId = (int) scalar($guardSql, $guardParams);
        return $existingPlanId;
    }
    
    // Get member profile
    $stmt = $pdo->prepare('SELECT * FROM member_profiles WHERE user_id = ?');
    $stmt->execute([$memberUserId]);
    $profile = $stmt->fetch();
    if (!$profile) {
        throw new RuntimeException('Cannot generate workout plan: member profile missing.');
    }
    
    if ((float)$profile['height_cm'] == 0 || (float)$profile['weight_kg'] == 0) {
        // Skip generating if they haven't provided measurements
        return 0;
    }

    $goal = $profile['primary_goal'];

    // Delete or archive existing system_generated plans for THIS gym only (never touch other gyms' plans)
    if ($scopedGymId !== null) {
        $pdo->prepare('DELETE FROM training_plans WHERE member_user_id = ? AND trainer_id IS NULL AND gym_id = ?')->execute([$memberUserId, $scopedGymId]);
    } else {
        $pdo->prepare('DELETE FROM training_plans WHERE member_user_id = ? AND trainer_id IS NULL AND (gym_id IS NULL OR gym_id = 0)')->execute([$memberUserId]);
    }

    // Get member name for title
    $stmt = $pdo->prepare('SELECT first_name FROM users WHERE user_id = ?');
    $stmt->execute([$memberUserId]);
    $firstName = $stmt->fetchColumn() ?: 'Member';

    // Create a new training plan
    $title = 'Workout Plan for ' . $firstName;
    $stmt = $pdo->prepare('INSERT INTO training_plans (member_user_id, trainer_id, gym_id, title, goal, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 4 WEEK), "active")');
    $stmt->execute([$memberUserId, $coachId, $scopedGymId, $title, $goal]);
    $planId = (int) $pdo->lastInsertId();

    if (function_exists('log_plan_change')) {
        log_plan_change('workout', $planId, $scopedGymId, $coachId ? (int)scalar('SELECT user_id FROM trainer_profiles WHERE trainer_id = ?', [$coachId]) : $memberUserId, 'created', 'Generated tailored workout plan');
    }

    // Generate workout using the helper
    [$exercises, $days, $expLevel, $goal] = _get_workout_parameters($profile, $pdo);

    if (!$exercises) {
        return $planId;
    }

    // Assign exercises per training day
    foreach ($days as $dayOfWeek) {
        $dayExercises = $exercises;
        if ($goal !== 'muscle_gain' && $goal !== 'fat_loss' && empty($profile['secondary_focus'])) {
            shuffle($dayExercises);
        }

        $prefDuration = (int) ($profile['preferred_duration_mins'] ?? 45);
        $targetCount = match(true) {
            $prefDuration <= 35 => rand(3, 4),
            $prefDuration >= 65 => rand(5, 6),
            default => rand(4, 5)
        };
        $assigned = _select_daily_exercises($dayExercises, $profile, $goal, $targetCount);
        
        $order = 1;
        foreach ($assigned as $ex) {
            [$sets, $reps, $rest, $tempo, $rpe, $notes] = _assign_exercise_details($goal, $expLevel, $ex);

            $stmt = $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, rest_seconds, tempo, rpe, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$planId, (int) $ex['exercise_id'], $dayOfWeek, $order++, $sets, $reps, $rest, $tempo, $rpe, $notes]);
        }
    }

    return $planId;
}

function auto_populate_plan(int $planId, int $memberUserId): void
{
    $pdo = db();
    
    $stmt = $pdo->prepare('SELECT * FROM member_profiles WHERE user_id = ?');
    $stmt->execute([$memberUserId]);
    $profile = $stmt->fetch();
    if (!$profile) return;

    $goal = $profile['primary_goal'];

    $pdo->prepare('DELETE FROM training_plan_exercises WHERE plan_id = ?')->execute([$planId]);

    [$exercises, $days, $expLevel, $goal] = _get_workout_parameters($profile, $pdo);

    if (!$exercises) return;

    foreach ($days as $dayOfWeek) {
        $dayExercises = $exercises;
        if ($goal !== 'muscle_gain' && $goal !== 'fat_loss' && empty($profile['secondary_focus'])) {
            shuffle($dayExercises);
        }

        $prefDuration = (int) ($profile['preferred_duration_mins'] ?? 45);
        $targetCount = match(true) {
            $prefDuration <= 35 => rand(3, 4),
            $prefDuration >= 65 => rand(5, 6),
            default => rand(4, 5)
        };
        $assigned = _select_daily_exercises($dayExercises, $profile, $goal, $targetCount);
        
        $order = 1;
        foreach ($assigned as $ex) {
            [$sets, $reps, $rest, $tempo, $rpe, $notes] = _assign_exercise_details($goal, $expLevel, $ex);

            $stmt = $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, rest_seconds, tempo, rpe, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$planId, (int) $ex['exercise_id'], $dayOfWeek, $order++, $sets, $reps, $rest, $tempo, $rpe, $notes]);
        }
    }
}

function auto_populate_day(int $planId, int $memberUserId, int $dayOfWeek): void
{
    $pdo = db();
    
    $stmt = $pdo->prepare('SELECT * FROM member_profiles WHERE user_id = ?');
    $stmt->execute([$memberUserId]);
    $profile = $stmt->fetch();
    if (!$profile) return;

    $goal = $profile['primary_goal'];

    // Delete existing exercises for this day in this plan
    $pdo->prepare('DELETE FROM training_plan_exercises WHERE plan_id = ? AND day_of_week = ?')->execute([$planId, $dayOfWeek]);

    [$exercises, $days, $expLevel, $goal] = _get_workout_parameters($profile, $pdo);

    if (!$exercises) return;

    $dayExercises = $exercises;
    if ($goal !== 'muscle_gain' && $goal !== 'fat_loss' && empty($profile['secondary_focus'])) {
        shuffle($dayExercises);
    }

    $targetCount = rand(3, 5);
    $assigned = _select_daily_exercises($dayExercises, $profile, $goal, $targetCount);
    
    $order = 1;
    foreach ($assigned as $ex) {
        [$sets, $reps, $rest, $tempo, $rpe, $notes] = _assign_exercise_details($goal, $expLevel, $ex);

        $stmt = $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, rest_seconds, tempo, rpe, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$planId, (int) $ex['exercise_id'], $dayOfWeek, $order++, $sets, $reps, $rest, $tempo, $rpe, $notes]);
    }
}

/**
 * Curated Workout Templates Library
 */
function get_workout_templates(): array
{
    return [
        'full_body' => [
            'name' => 'Full Body 3-Day',
            'badge' => '3 Days / Week',
            'goal' => 'muscle_gain',
            'description' => 'Comprehensive total-body workout covering major compound movement patterns across three non-consecutive days. Maximizes frequency and balanced development.',
            'days' => [
                1 => [
                    'focus' => 'Full Body A (Squat & Push Focus)',
                    'exercises' => [
                        ['name' => 'Squat', 'sets' => 3, 'reps' => '8-10', 'rest' => 90, 'tempo' => '3-0-1-0', 'rpe' => 'RPE 7-8', 'notes' => 'Keep chest tall, drive knees out over toes.'],
                        ['name' => 'Bench press', 'sets' => 3, 'reps' => '8-10', 'rest' => 90, 'tempo' => '3-0-1-0', 'rpe' => 'RPE 7-8', 'notes' => 'Retract scapulae and press evenly with stable base.'],
                        ['name' => 'Lat pulldown', 'sets' => 3, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 7', 'notes' => 'Pull with elbows down and back, squeeze lats.'],
                        ['name' => 'Plank', 'sets' => 3, 'reps' => '45-60s', 'rest' => 45, 'tempo' => 'Isometric', 'rpe' => 'RPE 7', 'notes' => 'Engage glutes and brace abdominal wall rigidly.']
                    ]
                ],
                3 => [
                    'focus' => 'Full Body B (Hinge & Vertical Press)',
                    'exercises' => [
                        ['name' => 'Deadlift', 'sets' => 3, 'reps' => '6-8', 'rest' => 120, 'tempo' => '2-1-1-0', 'rpe' => 'RPE 8', 'notes' => 'Neutral spine, drive forcefully through mid-foot.'],
                        ['name' => 'Overhead press', 'sets' => 3, 'reps' => '8-10', 'rest' => 90, 'tempo' => '2-0-1-0', 'rpe' => 'RPE 7-8', 'notes' => 'Squeeze glutes to protect lumbar spine.'],
                        ['name' => 'Dumbbell lunges', 'sets' => 3, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-1-0', 'rpe' => 'RPE 7', 'notes' => 'Keep torso upright, controlled 90-degree knee bend.'],
                        ['name' => 'Bicep curls', 'sets' => 3, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 8', 'notes' => 'Pin elbows to sides, complete full curl contraction.']
                    ]
                ],
                5 => [
                    'focus' => 'Full Body C (Hypertrophy & Core)',
                    'exercises' => [
                        ['name' => 'Leg press', 'sets' => 3, 'reps' => '10-12', 'rest' => 90, 'tempo' => '3-0-1-0', 'rpe' => 'RPE 8', 'notes' => 'Do not lock out knees at peak extension.'],
                        ['name' => 'Barbell row', 'sets' => 3, 'reps' => '8-10', 'rest' => 90, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 7-8', 'notes' => 'Hinge at hips, pull barbell toward lower ribcage.'],
                        ['name' => 'Tricep pushdown', 'sets' => 3, 'reps' => '12-15', 'rest' => 60, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 8', 'notes' => 'Full triceps lockout with flared rope finish.'],
                        ['name' => 'Russian twists', 'sets' => 3, 'reps' => '15-20', 'rest' => 45, 'tempo' => 'Controlled', 'rpe' => 'RPE 7', 'notes' => 'Rotate from torso, keeping hip alignment square.']
                    ]
                ]
            ]
        ],
        'upper_lower' => [
            'name' => 'Upper / Lower 4-Day',
            'badge' => '4 Days / Week',
            'goal' => 'muscle_gain',
            'description' => 'Classic 4-day periodization alternating upper body and lower body days. Exceptional for progressive overload and muscle building with adequate recovery.',
            'days' => [
                1 => [
                    'focus' => 'Upper Body Power (A)',
                    'exercises' => [
                        ['name' => 'Bench press', 'sets' => 4, 'reps' => '6-8', 'rest' => 120, 'tempo' => '3-0-1-0', 'rpe' => 'RPE 8', 'notes' => 'Arch upper back slightly, tuck elbows 45 degrees.'],
                        ['name' => 'Barbell row', 'sets' => 4, 'reps' => '6-8', 'rest' => 120, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 8', 'notes' => 'Explosive pull, controlled 2-second negative.'],
                        ['name' => 'Overhead press', 'sets' => 3, 'reps' => '8-10', 'rest' => 90, 'tempo' => '2-0-1-0', 'rpe' => 'RPE 7-8', 'notes' => 'Lock out elbows directly overhead in line with ears.'],
                        ['name' => 'Tricep pushdown', 'sets' => 3, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 8', 'notes' => 'Squeeze triceps for 1s at bottom lockout.']
                    ]
                ],
                2 => [
                    'focus' => 'Lower Body Quad Focus (A)',
                    'exercises' => [
                        ['name' => 'Squat', 'sets' => 4, 'reps' => '6-8', 'rest' => 120, 'tempo' => '3-1-1-0', 'rpe' => 'RPE 8', 'notes' => 'Break at hips and knees simultaneously.'],
                        ['name' => 'Leg press', 'sets' => 3, 'reps' => '10-12', 'rest' => 90, 'tempo' => '3-0-1-0', 'rpe' => 'RPE 8', 'notes' => 'Feet shoulder-width on platform center.'],
                        ['name' => 'Dumbbell lunges', 'sets' => 3, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-1-0', 'rpe' => 'RPE 7', 'notes' => 'Decelerate smoothly into bottom lunge step.'],
                        ['name' => 'Plank', 'sets' => 3, 'reps' => '60s', 'rest' => 45, 'tempo' => 'Isometric', 'rpe' => 'RPE 7', 'notes' => 'Hold solid rigid line from head to heels.']
                    ]
                ],
                4 => [
                    'focus' => 'Upper Body Hypertrophy (B)',
                    'exercises' => [
                        ['name' => 'Lat pulldown', 'sets' => 4, 'reps' => '10-12', 'rest' => 90, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 8', 'notes' => 'Wide grip, pull chest up toward the bar.'],
                        ['name' => 'Bench press', 'sets' => 3, 'reps' => '10-12', 'rest' => 90, 'tempo' => '3-0-1-0', 'rpe' => 'RPE 7-8', 'notes' => 'Moderate load, focus on chest stretch and pump.'],
                        ['name' => 'Lateral raise', 'sets' => 3, 'reps' => '12-15', 'rest' => 60, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 8', 'notes' => 'Slight forward lean, raise using lateral delts.'],
                        ['name' => 'Bicep curls', 'sets' => 3, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 8', 'notes' => 'Supinate wrists firmly at contraction peak.']
                    ]
                ],
                5 => [
                    'focus' => 'Lower Body Posterior Focus (B)',
                    'exercises' => [
                        ['name' => 'Deadlift', 'sets' => 4, 'reps' => '6-8', 'rest' => 120, 'tempo' => '2-1-1-0', 'rpe' => 'RPE 8', 'notes' => 'Engage lats to lock bar close to shins.'],
                        ['name' => 'Leg press', 'sets' => 3, 'reps' => '12-15', 'rest' => 90, 'tempo' => '2-0-1-0', 'rpe' => 'RPE 7-8', 'notes' => 'Higher foot placement for hamstring emphasis.'],
                        ['name' => 'Hanging leg raise', 'sets' => 3, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-1-0', 'rpe' => 'RPE 8', 'notes' => 'Avoid swinging, curl pelvis forward on ascent.'],
                        ['name' => 'Russian twists', 'sets' => 3, 'reps' => '20', 'rest' => 45, 'tempo' => 'Controlled', 'rpe' => 'RPE 7', 'notes' => 'Keep heels elevated for added challenge.']
                    ]
                ]
            ]
        ],
        'push_pull_legs' => [
            'name' => 'Push / Pull / Legs (PPL)',
            'badge' => '3 Days / Week',
            'goal' => 'muscle_gain',
            'description' => 'The premier athletic split categorizing muscles by mechanical movement pattern: Push (Chest, Shoulders, Triceps), Pull (Back, Biceps), Legs (Quads, Hamstrings, Core).',
            'days' => [
                1 => [
                    'focus' => 'Push Day (Chest, Shoulders, Triceps)',
                    'exercises' => [
                        ['name' => 'Bench press', 'sets' => 4, 'reps' => '8-10', 'rest' => 90, 'tempo' => '3-0-1-0', 'rpe' => 'RPE 8', 'notes' => 'Keep shoulder blades retracted and planted.'],
                        ['name' => 'Overhead press', 'sets' => 3, 'reps' => '8-10', 'rest' => 90, 'tempo' => '2-0-1-0', 'rpe' => 'RPE 7-8', 'notes' => 'Brace core, head through the window at top.'],
                        ['name' => 'Lateral raise', 'sets' => 3, 'reps' => '12-15', 'rest' => 60, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 8', 'notes' => 'Lead with elbows, pause 1 second at top.'],
                        ['name' => 'Tricep pushdown', 'sets' => 3, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 8', 'notes' => 'Lock out elbows without swaying upper body.']
                    ]
                ],
                3 => [
                    'focus' => 'Pull Day (Back, Rear Delts, Biceps)',
                    'exercises' => [
                        ['name' => 'Deadlift', 'sets' => 3, 'reps' => '6-8', 'rest' => 120, 'tempo' => '2-1-1-0', 'rpe' => 'RPE 8', 'notes' => 'Tight grip, engage posterior chain and drive hips.'],
                        ['name' => 'Barbell row', 'sets' => 3, 'reps' => '8-10', 'rest' => 90, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 7-8', 'notes' => 'Pull elbows high toward torso ribs.'],
                        ['name' => 'Lat pulldown', 'sets' => 3, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 7', 'notes' => 'Squeeze lats down, controlled return.'],
                        ['name' => 'Bicep curls', 'sets' => 3, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 8', 'notes' => 'Slow eccentric negative on descent.']
                    ]
                ],
                5 => [
                    'focus' => 'Legs & Core Day',
                    'exercises' => [
                        ['name' => 'Squat', 'sets' => 4, 'reps' => '8-10', 'rest' => 120, 'tempo' => '3-0-1-0', 'rpe' => 'RPE 8', 'notes' => 'Hit parallel depth with strong core brace.'],
                        ['name' => 'Leg press', 'sets' => 3, 'reps' => '10-12', 'rest' => 90, 'tempo' => '3-0-1-0', 'rpe' => 'RPE 8', 'notes' => 'Smooth rhythmic cadence without locking knees.'],
                        ['name' => 'Dumbbell lunges', 'sets' => 3, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-1-0', 'rpe' => 'RPE 7', 'notes' => 'Control descent, push off front heel.'],
                        ['name' => 'Hanging leg raise', 'sets' => 3, 'reps' => '10-15', 'rest' => 45, 'tempo' => 'Controlled', 'rpe' => 'RPE 8', 'notes' => 'Lift with lower abs, controlled descent.']
                    ]
                ]
            ]
        ],
        'strength_power' => [
            'name' => 'Strength & Heavy Compounds',
            'badge' => '3 Days / Week',
            'goal' => 'muscle_gain',
            'description' => 'Heavy linear progression program targeting maximum force production on the "Big 3" lifts (Squat, Bench, Deadlift) and key overhead assistance.',
            'days' => [
                1 => [
                    'focus' => 'Heavy Squat & Upper Press',
                    'exercises' => [
                        ['name' => 'Squat', 'sets' => 5, 'reps' => '5', 'rest' => 180, 'tempo' => '2-1-1-0', 'rpe' => 'RPE 8-9', 'notes' => 'High intensity work sets. Rest fully between sets.'],
                        ['name' => 'Overhead press', 'sets' => 4, 'reps' => '6', 'rest' => 120, 'tempo' => '2-0-1-0', 'rpe' => 'RPE 8', 'notes' => 'Explosive drive from clavicles to full lockout.'],
                        ['name' => 'Tricep pushdown', 'sets' => 3, 'reps' => '8-10', 'rest' => 60, 'tempo' => '2-0-1-0', 'rpe' => 'RPE 8', 'notes' => 'Auxiliary triceps work for lock-out power.']
                    ]
                ],
                3 => [
                    'focus' => 'Heavy Bench & Upper Pull',
                    'exercises' => [
                        ['name' => 'Bench press', 'sets' => 5, 'reps' => '5', 'rest' => 180, 'tempo' => '2-1-1-0', 'rpe' => 'RPE 8-9', 'notes' => 'Pause 1 second on chest before pressing.'],
                        ['name' => 'Barbell row', 'sets' => 4, 'reps' => '6', 'rest' => 120, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 8', 'notes' => 'Strict form, no excessive torso heave.'],
                        ['name' => 'Bicep curls', 'sets' => 3, 'reps' => '8-10', 'rest' => 60, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 7-8', 'notes' => 'Elbow joint stability support.']
                    ]
                ],
                5 => [
                    'focus' => 'Heavy Deadlift & Posterior Chain',
                    'exercises' => [
                        ['name' => 'Deadlift', 'sets' => 5, 'reps' => '5', 'rest' => 180, 'tempo' => '2-1-1-0', 'rpe' => 'RPE 8-9', 'notes' => 'Reset at each rep. Pull slack out of bar.'],
                        ['name' => 'Leg press', 'sets' => 3, 'reps' => '8-10', 'rest' => 120, 'tempo' => '3-0-1-0', 'rpe' => 'RPE 8', 'notes' => 'Quad hypertrophy volume overload.'],
                        ['name' => 'Plank', 'sets' => 3, 'reps' => '60-75s', 'rest' => 60, 'tempo' => 'Isometric', 'rpe' => 'RPE 8', 'notes' => 'Maximal intra-abdominal bracing endurance.']
                    ]
                ]
            ]
        ],
        'general_fitness' => [
            'name' => 'General Fitness & Conditioning',
            'badge' => '3 Days / Week',
            'goal' => 'general_health',
            'description' => 'A well-rounded, athletic routine that balances functional resistance training, aerobic intervals, and core stabilization for daily vitality.',
            'days' => [
                1 => [
                    'focus' => 'Circuit Resistance & Conditioning',
                    'exercises' => [
                        ['name' => 'Squat', 'sets' => 3, 'reps' => '12', 'rest' => 60, 'tempo' => '2-0-2-0', 'rpe' => 'RPE 7', 'notes' => 'Smooth continuous tempo, controlled cadence.'],
                        ['name' => 'Bench press', 'sets' => 3, 'reps' => '12', 'rest' => 60, 'tempo' => '2-0-2-0', 'rpe' => 'RPE 7', 'notes' => 'Controlled reps, full chest expansion.'],
                        ['name' => 'Treadmill intervals', 'sets' => 3, 'reps' => '10 mins', 'rest' => 60, 'tempo' => 'Interval', 'rpe' => 'RPE 8', 'notes' => '1 min fast jog / 1 min brisk recovery walk.'],
                        ['name' => 'Plank', 'sets' => 3, 'reps' => '45s', 'rest' => 45, 'tempo' => 'Isometric', 'rpe' => 'RPE 6', 'notes' => 'Steady nose breathing throughout.']
                    ]
                ],
                3 => [
                    'focus' => 'Pull Strength & Ergometer Endurance',
                    'exercises' => [
                        ['name' => 'Lat pulldown', 'sets' => 3, 'reps' => '12', 'rest' => 60, 'tempo' => '2-0-2-0', 'rpe' => 'RPE 7', 'notes' => 'Focus on upright posture and lat squeeze.'],
                        ['name' => 'Dumbbell lunges', 'sets' => 3, 'reps' => '12', 'rest' => 60, 'tempo' => '2-0-2-0', 'rpe' => 'RPE 7', 'notes' => 'Balance and single-leg stability.'],
                        ['name' => 'Rowing machine', 'sets' => 3, 'reps' => '10 mins', 'rest' => 60, 'tempo' => 'Cadence 24', 'rpe' => 'RPE 7-8', 'notes' => 'Legs, body, arms sequence on stroke.'],
                        ['name' => 'Dead bug', 'sets' => 3, 'reps' => '12', 'rest' => 45, 'tempo' => 'Slow', 'rpe' => 'RPE 6', 'notes' => 'Keep lower back glued to floor.']
                    ]
                ],
                5 => [
                    'focus' => 'Functional Stamina & Agility',
                    'exercises' => [
                        ['name' => 'Overhead press', 'sets' => 3, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-2-0', 'rpe' => 'RPE 7', 'notes' => 'Maintain neutral spine and tight core.'],
                        ['name' => 'Leg press', 'sets' => 3, 'reps' => '12-15', 'rest' => 60, 'tempo' => '2-0-2-0', 'rpe' => 'RPE 7', 'notes' => 'Moderate resistance, continuous movement.'],
                        ['name' => 'Jump rope', 'sets' => 3, 'reps' => '3 mins', 'rest' => 45, 'tempo' => 'Rhythm', 'rpe' => 'RPE 7', 'notes' => 'Stay light on balls of feet.'],
                        ['name' => 'Bicycle crunches', 'sets' => 3, 'reps' => '15-20', 'rest' => 45, 'tempo' => 'Controlled', 'rpe' => 'RPE 7', 'notes' => 'Elbow to opposite knee with shoulder elevation.']
                    ]
                ]
            ]
        ],
        'beginner_3day' => [
            'name' => 'Beginner 3-Day Foundation',
            'badge' => '3 Days / Week',
            'goal' => 'general_health',
            'description' => 'Designed for gym newcomers. Emphasizes foundational motor patterns, safe machine exercises, higher repetitions (12-15), and moderate rest to build neuromuscular confidence.',
            'days' => [
                1 => [
                    'focus' => 'Foundations A (Push & Quads)',
                    'exercises' => [
                        ['name' => 'Leg press', 'sets' => 3, 'reps' => '12-15', 'rest' => 60, 'tempo' => '3-0-1-0', 'rpe' => 'RPE 6-7', 'notes' => 'Safe starter movement. Focus on steady controlled descent.'],
                        ['name' => 'Bench press', 'sets' => 3, 'reps' => '10-12', 'rest' => 60, 'tempo' => '3-0-1-0', 'rpe' => 'RPE 6-7', 'notes' => 'Use light dumbbells or empty barbell to learn bar path.'],
                        ['name' => 'Lat pulldown', 'sets' => 3, 'reps' => '12-15', 'rest' => 60, 'tempo' => '2-0-2-0', 'rpe' => 'RPE 6', 'notes' => 'Pull gently to upper chest without leaning backwards.'],
                        ['name' => 'Plank', 'sets' => 3, 'reps' => '30-40s', 'rest' => 45, 'tempo' => 'Isometric', 'rpe' => 'RPE 6', 'notes' => 'Focus on holding steady form without sagging hips.']
                    ]
                ],
                3 => [
                    'focus' => 'Foundations B (Pull & Hamstrings)',
                    'exercises' => [
                        ['name' => 'Dumbbell lunges', 'sets' => 3, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-1-0', 'rpe' => 'RPE 6', 'notes' => 'Use bodyweight or light dumbbells to establish balance.'],
                        ['name' => 'Barbell row', 'sets' => 3, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-1-0', 'rpe' => 'RPE 6-7', 'notes' => 'Light weight, practice the hip-hinge position.'],
                        ['name' => 'Lateral raise', 'sets' => 3, 'reps' => '12-15', 'rest' => 60, 'tempo' => '2-0-1-0', 'rpe' => 'RPE 6', 'notes' => 'Light dumbbells, raise smoothly to shoulder level.'],
                        ['name' => 'Dead bug', 'sets' => 3, 'reps' => '10-12', 'rest' => 45, 'tempo' => 'Slow', 'rpe' => 'RPE 6', 'notes' => 'Exhale as arm and opposite leg extend.']
                    ]
                ],
                5 => [
                    'focus' => 'Foundations C (Compound & Conditioning)',
                    'exercises' => [
                        ['name' => 'Squat', 'sets' => 3, 'reps' => '10-12', 'rest' => 90, 'tempo' => '3-0-1-0', 'rpe' => 'RPE 6-7', 'notes' => 'Goblet squat with light dumbbell or bodyweight.'],
                        ['name' => 'Overhead press', 'sets' => 3, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-1-0', 'rpe' => 'RPE 6', 'notes' => 'Maintain neutral neck and spine alignment.'],
                        ['name' => 'Bicep curls', 'sets' => 3, 'reps' => '12-15', 'rest' => 60, 'tempo' => '2-0-1-0', 'rpe' => 'RPE 7', 'notes' => 'Control the weight down, do not swing shoulders.'],
                        ['name' => 'Stationary bike', 'sets' => 2, 'reps' => '10 mins', 'rest' => 60, 'tempo' => 'Easy Spin', 'rpe' => 'RPE 5-6', 'notes' => 'Low-impact cool-down and aerobic capacity builder.']
                    ]
                ]
            ]
        ],
        'bro_split_4day' => [
            'name' => '4-Day Muscle Split',
            'badge' => '4 Days / Week',
            'goal' => 'muscle_gain',
            'description' => 'Targeted high-volume body part split: Chest & Triceps, Back & Biceps, Shoulders & Abs, Legs. Outstanding pump and hypertrophy isolation.',
            'days' => [
                1 => [
                    'focus' => 'Day 1: Chest & Triceps',
                    'exercises' => [
                        ['name' => 'Bench press', 'sets' => 4, 'reps' => '8-10', 'rest' => 90, 'tempo' => '3-0-1-0', 'rpe' => 'RPE 8', 'notes' => 'Main compound press. Retract shoulder blades.'],
                        ['name' => 'Tricep pushdown', 'sets' => 4, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 8', 'notes' => 'Flare ropes at contraction for tricep peak.'],
                        ['name' => 'Plank', 'sets' => 3, 'reps' => '60s', 'rest' => 45, 'tempo' => 'Isometric', 'rpe' => 'RPE 7', 'notes' => 'Anti-extension core finisher.']
                    ]
                ],
                2 => [
                    'focus' => 'Day 2: Back & Biceps',
                    'exercises' => [
                        ['name' => 'Deadlift', 'sets' => 4, 'reps' => '6-8', 'rest' => 120, 'tempo' => '2-1-1-0', 'rpe' => 'RPE 8', 'notes' => 'Heavy hinge builder. Keep bar against shins.'],
                        ['name' => 'Barbell row', 'sets' => 3, 'reps' => '8-10', 'rest' => 90, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 8', 'notes' => 'Row to belly button, squeeze shoulder blades.'],
                        ['name' => 'Lat pulldown', 'sets' => 3, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 7-8', 'notes' => 'Full lat stretch at top without shrugging.'],
                        ['name' => 'Bicep curls', 'sets' => 4, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 8', 'notes' => 'Isolate biceps with 2s eccentric descent.']
                    ]
                ],
                4 => [
                    'focus' => 'Day 3: Shoulders & Abs',
                    'exercises' => [
                        ['name' => 'Overhead press', 'sets' => 4, 'reps' => '8-10', 'rest' => 90, 'tempo' => '2-0-1-0', 'rpe' => 'RPE 8', 'notes' => 'Strict vertical bar path. Squeeze glutes.'],
                        ['name' => 'Lateral raise', 'sets' => 4, 'reps' => '12-15', 'rest' => 60, 'tempo' => '2-0-1-1', 'rpe' => 'RPE 8', 'notes' => 'Side delt isolation with controlled tempo.'],
                        ['name' => 'Hanging leg raise', 'sets' => 3, 'reps' => '12-15', 'rest' => 45, 'tempo' => 'Controlled', 'rpe' => 'RPE 8', 'notes' => 'Posterior pelvic tilt to target lower abs.'],
                        ['name' => 'Russian twists', 'sets' => 3, 'reps' => '20', 'rest' => 45, 'tempo' => 'Controlled', 'rpe' => 'RPE 7', 'notes' => 'Oblique rotation with stable torso.']
                    ]
                ],
                5 => [
                    'focus' => 'Day 4: Quad & Hamstring Dominance',
                    'exercises' => [
                        ['name' => 'Squat', 'sets' => 4, 'reps' => '8-10', 'rest' => 120, 'tempo' => '3-0-1-0', 'rpe' => 'RPE 8', 'notes' => 'Drive up forcefully out of the hole.'],
                        ['name' => 'Leg press', 'sets' => 4, 'reps' => '10-12', 'rest' => 90, 'tempo' => '3-0-1-0', 'rpe' => 'RPE 8', 'notes' => 'High volume leg finisher, controlled cadence.'],
                        ['name' => 'Dumbbell lunges', 'sets' => 3, 'reps' => '10-12', 'rest' => 60, 'tempo' => '2-0-1-0', 'rpe' => 'RPE 7-8', 'notes' => 'Keep vertical shin angle on lead leg.']
                    ]
                ]
            ]
        ]
    ];
}

/**
 * Helper to fetch available workout templates formatted for UI selectors & builders
 */
function get_available_workout_templates(): array
{
    $raw = get_workout_templates();
    $result = [];
    foreach ($raw as $key => $t) {
        $daysCount = isset($t['days']) && is_array($t['days']) ? count($t['days']) : 3;
        $title = $t['title'] ?? $t['name'] ?? ucfirst(str_replace('_', ' ', $key));
        $result[$key] = array_merge($t, [
            'key' => $key,
            'title' => $title,
            'name' => $t['name'] ?? $title,
            'days_per_week' => $t['days_per_week'] ?? $daysCount,
            'split_name' => $t['split_name'] ?? $title,
            'primary_goal' => $t['primary_goal'] ?? $t['goal'] ?? 'general_health',
            'goal' => $t['goal'] ?? $t['primary_goal'] ?? 'general_health',
            'badge' => $t['badge'] ?? "{$daysCount} Days / Week",
            'description' => $t['description'] ?? '',
            'days' => $t['days'] ?? []
        ]);
    }
    return $result;
}

/**
 * Exercise search helper with intelligent name & muscle group matching
 */
function _find_exercise_id(PDO $pdo, int $gymId, string $targetName, ?string $fallbackMuscle = null): ?int
{
    if ($gymId <= 0) return null;

    // 1. Exact match in gym
    $stmt = $pdo->prepare('SELECT exercise_id FROM exercises WHERE gym_id = ? AND LOWER(name) = LOWER(?) LIMIT 1');
    $stmt->execute([$gymId, $targetName]);
    $id = $stmt->fetchColumn();
    if ($id) return (int) $id;

    // 2. Substring / LIKE match in gym
    $stmt = $pdo->prepare('SELECT exercise_id FROM exercises WHERE gym_id = ? AND LOWER(name) LIKE LOWER(?) LIMIT 1');
    $stmt->execute([$gymId, '%' . $targetName . '%']);
    $id = $stmt->fetchColumn();
    if ($id) return (int) $id;

    // 3. Fallback by muscle group if provided in gym
    if ($fallbackMuscle) {
        $stmt = $pdo->prepare('SELECT exercise_id FROM exercises WHERE gym_id = ? AND LOWER(muscle_group) = LOWER(?) LIMIT 1');
        $stmt->execute([$gymId, $fallbackMuscle]);
        $id = $stmt->fetchColumn();
        if ($id) return (int) $id;
    }

    return null;
}

/**
 * Applies a full workout template to a plan
 */
function apply_workout_template(int $planId, string $templateKey, int $gymId): bool
{
    $templates = get_workout_templates();
    if (!isset($templates[$templateKey])) {
        return false;
    }

    $template = $templates[$templateKey];
    $pdo = db();

    // Clear existing exercises in draft plan
    $pdo->prepare('DELETE FROM training_plan_exercises WHERE plan_id = ?')->execute([$planId]);

    // Update plan title and goal
    $pdo->prepare('UPDATE training_plans SET title = CONCAT("Workout Plan - ", ?), goal = ? WHERE plan_id = ?')
        ->execute([$template['name'], $template['goal'], $planId]);

    $insertStmt = $pdo->prepare('
        INSERT INTO training_plan_exercises 
        (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, target_weight_kg, rest_seconds, notes, tempo, rpe)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    foreach ($template['days'] as $dayNum => $dayData) {
        $order = 1;
        foreach ($dayData['exercises'] as $exDef) {
            $exId = _find_exercise_id($pdo, $gymId, $exDef['name']);
            if (!$exId) continue;

            $sets = (int) ($exDef['sets'] ?? 3);
            $reps = (string) ($exDef['reps'] ?? '10-12');
            $weight = isset($exDef['weight']) && $exDef['weight'] > 0 ? (float) $exDef['weight'] : null;
            $rest = (int) ($exDef['rest'] ?? 60);
            $notes = (string) ($exDef['notes'] ?? '');
            $tempo = (string) ($exDef['tempo'] ?? '2-0-2-0');
            $rpe = (string) ($exDef['rpe'] ?? 'RPE 7-8');

            $insertStmt->execute([
                $planId,
                $exId,
                $dayNum,
                $order++,
                $sets,
                $reps,
                $weight,
                $rest,
                $notes,
                $tempo,
                $rpe
            ]);
        }
    }

    return true;
}

/**
 * Configurable, intelligent auto-generation routine
 */
function auto_populate_plan_advanced(int $planId, int $memberUserId, array $options): bool
{
    $pdo = db();
    
    $stmt = $pdo->prepare('SELECT * FROM member_profiles WHERE user_id = ?');
    $stmt->execute([$memberUserId]);
    $profile = $stmt->fetch() ?: [];

    // Extract options with fallbacks to member profile
    $goal = $options['goal'] ?? map_detailed_goal_to_basic($profile['primary_goal'] ?? 'general_health');
    $expLevel = (int) ($options['experience_level'] ?? 2);
    $daysCount = (int) ($options['days_count'] ?? 3);
    $splitType = $options['split_type'] ?? 'auto';

    $fallbackGymId = (int) ($pdo->query('
            SELECT tp.gym_id 
            FROM training_plans p 
            JOIN trainer_profiles tp ON p.trainer_id = tp.trainer_id 
            WHERE p.plan_id = ' . (int)$planId)->fetchColumn() ?: 0);
    $memberGymId = (int) ($options['library_gym_id'] ?? 0);
    if ($memberGymId <= 0) {
        $memberGymId = workout_library_gym_id($pdo, $memberUserId, $fallbackGymId);
    }

    // Prioritize member's chosen training days over hardcoded presets
    $preferredDaysRaw = trim((string)($profile['preferred_workout_days'] ?? ''));
    $daysMap = [];
    if ($preferredDaysRaw !== '') {
        $parsedDays = array_map('intval', explode(',', $preferredDaysRaw));
        $parsedDays = array_values(array_filter($parsedDays, fn($d) => $d >= 1 && $d <= 7));
        sort($parsedDays);
        if (!empty($parsedDays)) {
            $daysMap = $parsedDays;
        }
    }
    if (empty($daysMap)) {
        $daysMap = workout_days_for_frequency($daysCount);
    }

    // If splitType is auto, pick best split for days count
    if ($splitType === 'auto') {
        if ($daysCount <= 3) {
            $splitType = ($goal === 'strength' || $goal === 'muscle_gain') ? 'full_body' : 'general';
        } elseif ($daysCount === 4) {
            $splitType = 'upper_lower';
        } else {
            $splitType = 'ppl';
        }
    }

    // Fetch exercises available for this gym
    $stmt = $pdo->prepare('SELECT * FROM exercises WHERE gym_id = ? ORDER BY muscle_group, name');
    $stmt->execute([$memberGymId]);
    $allExercises = $stmt->fetchAll();

    if (!$allExercises) return false;

    // Only replace the draft after confirming the selected library has exercises.
    $pdo->prepare('DELETE FROM training_plan_exercises WHERE plan_id = ?')->execute([$planId]);

    $insertStmt = $pdo->prepare('
        INSERT INTO training_plan_exercises 
        (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, target_weight_kg, rest_seconds, notes, tempo, rpe)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    // Group exercises by muscle/type
    $exercisesByGroup = [];
    foreach ($allExercises as $e) {
        $exercisesByGroup[$e['muscle_group']][] = $e;
    }

    // Day focus mapping based on split
    foreach ($daysMap as $dayIndex => $dayOfWeek) {
        $focusMuscles = match($splitType) {
            'upper_lower' => ($dayIndex % 2 === 0) 
                ? ['chest', 'back', 'shoulders', 'arms'] 
                : ['legs', 'posterior chain', 'abdominals', 'obliques'],
            'ppl' => match($dayIndex % 3) {
                0 => ['chest', 'shoulders', 'arms'],
                1 => ['back', 'arms', 'posterior chain'],
                default => ['legs', 'posterior chain', 'abdominals']
            },
            'body_part' => match($dayIndex % 4) {
                0 => ['chest', 'arms'],
                1 => ['back', 'arms'],
                2 => ['shoulders', 'abdominals'],
                default => ['legs', 'posterior chain']
            },
            default => ['legs', 'chest', 'back', 'abdominals'] // Full Body / General
        };

        // Pick 1 exercise per target muscle group
        $dayExercises = [];
        foreach ($focusMuscles as $mg) {
            if (!empty($exercisesByGroup[$mg])) {
                $candidates = $exercisesByGroup[$mg];
                $filtered = array_filter($candidates, fn($c) => (int)($c['difficulty_level'] ?? 1) <= $expLevel);
                if (empty($filtered)) $filtered = $candidates;
                $picked = $filtered[array_rand($filtered)];
                if (!in_array($picked['exercise_id'], array_column($dayExercises, 'exercise_id'))) {
                    $dayExercises[] = $picked;
                }
            }
        }

        // Limit to 4-5 exercises per day
        $dayExercises = array_slice($dayExercises, 0, min(5, max(3, count($dayExercises))));

        $order = 1;
        foreach ($dayExercises as $ex) {
            [$sets, $reps, $rest, $tempo, $rpe, $notes] = _assign_exercise_details($goal, $expLevel, $ex);
            $weight = null;
            if (!empty($profile['weight_kg']) && in_array($ex['muscle_group'], ['legs', 'chest', 'back'])) {
                $ratio = match($ex['muscle_group']) {
                    'legs' => 0.75,
                    'chest' => 0.60,
                    'back' => 0.55,
                    default => 0.30
                };
                $weight = round((float)$profile['weight_kg'] * $ratio, 1);
            }

            $insertStmt->execute([
                $planId,
                (int) $ex['exercise_id'],
                $dayOfWeek,
                $order++,
                $sets,
                $reps,
                $weight,
                $rest,
                $notes,
                $tempo,
                $rpe
            ]);
        }
    }

    return true;
}

/**
 * Generates a targeted routine for an individual day
 */
function auto_populate_day_focused(int $planId, int $memberUserId, int $dayOfWeek, string $focus, ?int $libraryGymId = null): bool
{
    $pdo = db();
    
    $stmt = $pdo->prepare('SELECT * FROM member_profiles WHERE user_id = ?');
    $stmt->execute([$memberUserId]);
    $profile = $stmt->fetch() ?: [];
    $goal = map_detailed_goal_to_basic($profile['primary_goal'] ?? 'general_health');
    $tier = (int) ($profile['fitness_tier'] ?? 1);
    $expLevel = ($tier >= 5) ? 3 : (($tier >= 3) ? 2 : 1);

    $fallbackGymId = (int) ($pdo->query('
            SELECT tp.gym_id 
            FROM training_plans p 
            JOIN trainer_profiles tp ON p.trainer_id = tp.trainer_id 
            WHERE p.plan_id = ' . (int)$planId)->fetchColumn() ?: 0);
    $memberGymId = $libraryGymId && $libraryGymId > 0
        ? $libraryGymId
        : workout_library_gym_id($pdo, $memberUserId, $fallbackGymId);

    $targetMuscles = match($focus) {
        'push' => ['chest', 'shoulders', 'arms'],
        'pull' => ['back', 'arms', 'posterior chain'],
        'legs' => ['legs', 'posterior chain', 'abdominals'],
        'upper' => ['chest', 'back', 'shoulders', 'arms'],
        'lower' => ['legs', 'posterior chain', 'abdominals', 'obliques'],
        'cardio_core' => ['full body', 'abdominals', 'obliques'],
        default => ['legs', 'chest', 'back', 'abdominals'] // full body
    };

    $placeholders = implode(',', array_fill(0, count($targetMuscles), '?'));
    $params = array_merge([$memberGymId], $targetMuscles);
    $stmt = $pdo->prepare("SELECT * FROM exercises WHERE gym_id = ? AND muscle_group IN ($placeholders) ORDER BY muscle_group, name");
    $stmt->execute($params);
    $exercises = $stmt->fetchAll();

    if (!$exercises) return false;

    // Keep the existing day intact when the selected library has no matches.
    $pdo->prepare('DELETE FROM training_plan_exercises WHERE plan_id = ? AND day_of_week = ?')->execute([$planId, $dayOfWeek]);

    $byGroup = [];
    foreach ($exercises as $e) {
        $byGroup[$e['muscle_group']][] = $e;
    }

    $selected = [];
    foreach ($targetMuscles as $m) {
        if (!empty($byGroup[$m])) {
            $pool = $byGroup[$m];
            $cand = $pool[array_rand($pool)];
            if (!in_array($cand['exercise_id'], array_column($selected, 'exercise_id'))) {
                $selected[] = $cand;
            }
        }
    }

    // Limit to 4-5 exercises
    $selected = array_slice($selected, 0, min(5, max(3, count($selected))));

    $insertStmt = $pdo->prepare('
        INSERT INTO training_plan_exercises 
        (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, target_weight_kg, rest_seconds, notes, tempo, rpe)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    $order = 1;
    foreach ($selected as $ex) {
        [$sets, $reps, $rest, $tempo, $rpe, $notes] = _assign_exercise_details($goal, $expLevel, $ex);
        $insertStmt->execute([
            $planId,
            (int) $ex['exercise_id'],
            $dayOfWeek,
            $order++,
            $sets,
            $reps,
            null,
            $rest,
            $notes,
            $tempo,
            $rpe
        ]);
    }

    return true;
}

