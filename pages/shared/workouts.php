<?php
declare(strict_types=1);

/**
 * Returns parameters for workouts based on member profile.
 */
function _get_workout_parameters(array $profile, PDO $pdo): array
{
    $goal = map_detailed_goal_to_basic($profile['primary_goal']);
    $tier = (int) ($profile['fitness_tier'] ?? 1);
    
    // Map fitness_tier to experience_level (1=Starter, 2=Intermediate, 3=Advanced)
    $expLevel = 1;
    if ($tier >= 3 && $tier <= 4) $expLevel = 2;
    if ($tier >= 5) $expLevel = 3;

    // Filter exercises by difficulty_level <= experience_level
    $stmt = $pdo->prepare('SELECT * FROM exercises WHERE difficulty_level <= ?');
    $stmt->execute([$expLevel]);
    $exercises = $stmt->fetchAll();
    
    if (!$exercises) {
        $exercises = $pdo->query('SELECT * FROM exercises')->fetchAll();
    }

    if ($goal === 'muscle_gain') {
        usort($exercises, fn($a, $b) => ($a['category'] === 'strength' ? 0 : 1) <=> ($b['category'] === 'strength' ? 0 : 1));
    } elseif ($goal === 'fat_loss') {
        usort($exercises, fn($a, $b) => ($a['category'] === 'cardio' ? 0 : 1) <=> ($b['category'] === 'cardio' ? 0 : 1));
    } else {
        shuffle($exercises);
    }

    $days = match($profile['activity_level']) {
        'sedentary' => [1, 3],
        'lightly_active' => [1, 3, 5],
        'moderately_active' => [1, 2, 4, 5],
        'very_active', 'extra_active' => [1, 2, 3, 5, 6],
        default => [1, 3, 5]
    };

    return [$exercises, $days, $expLevel, $goal];
}

function _assign_sets_reps(string $goal, int $expLevel, array $ex): array
{
    if ($ex['category'] === 'cardio') {
        return [3, '15 mins', 45];
    }

    if ($goal === 'muscle_gain') {
        if ($expLevel === 1) return [3, '12-15', 60]; // Starter
        if ($expLevel === 2) return [4, '8-10', 90];  // Intermediate
        return [5, '5-8', 120];                       // Advanced
    } elseif ($goal === 'fat_loss') {
        if ($expLevel === 1) return [3, '15-20', 45]; // Starter
        if ($expLevel === 2) return [4, '12-15', 60]; // Intermediate
        return [4, '10-12', 60];                      // Advanced
    } else {
        // General health / Maintenance
        if ($expLevel === 1) return [3, '10-12', 60];
        if ($expLevel === 2) return [3, '8-12', 60];
        return [4, '8-10', 90];
    }
}

/**
 * Returns true if the member does NOT have a system-generated workout plan 
 * created within the last 7 days.
 */
function can_recalculate_workout(int $memberUserId): bool
{
    $pdo = db();
    
    // 1. If the member currently has an active plan created by a trainer, they cannot recalculate.
    $trainerPlanExists = scalar('SELECT 1 FROM training_plans WHERE member_user_id = ? AND trainer_id IS NOT NULL AND status = "active" LIMIT 1', [$memberUserId]);
    if ($trainerPlanExists) {
        return false;
    }

    // 2. Check if a system-generated plan was created within the last 7 days.
    // Restriction removed per user request: allow generation anytime.
    return true;
}
function generate_workout_plan(int $memberUserId, ?int $coachId = null): int
{
    $pdo = db();
    
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

    // Delete any existing system_generated plans to replace them
    $pdo->prepare('DELETE FROM training_plans WHERE member_user_id = ? AND trainer_id IS NULL')->execute([$memberUserId]);

    // Get member name for title
    $stmt = $pdo->prepare('SELECT first_name FROM users WHERE user_id = ?');
    $stmt->execute([$memberUserId]);
    $firstName = $stmt->fetchColumn() ?: 'Member';

    // Create a new training plan
    $title = 'Workout Plan for ' . $firstName;
    $stmt = $pdo->prepare('INSERT INTO training_plans (member_user_id, trainer_id, title, goal, start_date, end_date, status) VALUES (?, ?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 4 WEEK), "active")');
    $stmt->execute([$memberUserId, $coachId, $title, $goal]);
    $planId = (int) $pdo->lastInsertId();

    // Generate workout using the helper
    [$exercises, $days, $expLevel, $goal] = _get_workout_parameters($profile, $pdo);

    if (!$exercises) {
        return $planId;
    }

    // Assign exercises per training day
    foreach ($days as $dayOfWeek) {
        $dayExercises = $exercises;
        if ($goal !== 'muscle_gain' && $goal !== 'fat_loss') {
            shuffle($dayExercises);
        }

        $numExercises = min(count($dayExercises), rand(3, 5));
        $assigned = array_slice($dayExercises, 0, $numExercises);
        
        $order = 1;
        foreach ($assigned as $ex) {
            [$sets, $reps, $rest] = _assign_sets_reps($goal, $expLevel, $ex);

            $stmt = $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, rest_seconds) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$planId, (int) $ex['exercise_id'], $dayOfWeek, $order++, $sets, $reps, $rest]);
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
        if ($goal !== 'muscle_gain' && $goal !== 'fat_loss') {
            shuffle($dayExercises);
        }

        $numExercises = min(count($dayExercises), rand(3, 5));
        $assigned = array_slice($dayExercises, 0, $numExercises);
        
        $order = 1;
        foreach ($assigned as $ex) {
            [$sets, $reps, $rest] = _assign_sets_reps($goal, $expLevel, $ex);

            $stmt = $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, rest_seconds) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$planId, (int) $ex['exercise_id'], $dayOfWeek, $order++, $sets, $reps, $rest]);
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
    if ($goal !== 'muscle_gain' && $goal !== 'fat_loss') {
        shuffle($dayExercises);
    }

    $numExercises = min(count($dayExercises), rand(3, 5));
    $assigned = array_slice($dayExercises, 0, $numExercises);
    
    $order = 1;
    foreach ($assigned as $ex) {
        [$sets, $reps, $rest] = _assign_sets_reps($goal, $expLevel, $ex);

        $stmt = $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, rest_seconds) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$planId, (int) $ex['exercise_id'], $dayOfWeek, $order++, $sets, $reps, $rest]);
    }
}
