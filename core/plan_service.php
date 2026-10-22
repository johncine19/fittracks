<?php
declare(strict_types=1);

/**
 * FitTracks Multi-Gym Plan Management Service
 * Handles workout and diet plan lifecycle across multiple gym memberships,
 * conflict detection, unified access, calendar aggregation, and audit trails.
 */

/**
 * Log a plan action into the plan_change_log audit table.
 */
function log_plan_change(
    string $planType,
    int $planId,
    ?int $gymId,
    int $changedByUserId,
    string $action,
    ?string $details = null
): bool {
    try {
        $stmt = db()->prepare('
            INSERT INTO plan_change_log (plan_type, plan_id, gym_id, changed_by_user_id, action, details)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        return $stmt->execute([
            $planType,
            $planId,
            $gymId ?: null,
            $changedByUserId,
            $action,
            $details
        ]);
    } catch (Throwable $e) {
        error_log('log_plan_change error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get all gyms a member is affiliated with (active membership, gym_members entry, or check-ins).
 */
function get_member_affiliated_gyms(int $userId): array
{
    $pdo = db();
    $sql = "
        SELECT DISTINCT g.gym_id, g.name, g.logo_url, g.brand_color,
               m.status AS membership_status,
               m.end_date AS membership_end_date,
               mp.name AS plan_name
        FROM gyms g
        LEFT JOIN gym_members gm ON gm.gym_id = g.gym_id AND gm.user_id = :u1
        LEFT JOIN memberships m ON m.user_id = :u2 AND m.status = 'active' AND m.end_date >= CURDATE()
        LEFT JOIN membership_plans mp ON mp.plan_id = m.plan_id AND mp.gym_id = g.gym_id
        WHERE (gm.user_id IS NOT NULL OR m.user_id IS NOT NULL)
          AND g.status = 'approved'
        ORDER BY g.name ASC
    ";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':u1' => $userId, ':u2' => $userId]);
        $gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Also check if member has plans originating from gyms not currently in gym_members
        $planGymsSql = "
            SELECT DISTINCT g.gym_id, g.name, g.logo_url, g.brand_color, 'plan_assigned' AS membership_status, NULL AS membership_end_date, NULL AS plan_name
            FROM gyms g
            WHERE g.status = 'approved'
              AND (
                  g.gym_id IN (SELECT DISTINCT gym_id FROM training_plans WHERE member_user_id = :u1 AND gym_id IS NOT NULL)
                  OR g.gym_id IN (SELECT DISTINCT gym_id FROM dietary_plans WHERE member_user_id = :u2 AND gym_id IS NOT NULL)
              )
        ";
        $stmt2 = $pdo->prepare($planGymsSql);
        $stmt2->execute([':u1' => $userId, ':u2' => $userId]);
        $planGyms = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $existingIds = array_column($gyms, 'gym_id');
        foreach ($planGyms as $pg) {
            if (!in_array($pg['gym_id'], $existingIds, true)) {
                $gyms[] = $pg;
                $existingIds[] = $pg['gym_id'];
            }
        }

        return $gyms;
    } catch (Throwable $e) {
        error_log('get_member_affiliated_gyms error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Retrieve all workout plans for a member, optionally filtered by gym or status.
 */
function get_member_workout_plans(int $userId, ?int $gymId = null, ?string $status = null): array
{
    $pdo = db();
    $params = [':user_id' => $userId];
    $where = 'tp.member_user_id = :user_id';

    if ($gymId !== null && $gymId > 0) {
        $where .= ' AND tp.gym_id = :gym_id';
        $params[':gym_id'] = $gymId;
    }

    // Member-facing: strictly exclude draft plans
    $where .= " AND tp.status != 'draft'";

    if ($status !== null && $status !== '' && $status !== 'all') {
        $where .= ' AND tp.status = :status';
        $params[':status'] = $status;
    }

    $sql = "
        SELECT tp.*,
               g.name AS gym_name,
               g.logo_url AS gym_logo,
               g.brand_color AS gym_brand_color,
               TRIM(CONCAT(COALESCE(u_tr.first_name, ''), ' ', COALESCE(u_tr.last_name, ''))) AS trainer_name,
               u_tr.email AS trainer_email,
               (SELECT COUNT(DISTINCT day_of_week) FROM training_plan_exercises WHERE plan_id = tp.plan_id) AS workout_days_count,
               (SELECT COUNT(*) FROM training_plan_exercises WHERE plan_id = tp.plan_id) AS exercise_count
        FROM training_plans tp
        LEFT JOIN gyms g ON g.gym_id = tp.gym_id
        LEFT JOIN trainer_profiles tpr ON tpr.trainer_id = tp.trainer_id
        LEFT JOIN users u_tr ON u_tr.user_id = tpr.user_id
        WHERE {$where}
        ORDER BY 
            CASE tp.status
                WHEN 'active' THEN 1
                WHEN 'upcoming' THEN 2
                WHEN 'paused' THEN 3
                WHEN 'completed' THEN 4
                WHEN 'archived' THEN 5
                ELSE 6
            END,
            tp.created_at DESC
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('get_member_workout_plans error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Retrieve all dietary plans for a member, optionally filtered by gym or status.
 */
function get_member_dietary_plans(int $userId, ?int $gymId = null, ?string $status = null): array
{
    $pdo = db();
    $params = [':user_id' => $userId];
    $where = 'dp.member_user_id = :user_id';

    if ($gymId !== null && $gymId > 0) {
        $where .= ' AND dp.gym_id = :gym_id';
        $params[':gym_id'] = $gymId;
    }

    // Member-facing: strictly exclude draft plans
    $where .= " AND dp.status != 'draft'";

    if ($status !== null && $status !== '' && $status !== 'all') {
        $where .= ' AND dp.status = :status';
        $params[':status'] = $status;
    }

    $sql = "
        SELECT dp.*,
               g.name AS gym_name,
               g.logo_url AS gym_logo,
               g.brand_color AS gym_brand_color,
               TRIM(CONCAT(COALESCE(u_tr.first_name, ''), ' ', COALESCE(u_tr.last_name, ''))) AS trainer_name,
               u_tr.email AS trainer_email,
               (SELECT COUNT(DISTINCT day_of_week) FROM dietary_plan_meals WHERE plan_id = dp.plan_id) AS meal_days_count,
               (SELECT COUNT(*) FROM dietary_plan_meals WHERE plan_id = dp.plan_id) AS total_meals_count,
               (SELECT ROUND(AVG(calories)) FROM (
                   SELECT day_of_week, SUM(calories) as calories 
                   FROM dietary_plan_meals 
                   WHERE plan_id = dp.plan_id 
                   GROUP BY day_of_week
               ) sub) AS avg_daily_calories
        FROM dietary_plans dp
        LEFT JOIN gyms g ON g.gym_id = dp.gym_id
        LEFT JOIN trainer_profiles tpr ON tpr.trainer_id = dp.trainer_id
        LEFT JOIN users u_tr ON u_tr.user_id = tpr.user_id
        WHERE {$where}
        ORDER BY 
            dp.is_preferred DESC,
            CASE dp.status
                WHEN 'active' THEN 1
                WHEN 'upcoming' THEN 2
                WHEN 'paused' THEN 3
                WHEN 'draft' THEN 4
                WHEN 'completed' THEN 5
                WHEN 'cancelled' THEN 6
                ELSE 7
            END,
            dp.created_at DESC
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('get_member_dietary_plans error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Detect schedule conflicts between active workout plans across different gyms.
 * Returns an array of conflicts grouped by day of week.
 */
function detect_workout_schedule_conflicts(int $userId): array
{
    $pdo = db();
    $sql = "
        SELECT 
            tpe.day_of_week,
            tp.plan_id,
            tp.title AS plan_title,
            tp.gym_id,
            COALESCE(g.name, 'Independent / Personal') AS gym_name,
            e.name AS exercise_name,
            tpe.sets,
            tpe.reps
        FROM training_plans tp
        JOIN training_plan_exercises tpe ON tpe.plan_id = tp.plan_id
        JOIN exercises e ON e.exercise_id = tpe.exercise_id
        LEFT JOIN gyms g ON g.gym_id = tp.gym_id
        WHERE tp.member_user_id = ?
          AND tp.status = 'active'
        ORDER BY tpe.day_of_week ASC, tp.plan_id ASC, tpe.sequence_order ASC
    ";

    $conflicts = [];
    $dayNames = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday'
    ];

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by day of week
        $days = [];
        foreach ($rows as $row) {
            $dow = (int)$row['day_of_week'];
            $planId = (int)$row['plan_id'];
            if (!isset($days[$dow][$planId])) {
                $days[$dow][$planId] = [
                    'plan_id' => $planId,
                    'plan_title' => $row['plan_title'],
                    'gym_id' => $row['gym_id'],
                    'gym_name' => $row['gym_name'],
                    'exercises' => []
                ];
            }
            $days[$dow][$planId]['exercises'][] = $row['exercise_name'] . ' (' . $row['sets'] . '×' . $row['reps'] . ')';
        }

        // Check days with multiple active plans from DIFFERENT gyms
        foreach ($days as $dow => $plans) {
            if (count($plans) > 1) {
                // Check if they are from different gyms
                $gymIds = [];
                foreach ($plans as $p) {
                    $gymIds[] = $p['gym_id'];
                }
                $uniqueGyms = array_unique($gymIds);
                if (count($uniqueGyms) > 1 || in_array(null, $gymIds, true)) {
                    $conflicts[] = [
                        'day_of_week' => $dow,
                        'day_name' => $dayNames[$dow] ?? "Day {$dow}",
                        'plans' => array_values($plans),
                        'conflict_count' => count($plans)
                    ];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('detect_workout_schedule_conflicts error: ' . $e->getMessage());
    }

    return $conflicts;
}

/**
 * Detect dietary plan conflicts:
 * Flag when a member has multiple active diet plans from different gyms,
 * and identify whether a preferred nutrition plan has been marked.
 */
function detect_dietary_plan_conflicts(int $userId): array
{
    $pdo = db();
    $sql = "
        SELECT dp.plan_id, dp.title, dp.goal, dp.gym_id, dp.is_preferred,
               COALESCE(g.name, 'Independent / Personal') AS gym_name,
               (SELECT ROUND(AVG(calories)) FROM (
                   SELECT day_of_week, SUM(calories) as calories 
                   FROM dietary_plan_meals 
                   WHERE plan_id = dp.plan_id 
                   GROUP BY day_of_week
               ) sub) AS avg_calories
        FROM dietary_plans dp
        LEFT JOIN gyms g ON g.gym_id = dp.gym_id
        WHERE dp.member_user_id = ?
          AND dp.status = 'active'
        ORDER BY dp.is_preferred DESC, dp.created_at DESC
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $activePlans = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($activePlans) <= 1) {
            return [
                'has_conflict' => false,
                'active_count' => count($activePlans),
                'preferred_plan_id' => !empty($activePlans[0]['is_preferred']) ? (int)$activePlans[0]['plan_id'] : null,
                'plans' => $activePlans
            ];
        }

        // Multiple active plans exist
        $hasPreferred = false;
        $preferredPlanId = null;
        foreach ($activePlans as $p) {
            if (!empty($p['is_preferred'])) {
                $hasPreferred = true;
                $preferredPlanId = (int)$p['plan_id'];
                break;
            }
        }

        return [
            'has_conflict' => true,
            'active_count' => count($activePlans),
            'has_preferred' => $hasPreferred,
            'preferred_plan_id' => $preferredPlanId,
            'plans' => $activePlans,
            'message' => 'You have ' . count($activePlans) . ' active diet plans from different gyms. ' .
                         ($hasPreferred ? 'One is currently marked as your primary/preferred nutrition plan.' : 'Please choose your preferred nutrition plan so your daily meal logs and macro goals align properly.')
        ];
    } catch (Throwable $e) {
        error_log('detect_dietary_plan_conflicts error: ' . $e->getMessage());
        return ['has_conflict' => false, 'plans' => []];
    }
}

/**
 * Set a specific diet plan as preferred for a member, clearing preferred status on all others.
 */
function set_preferred_dietary_plan(int $userId, int $planId): bool
{
    $pdo = db();
    $ownTx = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownTx = true;
        }

        // 1. Clear preferred flag on all plans for this member
        $stmtClear = $pdo->prepare('UPDATE dietary_plans SET is_preferred = 0 WHERE member_user_id = ?');
        $stmtClear->execute([$userId]);

        // 2. Set preferred flag on chosen plan
        $stmtSet = $pdo->prepare('UPDATE dietary_plans SET is_preferred = 1 WHERE plan_id = ? AND member_user_id = ?');
        $stmtSet->execute([$planId, $userId]);

        // 3. Log the change
        $gymId = (int) scalar('SELECT gym_id FROM dietary_plans WHERE plan_id = ?', [$planId]);
        log_plan_change('diet', $planId, $gymId ?: null, $userId, 'updated', 'Marked plan as preferred nutrition guide');

        if ($ownTx) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('set_preferred_dietary_plan error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Toggle or update the status of a workout or diet plan (active, paused, archived, completed).
 */
function update_plan_status(
    string $planType,
    int $planId,
    string $newStatus,
    int $changedByUserId,
    ?string $reason = null
): bool {
    $pdo = db();
    $validStatuses = ['active', 'paused', 'completed', 'archived', 'cancelled', 'upcoming'];
    if (!in_array($newStatus, $validStatuses, true)) {
        return false;
    }

    $table = ($planType === 'workout') ? 'training_plans' : 'dietary_plans';

    try {
        // Fetch current plan details
        $stmtCheck = $pdo->prepare("SELECT plan_id, member_user_id, gym_id, status FROM {$table} WHERE plan_id = ?");
        $stmtCheck->execute([$planId]);
        $plan = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            return false;
        }

        $oldStatus = $plan['status'];
        if ($oldStatus === $newStatus) {
            return true;
        }

        $stmtUpdate = $pdo->prepare("UPDATE {$table} SET status = ?, updated_at = NOW() WHERE plan_id = ?");
        $stmtUpdate->execute([$newStatus, $planId]);

        $details = "Status changed from '{$oldStatus}' to '{$newStatus}'" . ($reason ? ": {$reason}" : '');
        log_plan_change(
            $planType,
            $planId,
            $plan['gym_id'] ? (int)$plan['gym_id'] : null,
            $changedByUserId,
            $newStatus === 'paused' ? 'paused' : ($newStatus === 'archived' ? 'archived' : 'updated'),
            $details
        );

        return true;
    } catch (Throwable $e) {
        error_log('update_plan_status error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get aggregated 7-day consolidated calendar of all active workout plans for a member.
 */
function get_consolidated_workout_calendar(int $userId): array
{
    $pdo = db();
    $sql = "
        SELECT 
            tpe.plan_exercise_id,
            tpe.day_of_week,
            tpe.sequence_order,
            tpe.sets,
            tpe.reps,
            tpe.target_weight_kg,
            tpe.rest_seconds,
            tpe.tempo,
            tpe.rpe,
            e.name AS exercise_name,
            e.category,
            e.muscle_group,
            tp.plan_id,
            tp.title AS plan_title,
            tp.gym_id,
            COALESCE(g.name, 'Independent / Personal') AS gym_name,
            g.brand_color AS gym_brand_color,
            TRIM(CONCAT(COALESCE(u_tr.first_name, ''), ' ', COALESCE(u_tr.last_name, ''))) AS trainer_name
        FROM training_plans tp
        JOIN training_plan_exercises tpe ON tpe.plan_id = tp.plan_id
        JOIN exercises e ON e.exercise_id = tpe.exercise_id
        LEFT JOIN gyms g ON g.gym_id = tp.gym_id
        LEFT JOIN trainer_profiles tpr ON tpr.trainer_id = tp.trainer_id
        LEFT JOIN users u_tr ON u_tr.user_id = tpr.user_id
        WHERE tp.member_user_id = ?
          AND tp.status = 'active'
        ORDER BY tpe.day_of_week ASC, tp.plan_id ASC, tpe.sequence_order ASC
    ";

    $days = [
        1 => ['name' => 'Monday', 'short' => 'Mon', 'plans' => []],
        2 => ['name' => 'Tuesday', 'short' => 'Tue', 'plans' => []],
        3 => ['name' => 'Wednesday', 'short' => 'Wed', 'plans' => []],
        4 => ['name' => 'Thursday', 'short' => 'Thu', 'plans' => []],
        5 => ['name' => 'Friday', 'short' => 'Fri', 'plans' => []],
        6 => ['name' => 'Saturday', 'short' => 'Sat', 'plans' => []],
        7 => ['name' => 'Sunday', 'short' => 'Sun', 'plans' => []],
    ];

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $dow = (int)$row['day_of_week'];
            if (!isset($days[$dow])) continue;

            $planId = (int)$row['plan_id'];
            if (!isset($days[$dow]['plans'][$planId])) {
                $days[$dow]['plans'][$planId] = [
                    'plan_id' => $planId,
                    'plan_title' => $row['plan_title'],
                    'gym_id' => $row['gym_id'],
                    'gym_name' => $row['gym_name'],
                    'gym_brand_color' => !empty($row['gym_brand_color']) ? (string)$row['gym_brand_color'] : 'var(--lime)',
                    'trainer_name' => $row['trainer_name'],
                    'exercises' => []
                ];
            }

            $days[$dow]['plans'][$planId]['exercises'][] = [
                'exercise_id' => $row['plan_exercise_id'],
                'name' => $row['exercise_name'],
                'category' => $row['category'],
                'sets' => $row['sets'],
                'reps' => $row['reps'],
                'weight' => $row['target_weight_kg'],
                'rest' => $row['rest_seconds'],
                'tempo' => $row['tempo'],
                'rpe' => $row['rpe']
            ];
        }
    } catch (Throwable $e) {
        error_log('get_consolidated_workout_calendar error: ' . $e->getMessage());
    }

    return $days;
}

/**
 * Resolve the effective gym of a plan: its own gym_id, falling back to the
 * authoring trainer's gym for legacy rows created before gym_id existed.
 */
function resolve_plan_gym_id(string $planType, int $planId): ?int
{
    $table = ($planType === 'workout') ? 'training_plans' : 'dietary_plans';
    try {
        $gymId = scalar(
            "SELECT COALESCE(p.gym_id, tpr.gym_id)
             FROM {$table} p
             LEFT JOIN trainer_profiles tpr ON tpr.trainer_id = p.trainer_id
             WHERE p.plan_id = ?",
            [$planId]
        );
        return $gymId ? (int) $gymId : null;
    } catch (Throwable $e) {
        error_log('resolve_plan_gym_id error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Backend authorization guard for staff actions on a plan.
 * - platform_admin: always allowed
 * - trainer: only plans they authored (trainer_id match)
 * - gym_owner: plans they authored OR plans whose effective gym is their gym.
 *   Membership of the member in the owner's gym is NOT sufficient, so a member
 *   enrolled in two gyms never exposes Gym A's plans to Gym B's owner.
 */
function can_manage_plan(string $planType, int $planId, array $user, int $coachId, ?int $gymId): bool
{
    if ($planId <= 0) {
        return false;
    }
    $role = (string) ($user['role'] ?? '');
    if ($role === 'platform_admin') {
        return true;
    }

    $table = ($planType === 'workout') ? 'training_plans' : 'dietary_plans';
    $trainerId = (int) scalar("SELECT trainer_id FROM {$table} WHERE plan_id = ?", [$planId]);
    if ($coachId > 0 && $trainerId === $coachId) {
        return true;
    }

    if ($role === 'gym_owner' && $gymId) {
        $planGym = resolve_plan_gym_id($planType, $planId);
        return $planGym !== null && $planGym === (int) $gymId;
    }

    return false;
}

/**
 * Pause active plans tied to gyms where the member's membership has lapsed
 * (expired/cancelled with no remaining active membership at that gym).
 * Plans are paused — never deleted — so history is preserved and the plans
 * resume visibility if the member re-subscribes. Returns number of plans paused.
 */
function pause_plans_for_lapsed_memberships(?int $userId = null, ?int $actorUserId = null): int
{
    $pdo = db();
    $paused = 0;
    $userFilter = ($userId !== null && $userId > 0) ? ' AND p.member_user_id = ?' : '';

    foreach (['workout' => 'training_plans', 'diet' => 'dietary_plans'] as $type => $table) {
        $sql = "
            SELECT p.plan_id, p.member_user_id, p.gym_id
            FROM {$table} p
            WHERE p.status = 'active'
              AND p.gym_id IS NOT NULL
              AND p.trainer_id IS NOT NULL
              {$userFilter}
              AND EXISTS (
                  SELECT 1 FROM memberships m
                  JOIN membership_plans mp ON mp.plan_id = m.plan_id
                  WHERE m.user_id = p.member_user_id AND mp.gym_id = p.gym_id
                    AND m.status IN ('expired', 'cancelled')
              )
              AND NOT EXISTS (
                  SELECT 1 FROM memberships m
                  JOIN membership_plans mp ON mp.plan_id = m.plan_id
                  WHERE m.user_id = p.member_user_id AND mp.gym_id = p.gym_id
                    AND m.status IN ('active', 'pending') AND m.end_date >= CURDATE()
              )
        ";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($userFilter !== '' ? [$userId] : []);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $upd = $pdo->prepare("UPDATE {$table} SET status = 'paused' WHERE plan_id = ? AND status = 'active'");
                $upd->execute([(int) $row['plan_id']]);
                if ($upd->rowCount() > 0) {
                    $paused++;
                    log_plan_change(
                        $type,
                        (int) $row['plan_id'],
                        (int) $row['gym_id'],
                        $actorUserId ?: (int) $row['member_user_id'],
                        'paused',
                        'Auto-paused: membership at this gym ended'
                    );
                }
            }
        } catch (Throwable $e) {
            error_log('pause_plans_for_lapsed_memberships error: ' . $e->getMessage());
        }
    }

    return $paused;
}

/**
 * Detect excessive combined training workload across all active plans.
 * Flags days where total sets exceed a safe threshold, or muscle groups trained
 * on consecutive days by different gyms (insufficient recovery).
 */
function detect_workload_warnings(int $userId, int $maxDailySets = 30): array
{
    $warnings = [];
    $dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    try {
        $rows = query_all("
            SELECT tpe.day_of_week, tp.gym_id, COALESCE(g.name, 'Independent / Personal') AS gym_name,
                   LOWER(COALESCE(e.muscle_group, '')) AS muscle_group, COALESCE(tpe.sets, 0) AS sets
            FROM training_plans tp
            JOIN training_plan_exercises tpe ON tpe.plan_id = tp.plan_id
            JOIN exercises e ON e.exercise_id = tpe.exercise_id
            LEFT JOIN gyms g ON g.gym_id = tp.gym_id
            WHERE tp.member_user_id = ? AND tp.status = 'active'
        ", [$userId]);

        $setsPerDay = [];
        $muscleDayGyms = []; // [muscle][dow] => [gymKey => gymName]
        foreach ($rows as $r) {
            $dow = (int) $r['day_of_week'];
            $setsPerDay[$dow] = ($setsPerDay[$dow] ?? 0) + (int) $r['sets'];
            if ($r['muscle_group'] !== '') {
                $gymKey = (string) ($r['gym_id'] ?? 'none');
                $muscleDayGyms[$r['muscle_group']][$dow][$gymKey] = $r['gym_name'];
            }
        }

        foreach ($setsPerDay as $dow => $sets) {
            if ($sets > $maxDailySets) {
                $warnings[] = [
                    'type' => 'volume',
                    'day_of_week' => $dow,
                    'message' => sprintf('%s has %d total sets across your plans (recommended max %d). Consider spreading the load.', $dayNames[$dow] ?? "Day {$dow}", $sets, $maxDailySets),
                ];
            }
        }

        foreach ($muscleDayGyms as $muscle => $days) {
            foreach ($days as $dow => $gyms) {
                $next = $dow === 7 ? 1 : $dow + 1;
                if (empty($days[$next])) {
                    continue;
                }
                // Only flag when the back-to-back sessions come from different gyms
                $allGyms = $gyms + $days[$next];
                if (count($allGyms) > 1) {
                    $warnings[] = [
                        'type' => 'recovery',
                        'day_of_week' => $dow,
                        'message' => sprintf('%s is trained on %s and %s by different gyms (%s). Allow at least 48h recovery.', ucfirst($muscle), $dayNames[$dow], $dayNames[$next], implode(' & ', array_unique(array_values($allGyms)))),
                    ];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('detect_workload_warnings error: ' . $e->getMessage());
    }

    return $warnings;
}
