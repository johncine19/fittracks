<?php
declare(strict_types=1);

// -----------------------------------------------------------------------
// Member physical profile helpers.
// Workout generation lives in pages/shared/workouts.php.
// -----------------------------------------------------------------------

function save_member_profile(int $userId): void
{
    $pdo = db();
    $existing = member_profile($userId, true) ?? [];

    $resolveField = static function (string $key, mixed $default = null) use ($existing): mixed {
        if (array_key_exists($key, $_POST)) {
            $val = $_POST[$key];
            if (is_string($val)) {
                $val = trim($val);
            }
            return ($val !== '' && $val !== null) ? $val : $default;
        }
        return array_key_exists($key, $existing) && $existing[$key] !== null && $existing[$key] !== ''
            ? $existing[$key]
            : $default;
    };

    $sexVal = (string) $resolveField('biological_sex', 'male');
    $heightVal = (float) ($resolveField('height_cm', 0.0) ?: 0.0);
    $weightVal = (float) ($resolveField('weight_kg', 0.0) ?: 0.0);
    $neckVal = $resolveField('neck_cm', null);
    $waistVal = $resolveField('waist_cm', null);
    $hipVal = $sexVal === 'female' ? $resolveField('hip_cm', null) : null;
    $chestVal = $resolveField('chest_cm', null);
    $armVal = $resolveField('arm_cm', null);
    $ageVal = (int) ($resolveField('age', 0) ?: 0);
    $activityVal = (string) $resolveField('activity_level', 'sedentary');
    $goalVal = (string) $resolveField('primary_goal', '');

    // Resolve birth_date from either birth_date input or age input
    $birthDateVal = $existing['birth_date'] ?? null;
    if (!empty($_POST['birth_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string)$_POST['birth_date']))) {
        $birthDateVal = trim((string)$_POST['birth_date']);
    } elseif ($ageVal > 0) {
        $existingAge = isset($existing['age']) ? (int)$existing['age'] : 0;
        if (empty($birthDateVal) || $existingAge !== $ageVal) {
            $birthYear = (int)date('Y') - max(10, min(120, $ageVal));
            $birthDateVal = sprintf('%04d-01-01', $birthYear);
        }
    }

    $weeklyTargetRaw = $resolveField('weekly_workout_target', null);
    $durationRaw = $resolveField('preferred_duration_mins', 45);

    // 1. Upsert core normalized member_profiles row
    $stmt = $pdo->prepare('
        INSERT INTO member_profiles (
            user_id, birth_date, biological_sex, height_cm, activity_level, primary_goal, weekly_workout_target, preferred_duration_mins
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            birth_date = VALUES(birth_date),
            biological_sex = VALUES(biological_sex),
            height_cm = VALUES(height_cm),
            activity_level = VALUES(activity_level),
            primary_goal = VALUES(primary_goal),
            weekly_workout_target = VALUES(weekly_workout_target),
            preferred_duration_mins = VALUES(preferred_duration_mins)
    ');
    $stmt->execute([
        $userId,
        $birthDateVal,
        $sexVal,
        $heightVal,
        $activityVal,
        $goalVal,
        $weeklyTargetRaw !== null ? (int)$weeklyTargetRaw : null,
        $durationRaw !== null ? (int)$durationRaw : 45,
    ]);

    // 2. Record body measurements in progress_logs (single source of truth for historical measurements)
    $today = date('Y-m-d');
    $w = $weightVal;
    $waist = $waistVal !== null ? (float) $waistVal : null;
    $neck = $neckVal !== null ? (float) $neckVal : null;
    $hips = ($sexVal === 'female' && $hipVal !== null) ? (float) $hipVal : null;
    $chest = $chestVal !== null ? (float) $chestVal : null;
    $arm = $armVal !== null ? (float) $armVal : null;

    // Calculate Navy Body Fat % if required measurements are available
    $bodyFatPercent = null;
    if ($heightVal > 0 && $neck !== null && $neck > 0 && $waist !== null && $waist > 0) {
        if ($sexVal !== 'female' && $waist > $neck) {
            $bfCalc = 495 / (1.0324 - 0.19077 * log10($waist - $neck) + 0.15456 * log10($heightVal)) - 450;
            if ($bfCalc >= 3 && $bfCalc <= 65) {
                $bodyFatPercent = round($bfCalc, 1);
            }
        } elseif ($sexVal === 'female' && $hips !== null && $hips > 0 && ($waist + $hips) > $neck) {
            $bfCalc = 495 / (1.29579 - 0.35004 * log10($waist + $hips - $neck) + 0.22100 * log10($heightVal)) - 450;
            if ($bfCalc >= 3 && $bfCalc <= 65) {
                $bodyFatPercent = round($bfCalc, 1);
            }
        }
    }

    if ($w > 0 || $neck !== null || $waist !== null || $hips !== null || $chest !== null || $arm !== null) {
        $existingLogId = scalar('SELECT log_id FROM progress_logs WHERE user_id = ? AND log_date = ? ORDER BY log_id DESC LIMIT 1', [$userId, $today]);
        if ($existingLogId) {
            $pdo->prepare('
                UPDATE progress_logs SET
                    weight_kg = IF(? > 0, ?, weight_kg),
                    body_fat_percent = COALESCE(?, body_fat_percent),
                    neck_cm = COALESCE(?, neck_cm),
                    waist_cm = COALESCE(?, waist_cm),
                    hips_cm = COALESCE(?, hips_cm),
                    chest_cm = COALESCE(?, chest_cm),
                    arm_cm = COALESCE(?, arm_cm)
                WHERE log_id = ?
            ')->execute([$w, $w, $bodyFatPercent, $neck, $waist, $hips, $chest, $arm, $existingLogId]);
        } else {
            $pdo->prepare('
                INSERT INTO progress_logs (user_id, log_date, weight_kg, body_fat_percent, neck_cm, waist_cm, hips_cm, chest_cm, arm_cm, recorded_by, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([$userId, $today, $w > 0 ? $w : 70.0, $bodyFatPercent, $neck, $waist, $hips, $chest, $arm, $userId, 'Updated via physical profile']);
        }
    }

    // 3. Sync preferred_workout_days -> member_workout_days (1NF)
    if (array_key_exists('preferred_workout_days', $_POST)) {
        $pdo->prepare('DELETE FROM member_workout_days WHERE user_id = ?')->execute([$userId]);
        $rawDays = is_array($_POST['preferred_workout_days'])
            ? implode(',', $_POST['preferred_workout_days'])
            : trim((string)$_POST['preferred_workout_days']);
        if ($rawDays !== '') {
            $days = array_filter(array_map('intval', explode(',', $rawDays)), fn($d) => $d >= 1 && $d <= 7);
            $insDay = $pdo->prepare('INSERT IGNORE INTO member_workout_days (user_id, day_of_week) VALUES (?, ?)');
            foreach (array_unique($days) as $d) {
                $insDay->execute([$userId, $d]);
            }
        }
    }

    // 4. Sync secondary_focus -> focus_areas + member_focus_areas (1NF)
    if (array_key_exists('secondary_focus', $_POST)) {
        $pdo->prepare('DELETE FROM member_focus_areas WHERE user_id = ?')->execute([$userId]);
        $rawSec = $_POST['secondary_focus'];
        $secItems = [];
        if (is_array($rawSec)) {
            $secItems = array_filter(array_map('trim', $rawSec));
        } elseif (is_string($rawSec) && trim($rawSec) !== '' && strtolower(trim($rawSec)) !== 'none') {
            $safeStr = str_replace('Biceps, Triceps & Arms', 'Biceps__COMMA__ Triceps & Arms', trim($rawSec));
            $parts = array_filter(array_map('trim', explode(',', $safeStr)));
            foreach ($parts as $p) {
                $secItems[] = str_replace('Biceps__COMMA__ Triceps & Arms', 'Biceps, Triceps & Arms', $p);
            }
        }
        foreach ($secItems as $areaName) {
            if ($areaName === '' || strtolower($areaName) === 'none') {
                continue;
            }
            $pdo->prepare('INSERT IGNORE INTO focus_areas (name) VALUES (?)')->execute([$areaName]);
            $areaId = (int) scalar('SELECT focus_area_id FROM focus_areas WHERE name = ? LIMIT 1', [$areaName]);
            if ($areaId > 0) {
                $pdo->prepare('INSERT IGNORE INTO member_focus_areas (user_id, focus_area_id) VALUES (?, ?)')->execute([$userId, $areaId]);
            }
        }
    }

    // 5. Sync dietary_restrictions -> dietary_restrictions + member_dietary_restrictions (1NF)
    if (array_key_exists('dietary_restrictions', $_POST) || empty($existing)) {
        $pdo->prepare('DELETE FROM member_dietary_restrictions WHERE user_id = ?')->execute([$userId]);
        $rawDiet = $_POST['dietary_restrictions'] ?? ($existing['dietary_restrictions'] ?? 'none');
        $dietItems = is_array($rawDiet)
            ? array_filter(array_map('trim', $rawDiet))
            : array_filter(array_map('trim', explode(',', (string)$rawDiet)));
        if (empty($dietItems)) {
            $dietItems = ['none'];
        }
        foreach ($dietItems as $rName) {
            $pdo->prepare('INSERT IGNORE INTO dietary_restrictions (name) VALUES (?)')->execute([$rName]);
            $rId = (int) scalar('SELECT restriction_id FROM dietary_restrictions WHERE name = ? LIMIT 1', [$rName]);
            if ($rId > 0) {
                $pdo->prepare('INSERT IGNORE INTO member_dietary_restrictions (user_id, restriction_id) VALUES (?, ?)')->execute([$userId, $rId]);
            }
        }
    }

    // 6. Sync target/current metrics -> member_goals
    $upsertGoal = $pdo->prepare('
        INSERT INTO member_goals (user_id, goal_type, exercise_or_activity, current_value, target_value, unit)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            exercise_or_activity = COALESCE(VALUES(exercise_or_activity), exercise_or_activity),
            current_value = COALESCE(VALUES(current_value), current_value),
            target_value = VALUES(target_value),
            unit = VALUES(unit)
    ');

    $goalMappings = [
        'weight'   => ['target_key' => 'target_weight_kg',        'curr' => $w > 0 ? $w : null, 'unit' => 'kg'],
        'body_fat' => ['target_key' => 'target_body_fat_percent', 'curr' => $bodyFatPercent,    'unit' => '%'],
        'waist'    => ['target_key' => 'target_waist_cm',         'curr' => $waist,             'unit' => 'cm'],
        'arm'      => ['target_key' => 'target_arm_cm',           'curr' => $arm,               'unit' => 'cm'],
        'chest'    => ['target_key' => 'target_chest_cm',         'curr' => $chest,             'unit' => 'cm'],
    ];

    foreach ($goalMappings as $gType => $cfg) {
        if (array_key_exists($cfg['target_key'], $_POST)) {
            $tVal = $_POST[$cfg['target_key']] !== '' && $_POST[$cfg['target_key']] !== null ? abs((float)$_POST[$cfg['target_key']]) : null;
            if ($tVal !== null) {
                $upsertGoal->execute([$userId, $gType, null, $cfg['curr'], $tVal, $cfg['unit']]);
            } else {
                $pdo->prepare('DELETE FROM member_goals WHERE user_id = ? AND goal_type = ?')->execute([$userId, $gType]);
            }
        }
    }

    $targetEx = $resolveField('target_exercise', null);
    $currStr = $resolveField('current_strength_max_kg', null);
    $targStr = $resolveField('target_strength_max_kg', null);
    if ($targetEx !== null || $currStr !== null || $targStr !== null) {
        $upsertGoal->execute([
            $userId,
            'strength',
            $targetEx !== null ? (string)$targetEx : null,
            $currStr !== null ? (float)$currStr : null,
            $targStr !== null ? (float)$targStr : null,
            'kg',
        ]);
    }

    $endAct = $resolveField('endurance_activity', null);
    $currDist = $resolveField('current_endurance_distance_km', null);
    $targDist = $resolveField('target_endurance_distance_km', null);
    if ($endAct !== null || $currDist !== null || $targDist !== null) {
        $upsertGoal->execute([
            $userId,
            'endurance_distance',
            $endAct !== null ? (string)$endAct : null,
            $currDist !== null ? (float)$currDist : null,
            $targDist !== null ? (float)$targDist : null,
            'km',
        ]);
    }

    $currTime = $resolveField('current_endurance_time_mins', null);
    $targTime = $resolveField('target_endurance_time_mins', null);
    if ($currTime !== null || $targTime !== null) {
        $upsertGoal->execute([
            $userId,
            'endurance_time',
            $endAct !== null ? (string)$endAct : null,
            $currTime !== null ? (float)$currTime : null,
            $targTime !== null ? (float)$targTime : null,
            'mins',
        ]);
    }

    // 7. Sync trainer_assessment_notes -> trainer_assessments
    if (array_key_exists('trainer_assessment_notes', $_POST)) {
        $notesText = trim((string)$_POST['trainer_assessment_notes']);
        if ($notesText !== '') {
            $currUser = function_exists('current_user') ? current_user() : null;
            $trainerId = null;
            if ($currUser && ($currUser['role'] ?? '') === 'trainer') {
                $trainerId = (int) scalar('SELECT trainer_id FROM trainer_profiles WHERE user_id = ? LIMIT 1', [(int)$currUser['user_id']]) ?: null;
            }
            $existingAssessId = (int) scalar('SELECT assessment_id FROM trainer_assessments WHERE member_user_id = ? ORDER BY updated_at DESC, assessment_id DESC LIMIT 1', [$userId]);
            if ($existingAssessId > 0) {
                $pdo->prepare('UPDATE trainer_assessments SET notes = ?, trainer_id = COALESCE(?, trainer_id) WHERE assessment_id = ?')
                    ->execute([$notesText, $trainerId, $existingAssessId]);
            } else {
                $pdo->prepare('INSERT INTO trainer_assessments (member_user_id, trainer_id, notes) VALUES (?, ?, ?)')
                    ->execute([$userId, $trainerId, $notesText]);
            }
        }
    }

    // If measurements required for Body Fat % are now completed, clean up any pending reminder notification
    $hasAllBfMeasurements = ($neck !== null && $neck > 0 && $waist !== null && $waist > 0 && ($sexVal !== 'female' || ($hips !== null && $hips > 0)));
    if ($hasAllBfMeasurements) {
        try {
            $pdo->prepare('DELETE FROM notifications WHERE user_id = ? AND title LIKE "%Complete Your Body Measurements%"')
                ->execute([$userId]);
        } catch (Throwable) {}
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

    // Refresh static profile cache so callers in the same request see updated data
    member_profile($userId, true);
}

function member_profile(int $userId, bool $forceRefresh = false): ?array
{
    static $profileCache = [];
    if (!$forceRefresh && array_key_exists($userId, $profileCache)) {
        return $profileCache[$userId];
    }

    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT mp.*,
               CASE
                   WHEN mp.birth_date IS NOT NULL THEN TIMESTAMPDIFF(YEAR, mp.birth_date, CURDATE())
                   ELSE 25
               END AS age
        FROM member_profiles mp
        WHERE mp.user_id = ?
    ');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$profile) {
        $profileCache[$userId] = null;
        return null;
    }

    // 1. Hydrate latest measurements from progress_logs (using latest non-null values across history)
    $profile['weight_kg'] = 0.0;
    $profile['neck_cm'] = null;
    $profile['waist_cm'] = null;
    $profile['hip_cm'] = null;
    $profile['chest_cm'] = null;
    $profile['arm_cm'] = null;
    $profile['body_fat_percent'] = null;

    try {
        $logsStmt = $pdo->prepare('
            SELECT weight_kg, body_fat_percent, neck_cm, waist_cm, hips_cm, chest_cm, arm_cm
            FROM progress_logs
            WHERE user_id = ?
            ORDER BY log_date DESC, log_id DESC
            LIMIT 10
        ');
        $logsStmt->execute([$userId]);
        $logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($logs as $log) {
            if (empty($profile['weight_kg']) && !empty($log['weight_kg']) && (float)$log['weight_kg'] > 0) {
                $profile['weight_kg'] = $log['weight_kg'];
            }
            if ($profile['neck_cm'] === null && !empty($log['neck_cm'])) {
                $profile['neck_cm'] = $log['neck_cm'];
            }
            if ($profile['waist_cm'] === null && !empty($log['waist_cm'])) {
                $profile['waist_cm'] = $log['waist_cm'];
            }
            if (($profile['biological_sex'] ?? 'male') === 'female' && $profile['hip_cm'] === null && !empty($log['hips_cm'])) {
                $profile['hip_cm'] = $log['hips_cm'];
            }
            if ($profile['chest_cm'] === null && !empty($log['chest_cm'])) {
                $profile['chest_cm'] = $log['chest_cm'];
            }
            if ($profile['arm_cm'] === null && !empty($log['arm_cm'])) {
                $profile['arm_cm'] = $log['arm_cm'];
            }
            if ($profile['body_fat_percent'] === null && !empty($log['body_fat_percent'])) {
                $profile['body_fat_percent'] = $log['body_fat_percent'];
            }
        }
    } catch (Throwable) {}

    // 2. Hydrate preferred_workout_days from member_workout_days
    $profile['preferred_workout_days'] = null;
    $profile['workout_days_list'] = [];
    try {
        $daysStmt = $pdo->prepare('SELECT day_of_week FROM member_workout_days WHERE user_id = ? ORDER BY day_of_week ASC');
        $daysStmt->execute([$userId]);
        $days = array_map('intval', $daysStmt->fetchAll(PDO::FETCH_COLUMN));
        if (!empty($days)) {
            $profile['workout_days_list'] = $days;
            $profile['preferred_workout_days'] = implode(',', $days);
        }
    } catch (Throwable) {}

    // 3. Hydrate secondary_focus from member_focus_areas + focus_areas
    $profile['secondary_focus'] = null;
    $profile['focus_areas_list'] = [];
    try {
        $focusStmt = $pdo->prepare('
            SELECT fa.name
            FROM member_focus_areas mfa
            JOIN focus_areas fa ON fa.focus_area_id = mfa.focus_area_id
            WHERE mfa.user_id = ?
        ');
        $focusStmt->execute([$userId]);
        $areas = $focusStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($areas)) {
            $profile['focus_areas_list'] = $areas;
            $profile['secondary_focus'] = implode(', ', $areas);
        }
    } catch (Throwable) {}

    // 4. Hydrate dietary_restrictions from member_dietary_restrictions + dietary_restrictions
    $profile['dietary_restrictions'] = 'none';
    $profile['dietary_restrictions_list'] = [];
    try {
        $dietStmt = $pdo->prepare('
            SELECT dr.name
            FROM member_dietary_restrictions mdr
            JOIN dietary_restrictions dr ON dr.restriction_id = mdr.restriction_id
            WHERE mdr.user_id = ?
        ');
        $dietStmt->execute([$userId]);
        $restrictions = $dietStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($restrictions)) {
            $profile['dietary_restrictions_list'] = $restrictions;
            $profile['dietary_restrictions'] = implode(', ', $restrictions);
        }
    } catch (Throwable) {}

    // 5. Hydrate goals from member_goals
    $profile['target_weight_kg'] = null;
    $profile['target_body_fat_percent'] = null;
    $profile['target_arm_cm'] = null;
    $profile['target_chest_cm'] = null;
    $profile['target_waist_cm'] = null;
    $profile['target_exercise'] = null;
    $profile['current_strength_max_kg'] = null;
    $profile['target_strength_max_kg'] = null;
    $profile['endurance_activity'] = null;
    $profile['current_endurance_distance_km'] = null;
    $profile['current_endurance_time_mins'] = null;
    $profile['target_endurance_distance_km'] = null;
    $profile['target_endurance_time_mins'] = null;
    $profile['goals'] = [];

    try {
        $goalsStmt = $pdo->prepare('SELECT * FROM member_goals WHERE user_id = ?');
        $goalsStmt->execute([$userId]);
        $goals = $goalsStmt->fetchAll(PDO::FETCH_ASSOC);
        $profile['goals'] = $goals;
        foreach ($goals as $g) {
            switch ($g['goal_type']) {
                case 'weight':
                    $profile['target_weight_kg'] = $g['target_value'];
                    break;
                case 'body_fat':
                    $profile['target_body_fat_percent'] = $g['target_value'];
                    break;
                case 'waist':
                    $profile['target_waist_cm'] = $g['target_value'];
                    break;
                case 'arm':
                    $profile['target_arm_cm'] = $g['target_value'];
                    break;
                case 'chest':
                    $profile['target_chest_cm'] = $g['target_value'];
                    break;
                case 'strength':
                    $profile['target_exercise'] = $g['exercise_or_activity'];
                    $profile['current_strength_max_kg'] = $g['current_value'];
                    $profile['target_strength_max_kg'] = $g['target_value'];
                    break;
                case 'endurance_distance':
                    $profile['endurance_activity'] = $g['exercise_or_activity'];
                    $profile['current_endurance_distance_km'] = $g['current_value'];
                    $profile['target_endurance_distance_km'] = $g['target_value'];
                    break;
                case 'endurance_time':
                    if (empty($profile['endurance_activity']) && !empty($g['exercise_or_activity'])) {
                        $profile['endurance_activity'] = $g['exercise_or_activity'];
                    }
                    $profile['current_endurance_time_mins'] = $g['current_value'] !== null ? (int)$g['current_value'] : null;
                    $profile['target_endurance_time_mins'] = $g['target_value'] !== null ? (int)$g['target_value'] : null;
                    break;
            }
        }
    } catch (Throwable) {}

    // 6. Hydrate trainer_assessment_notes from trainer_assessments
    $profile['trainer_assessment_notes'] = null;
    try {
        $taStmt = $pdo->prepare('SELECT notes FROM trainer_assessments WHERE member_user_id = ? ORDER BY updated_at DESC, assessment_id DESC LIMIT 1');
        $taStmt->execute([$userId]);
        $taNotes = $taStmt->fetchColumn();
        if ($taNotes !== false) {
            $profile['trainer_assessment_notes'] = (string)$taNotes;
        }
    } catch (Throwable) {}

    $profileCache[$userId] = $profile;
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

    // Fetch exercises for this gym, falling back to or supplementing with universal exercises (gym_id = 0)
    if ($memberGymId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM exercises WHERE gym_id = ? OR gym_id = 0 ORDER BY (gym_id = ?) DESC, category, name');
        $stmt->execute([$memberGymId, $memberGymId]);
        $exercises = $stmt->fetchAll();
    } else {
        $exercises = $pdo->query('SELECT * FROM exercises WHERE gym_id = 0 ORDER BY category, name')->fetchAll();
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
