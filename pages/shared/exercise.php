<?php
declare(strict_types=1);

// -----------------------------------------------------------------------
// Member physical profile helpers.
// Workout generation lives in pages/shared/workouts.php.
// -----------------------------------------------------------------------

function save_member_profile(int $userId): void
{
    $pdo = db();

    // Ensure new target measurement columns exist (self-healing for seamless live operation)
    static $columnsChecked = false;
    if (!$columnsChecked) {
        $neededCols = [
            'chest_cm'                       => 'DECIMAL(5,2) DEFAULT NULL',
            'arm_cm'                         => 'DECIMAL(5,2) DEFAULT NULL',
            'target_arm_cm'                  => 'DECIMAL(5,2) DEFAULT NULL',
            'target_chest_cm'                => 'DECIMAL(5,2) DEFAULT NULL',
            'target_waist_cm'                => 'DECIMAL(5,2) DEFAULT NULL',
            'target_exercise'                => 'VARCHAR(100) DEFAULT NULL',
            'current_strength_max_kg'        => 'DECIMAL(5,2) DEFAULT NULL',
            'target_strength_max_kg'         => 'DECIMAL(5,2) DEFAULT NULL',
            'endurance_activity'             => 'VARCHAR(50) DEFAULT NULL',
            'current_endurance_distance_km'  => 'DECIMAL(5,2) DEFAULT NULL',
            'current_endurance_time_mins'    => 'INT DEFAULT NULL',
            'target_endurance_distance_km'   => 'DECIMAL(5,2) DEFAULT NULL',
            'target_endurance_time_mins'     => 'INT DEFAULT NULL',
            'weekly_workout_target'          => 'TINYINT UNSIGNED DEFAULT NULL',
            'preferred_duration_mins'        => 'SMALLINT UNSIGNED DEFAULT 45',
        ];
        foreach ($neededCols as $col => $def) {
            try {
                $pdo->query("SELECT {$col} FROM member_profiles LIMIT 1");
            } catch (Throwable) {
                try {
                    $pdo->exec("ALTER TABLE member_profiles ADD COLUMN {$col} {$def}");
                } catch (Throwable) {}
            }
        }
        $columnsChecked = true;
    }

    $stmt = $pdo->prepare('INSERT INTO member_profiles (
        user_id, height_cm, weight_kg, neck_cm, waist_cm, hip_cm, chest_cm, arm_cm, age, biological_sex, activity_level, primary_goal, dietary_restrictions,
        target_weight_kg, target_body_fat_percent, target_arm_cm, target_chest_cm, target_waist_cm,
        target_exercise, current_strength_max_kg, target_strength_max_kg,
        endurance_activity, current_endurance_distance_km, current_endurance_time_mins,
        target_endurance_distance_km, target_endurance_time_mins, weekly_workout_target, preferred_duration_mins
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE 
        height_cm = VALUES(height_cm), weight_kg = VALUES(weight_kg), neck_cm = VALUES(neck_cm), waist_cm = VALUES(waist_cm), hip_cm = VALUES(hip_cm),
        chest_cm = VALUES(chest_cm), arm_cm = VALUES(arm_cm),
        age = VALUES(age), biological_sex = VALUES(biological_sex), activity_level = VALUES(activity_level), primary_goal = VALUES(primary_goal), dietary_restrictions = VALUES(dietary_restrictions),
        target_weight_kg = VALUES(target_weight_kg), target_body_fat_percent = VALUES(target_body_fat_percent), target_arm_cm = VALUES(target_arm_cm), target_chest_cm = VALUES(target_chest_cm), target_waist_cm = VALUES(target_waist_cm),
        target_exercise = VALUES(target_exercise), current_strength_max_kg = VALUES(current_strength_max_kg), target_strength_max_kg = VALUES(target_strength_max_kg),
        endurance_activity = VALUES(endurance_activity), current_endurance_distance_km = VALUES(current_endurance_distance_km), current_endurance_time_mins = VALUES(current_endurance_time_mins),
        target_endurance_distance_km = VALUES(target_endurance_distance_km), target_endurance_time_mins = VALUES(target_endurance_time_mins),
        weekly_workout_target = VALUES(weekly_workout_target), preferred_duration_mins = VALUES(preferred_duration_mins)');

    $stmt->execute([
        $userId,
        post('height_cm') ?: 0.0,
        post('weight_kg') ?: 0.0,
        post('neck_cm') ?: null,
        post('waist_cm') ?: null,
        post('biological_sex') === 'female' ? (post('hip_cm') ?: null) : null,
        post('chest_cm') ?: null,
        post('arm_cm') ?: null,
        (int) (post('age') ?: 0),
        post('biological_sex') ?: 'male',
        post('activity_level') ?: 'sedentary',
        post('primary_goal') ?: '',
        post('dietary_restrictions') ?: 'none',
        post('target_weight_kg') ?: null,
        post('target_body_fat_percent') ?: null,
        post('target_arm_cm') ?: null,
        post('target_chest_cm') ?: null,
        post('target_waist_cm') ?: null,
        post('target_exercise') ?: null,
        post('current_strength_max_kg') ?: null,
        post('target_strength_max_kg') ?: null,
        post('endurance_activity') ?: null,
        post('current_endurance_distance_km') ?: null,
        post('current_endurance_time_mins') ?: null,
        post('target_endurance_distance_km') ?: null,
        post('target_endurance_time_mins') ?: null,
        post('weekly_workout_target') ? (int)post('weekly_workout_target') : null,
        post('preferred_duration_mins') ? (int)post('preferred_duration_mins') : 45,
    ]);

    // Keep progress_logs synchronized with physical profile so progress page and profile page match
    $today = date('Y-m-d');
    $w = (float)(post('weight_kg') ?: 0);
    $waist = post('waist_cm') ? (float)post('waist_cm') : null;
    $neck = post('neck_cm') ? (float)post('neck_cm') : null;
    $hips = (post('biological_sex') === 'female' && post('hip_cm')) ? (float)post('hip_cm') : null;
    $chest = post('chest_cm') ? (float)post('chest_cm') : null;
    $arm = post('arm_cm') ? (float)post('arm_cm') : null;

    if ($w > 0) {
        $existingLogId = scalar('SELECT log_id FROM progress_logs WHERE user_id = ? AND log_date = ?', [$userId, $today]);
        if ($existingLogId) {
            $pdo->prepare('UPDATE progress_logs SET weight_kg = ?, waist_cm = COALESCE(?, waist_cm), hips_cm = COALESCE(?, hips_cm), chest_cm = COALESCE(?, chest_cm), arm_cm = COALESCE(?, arm_cm) WHERE log_id = ?')
                ->execute([$w, $waist, $hips, $chest, $arm, $existingLogId]);
        } else {
            $pdo->prepare('INSERT INTO progress_logs (user_id, log_date, weight_kg, waist_cm, hips_cm, chest_cm, arm_cm, recorded_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$userId, $today, $w, $waist, $hips, $chest, $arm, $userId, 'Updated via physical profile']);
        }
    }

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
    if (!$profile) {
        return null;
    }
    // Fallback to latest progress_logs measurement if profile column is empty
    if (empty($profile['chest_cm']) || empty($profile['arm_cm'])) {
        try {
            $latestLog = db()->query("SELECT chest_cm, arm_cm FROM progress_logs WHERE user_id = $userId ORDER BY log_date DESC LIMIT 1")->fetch();
            if ($latestLog) {
                if (empty($profile['chest_cm']) && !empty($latestLog['chest_cm'])) {
                    $profile['chest_cm'] = $latestLog['chest_cm'];
                }
                if (empty($profile['arm_cm']) && !empty($latestLog['arm_cm'])) {
                    $profile['arm_cm'] = $latestLog['arm_cm'];
                }
            }
        } catch (Throwable) {}
    }
    return $profile;
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

    $goal = map_detailed_goal_to_basic($profile['primary_goal']);
    $activityLevel = $profile['activity_level'];
    $sex           = $profile['biological_sex'];
    $weight        = (float) $profile['weight_kg'];

    // Use age directly from profile
    $age = (int) $profile['age'];

    // Fetch member's gym_id
    $pdo = db();
    $memberGymId = (int) $pdo->query('SELECT gym_id FROM gym_members WHERE user_id = ' . $userId)->fetchColumn();

    // Fetch exercises for this gym, falling back to universal exercises (gym_id = 0) if none found
    $stmt = $pdo->prepare('SELECT * FROM exercises WHERE gym_id = ? ORDER BY category, name');
    $stmt->execute([$memberGymId]);
    $exercises = $stmt->fetchAll();
    if (!$exercises && $memberGymId !== 0) {
        $stmt = $pdo->prepare('SELECT * FROM exercises WHERE gym_id = 0 ORDER BY category, name');
        $stmt->execute();
        $exercises = $stmt->fetchAll();
    }
    if (!$exercises) {
        $exercises = $pdo->query('SELECT * FROM exercises ORDER BY category, name')->fetchAll();
    }
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
