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
        SELECT g.gym_id, g.name, g.logo_url, g.brand_color,
               MAX(m.status) AS membership_status,
               MAX(m.end_date) AS membership_end_date,
               MAX(m.plan_name) AS plan_name
        FROM gyms g
        LEFT JOIN gym_members gm ON gm.gym_id = g.gym_id AND gm.user_id = :u1
        LEFT JOIN (
            SELECT m2.user_id, m2.status, m2.end_date, mp2.gym_id, mp2.plan_name
            FROM memberships m2
            JOIN membership_plans mp2 ON mp2.plan_id = m2.plan_id
            WHERE m2.user_id = :u2 AND m2.status = 'active' AND m2.end_date >= CURDATE()
        ) m ON m.gym_id = g.gym_id
        LEFT JOIN (
            SELECT DISTINCT gym_id FROM training_plans WHERE member_user_id = :u3 AND gym_id IS NOT NULL AND status IN ('active', 'paused', 'upcoming')
            UNION
            SELECT DISTINCT gym_id FROM dietary_plans WHERE member_user_id = :u4 AND gym_id IS NOT NULL AND status IN ('active', 'paused', 'upcoming')
        ) p ON p.gym_id = g.gym_id
        WHERE g.status = 'approved'
          AND (gm.user_id IS NOT NULL OR m.gym_id IS NOT NULL OR p.gym_id IS NOT NULL)
        GROUP BY g.gym_id, g.name, g.logo_url, g.brand_color
        ORDER BY g.name ASC
    ";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':u1' => $userId,
            ':u2' => $userId,
            ':u3' => $userId,
            ':u4' => $userId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    ensure_archived_plan_support();
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
    } else {
        // When viewing all (default view), exclude archived plans so active view stays clean
        $where .= " AND tp.status != 'archived'";
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
    } else {
        // When viewing all (default view), exclude archived plans so active view stays clean
        $where .= " AND dp.status != 'archived'";
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

        $shortDayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        foreach ($muscleDayGyms as $muscle => $days) {
            $involvedDays = [];
            $involvedGyms = [];
            foreach ($days as $dow => $gyms) {
                $next = $dow === 7 ? 1 : $dow + 1;
                if (!empty($days[$next])) {
                    $combinedGyms = $gyms + $days[$next];
                    if (count($combinedGyms) > 1) {
                        $involvedDays[$dow] = true;
                        $involvedDays[$next] = true;
                        foreach ($combinedGyms as $gk => $gn) {
                            $involvedGyms[$gk] = $gn;
                        }
                    }
                }
            }
            if (!empty($involvedDays)) {
                ksort($involvedDays);
                $dayLabels = array_map(fn($d) => $shortDayNames[$d] ?? "Day $d", array_keys($involvedDays));
                $gymList = implode(' & ', array_unique(array_values($involvedGyms)));
                $warnings[] = [
                    'type' => 'recovery',
                    'muscle' => $muscle,
                    'message' => sprintf(
                        '%s is trained consecutively (%s) across %s. Allow 48h recovery between sessions.',
                        ucfirst($muscle),
                        implode(', ', $dayLabels),
                        $gymList
                    ),
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('detect_workload_warnings error: ' . $e->getMessage());
    }

    return $warnings;
}

/**
 * Ensure dietary_plans supports 'archived' status and run one-time orphan plan sweep safely outside transactions.
 */
function ensure_archived_plan_support(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $lockFile = __DIR__ . '/../storage/cache/.archived_plan_schema_v1.lock';
    if (file_exists($lockFile)) {
        return;
    }

    try {
        $pdo = db();
        if ($pdo->inTransaction()) {
            return;
        }
        $col = query_one("SHOW COLUMNS FROM dietary_plans LIKE 'status'");
        $type = strtolower((string)($col['Type'] ?? $col['type'] ?? ''));
        if ($type !== '' && !str_contains($type, "'archived'")) {
            $pdo->exec("ALTER TABLE dietary_plans MODIFY COLUMN status ENUM('active','completed','cancelled','draft','upcoming','paused','archived') NOT NULL DEFAULT 'draft'");
        }
        archive_defunct_plans();
        if (is_dir(__DIR__ . '/../storage/cache')) {
            @file_put_contents($lockFile, '1');
        }
    } catch (Throwable $e) {
        error_log('ensure_archived_plan_support notice: ' . $e->getMessage());
    }
}

/**
 * Automatically archive active/paused plans associated with defunct gyms, rejected gyms, or deleted trainers/owners.
 */
function archive_defunct_plans(?int $gymId = null, ?int $userId = null): int
{
    $pdo = db();
    $archivedCount = 0;

    // 1. If gymId is given, archive all plans for that gym
    if ($gymId !== null && $gymId > 0) {
        try {
            $stmt1 = $pdo->prepare("UPDATE training_plans SET status = 'archived' WHERE gym_id = ? AND status IN ('active', 'paused', 'upcoming')");
            $stmt1->execute([$gymId]);
            $archivedCount += (int)$stmt1->rowCount();
        } catch (Throwable $e) {
            error_log('archive_defunct_plans workout gym error: ' . $e->getMessage());
        }

        try {
            $stmt2 = $pdo->prepare("UPDATE dietary_plans SET status = 'archived', is_preferred = 0 WHERE gym_id = ? AND status IN ('active', 'paused', 'upcoming')");
            $stmt2->execute([$gymId]);
            $archivedCount += (int)$stmt2->rowCount();
        } catch (Throwable $e) {
            try {
                $stmtFallback = $pdo->prepare("UPDATE dietary_plans SET status = 'cancelled', is_preferred = 0 WHERE gym_id = ? AND status IN ('active', 'paused', 'upcoming')");
                $stmtFallback->execute([$gymId]);
                $archivedCount += (int)$stmtFallback->rowCount();
            } catch (Throwable) {}
        }
        return $archivedCount;
    }

    // 2. If userId is given (deleted gym owner or trainer)
    if ($userId !== null && $userId > 0) {
        try {
            $trainerId = (int) scalar('SELECT trainer_id FROM trainer_profiles WHERE user_id = ?', [$userId]);
            if ($trainerId > 0) {
                try {
                    $stmt1 = $pdo->prepare("UPDATE training_plans SET status = 'archived' WHERE trainer_id = ? AND status IN ('active', 'paused', 'upcoming')");
                    $stmt1->execute([$trainerId]);
                    $archivedCount += (int)$stmt1->rowCount();
                } catch (Throwable) {}

                try {
                    $stmt2 = $pdo->prepare("UPDATE dietary_plans SET status = 'archived', is_preferred = 0 WHERE trainer_id = ? AND status IN ('active', 'paused', 'upcoming')");
                    $stmt2->execute([$trainerId]);
                    $archivedCount += (int)$stmt2->rowCount();
                } catch (Throwable) {
                    try {
                        $stmtFallback = $pdo->prepare("UPDATE dietary_plans SET status = 'cancelled', is_preferred = 0 WHERE trainer_id = ? AND status IN ('active', 'paused', 'upcoming')");
                        $stmtFallback->execute([$trainerId]);
                        $archivedCount += (int)$stmtFallback->rowCount();
                    } catch (Throwable) {}
                }
            }

            $ownedGymIds = $pdo->prepare('SELECT gym_id FROM gyms WHERE owner_user_id = ?');
            $ownedGymIds->execute([$userId]);
            $gIds = $ownedGymIds->fetchAll(PDO::FETCH_COLUMN);
            foreach ($gIds as $gId) {
                $archivedCount += archive_defunct_plans((int)$gId);
            }
        } catch (Throwable $e) {
            error_log('archive_defunct_plans user error: ' . $e->getMessage());
        }
        return $archivedCount;
    }

    // 3. Global sweep (single-table UPDATE for MySQL 8.0 & TiDB Cloud compatibility)
    $defunctGymSubquery = "
        SELECT g.gym_id FROM gyms g
        LEFT JOIN users u_ow ON u_ow.user_id = g.owner_user_id
        WHERE g.status IN ('rejected', 'suspended')
           OR u_ow.status = 'inactive'
           OR u_ow.email LIKE 'deleted_%'
    ";
    $defunctTrainerSubquery = "
        SELECT tpr.trainer_id FROM trainer_profiles tpr
        JOIN users u_tr ON u_tr.user_id = tpr.user_id
        WHERE u_tr.status = 'inactive' OR u_tr.email LIKE 'deleted_%'
    ";

    try {
        $sqlWorkout = "
            UPDATE training_plans
            SET status = 'archived'
            WHERE status IN ('active', 'paused', 'upcoming')
              AND (gym_id IN ($defunctGymSubquery) OR trainer_id IN ($defunctTrainerSubquery))
        ";
        $archivedCount += (int)$pdo->exec($sqlWorkout);
    } catch (Throwable $e) {
        error_log('archive_defunct_plans global workout error: ' . $e->getMessage());
    }

    try {
        $sqlDiet = "
            UPDATE dietary_plans
            SET status = 'archived', is_preferred = 0
            WHERE status IN ('active', 'paused', 'upcoming')
              AND (gym_id IN ($defunctGymSubquery) OR trainer_id IN ($defunctTrainerSubquery))
        ";
        $archivedCount += (int)$pdo->exec($sqlDiet);
    } catch (Throwable $e) {
        error_log('archive_defunct_plans global diet error: ' . $e->getMessage());
    }

    return $archivedCount;
}
