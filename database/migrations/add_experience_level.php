<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

$pdo = db();
$check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_profiles' AND COLUMN_NAME = 'experience_level'");
$check->execute();

if ((int)$check->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE member_profiles ADD COLUMN experience_level TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER preferred_duration_mins");
    echo "Added member_profiles.experience_level.\n";

    // Preserve any existing experience selection that was previously stored in fitness_tier
    $pdo->exec("
        UPDATE member_profiles
        SET experience_level = CASE
            WHEN fitness_tier >= 5 THEN 3
            WHEN fitness_tier >= 3 THEN 2
            ELSE 1
        END
    ");
    echo "Backfilled experience_level from fitness_tier.\n";

    // Recompute fitness_tier strictly from actual exercise_completions counts
    $pdo->exec("
        UPDATE member_profiles mp
        LEFT JOIN (
            SELECT user_id, COUNT(*) AS completed_cnt
            FROM exercise_completions
            GROUP BY user_id
        ) ec ON ec.user_id = mp.user_id
        SET mp.fitness_tier = CASE
            WHEN COALESCE(ec.completed_cnt, 0) >= 350 THEN 6
            WHEN COALESCE(ec.completed_cnt, 0) >= 175 THEN 5
            WHEN COALESCE(ec.completed_cnt, 0) >= 75  THEN 4
            WHEN COALESCE(ec.completed_cnt, 0) >= 25  THEN 3
            WHEN COALESCE(ec.completed_cnt, 0) >= 5   THEN 2
            ELSE 1
        END
    ");
    echo "Recalculated fitness_tier from exercise_completions.\n";
} else {
    echo "member_profiles.experience_level already exists.\n";
}
