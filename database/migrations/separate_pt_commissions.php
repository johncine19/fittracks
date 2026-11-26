<?php
declare(strict_types=1);

/**
 * Migration: Separate Personal Training (PT) Packages & Payments from Membership Plans.
 *
 * - Creates `trainer_packages` (session packs & monthly coaching bundles)
 * - Creates `pt_payments` (dedicated PT billing table)
 * - Decouples `trainer_commissions` (nullable payment_id, adds source_type, pt_payment_id, assignment_id)
 * - Links `trainer_assignments` to optional package_id, pt_payment_id, payment_status
 *
 * Safe & idempotent on both MySQL 8 (XAMPP) and TiDB.
 */
function migrate_separate_pt_commissions(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();

    // 1. Create trainer_packages
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `trainer_packages` (
            `package_id` int UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `gym_id` int UNSIGNED NOT NULL,
            `trainer_id` int UNSIGNED DEFAULT NULL COMMENT 'NULL = any trainer at gym; set = trainer-specific package',
            `package_name` varchar(120) NOT NULL,
            `package_type` enum('session_pack','monthly_coaching') NOT NULL DEFAULT 'session_pack',
            `session_count` int UNSIGNED DEFAULT NULL COMMENT 'Total sessions for session_pack',
            `duration_days` int UNSIGNED DEFAULT 30 COMMENT 'Validity window in days',
            `price` decimal(10,2) NOT NULL DEFAULT 0.00,
            `commission_rate` decimal(5,2) DEFAULT NULL COMMENT 'Package default trainer commission % (NULL = fallback to trainer/gym rate)',
            `description` text DEFAULT NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_tp_gym` (`gym_id`),
            KEY `idx_tp_trainer` (`trainer_id`),
            CONSTRAINT `fk_tp_gym` FOREIGN KEY (`gym_id`) REFERENCES `gyms` (`gym_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_tp_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `trainer_profiles` (`trainer_id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 2. Create pt_payments
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `pt_payments` (
            `pt_payment_id` int UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `gym_id` int UNSIGNED NOT NULL,
            `member_user_id` int UNSIGNED NOT NULL,
            `trainer_id` int UNSIGNED NOT NULL COMMENT 'References trainer_profiles.trainer_id',
            `assignment_id` int UNSIGNED DEFAULT NULL,
            `package_id` int UNSIGNED DEFAULT NULL COMMENT 'NULL for single per-session fee',
            `payment_type` enum('pt_session','pt_package') NOT NULL DEFAULT 'pt_session',
            `sessions_purchased` int UNSIGNED NOT NULL DEFAULT 1,
            `sessions_remaining` int UNSIGNED NOT NULL DEFAULT 1,
            `amount` decimal(10,2) NOT NULL,
            `payment_date` date NOT NULL,
            `payment_method` varchar(40) NOT NULL DEFAULT 'cash',
            `status` enum('pending','paid','refunded','cancelled') NOT NULL DEFAULT 'pending',
            `receipt_number` varchar(80) DEFAULT NULL,
            `xendit_reference_id` varchar(120) DEFAULT NULL,
            `xendit_invoice_id` varchar(120) DEFAULT NULL,
            `processed_by` int UNSIGNED DEFAULT NULL,
            `notes` varchar(255) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_ptp_gym` (`gym_id`),
            KEY `idx_ptp_member` (`member_user_id`),
            KEY `idx_ptp_trainer` (`trainer_id`),
            KEY `idx_ptp_assignment` (`assignment_id`),
            KEY `idx_ptp_status` (`status`),
            UNIQUE KEY `uq_ptp_xendit_ref` (`xendit_reference_id`),
            CONSTRAINT `fk_ptp_gym` FOREIGN KEY (`gym_id`) REFERENCES `gyms` (`gym_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_ptp_member` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_ptp_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `trainer_profiles` (`trainer_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_ptp_package` FOREIGN KEY (`package_id`) REFERENCES `trainer_packages` (`package_id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $colInfo = static function (PDO $db, string $table, string $col): ?array {
        $stmt = $db->prepare(
            "SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
        );
        $stmt->execute([$table, $col]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    };

    $hasIndex = static function (PDO $db, string $table, string $idx): bool {
        $stmt = $db->prepare(
            "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1"
        );
        $stmt->execute([$table, $idx]);
        return (bool)$stmt->fetchColumn();
    };

    $hasFk = static function (PDO $db, string $table, string $fk): bool {
        $stmt = $db->prepare(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1"
        );
        $stmt->execute([$table, $fk]);
        return (bool)$stmt->fetchColumn();
    };

    // 3. Decouple trainer_commissions
    $payIdCol = $colInfo($pdo, 'trainer_commissions', 'payment_id');
    if ($payIdCol && strtoupper((string)$payIdCol['IS_NULLABLE']) !== 'YES') {
        $hadFk2 = $hasFk($pdo, 'trainer_commissions', 'trainer_commissions_ibfk_2');
        if ($hadFk2) {
            $pdo->exec("ALTER TABLE `trainer_commissions` DROP FOREIGN KEY `trainer_commissions_ibfk_2`");
        }
        $pdo->exec("ALTER TABLE `trainer_commissions` MODIFY COLUMN `payment_id` int UNSIGNED DEFAULT NULL");
        if ($hadFk2) {
            $pdo->exec("ALTER TABLE `trainer_commissions` ADD CONSTRAINT `trainer_commissions_ibfk_2` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE CASCADE");
        }
    }

    if (!$colInfo($pdo, 'trainer_commissions', 'source_type')) {
        $pdo->exec("ALTER TABLE `trainer_commissions` ADD COLUMN `source_type` enum('pt_session','pt_package','membership') NOT NULL DEFAULT 'pt_session' AFTER `payment_id`");
        $pdo->exec("UPDATE `trainer_commissions` SET `source_type` = 'membership' WHERE `payment_id` IS NOT NULL");
    }

    if (!$colInfo($pdo, 'trainer_commissions', 'pt_payment_id')) {
        $pdo->exec("ALTER TABLE `trainer_commissions` ADD COLUMN `pt_payment_id` int UNSIGNED DEFAULT NULL AFTER `source_type`");
    }

    if (!$colInfo($pdo, 'trainer_commissions', 'assignment_id')) {
        $pdo->exec("ALTER TABLE `trainer_commissions` ADD COLUMN `assignment_id` int UNSIGNED DEFAULT NULL AFTER `pt_payment_id`");
    }

    if (!$hasIndex($pdo, 'trainer_commissions', 'idx_tc_pt_payment')) {
        $pdo->exec("ALTER TABLE `trainer_commissions` ADD KEY `idx_tc_pt_payment` (`pt_payment_id`)");
    }

    if (!$hasIndex($pdo, 'trainer_commissions', 'idx_tc_assignment')) {
        $pdo->exec("ALTER TABLE `trainer_commissions` ADD KEY `idx_tc_assignment` (`assignment_id`)");
    }

    if (!$hasFk($pdo, 'trainer_commissions', 'fk_tc_pt_payment')) {
        try {
            $pdo->exec("ALTER TABLE `trainer_commissions` ADD CONSTRAINT `fk_tc_pt_payment` FOREIGN KEY (`pt_payment_id`) REFERENCES `pt_payments` (`pt_payment_id`) ON DELETE CASCADE");
        } catch (Throwable) {
            // Non-fatal if FK already exists under another name
        }
    }

    // 4. Link trainer_assignments to PT package/payment tracking
    if (!$colInfo($pdo, 'trainer_assignments', 'package_id')) {
        $pdo->exec("ALTER TABLE `trainer_assignments` ADD COLUMN `package_id` int UNSIGNED DEFAULT NULL AFTER `trainer_id`");
    }

    if (!$colInfo($pdo, 'trainer_assignments', 'pt_payment_id')) {
        $pdo->exec("ALTER TABLE `trainer_assignments` ADD COLUMN `pt_payment_id` int UNSIGNED DEFAULT NULL AFTER `package_id`");
    }

    if (!$colInfo($pdo, 'trainer_assignments', 'payment_status')) {
        $pdo->exec("ALTER TABLE `trainer_assignments` ADD COLUMN `payment_status` enum('unpaid','paid','complimentary') NOT NULL DEFAULT 'unpaid' AFTER `status`");
    }

    if (!$hasIndex($pdo, 'trainer_assignments', 'idx_ta_pt_payment')) {
        $pdo->exec("ALTER TABLE `trainer_assignments` ADD KEY `idx_ta_pt_payment` (`pt_payment_id`)");
    }
}

// Allow direct CLI execution: php database/migrations/separate_pt_commissions.php
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require_once __DIR__ . '/../../core/bootstrap.php';
    migrate_separate_pt_commissions(db());
    fwrite(STDOUT, "Migration separate_pt_commissions completed successfully.\n");
}
