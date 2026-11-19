<?php
declare(strict_types=1);

function complete_exercise_action(): void
{
    $user = require_roles(['member']);
    verify_csrf();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo = db();
        $planId = (int) post('plan_id');
        $exerciseId = (int) post('exercise_id');
        $weightRaw = trim((string) post('weight_lifted_kg'));
        $weightLifted = ($weightRaw !== '' && is_numeric($weightRaw)) ? (float) $weightRaw : null;
        if ($weightLifted !== null && ($weightLifted <= 0 || $weightLifted > 500)) {
            $weightLifted = null;
        }

        $setWeightsJson = null;
        $setWeightsRaw = trim((string) post('set_weights_json'));
        if ($setWeightsRaw !== '') {
            $decoded = json_decode($setWeightsRaw, true);
            if (is_array($decoded)) {
                $sanitized = [];
                $positiveWeights = [];
                foreach ($decoded as $w) {
                    if ($w !== null && $w !== '' && is_numeric($w)) {
                        $val = (float) $w;
                        if ($val > 0 && $val <= 500) {
                            $sanitized[] = $val;
                            $positiveWeights[] = $val;
                            continue;
                        }
                    }
                    $sanitized[] = null;
                }
                if (!empty($positiveWeights)) {
                    $setWeightsJson = json_encode($sanitized);
                    if ($weightLifted === null) {
                        $weightLifted = max($positiveWeights);
                    }
                }
            }
        }

        $userId = (int) $user['user_id'];
        $date = date('Y-m-d');

        try {
            $colCheck = $pdo->query("SHOW COLUMNS FROM exercise_completions LIKE 'weight_lifted_kg'")->fetch();
            if (!$colCheck) {
                $pdo->exec('ALTER TABLE exercise_completions ADD COLUMN weight_lifted_kg DECIMAL(6,2) DEFAULT NULL');
            }
            $setColCheck = $pdo->query("SHOW COLUMNS FROM exercise_completions LIKE 'set_weights_json'")->fetch();
            if (!$setColCheck) {
                $pdo->exec('ALTER TABLE exercise_completions ADD COLUMN set_weights_json TEXT DEFAULT NULL');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO exercise_completions (user_id, plan_id, exercise_id, completed_date, weight_lifted_kg, set_weights_json)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     weight_lifted_kg = COALESCE(VALUES(weight_lifted_kg), weight_lifted_kg),
                     set_weights_json = COALESCE(VALUES(set_weights_json), set_weights_json)'
            );
            $stmt->execute([$userId, $planId, $exerciseId, $date, $weightLifted, $setWeightsJson]);

            $newPr = false;
            if ($weightLifted !== null) {
                $exName = (string) (scalar('SELECT name FROM exercises WHERE exercise_id = ? LIMIT 1', [$exerciseId]) ?: 'Strength Lift');
                $goalRow = query_one("SELECT goal_id, current_value FROM member_goals WHERE user_id = ? AND goal_type = 'strength' LIMIT 1", [$userId]);
                if ($goalRow) {
                    $prevMax = $goalRow['current_value'] !== null ? (float) $goalRow['current_value'] : 0.0;
                    if ($weightLifted > $prevMax) {
                        $newPr = true;
                        $pdo->prepare("UPDATE member_goals SET current_value = ? WHERE goal_id = ?")
                            ->execute([$weightLifted, (int) $goalRow['goal_id']]);
                    }
                } else {
                    $newPr = true;
                    $pdo->prepare("INSERT INTO member_goals (user_id, goal_type, current_value, target_value, unit) VALUES (?, 'strength', ?, NULL, 'kg')")
                        ->execute([$userId, $weightLifted]);
                }

                // Update any matching multi-exercise strength targets
                foreach (get_member_strength_targets($userId) as $st) {
                    $stEx = trim((string)($st['exercise_name'] ?? ''));
                    if ($stEx === '') {
                        continue;
                    }
                    $matches = (strcasecmp($stEx, $exName) === 0 || str_contains(strtolower($exName), strtolower($stEx)) || str_contains(strtolower($stEx), strtolower($exName)));
                    $prevStMax = $st['current_max_kg'] !== null ? (float) $st['current_max_kg'] : 0.0;
                    if ($matches && $weightLifted > $prevStMax) {
                        $newPr = true;
                        $pdo->prepare("UPDATE member_strength_targets SET current_max_kg = ? WHERE id = ? AND user_id = ?")
                            ->execute([$weightLifted, (int) $st['id'], $userId]);
                    }
                }
            }

            $tierInfo = check_and_upgrade_tier($userId, $planId);

            // Invalidate sidebar badges cache so nav status updates immediately
            foreach ($_SESSION as $k => $v) {
                if (str_starts_with((string)$k, 'sidebar_badges_' . $userId)) {
                    unset($_SESSION[$k]);
                }
            }

            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'tier_upgraded' => $tierInfo, 'new_pr' => $newPr]);
            exit;
        } catch (Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }
}
