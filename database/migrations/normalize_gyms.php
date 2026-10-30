<?php
declare(strict_types=1);

/**
 * Phase 3 Migration: Normalize gyms table (44 columns -> 22 core columns)
 *
 * Splits out:
 * 1. gym_verification_documents (document_id, gym_id, document_type, file_url, id_type_id, custom_id_type_label, created_at, updated_at)
 *    - Replaces permit_url, id_url, business_permit_url, valid_id_url, valid_id_type,
 *      secondary_valid_id_url, secondary_valid_id_type, barangay_clearance_url, fire_safety_cert_url
 *    - Links id_type_id to gym_verification_id_types(id)
 * 2. gym_payout_accounts (gym_id, xendit_subaccount_id, payout_channel, payout_account_number, payout_account_name, updated_at)
 *    - Replaces xendit_subaccount_id, payout_channel, payout_account_number, payout_account_name
 * 3. gym_settings (gym_id, inactivity_threshold_days, inactivity_cooldown_days, auto_inactivity_alerts,
 *      staff_hide_financials, eod_email_summary, last_eod_email_sent_at, last_eod_email_sent_by,
 *      eod_gate_time, pending_eod_gate_time, eod_gate_effective_date, updated_at)
 *    - Replaces operational/EOD/inactivity settings columns on gyms
 * 4. Adds subscription_plan_id (FK -> platform_subscription_plans.id) on gyms and plan_id on gym_subscription_payments.
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/../../core/bootstrap.php';
}

function run_normalize_gyms_migration(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();

    // =========================================================================
    // 1. Create Normalized Tables
    // =========================================================================
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gym_verification_documents (
            document_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            gym_id INT UNSIGNED NOT NULL,
            document_type ENUM('business_permit', 'valid_id', 'secondary_valid_id', 'barangay_clearance', 'fire_safety_cert') NOT NULL,
            file_url VARCHAR(255) NOT NULL,
            id_type_id INT DEFAULT NULL,
            custom_id_type_label VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (document_id),
            UNIQUE KEY uq_gym_doc_type (gym_id, document_type),
            KEY idx_gvd_id_type (id_type_id),
            CONSTRAINT fk_gvd_gym FOREIGN KEY (gym_id) REFERENCES gyms (gym_id) ON DELETE CASCADE,
            CONSTRAINT fk_gvd_id_type FOREIGN KEY (id_type_id) REFERENCES gym_verification_id_types (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gym_payout_accounts (
            gym_id INT UNSIGNED NOT NULL,
            xendit_subaccount_id VARCHAR(64) DEFAULT NULL,
            payout_channel VARCHAR(30) NOT NULL DEFAULT 'gcash',
            payout_account_number VARCHAR(50) DEFAULT NULL,
            payout_account_name VARCHAR(100) DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (gym_id),
            CONSTRAINT fk_gpa_gym FOREIGN KEY (gym_id) REFERENCES gyms (gym_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gym_settings (
            gym_id INT UNSIGNED NOT NULL,
            inactivity_threshold_days INT UNSIGNED DEFAULT NULL,
            inactivity_cooldown_days INT UNSIGNED DEFAULT NULL,
            auto_inactivity_alerts TINYINT(1) NOT NULL DEFAULT 1,
            staff_hide_financials TINYINT(1) NOT NULL DEFAULT 0,
            eod_email_summary TINYINT(1) NOT NULL DEFAULT 1,
            last_eod_email_sent_at TIMESTAMP NULL DEFAULT NULL,
            last_eod_email_sent_by INT UNSIGNED DEFAULT NULL,
            eod_gate_time TIME NOT NULL DEFAULT '17:00:00',
            pending_eod_gate_time TIME DEFAULT NULL,
            eod_gate_effective_date DATE DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (gym_id),
            CONSTRAINT fk_gs_gym FOREIGN KEY (gym_id) REFERENCES gyms (gym_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    // =========================================================================
    // 2. Build Lookup Maps for gym_verification_id_types & platform_subscription_plans
    // =========================================================================
    $idTypesByName = [];
    $idTypesByCode = [];
    $otherIdTypeId = null;
    try {
        $idRows = $pdo->query('SELECT id, id_code, id_name FROM gym_verification_id_types')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($idRows as $ir) {
            $idTypesByName[strtolower(trim((string)$ir['id_name']))] = (int)$ir['id'];
            $idTypesByCode[strtolower(trim((string)$ir['id_code']))] = (int)$ir['id'];
            if (strtolower((string)$ir['id_code']) === 'other') {
                $otherIdTypeId = (int)$ir['id'];
            }
        }
    } catch (Throwable) {}

    $resolveIdType = static function (?string $rawLabel) use ($idTypesByName, $idTypesByCode, $otherIdTypeId): array {
        $label = trim((string)$rawLabel);
        if ($label === '') {
            return [null, null];
        }
        $lower = strtolower($label);
        if (isset($idTypesByName[$lower])) {
            return [$idTypesByName[$lower], null];
        }
        if (isset($idTypesByCode[$lower])) {
            return [$idTypesByCode[$lower], null];
        }
        return [$otherIdTypeId, $label];
    };

    // =========================================================================
    // 3. Migrate Data from gyms to Child Tables (if legacy columns still exist)
    // =========================================================================
    $gymCols = array_column($pdo->query('SHOW COLUMNS FROM gyms')->fetchAll(PDO::FETCH_ASSOC), 'Field');

    $allGyms = $pdo->query('SELECT * FROM gyms')->fetchAll(PDO::FETCH_ASSOC);

    $insDoc = $pdo->prepare('
        INSERT INTO gym_verification_documents (gym_id, document_type, file_url, id_type_id, custom_id_type_label)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            file_url = VALUES(file_url),
            id_type_id = COALESCE(VALUES(id_type_id), id_type_id),
            custom_id_type_label = COALESCE(VALUES(custom_id_type_label), custom_id_type_label)
    ');

    $insPayout = $pdo->prepare('
        INSERT INTO gym_payout_accounts (gym_id, xendit_subaccount_id, payout_channel, payout_account_number, payout_account_name)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            xendit_subaccount_id = COALESCE(VALUES(xendit_subaccount_id), xendit_subaccount_id),
            payout_channel = COALESCE(VALUES(payout_channel), payout_channel),
            payout_account_number = COALESCE(VALUES(payout_account_number), payout_account_number),
            payout_account_name = COALESCE(VALUES(payout_account_name), payout_account_name)
    ');

    $insSettings = $pdo->prepare('
        INSERT INTO gym_settings (
            gym_id, inactivity_threshold_days, inactivity_cooldown_days, auto_inactivity_alerts,
            staff_hide_financials, eod_email_summary, last_eod_email_sent_at, last_eod_email_sent_by,
            eod_gate_time, pending_eod_gate_time, eod_gate_effective_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            inactivity_threshold_days = COALESCE(VALUES(inactivity_threshold_days), inactivity_threshold_days),
            inactivity_cooldown_days = COALESCE(VALUES(inactivity_cooldown_days), inactivity_cooldown_days),
            auto_inactivity_alerts = VALUES(auto_inactivity_alerts),
            staff_hide_financials = VALUES(staff_hide_financials),
            eod_email_summary = VALUES(eod_email_summary),
            last_eod_email_sent_at = COALESCE(VALUES(last_eod_email_sent_at), last_eod_email_sent_at),
            last_eod_email_sent_by = COALESCE(VALUES(last_eod_email_sent_by), last_eod_email_sent_by),
            eod_gate_time = VALUES(eod_gate_time),
            pending_eod_gate_time = COALESCE(VALUES(pending_eod_gate_time), pending_eod_gate_time),
            eod_gate_effective_date = COALESCE(VALUES(eod_gate_effective_date), eod_gate_effective_date)
    ');

    foreach ($allGyms as $g) {
        $gid = (int)$g['gym_id'];

        // 3a. Verification documents
        $permitUrl = trim((string)($g['business_permit_url'] ?? $g['permit_url'] ?? ''));
        if ($permitUrl !== '') {
            $insDoc->execute([$gid, 'business_permit', $permitUrl, null, null]);
        }

        $validIdUrl = trim((string)($g['valid_id_url'] ?? $g['id_url'] ?? ''));
        if ($validIdUrl !== '') {
            [$idTypeId, $customLabel] = $resolveIdType($g['valid_id_type'] ?? null);
            $insDoc->execute([$gid, 'valid_id', $validIdUrl, $idTypeId, $customLabel]);
        }

        $secIdUrl = trim((string)($g['secondary_valid_id_url'] ?? ''));
        if ($secIdUrl !== '') {
            [$secIdTypeId, $secCustomLabel] = $resolveIdType($g['secondary_valid_id_type'] ?? null);
            $insDoc->execute([$gid, 'secondary_valid_id', $secIdUrl, $secIdTypeId, $secCustomLabel]);
        }

        $brgyUrl = trim((string)($g['barangay_clearance_url'] ?? ''));
        if ($brgyUrl !== '') {
            $insDoc->execute([$gid, 'barangay_clearance', $brgyUrl, null, null]);
        }

        $fireUrl = trim((string)($g['fire_safety_cert_url'] ?? ''));
        if ($fireUrl !== '') {
            $insDoc->execute([$gid, 'fire_safety_cert', $fireUrl, null, null]);
        }

        // 3b. Payout accounts
        if (in_array('payout_channel', $gymCols, true) || in_array('xendit_subaccount_id', $gymCols, true)) {
            $xenditSub = isset($g['xendit_subaccount_id']) && trim((string)$g['xendit_subaccount_id']) !== '' ? trim((string)$g['xendit_subaccount_id']) : null;
            $pChan = isset($g['payout_channel']) && trim((string)$g['payout_channel']) !== '' ? trim((string)$g['payout_channel']) : 'gcash';
            $pNum = isset($g['payout_account_number']) && trim((string)$g['payout_account_number']) !== '' ? trim((string)$g['payout_account_number']) : null;
            $pName = isset($g['payout_account_name']) && trim((string)$g['payout_account_name']) !== '' ? trim((string)$g['payout_account_name']) : null;
            $insPayout->execute([$gid, $xenditSub, $pChan, $pNum, $pName]);
        }

        // 3c. Gym settings
        if (in_array('eod_gate_time', $gymCols, true) || in_array('auto_inactivity_alerts', $gymCols, true)) {
            $inactThresh = isset($g['inactivity_threshold_days']) && $g['inactivity_threshold_days'] !== null ? (int)$g['inactivity_threshold_days'] : null;
            $inactCool = isset($g['inactivity_cooldown_days']) && $g['inactivity_cooldown_days'] !== null ? (int)$g['inactivity_cooldown_days'] : null;
            $autoAlerts = isset($g['auto_inactivity_alerts']) ? (int)$g['auto_inactivity_alerts'] : 1;
            $hideFin = isset($g['staff_hide_financials']) ? (int)$g['staff_hide_financials'] : 0;
            $eodSum = isset($g['eod_email_summary']) ? (int)$g['eod_email_summary'] : 1;
            $lastEodAt = $g['last_eod_email_sent_at'] ?? null;
            $lastEodBy = isset($g['last_eod_email_sent_by']) && $g['last_eod_email_sent_by'] !== null ? (int)$g['last_eod_email_sent_by'] : null;
            $gateTime = !empty($g['eod_gate_time']) ? (string)$g['eod_gate_time'] : '17:00:00';
            $pendGate = $g['pending_eod_gate_time'] ?? null;
            $pendDate = $g['eod_gate_effective_date'] ?? null;

            $insSettings->execute([
                $gid, $inactThresh, $inactCool, $autoAlerts,
                $hideFin, $eodSum, $lastEodAt, $lastEodBy,
                $gateTime, $pendGate, $pendDate
            ]);
        }
    }

    // =========================================================================
    // 4. Add Foreign Keys to platform_subscription_plans on gyms & gym_subscription_payments
    // =========================================================================
    if (!in_array('subscription_plan_id', $gymCols, true)) {
        $pdo->exec('ALTER TABLE gyms ADD COLUMN subscription_plan_id INT DEFAULT NULL AFTER subscription_plan');
        try {
            $pdo->exec('ALTER TABLE gyms ADD CONSTRAINT fk_gyms_sub_plan FOREIGN KEY (subscription_plan_id) REFERENCES platform_subscription_plans (id) ON DELETE SET NULL');
        } catch (Throwable) {}
    }

    $pdo->exec('
        UPDATE gyms g
        JOIN platform_subscription_plans p
          ON LOWER(p.name) = LOWER(g.subscription_plan) OR LOWER(p.plan_key) = LOWER(g.subscription_plan)
        SET g.subscription_plan_id = p.id
        WHERE g.subscription_plan_id IS NULL
    ');

    $gspCols = array_column($pdo->query('SHOW COLUMNS FROM gym_subscription_payments')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('plan_id', $gspCols, true)) {
        $pdo->exec('ALTER TABLE gym_subscription_payments ADD COLUMN plan_id INT DEFAULT NULL AFTER owner_user_id');
        try {
            $pdo->exec('ALTER TABLE gym_subscription_payments ADD CONSTRAINT fk_gsp_sub_plan FOREIGN KEY (plan_id) REFERENCES platform_subscription_plans (id) ON DELETE SET NULL');
        } catch (Throwable) {}
    }

    $pdo->exec('
        UPDATE gym_subscription_payments gsp
        JOIN platform_subscription_plans p
          ON LOWER(p.name) = LOWER(gsp.plan_name) OR LOWER(p.plan_key) = LOWER(gsp.plan_name)
        SET gsp.plan_id = p.id
        WHERE gsp.plan_id IS NULL
    ');

    // =========================================================================
    // 5. Drop the 23 Denormalized Columns from gyms
    // =========================================================================
    $columnsToDrop = [
        // Verification documents (9)
        'permit_url',
        'id_url',
        'business_permit_url',
        'valid_id_url',
        'valid_id_type',
        'secondary_valid_id_url',
        'secondary_valid_id_type',
        'barangay_clearance_url',
        'fire_safety_cert_url',
        // Payout accounts (4)
        'xendit_subaccount_id',
        'payout_channel',
        'payout_account_number',
        'payout_account_name',
        // Settings & EOD (10)
        'inactivity_threshold_days',
        'inactivity_cooldown_days',
        'auto_inactivity_alerts',
        'staff_hide_financials',
        'eod_email_summary',
        'last_eod_email_sent_at',
        'last_eod_email_sent_by',
        'eod_gate_time',
        'pending_eod_gate_time',
        'eod_gate_effective_date',
    ];

    $dropClauses = [];
    foreach ($columnsToDrop as $col) {
        if (in_array($col, $gymCols, true)) {
            $dropClauses[] = "DROP COLUMN `{$col}`";
        }
    }

    if (!empty($dropClauses)) {
        $pdo->exec('ALTER TABLE gyms ' . implode(', ', $dropClauses));
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    run_normalize_gyms_migration();
    echo "Phase 3 gyms normalization migration completed successfully.\n";
}

