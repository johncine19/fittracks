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
        $userId = (int) $user['user_id'];
        $date = date('Y-m-d');

        try {
            $stmt = $pdo->prepare('INSERT IGNORE INTO exercise_completions (user_id, plan_id, exercise_id, completed_date) VALUES (?, ?, ?, ?)');
            $stmt->execute([$userId, $planId, $exerciseId, $date]);

            $tierInfo = check_and_upgrade_tier($userId, $planId);

            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'tier_upgraded' => $tierInfo]);
            exit;
        } catch (Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }
}
