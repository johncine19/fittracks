<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

$pdo = db();
$check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_profiles' AND COLUMN_NAME = 'trainer_assessment_notes'");
$check->execute();

if ((int)$check->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE member_profiles ADD COLUMN trainer_assessment_notes TEXT DEFAULT NULL AFTER preferred_duration_mins");
    echo "Added member_profiles.trainer_assessment_notes.\n";
} else {
    echo "member_profiles.trainer_assessment_notes already exists.\n";
}
