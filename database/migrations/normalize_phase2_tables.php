<?php
declare(strict_types=1);

/**
 * Phase 2 Migration: Normalize equipment_sessions, diet_rules, and platform_subscription_plans
 *
 * 1. equipment_sessions:
 *    - Creates equipment_session_partners (session_id, partner_user_id) to fix 1NF violation on partner_user_ids
 *    - Drops partner_user_ids and derived duration_seconds from equipment_sessions
 * 2. diet_rules:
 *    - Splits composite string macro_split into atomic numeric columns: protein_pct, carbs_pct, fat_pct
 *    - Drops macro_split from diet_rules
 * 3. platform_subscription_plans:
 *    - Creates plan_features (feature_id, plan_id, feature_text, display_order) to fix 1NF violation on features
 *    - Drops features from platform_subscription_plans
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/../../core/bootstrap.php';
}

function run_normalize_phase2_migration(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();

    // =========================================================================
    // 1. equipment_sessions -> equipment_session_partners & drop duration_seconds
    // =========================================================================
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS equipment_session_partners (
            session_id INT UNSIGNED NOT NULL,
            partner_user_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (session_id, partner_user_id),
            KEY idx_esp_partner (partner_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    $esCols = array_column($pdo->query('SHOW COLUMNS FROM equipment_sessions')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (in_array('partner_user_ids', $esCols, true)) {
        $sessionsWithPartners = $pdo->query("SELECT session_id, partner_user_ids FROM equipment_sessions WHERE partner_user_ids IS NOT NULL AND partner_user_ids != ''")->fetchAll(PDO::FETCH_ASSOC);
        $insPartner = $pdo->prepare('INSERT IGNORE INTO equipment_session_partners (session_id, partner_user_id) VALUES (?, ?)');
        foreach ($sessionsWithPartners as $sRow) {
            $sid = (int)$sRow['session_id'];
            $pids = array_filter(array_map('intval', explode(',', (string)$sRow['partner_user_ids'])), fn($id) => $id > 0);
            foreach (array_unique($pids) as $pid) {
                $insPartner->execute([$sid, $pid]);
            }
        }
    }

    $esDrop = [];
    if (in_array('partner_user_ids', $esCols, true)) {
        $esDrop[] = 'DROP COLUMN `partner_user_ids`';
    }
    if (in_array('duration_seconds', $esCols, true)) {
        $esDrop[] = 'DROP COLUMN `duration_seconds`';
    }
    if (!empty($esDrop)) {
        $pdo->exec('ALTER TABLE equipment_sessions ' . implode(', ', $esDrop));
    }

    // =========================================================================
    // 2. diet_rules -> protein_pct, carbs_pct, fat_pct
    // =========================================================================
    $drCols = array_column($pdo->query('SHOW COLUMNS FROM diet_rules')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('protein_pct', $drCols, true)) {
        $pdo->exec('ALTER TABLE diet_rules ADD COLUMN protein_pct TINYINT UNSIGNED NOT NULL DEFAULT 35 AFTER activity_level');
    }
    if (!in_array('carbs_pct', $drCols, true)) {
        $pdo->exec('ALTER TABLE diet_rules ADD COLUMN carbs_pct TINYINT UNSIGNED NOT NULL DEFAULT 35 AFTER protein_pct');
    }
    if (!in_array('fat_pct', $drCols, true)) {
        $pdo->exec('ALTER TABLE diet_rules ADD COLUMN fat_pct TINYINT UNSIGNED NOT NULL DEFAULT 30 AFTER carbs_pct');
    }

    if (in_array('macro_split', $drCols, true)) {
        $rules = $pdo->query('SELECT rule_id, macro_split FROM diet_rules')->fetchAll(PDO::FETCH_ASSOC);
        $updRule = $pdo->prepare('UPDATE diet_rules SET protein_pct = ?, carbs_pct = ?, fat_pct = ? WHERE rule_id = ?');
        foreach ($rules as $r) {
            $splitStr = (string)($r['macro_split'] ?? '');
            $pPct = 35;
            $cPct = 35;
            $fPct = 30;

            if (preg_match('/(\d+)%\s*Protein/i', $splitStr, $m)) {
                $pPct = (int)$m[1];
                if (preg_match('/(\d+)%\s*Carbs/i', $splitStr, $mc)) $cPct = (int)$mc[1];
                if (preg_match('/(\d+)%\s*Fat/i', $splitStr, $mf)) $fPct = (int)$mf[1];
            } elseif (preg_match_all('/(\d+)\s*%/', $splitStr, $matches) && count($matches[1]) >= 3) {
                $pPct = (int)$matches[1][0];
                $cPct = (int)$matches[1][1];
                $fPct = (int)$matches[1][2];
            }
            $updRule->execute([$pPct, $cPct, $fPct, (int)$r['rule_id']]);
        }
        $pdo->exec('ALTER TABLE diet_rules DROP COLUMN `macro_split`');
    }

    // =========================================================================
    // 3. platform_subscription_plans -> plan_features
    // =========================================================================
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS plan_features (
            feature_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            plan_id INT UNSIGNED NOT NULL,
            feature_text VARCHAR(255) NOT NULL,
            display_order INT UNSIGNED NOT NULL DEFAULT 0,
            KEY idx_pf_plan (plan_id, display_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    $pspCols = array_column($pdo->query('SHOW COLUMNS FROM platform_subscription_plans')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (in_array('features', $pspCols, true)) {
        $plans = $pdo->query('SELECT id, plan_key, features FROM platform_subscription_plans')->fetchAll(PDO::FETCH_ASSOC);
        $insFeat = $pdo->prepare('INSERT INTO plan_features (plan_id, feature_text, display_order) VALUES (?, ?, ?)');
        foreach ($plans as $pl) {
            $planId = (int)$pl['id'];
            $existingCount = (int)scalar('SELECT COUNT(*) FROM plan_features WHERE plan_id = ?', [$planId]);
            if ($existingCount === 0 && !empty($pl['features'])) {
                $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$pl['features']))));
                foreach ($lines as $idx => $lineText) {
                    $insFeat->execute([$planId, $lineText, ($idx + 1) * 10]);
                }
            }
        }
        $pdo->exec('ALTER TABLE platform_subscription_plans DROP COLUMN `features`');
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    run_normalize_phase2_migration();
    echo "Phase 2 normalization migration completed successfully.\n";
}

