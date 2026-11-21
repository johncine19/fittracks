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

/**
 * Scores an exercise for a member based on secondary focus, goal affinity, and experience level.
 */
function _score_exercise_for_member(
    array $ex,
    string $goal,
    int $expLevel,
    array $targetMuscles,
    array $targetCategories,
    array $targetNames,
    int $age = 25
): int {
    $score = 0;
    $mg = strtolower($ex['muscle_group'] ?? '');
    $cat = strtolower($ex['category'] ?? '');
    $name = strtolower($ex['name'] ?? '');

    if (in_array($mg, $targetMuscles, true)) $score += 10;
    if (in_array($cat, $targetCategories, true)) $score += 6;

    foreach ($targetNames as $tn) {
        if (str_contains($name, $tn)) $score += 8;
    }

    if ($goal === 'muscle_gain' && $cat === 'strength') $score += 2;
    if (($goal === 'fat_loss' || $goal === 'weight_loss') && $cat === 'cardio') $score += 2;

    // Soft difficulty bonus: keep foundational Beginner lifts in the pool,
    // while prioritizing Intermediate/Advanced variations for experienced members.
    $diff = max(1, (int)($ex['difficulty_level'] ?? 1));
    if ($expLevel >= 3) {
        $score += ($diff >= 3) ? 6 : (($diff === 2) ? 3 : 0);
    } elseif ($expLevel === 2) {
        $score += ($diff >= 2) ? 4 : 1;
    }

    // Joint-friendly preference for older adults who are not Advanced
    if (($age >= 60 && $expLevel <= 2) || ($age >= 55 && $expLevel === 1)) {
        if (in_array($cat, ['strongman', 'plyometrics', 'olympic weightlifting'], true)) {
            $score -= 8;
        }
    }

    return $score;
}

/**
 * Picks up to $count items from a pre-sorted pool while preserving top-score bias and day-to-day variety.
 */
function _pick_varied_top_exercises(array $sortedPool, int $count): array
{
    if ($count <= 0 || empty($sortedPool)) {
        return [];
    }
    $windowSize = min(count($sortedPool), max($count * 3, 5));
    $topWindow = array_slice($sortedPool, 0, $windowSize);
    shuffle($topWindow);
    return array_slice($topWindow, 0, $count);
}

/**
 * Selects the daily exercise flow for a single training day up to $targetCount.
 */
function _select_daily_exercises(array $dayExercises, array $profile, string $goal, int $targetCount): array
{
    $secFocus = trim((string)($profile['secondary_focus'] ?? ''));
    if ($secFocus !== '') {
        // Prioritize exercises matching secondary_focus first (balanced across muscle groups), then fill remaining slots
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

        // Round-robin across focus muscle groups so multi-focus plans (e.g. Legs & Chest) get
        // balanced coverage and up to 2-3 focus exercises before filling with general movements.
        $maxPerGroup = count($secByGroup) > 1 ? 2 : 3;
        $groupPools = [];
        foreach ($secByGroup as $grp => $grpExs) {
            $groupPools[$grp] = _pick_varied_top_exercises($grpExs, $maxPerGroup);
        }

        for ($round = 0; $round < $maxPerGroup && count($assigned) < $targetCount; $round++) {
            foreach ($groupPools as $pool) {
                if (count($assigned) >= $targetCount) break;
                if (isset($pool[$round])) {
                    $assigned[] = $pool[$round];
                }
            }
        }

        $remaining = $targetCount - count($assigned);
        if ($remaining > 0 && !empty($generalExercises)) {
            $generalPool = _pick_varied_top_exercises($generalExercises, $remaining + 2);
            foreach ($generalPool as $gx) {
                if (count($assigned) >= $targetCount) break;
                if (!in_array($gx['exercise_id'], array_column($assigned, 'exercise_id'))) {
                    $assigned[] = $gx;
                }
            }
        }

        if (!empty($assigned)) {
            return $assigned;
        }
    }

    // Default flow: take varied picks from the top-scored $targetCount exercises
    return _pick_varied_top_exercises($dayExercises, min(count($dayExercises), $targetCount));
}

/**
 * Resolves database-driven exercise pool, training days, experience level, and mapped goal.
 */
function _get_workout_parameters(array $profile, PDO $pdo): array
{
    // 1. Map detailed primary_goal to core category & resolve experience_level (1=Starter, 2=Intermediate, 3=Advanced)
    $goal = map_detailed_goal_to_basic($profile['primary_goal']);
    $expLevel = resolve_member_experience_level($profile);

    // Fetch member's gym_id
    $memberGymId = (int) $pdo->query('SELECT gym_id FROM gym_members WHERE user_id = ' . (int)($profile['user_id'] ?? 0))->fetchColumn();

    // 2. Filter exercises by difficulty_level <= experience_level AND gym_id (including universal catalog gym_id = 0)
    $stmt = $pdo->prepare('SELECT * FROM exercises WHERE difficulty_level <= ? AND (gym_id = ? OR gym_id = 0 OR gym_id IS NULL)');
    $stmt->execute([$expLevel, $memberGymId]);
    $exercises = $stmt->fetchAll();
    
    if (!$exercises) {
        $stmt = $pdo->prepare('SELECT * FROM exercises WHERE (gym_id = ? OR gym_id = 0 OR gym_id IS NULL)');
        $stmt->execute([$memberGymId]);
        $exercises = $stmt->fetchAll();
    }

    // 3. Score & sort exercises by secondary_focus (muscle_group +10, name +8, category +6),
    // goal affinity (+2), experience-level difficulty bonus (+0..+6), and age-aware recovery.
    $age = max(16, (int)($profile['age'] ?? 25));
    [$targetMuscles, $targetCategories, $targetNames] = _get_secondary_focus_targets($profile['secondary_focus'] ?? null);

    if (!empty($targetMuscles) || !empty($targetCategories) || !empty($targetNames) || $expLevel >= 2 || $age >= 55 || $goal === 'muscle_gain' || $goal === 'fat_loss' || $goal === 'weight_loss') {
        usort($exercises, function($a, $b) use ($goal, $expLevel, $targetMuscles, $targetCategories, $targetNames, $age) {
            $scoreA = _score_exercise_for_member($a, $goal, $expLevel, $targetMuscles, $targetCategories, $targetNames, $age);
            $scoreB = _score_exercise_for_member($b, $goal, $expLevel, $targetMuscles, $targetCategories, $targetNames, $age);
            return $scoreB <=> $scoreA;
        });
    } else {
        shuffle($exercises);
    }

    // 4. Resolve weekly schedule priority: preferred_workout_days -> weekly_workout_target -> activity_level
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

/**
 * Rule-based prescription matrix for sets, reps, rest_seconds, tempo, RPE, and coaching notes.
 *
 * Prescribes training volume and intensity based on:
 *   - Exercise category ('cardio' -> fixed 3x15 mins, Steady Pace, RPE 7)
 *   - Mapped primary goal ('strength', 'muscle_gain'/'hypertrophy', 'fat_loss'/'weight_loss', or general health)
 *   - Experience level (1 = Starter, 2 = Intermediate, 3 = Advanced)
 * Uses the exercise's DB description as notes when present, falling back to a goal/level form cue.
 */
function _assign_exercise_details(string $goal, int $expLevel, array $ex, int $age = 25): array
{
    $cat = $ex['category'] ?? 'strength';
    $customDesc = !empty($ex['description']) ? trim((string)$ex['description']) : null;
    
    if ($cat === 'cardio') {
        $sets = 3;
        $reps = '15 mins';
        $rest = 45;
        $tempo = 'Steady Pace';
        $rpe = 'RPE 7';
        $cue = 'Keep cadence steady in target aerobic zone.';
    } elseif ($goal === 'strength' || $goal === 'strength_power') {
        if ($expLevel === 1) {
            $sets = 3; $reps = '6-8';
            $rest = 120;
            $tempo = '2-1-1-0';
            $rpe = 'RPE 7-8';
            $cue = 'Focus on bar path, brace core tightly.';
        } elseif ($expLevel === 2) {
            $sets = 4; 
            $reps = '5-6'; 
            $rest = 150; 
            $tempo = '2-1-1-0'; 
            $rpe = 'RPE 8'; 
            $cue = 'Control the eccentric, explode upward with full intent.';
        } else {
            $sets = 5; 
            $reps = '3-5'; 
            $rest = 180; 
            $tempo = '2-1-1-0'; 
            $rpe = 'RPE 8-9'; 
            $cue = 'Competition form. Rest fully between work sets.';
        }
    } elseif ($goal === 'muscle_gain' || $goal === 'hypertrophy') {
        if ($expLevel === 1) {
            $sets = 3; 
            $reps = '10-12'; 
            $rest = 60; 
            $tempo = '3-0-1-0'; 
            $rpe = 'RPE 7'; 
            $cue = 'Control the negative (3s down), feel target muscle stretch.';
        } elseif ($expLevel === 2) {
            $sets = 4; 
            $reps = '8-10'; 
            $rest = 90; 
            $tempo = '3-0-1-0'; 
            $rpe = 'RPE 8'; 
            $cue = 'Squeeze peak contraction for 1 second on each rep.';
        } else {
            $sets = 4; 
            $reps = '6-10'; 
            $rest = 90; 
            $tempo = '3-0-1-1'; 
            $rpe = 'RPE 8-9'; 
            $cue = 'Train close to failure with strict form.';
        }
    } elseif ($goal === 'fat_loss' || $goal === 'weight_loss') {
        if ($expLevel === 1) {
            $sets = 3; 
            $reps = '12-15'; 
            $rest = 45; 
            $tempo = '2-0-2-0'; 
            $rpe = 'RPE 6-7'; 
            $cue = 'Smooth continuous cadence, minimize rest.';
        } elseif ($expLevel === 2) {
            $sets = 4; 
            $reps = '12-15'; 
            $rest = 60; 
            $tempo = '2-0-2-0'; 
            $rpe = 'RPE 7-8'; 
            $cue = 'Maintain high output density and rhythmic breathing.';
        } else {
            $sets = 4; 
            $reps = '10-12'; 
            $rest = 60; 
            $tempo = '2-0-1-0'; 
            $rpe = 'RPE 8'; 
            $cue = 'High metabolic demand, short rest between sets.';
        }
    } elseif ($goal === 'endurance') {
        if ($expLevel === 1) {
            $sets = 2;
            $reps = '15-20';
            $rest = 30;
            $tempo = '2-0-2-0';
            $rpe = 'RPE 6-7';
            $cue = 'Light-moderate load, focus on steady breathing and muscular stamina.';
        } elseif ($expLevel === 2) {
            $sets = 3;
            $reps = '15-20';
            $rest = 30;
            $tempo = '2-0-1-0';
            $rpe = 'RPE 7-8';
            $cue = 'Keep transitions crisp with short 30s rests to build work capacity.';
        } else {
            $sets = 4;
            $reps = '15-25';
            $rest = 25;
            $tempo = '2-0-1-0';
            $rpe = 'RPE 8';
            $cue = 'High-volume endurance pacing with minimal rest between sets.';
        }
    } else {
        // General health / Maintenance
        if ($expLevel === 1) {
            $sets = 3; 
            $reps = '10-12'; 
            $rest = 60; 
            $tempo = '2-0-2-0'; 
            $rpe = 'RPE 6'; 
            $cue = 'Focus on posture alignment and smooth technique.';
        } elseif ($expLevel === 2) {
            $sets = 3; 
            $reps = '8-12'; 
            $rest = 60; 
            $tempo = '2-0-1-0'; 
            $rpe = 'RPE 7'; 
            $cue = 'Solid mechanical tension and full range of motion.';
        } else {
            $sets = 4; 
            $reps = '8-10'; 
            $rest = 90; 
            $tempo = '2-0-1-0'; 
            $rpe = 'RPE 7-8'; 
            $cue = 'Maintain balanced strength across all planes.';
        }
    }

    // Age-aware recovery & intensity guard (keeps experience_level as primary driver)
    if ($cat !== 'cardio' && $age > 0) {
        if ($age < 18) {
            if (in_array($reps, ['3-5', '5-6'], true)) {
                $reps = '8-10';
            }
            if (in_array($rpe, ['RPE 8', 'RPE 8-9'], true)) {
                $rpe = 'RPE 7';
            }
        } elseif ($age >= 55) {
            $rest += ($age >= 65) ? 30 : 15;
            if ($expLevel <= 2 && in_array($rpe, ['RPE 8', 'RPE 8-9'], true)) {
                $rpe = ($age >= 65 && $expLevel === 1) ? 'RPE 7' : 'RPE 7-8';
            }
        }
    }

    $notes = ($customDesc !== null && $customDesc !== '') ? $customDesc : $cue;
    return [$sets, $reps, $rest, $tempo, $rpe, $notes];
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

/**
 * Generates a 4-week workout plan for self-guided members (or when triggered by a coach).
 */
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
    $profile = member_profile($memberUserId);
    if (!$profile) {
        throw new RuntimeException('Cannot generate workout plan: member profile missing.');
    }
    
    if ((float)$profile['height_cm'] == 0 || (float)$profile['weight_kg'] == 0) {
        // Skip generating if they haven't provided measurements
        return 0;
    }

    $goal = $profile['primary_goal'];

    // Archive existing active system-generated plans for THIS gym only (preserves version history and logs)
    if ($scopedGymId !== null) {
        $pdo->prepare('UPDATE training_plans SET status = "archived" WHERE member_user_id = ? AND trainer_id IS NULL AND gym_id = ? AND status = "active"')->execute([$memberUserId, $scopedGymId]);
    } else {
        $pdo->prepare('UPDATE training_plans SET status = "archived" WHERE member_user_id = ? AND trainer_id IS NULL AND (gym_id IS NULL OR gym_id = 0) AND status = "active"')->execute([$memberUserId]);
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

        // Number of exercises per day ($targetCount) is controlled by preferred_duration_mins:
        //   <= 35 mins       -> 3-4 exercises
        //   45-60 mins (def) -> 4-5 exercises
        //   >= 65 mins       -> 5-6 exercises
        // (Experience level $expLevel instead scales exercise difficulty_level and sets/reps/rest/tempo/RPE.)
        $prefDuration = (int) ($profile['preferred_duration_mins'] ?? 45);
        $targetCount = match(true) {
            $prefDuration <= 35 => rand(3, 4),
            $prefDuration >= 65 => rand(5, 6),
            default => rand(4, 5)
        };
        $assigned = _select_daily_exercises($dayExercises, $profile, $goal, $targetCount);
        
        $order = 1;
        foreach ($assigned as $ex) {
            [$sets, $reps, $rest, $tempo, $rpe, $notes] = _assign_exercise_details($goal, $expLevel, $ex, (int)($profile['age'] ?? 25));

            $stmt = $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, rest_seconds, tempo, rpe, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$planId, (int) $ex['exercise_id'], $dayOfWeek, $order++, $sets, $reps, $rest, $tempo, $rpe, $notes]);
        }
    }

    return $planId;
}

function auto_populate_plan(int $planId, int $memberUserId): void
{
    $pdo = db();
    
    $profile = member_profile($memberUserId);
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

        // Number of exercises per day ($targetCount) is controlled by preferred_duration_mins:
        //   <= 35 mins       -> 3-4 exercises
        //   45-60 mins (def) -> 4-5 exercises
        //   >= 65 mins       -> 5-6 exercises
        // (Experience level $expLevel instead scales exercise difficulty_level and sets/reps/rest/tempo/RPE.)
        $prefDuration = (int) ($profile['preferred_duration_mins'] ?? 45);
        $targetCount = match(true) {
            $prefDuration <= 35 => rand(3, 4),
            $prefDuration >= 65 => rand(5, 6),
            default => rand(4, 5)
        };
        $assigned = _select_daily_exercises($dayExercises, $profile, $goal, $targetCount);
        
        $order = 1;
        foreach ($assigned as $ex) {
            [$sets, $reps, $rest, $tempo, $rpe, $notes] = _assign_exercise_details($goal, $expLevel, $ex, (int)($profile['age'] ?? 25));

            $stmt = $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, rest_seconds, tempo, rpe, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$planId, (int) $ex['exercise_id'], $dayOfWeek, $order++, $sets, $reps, $rest, $tempo, $rpe, $notes]);
        }
    }
}

function auto_populate_day(int $planId, int $memberUserId, int $dayOfWeek): void
{
    $pdo = db();
    
    $profile = member_profile($memberUserId);
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

function normalize_muscle_group_key(string $mg): string
{
    $mg = strtolower(trim($mg));
    return match($mg) {
        'chest' => 'chest',
        'back', 'middle back', 'lower back', 'lats' => 'back',
        'shoulders', 'shoulder' => 'shoulders',
        'arms', 'biceps', 'triceps', 'forearms' => 'arms',
        'legs', 'quadriceps', 'quads', 'hamstrings', 'calves', 'glutes' => 'legs',
        'abdominals', 'abs', 'obliques', 'core' => 'abdominals',
        'posterior chain', 'posterior_chain' => 'posterior chain',
        default => $mg ?: 'general'
    };
}

/**
 * Configurable, intelligent auto-generation routine
 */
function auto_populate_plan_advanced(int $planId, int $memberUserId, array $options): bool
{
    $pdo = db();
    
    $profile = member_profile($memberUserId) ?: [];

    // Extract options with fallbacks to member profile
    $goal = $options['goal'] ?? map_detailed_goal_to_basic($profile['primary_goal'] ?? 'general_health');
    $rawExp = $options['experience_level'] ?? 2;
    if (is_numeric($rawExp)) {
        $expLevel = max(1, min(3, (int)$rawExp));
    } else {
        $expLevel = match(strtolower((string)$rawExp)) {
            'beginner' => 1,
            'advanced', 'expert' => 3,
            default => 2, // intermediate
        };
    }
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

    // Fetch exercises available for this gym (including global library exercises)
    if ($memberGymId > 0) {
        $stmt = $pdo->prepare('
            SELECT * FROM exercises 
            WHERE (gym_id = ? OR gym_id = 0 OR gym_id IS NULL) 
            ORDER BY (gym_id = ?) DESC, muscle_group, name
        ');
        $stmt->execute([$memberGymId, $memberGymId]);
    } else {
        $stmt = $pdo->query('
            SELECT * FROM exercises 
            WHERE (gym_id = 0 OR gym_id IS NULL) 
            ORDER BY muscle_group, name
        ');
    }
    $allExercises = $stmt->fetchAll();

    if (!$allExercises) return false;

    // Deduplicate by name so gym-specific overrides win
    $dedupedExercises = [];
    $seenNames = [];
    foreach ($allExercises as $ex) {
        $nameKey = strtolower(trim((string)$ex['name']));
        if (!isset($seenNames[$nameKey])) {
            $seenNames[$nameKey] = true;
            $dedupedExercises[] = $ex;
        }
    }
    $allExercises = $dedupedExercises;

    // Only replace the draft after confirming the selected library has exercises.
    $pdo->prepare('DELETE FROM training_plan_exercises WHERE plan_id = ?')->execute([$planId]);

    $insertStmt = $pdo->prepare('
        INSERT INTO training_plan_exercises 
        (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, target_weight_kg, rest_seconds, notes, tempo, rpe)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    // Group exercises by normalized muscle/type
    $exercisesByGroup = [];
    foreach ($allExercises as $e) {
        $rawMg = (string)($e['muscle_group'] ?? '');
        $normKey = normalize_muscle_group_key($rawMg);
        $exercisesByGroup[$normKey][] = $e;
        if ($rawMg !== '' && strtolower($rawMg) !== $normKey) {
            $exercisesByGroup[strtolower($rawMg)][] = $e;
        }
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
            $normMg = normalize_muscle_group_key($mg);
            if (!empty($exercisesByGroup[$normMg])) {
                $candidates = $exercisesByGroup[$normMg];
                // Exclude exercises already scheduled in today's session
                $existingIds = array_column($dayExercises, 'exercise_id');
                $candidates = array_values(array_filter($candidates, fn($c) => !in_array($c['exercise_id'], $existingIds, true)));
                if (empty($candidates)) {
                    $candidates = $exercisesByGroup[$normMg];
                }

                $filtered = array_values(array_filter($candidates, fn($c) => (int)($c['difficulty_level'] ?? 1) <= $expLevel));
                if (empty($filtered)) {
                    $filtered = $candidates;
                }

                // If member is intermediate (2) or advanced (3), produce a balanced mix of target tier and foundational exercises
                if ($expLevel >= 2) {
                    $targetTier = array_values(array_filter($filtered, fn($c) => (int)($c['difficulty_level'] ?? 1) === $expLevel));
                    $lowerTier = array_values(array_filter($filtered, fn($c) => (int)($c['difficulty_level'] ?? 1) < $expLevel));

                    if (!empty($targetTier) && !empty($lowerTier)) {
                        $targetCount = count(array_filter($dayExercises, fn($d) => (int)($d['difficulty_level'] ?? 1) === $expLevel));
                        $lowerCount = count(array_filter($dayExercises, fn($d) => (int)($d['difficulty_level'] ?? 1) < $expLevel));

                        if ($targetCount <= $lowerCount) {
                            $picked = $targetTier[array_rand($targetTier)];
                        } else {
                            $picked = $lowerTier[array_rand($lowerTier)];
                        }
                    } elseif (!empty($targetTier)) {
                        $picked = $targetTier[array_rand($targetTier)];
                    } else {
                        $picked = $filtered[array_rand($filtered)];
                    }
                } else {
                    $picked = $filtered[array_rand($filtered)];
                }

                if (!in_array($picked['exercise_id'], array_column($dayExercises, 'exercise_id'), true)) {
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
            $cat = strtolower(trim((string)($ex['category'] ?? '')));
            if ($cat !== 'cardio' && !empty($profile['weight_kg']) && in_array(strtolower($ex['muscle_group'] ?? ''), ['legs', 'chest', 'back'])) {
                $ratio = match(strtolower($ex['muscle_group'])) {
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
    
    $profile = member_profile($memberUserId) ?: [];
    $goal = map_detailed_goal_to_basic($profile['primary_goal'] ?? 'general_health');
    $expLevel = resolve_member_experience_level($profile);

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

    if ($memberGymId > 0) {
        $stmt = $pdo->prepare('
            SELECT * FROM exercises 
            WHERE (gym_id = ? OR gym_id = 0 OR gym_id IS NULL) 
            ORDER BY (gym_id = ?) DESC, muscle_group, name
        ');
        $stmt->execute([$memberGymId, $memberGymId]);
    } else {
        $stmt = $pdo->query('
            SELECT * FROM exercises 
            WHERE (gym_id = 0 OR gym_id IS NULL) 
            ORDER BY muscle_group, name
        ');
    }
    $rawDayExercises = $stmt->fetchAll();

    if (!$rawDayExercises) return false;

    $dedupedDayExercises = [];
    $seenDayNames = [];
    foreach ($rawDayExercises as $ex) {
        $nameKey = strtolower(trim((string)$ex['name']));
        if (!isset($seenDayNames[$nameKey])) {
            $seenDayNames[$nameKey] = true;
            $dedupedDayExercises[] = $ex;
        }
    }
    $exercises = $dedupedDayExercises;

    // Keep the existing day intact when the selected library has no matches.
    $pdo->prepare('DELETE FROM training_plan_exercises WHERE plan_id = ? AND day_of_week = ?')->execute([$planId, $dayOfWeek]);

    $byGroup = [];
    foreach ($exercises as $e) {
        $rawMg = (string)($e['muscle_group'] ?? '');
        $normKey = normalize_muscle_group_key($rawMg);
        $byGroup[$normKey][] = $e;
        if ($rawMg !== '' && strtolower($rawMg) !== $normKey) {
            $byGroup[strtolower($rawMg)][] = $e;
        }
    }

    $selected = [];
    foreach ($targetMuscles as $m) {
        $normM = normalize_muscle_group_key($m);
        if (!empty($byGroup[$normM])) {
            $pool = $byGroup[$normM];
            $existingIds = array_column($selected, 'exercise_id');
            $pool = array_values(array_filter($pool, fn($c) => !in_array($c['exercise_id'], $existingIds, true)));
            if (empty($pool)) {
                $pool = $byGroup[$normM];
            }

            $filtered = array_values(array_filter($pool, fn($c) => (int)($c['difficulty_level'] ?? 1) <= $expLevel));
            if (empty($filtered)) {
                $filtered = $pool;
            }

            if ($expLevel >= 2) {
                $targetTier = array_values(array_filter($filtered, fn($c) => (int)($c['difficulty_level'] ?? 1) === $expLevel));
                $lowerTier = array_values(array_filter($filtered, fn($c) => (int)($c['difficulty_level'] ?? 1) < $expLevel));

                if (!empty($targetTier) && !empty($lowerTier)) {
                    $targetCount = count(array_filter($selected, fn($d) => (int)($d['difficulty_level'] ?? 1) === $expLevel));
                    $lowerCount = count(array_filter($selected, fn($d) => (int)($d['difficulty_level'] ?? 1) < $expLevel));

                    if ($targetCount <= $lowerCount) {
                        $cand = $targetTier[array_rand($targetTier)];
                    } else {
                        $cand = $lowerTier[array_rand($lowerTier)];
                    }
                } elseif (!empty($targetTier)) {
                    $cand = $targetTier[array_rand($targetTier)];
                } else {
                    $cand = $filtered[array_rand($filtered)];
                }
            } else {
                $cand = $filtered[array_rand($filtered)];
            }

            if (!in_array($cand['exercise_id'], array_column($selected, 'exercise_id'), true)) {
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

