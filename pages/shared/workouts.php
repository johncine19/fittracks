<?php
declare(strict_types=1);

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

    // Fetch all available exercises
    $exercises = $pdo->query('SELECT * FROM exercises')->fetchAll();
    if (!$exercises) {
        return $planId;
    }

    // Weight exercise selection toward the member's goal
    if ($goal === 'muscle_gain') {
        usort($exercises, fn($a, $b) => ($a['category'] === 'strength' ? 0 : 1) <=> ($b['category'] === 'strength' ? 0 : 1));
    } elseif ($goal === 'fat_loss') {
        usort($exercises, fn($a, $b) => ($a['category'] === 'cardio' ? 0 : 1) <=> ($b['category'] === 'cardio' ? 0 : 1));
    } else {
        shuffle($exercises);
    }

    // Determine how many days per week to assign based on activity level
    $days = match($profile['activity_level']) {
        'sedentary' => [1, 3], // Monday, Wednesday
        'lightly_active' => [1, 3, 5], // Mon, Wed, Fri
        'moderately_active' => [1, 2, 4, 5], // Mon, Tue, Thu, Fri
        'very_active', 'extra_active' => [1, 2, 3, 5, 6], // 5 days
        default => [1, 3, 5]
    };

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
            // Determine sets/reps based on goal
            $sets = 3;
            $reps = '10-12';
            $rest = 60;

            if ($goal === 'muscle_gain') {
                $sets = 4;
                $reps = '8-10';
                $rest = 90;
            } elseif ($goal === 'fat_loss' || $ex['category'] === 'cardio') {
                $sets = 3;
                $reps = '15-20';
                $rest = 45;
                if ($ex['category'] === 'cardio') {
                    $reps = '15 mins';
                }
            }

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

    $exercises = $pdo->query('SELECT * FROM exercises')->fetchAll();
    if (!$exercises) return;

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

    foreach ($days as $dayOfWeek) {
        $dayExercises = $exercises;
        if ($goal !== 'muscle_gain' && $goal !== 'fat_loss') {
            shuffle($dayExercises);
        }

        $numExercises = min(count($dayExercises), rand(3, 5));
        $assigned = array_slice($dayExercises, 0, $numExercises);
        
        $order = 1;
        foreach ($assigned as $ex) {
            $sets = 3;
            $reps = '10-12';
            $rest = 60;

            if ($goal === 'muscle_gain') {
                $sets = 4;
                $reps = '8-10';
                $rest = 90;
            } elseif ($goal === 'fat_loss' || $ex['category'] === 'cardio') {
                $sets = 3;
                $reps = '15-20';
                $rest = 45;
                if ($ex['category'] === 'cardio') {
                    $reps = '15 mins';
                }
            }

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

    $exercises = $pdo->query('SELECT * FROM exercises')->fetchAll();
    if (!$exercises) return;

    if ($goal === 'muscle_gain') {
        usort($exercises, fn($a, $b) => ($a['category'] === 'strength' ? 0 : 1) <=> ($b['category'] === 'strength' ? 0 : 1));
    } elseif ($goal === 'fat_loss') {
        usort($exercises, fn($a, $b) => ($a['category'] === 'cardio' ? 0 : 1) <=> ($b['category'] === 'cardio' ? 0 : 1));
    } else {
        shuffle($exercises);
    }

    $dayExercises = $exercises;
    if ($goal !== 'muscle_gain' && $goal !== 'fat_loss') {
        shuffle($dayExercises);
    }

    $numExercises = min(count($dayExercises), rand(3, 5));
    $assigned = array_slice($dayExercises, 0, $numExercises);
    
    $order = 1;
    foreach ($assigned as $ex) {
        $sets = 3;
        $reps = '10-12';
        $rest = 60;

        if ($goal === 'muscle_gain') {
            $sets = 4;
            $reps = '8-10';
            $rest = 90;
        } elseif ($goal === 'fat_loss' || $ex['category'] === 'cardio') {
            $sets = 3;
            $reps = '15-20';
            $rest = 45;
            if ($ex['category'] === 'cardio') {
                $reps = '15 mins';
            }
        }

        $stmt = $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, rest_seconds) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$planId, (int) $ex['exercise_id'], $dayOfWeek, $order++, $sets, $reps, $rest]);
    }
}
