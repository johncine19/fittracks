<?php
declare(strict_types=1);

// -----------------------------------------------------------------------
// Member physical profile helpers.
// Workout generation lives in pages/shared/workouts.php.
// -----------------------------------------------------------------------

function save_member_profile(int $userId): void
{
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO member_profiles (user_id, height_cm, weight_kg, neck_cm, waist_cm, hip_cm, age, biological_sex, activity_level, primary_goal)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE height_cm = VALUES(height_cm), weight_kg = VALUES(weight_kg), neck_cm = VALUES(neck_cm), waist_cm = VALUES(waist_cm), hip_cm = VALUES(hip_cm), age = VALUES(age), biological_sex = VALUES(biological_sex), activity_level = VALUES(activity_level), primary_goal = VALUES(primary_goal)');
    $stmt->execute([
        $userId,
        post('height_cm') ?: 0.0,
        post('weight_kg') ?: 0.0,
        post('neck_cm') ?: null,
        post('waist_cm') ?: null,
        post('biological_sex') === 'female' ? (post('hip_cm') ?: null) : null,
        (int) (post('age') ?: 0),
        post('biological_sex'),
        post('activity_level'),
        post('primary_goal'),
    ]);

    // Update fitness_tier based on selected experience_level
    if (isset($_POST['experience_level'])) {
        $exp = (int) $_POST['experience_level'];
        $tier = 1;
        if ($exp === 2) $tier = 3;
        if ($exp === 3) $tier = 5;
        $pdo->prepare('UPDATE member_profiles SET fitness_tier = ? WHERE user_id = ?')
            ->execute([$tier, $userId]);
    }
}

function member_profile(int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM member_profiles WHERE user_id = ?');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
    return $profile ?: null;
}

/**
 * Returns personalised exercise recommendations based on the member's profile.
 *
 * The algorithm considers:
 *  - primary_goal   → prioritises exercise categories (strength, cardio, core)
 *  - activity_level → determines how many exercises to suggest
 *  - biological_sex / weight / age → adjusts intensity descriptors
 *
 * Each recommendation includes the exercise row plus a 'recommendation' text
 * explaining *why* it was chosen and suggested sets/reps.
 *
 * @return array<int, array{exercise: array, recommendation: string, sets: int, reps: string, rest_seconds: int, priority: string}>
 */
function get_exercise_recommendations(int $userId): array
{
    $profile = member_profile($userId);
    if (!$profile) {
        return [];
    }

    $goal          = $profile['primary_goal'];
    $activityLevel = $profile['activity_level'];
    $sex           = $profile['biological_sex'];
    $weight        = (float) $profile['weight_kg'];

    // Use age directly from profile
    $age = (int) $profile['age'];

    // Fetch all exercises
    $exercises = db()->query('SELECT * FROM exercises ORDER BY category, name')->fetchAll();
    if (!$exercises) {
        return [];
    }

    // Categorise exercises
    $byCategory = [];
    foreach ($exercises as $ex) {
        $byCategory[$ex['category']][] = $ex;
    }

    // Determine category mix based on goal
    $categoryWeights = match ($goal) {
        'muscle_gain'    => ['strength' => 6, 'core' => 2, 'cardio' => 1],
        'fat_loss'       => ['cardio' => 5, 'strength' => 2, 'core' => 2],
        'maintenance'    => ['strength' => 3, 'cardio' => 3, 'core' => 2],
        'general_health' => ['cardio' => 3, 'strength' => 3, 'core' => 3],
        default          => ['strength' => 3, 'cardio' => 3, 'core' => 2],
    };

    // Determine total recommendation count based on activity level
    $totalCount = match ($activityLevel) {
        'sedentary'         => 4,
        'lightly_active'    => 5,
        'moderately_active' => 6,
        'very_active'       => 7,
        'extra_active'      => 8,
        default             => 5,
    };

    // Build recommendation slots proportionally
    $totalWeight = array_sum($categoryWeights);
    $slots = [];
    foreach ($categoryWeights as $cat => $w) {
        $count = max(1, (int) round(($w / $totalWeight) * $totalCount));
        $slots[$cat] = $count;
    }

    // Adjust so we don't overshoot
    while (array_sum($slots) > $totalCount) {
        // Reduce lowest-priority category
        $minCat = array_keys($slots, min($slots))[0];
        if ($slots[$minCat] > 1) {
            $slots[$minCat]--;
        } else {
            break;
        }
    }

    // Determine intensity based on age and activity level
    $intensity = 'moderate';
    if ($age < 30 && in_array($activityLevel, ['very_active', 'extra_active'])) {
        $intensity = 'high';
    } elseif ($age >= 50 || $activityLevel === 'sedentary') {
        $intensity = 'light';
    }

    // Build recommendations
    $recommendations = [];

    foreach ($slots as $cat => $count) {
        $available = $byCategory[$cat] ?? [];
        if (!$available) continue;

        shuffle($available);
        $picked = array_slice($available, 0, $count);

        foreach ($picked as $ex) {
            // Determine sets/reps/rest based on goal + intensity
            $sets = 3;
            $reps = '10-12';
            $rest = 60;
            $priority = 'recommended';

            if ($cat === 'strength') {
                if ($goal === 'muscle_gain') {
                    $sets = $intensity === 'high' ? 5 : 4;
                    $reps = $intensity === 'high' ? '6-8' : '8-10';
                    $rest = $intensity === 'high' ? 120 : 90;
                    $priority = 'high';
                } elseif ($goal === 'fat_loss') {
                    $sets = 3;
                    $reps = '12-15';
                    $rest = 45;
                } else {
                    $sets = $intensity === 'light' ? 2 : 3;
                    $reps = '10-12';
                    $rest = 60;
                }
            } elseif ($cat === 'cardio') {
                if ($goal === 'fat_loss') {
                    $sets = 1;
                    $reps = $intensity === 'high' ? '25 mins' : ($intensity === 'light' ? '15 mins' : '20 mins');
                    $rest = 0;
                    $priority = 'high';
                } else {
                    $sets = 1;
                    $reps = $intensity === 'light' ? '10 mins' : '15 mins';
                    $rest = 0;
                }
            } elseif ($cat === 'core') {
                $sets = $intensity === 'light' ? 2 : 3;
                $reps = $intensity === 'high' ? '15-20' : '10-15';
                $rest = 30;
            }

            // Build reason text
            $reason = build_recommendation_reason($ex, $goal, $activityLevel, $sex, $age, $weight, $intensity);

            $recommendations[] = [
                'exercise'       => $ex,
                'recommendation' => $reason,
                'sets'           => $sets,
                'reps'           => $reps,
                'rest_seconds'   => $rest,
                'priority'       => $priority,
                'category'       => $cat,
            ];
        }
    }

    // Sort: high priority first, then recommended
    usort($recommendations, function ($a, $b) {
        $order = ['high' => 0, 'recommended' => 1];
        return ($order[$a['priority']] ?? 2) <=> ($order[$b['priority']] ?? 2);
    });

    return $recommendations;
}

/**
 * Builds a human-readable reason string explaining why an exercise was recommended.
 */
function build_recommendation_reason(
    array  $exercise,
    string $goal,
    string $activityLevel,
    string $sex,
    int    $age,
    float  $weight,
    string $intensity
): string {
    $name      = $exercise['name'];
    $category  = $exercise['category'];
    $muscle    = $exercise['muscle_group'];
    $goalLabel = ucwords(str_replace('_', ' ', $goal));

    $reasons = [];

    // Goal-based reason
    if ($goal === 'muscle_gain' && $category === 'strength') {
        $reasons[] = "Ideal for your {$goalLabel} goal — targets {$muscle} with compound resistance.";
    } elseif ($goal === 'fat_loss' && $category === 'cardio') {
        $reasons[] = "Great for your {$goalLabel} goal — maximises calorie burn through sustained effort.";
    } elseif ($goal === 'fat_loss' && $category === 'strength') {
        $reasons[] = "Strength training preserves muscle mass during fat loss — works {$muscle}.";
    } elseif ($goal === 'muscle_gain' && $category === 'cardio') {
        $reasons[] = "Light cardio supports recovery and cardiovascular health alongside muscle building.";
    } elseif ($category === 'core') {
        $reasons[] = "Core stability supports all other exercises and improves posture.";
    } else {
        $reasons[] = "Balanced addition for your {$goalLabel} routine — engages {$muscle}.";
    }

    // Intensity note
    if ($intensity === 'light') {
        $reasons[] = "Adjusted to a lighter intensity based on your current activity level.";
    } elseif ($intensity === 'high') {
        $reasons[] = "Elevated intensity to match your high activity level.";
    }

    // Age-based note
    if ($age >= 50) {
        $reasons[] = "Lower impact variation recommended for joint health.";
    } elseif ($age < 25 && $category === 'strength') {
        $reasons[] = "Great age to build foundational strength.";
    }

    return implode(' ', $reasons);
}
