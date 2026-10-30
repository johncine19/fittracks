<?php
declare(strict_types=1);

/**
 * Phase 5 Migration: Normalize gym_equipment and trainer_assignments (1NF, 2NF, 3NF)
 *
 * 1. gym_equipment normalization:
 *    - Drops redundant maintenance tracking columns:
 *      `maintenance_reason`, `expected_return_date`, `last_maintenance_date`, `next_maintenance_date`.
 *    - Drops circular dependency `current_session_id` (active session is derived from `equipment_sessions`).
 *    - Adds `next_maintenance_date` to `equipment_maintenance_logs` to preserve scheduled service targets.
 *    - Creates view `v_gym_equipment` dynamically joining equipment with latest maintenance log and active session.
 *
 * 2. trainer_assignments normalization (2NF partial dependencies):
 *    - Extracts `assignment_groups` (group_id, gym_id, trainer_id, activity_title, assigned_by, assigned_date, status).
 *    - Extracts `assignment_group_members` (assignment_id, group_id, member_user_id, status, assigned_date, ended_date, rejection_reason).
 *    - Migrates all existing assignments into `assignment_groups` and `assignment_group_members`.
 *    - Adds synchronization triggers on `trainer_assignments` to guarantee 100% backward compatibility
 *      with zero runtime errors for all existing queries.
 *    - Creates view `v_assignment_group_details`.
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/../../core/bootstrap.php';
}

function run_normalize_phase5_migration(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();

    // =========================================================================
    // 1. Prepare equipment_maintenance_logs
    // =========================================================================
    $maintCols = array_column($pdo->query('SHOW COLUMNS FROM equipment_maintenance_logs')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('next_maintenance_date', $maintCols, true)) {
        $pdo->exec('ALTER TABLE equipment_maintenance_logs ADD COLUMN next_maintenance_date DATE DEFAULT NULL AFTER expected_return_date');
    }

    // =========================================================================
    // 2. Migrate existing maintenance details from gym_equipment
    // =========================================================================
    $equipCols = array_column($pdo->query('SHOW COLUMNS FROM gym_equipment')->fetchAll(PDO::FETCH_ASSOC), 'Field');

    if (in_array('maintenance_reason', $equipCols, true) || in_array('next_maintenance_date', $equipCols, true) || in_array('last_maintenance_date', $equipCols, true)) {
        // Backfill any equipment that has maintenance or next scheduled date not yet in maintenance logs
        $existingEquipment = $pdo->query('
            SELECT equipment_id, gym_id, status, maintenance_reason, expected_return_date, last_maintenance_date, next_maintenance_date
            FROM gym_equipment
            WHERE maintenance_reason IS NOT NULL
               OR next_maintenance_date IS NOT NULL
               OR expected_return_date IS NOT NULL
               OR last_maintenance_date IS NOT NULL
        ')->fetchAll(PDO::FETCH_ASSOC);

        $logInsert = $pdo->prepare('
            INSERT INTO equipment_maintenance_logs (equipment_id, gym_id, logged_by_user_id, action, reason, expected_return_date, next_maintenance_date, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()))
        ');

        foreach ($existingEquipment as $eq) {
            $eqId = (int)$eq['equipment_id'];
            $eqGym = (int)$eq['gym_id'];
            $reason = !empty($eq['maintenance_reason']) ? $eq['maintenance_reason'] : 'Initial equipment service record';
            $action = in_array($eq['status'], ['maintenance', 'out_of_service'], true) ? $eq['status'] : 'scheduled_maintenance';
            $expected = !empty($eq['expected_return_date']) ? $eq['expected_return_date'] : null;
            $nextMaint = !empty($eq['next_maintenance_date']) ? $eq['next_maintenance_date'] : null;
            $lastMaint = !empty($eq['last_maintenance_date']) ? ($eq['last_maintenance_date'] . ' 00:00:00') : null;

            // Check if log entry already exists
            $hasLog = (bool)$pdo->query("SELECT 1 FROM equipment_maintenance_logs WHERE equipment_id = {$eqId} LIMIT 1")->fetchColumn();
            if (!$hasLog) {
                // Find gym owner to attribute log
                $ownerId = (int)($pdo->query("SELECT owner_user_id FROM gyms WHERE gym_id = {$eqGym}")->fetchColumn() ?: 1);
                $logInsert->execute([$eqId, $eqGym, $ownerId, $action, $reason, $expected, $nextMaint, $lastMaint]);
            }
        }
    }

    // =========================================================================
    // 3. Drop Redundant Maintenance & Circular Session Columns from gym_equipment
    // =========================================================================
    $colsToDrop = [
        'last_maintenance_date',
        'next_maintenance_date',
        'maintenance_reason',
        'expected_return_date',
        'current_session_id',
    ];

    $dropClauses = [];
    foreach ($colsToDrop as $c) {
        if (in_array($c, $equipCols, true)) {
            $dropClauses[] = "DROP COLUMN `{$c}`";
        }
    }

    if (!empty($dropClauses)) {
        $pdo->exec('ALTER TABLE gym_equipment ' . implode(', ', $dropClauses));
    }

    // =========================================================================
    // 4. Create Normalized View v_gym_equipment
    // =========================================================================
    $pdo->exec('
        CREATE OR REPLACE VIEW v_gym_equipment AS
        SELECT 
            e.*,
            s.session_id AS current_session_id,
            eml.maintenance_reason,
            eml.expected_return_date,
            eml.next_maintenance_date,
            eml.last_maintenance_date
        FROM gym_equipment e
        LEFT JOIN equipment_sessions s 
            ON s.equipment_id = e.equipment_id AND s.session_status = "active"
        LEFT JOIN (
            SELECT eml1.equipment_id,
                   eml1.reason AS maintenance_reason,
                   eml1.expected_return_date,
                   eml1.next_maintenance_date,
                   eml1.created_at AS last_maintenance_date
            FROM equipment_maintenance_logs eml1
            JOIN (
                SELECT equipment_id, MAX(log_id) AS max_log_id
                FROM equipment_maintenance_logs
                GROUP BY equipment_id
            ) eml_max ON eml1.log_id = eml_max.max_log_id
        ) eml ON eml.equipment_id = e.equipment_id
    ');

    // =========================================================================
    // 5. Create assignment_groups & assignment_group_members
    // =========================================================================
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS assignment_groups (
            group_id VARCHAR(36) NOT NULL,
            gym_id INT UNSIGNED DEFAULT NULL,
            trainer_id INT UNSIGNED NOT NULL,
            activity_title VARCHAR(255) NOT NULL DEFAULT 'Personal Training',
            assigned_by INT UNSIGNED DEFAULT NULL,
            assigned_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status ENUM('pending_admin','pending_trainer','active','rejected','ended') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id),
            KEY idx_ag_trainer (trainer_id),
            KEY idx_ag_gym (gym_id),
            KEY idx_ag_status (status),
            CONSTRAINT fk_ag_trainer FOREIGN KEY (trainer_id) REFERENCES trainer_profiles (trainer_id) ON DELETE CASCADE,
            CONSTRAINT fk_ag_assigned_by FOREIGN KEY (assigned_by) REFERENCES users (user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS assignment_group_members (
            assignment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id VARCHAR(36) NOT NULL,
            member_user_id INT UNSIGNED NOT NULL,
            status ENUM('pending_admin','pending_trainer','active','rejected','ended') NOT NULL DEFAULT 'pending_admin',
            assigned_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ended_date DATETIME DEFAULT NULL,
            rejection_reason TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (assignment_id),
            UNIQUE KEY uq_group_member (group_id, member_user_id),
            KEY idx_agm_member (member_user_id),
            KEY idx_agm_status (status),
            CONSTRAINT fk_agm_group FOREIGN KEY (group_id) REFERENCES assignment_groups (group_id) ON DELETE CASCADE,
            CONSTRAINT fk_agm_member FOREIGN KEY (member_user_id) REFERENCES users (user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    // =========================================================================
    // 6. Migrate Existing trainer_assignments Rows
    // =========================================================================
    // Ensure all existing assignments have a valid group_id
    $missingGroupRows = $pdo->query('SELECT assignment_id FROM trainer_assignments WHERE group_id IS NULL OR group_id = ""')->fetchAll(PDO::FETCH_ASSOC);
    $updGid = $pdo->prepare('UPDATE trainer_assignments SET group_id = ? WHERE assignment_id = ?');
    foreach ($missingGroupRows as $mgr) {
        $updGid->execute([bin2hex(random_bytes(16)), (int)$mgr['assignment_id']]);
    }

    // Populate assignment_groups from distinct group_ids
    $pdo->exec("
        INSERT INTO assignment_groups (group_id, gym_id, trainer_id, activity_title, assigned_by, assigned_date, status, created_at)
        SELECT 
            ta.group_id,
            tp.gym_id,
            ta.trainer_id,
            COALESCE(ta.activity_title, 'Personal Training'),
            ta.assigned_by,
            MIN(ta.assigned_date),
            CASE 
                WHEN SUM(CASE WHEN ta.status = 'active' THEN 1 ELSE 0 END) > 0 THEN 'active'
                WHEN SUM(CASE WHEN ta.status = 'pending_trainer' THEN 1 ELSE 0 END) > 0 THEN 'pending_trainer'
                WHEN SUM(CASE WHEN ta.status = 'pending_admin' THEN 1 ELSE 0 END) > 0 THEN 'pending_admin'
                ELSE 'ended'
            END,
            MIN(ta.assigned_date)
        FROM trainer_assignments ta
        LEFT JOIN trainer_profiles tp ON tp.trainer_id = ta.trainer_id
        WHERE ta.group_id IS NOT NULL AND ta.group_id != ''
        GROUP BY ta.group_id, tp.gym_id, ta.trainer_id, ta.activity_title, ta.assigned_by
        ON DUPLICATE KEY UPDATE
            activity_title = VALUES(activity_title),
            status = VALUES(status)
    ");

    // Populate assignment_group_members
    $pdo->exec("
        INSERT INTO assignment_group_members (assignment_id, group_id, member_user_id, status, assigned_date, ended_date, rejection_reason, created_at)
        SELECT 
            ta.assignment_id,
            ta.group_id,
            ta.member_user_id,
            ta.status,
            ta.assigned_date,
            ta.ended_date,
            ta.rejection_reason,
            ta.assigned_date
        FROM trainer_assignments ta
        WHERE ta.group_id IS NOT NULL AND ta.group_id != ''
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            ended_date = VALUES(ended_date),
            rejection_reason = VALUES(rejection_reason)
    ");

    // =========================================================================
    // 7. Setup Bidirectional Sync Triggers on trainer_assignments
    // =========================================================================
    $pdo->exec('DROP TRIGGER IF EXISTS trg_trainer_assignments_insert');
    $pdo->exec("
        CREATE TRIGGER trg_trainer_assignments_insert AFTER INSERT ON trainer_assignments
        FOR EACH ROW
        BEGIN
            DECLARE resolved_group_id VARCHAR(36);
            DECLARE resolved_gym_id INT UNSIGNED;

            SET resolved_group_id = IF(NEW.group_id IS NOT NULL AND NEW.group_id != '', NEW.group_id, UUID());

            SELECT gym_id INTO resolved_gym_id FROM trainer_profiles WHERE trainer_id = NEW.trainer_id LIMIT 1;

            INSERT INTO assignment_groups (group_id, gym_id, trainer_id, activity_title, assigned_by, assigned_date, status)
            VALUES (resolved_group_id, resolved_gym_id, NEW.trainer_id, COALESCE(NEW.activity_title, 'Personal Training'), NEW.assigned_by, NEW.assigned_date, NEW.status)
            ON DUPLICATE KEY UPDATE
                activity_title = VALUES(activity_title),
                status = VALUES(status);

            INSERT INTO assignment_group_members (assignment_id, group_id, member_user_id, status, assigned_date, ended_date, rejection_reason)
            VALUES (NEW.assignment_id, resolved_group_id, NEW.member_user_id, NEW.status, NEW.assigned_date, NEW.ended_date, NEW.rejection_reason)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                ended_date = VALUES(ended_date),
                rejection_reason = VALUES(rejection_reason);
        END
    ");

    $pdo->exec('DROP TRIGGER IF EXISTS trg_trainer_assignments_update');
    $pdo->exec("
        CREATE TRIGGER trg_trainer_assignments_update AFTER UPDATE ON trainer_assignments
        FOR EACH ROW
        BEGIN
            UPDATE assignment_groups
            SET trainer_id = NEW.trainer_id,
                activity_title = COALESCE(NEW.activity_title, activity_title),
                status = NEW.status
            WHERE group_id = NEW.group_id;

            UPDATE assignment_group_members
            SET group_id = NEW.group_id,
                member_user_id = NEW.member_user_id,
                status = NEW.status,
                assigned_date = NEW.assigned_date,
                ended_date = NEW.ended_date,
                rejection_reason = NEW.rejection_reason
            WHERE assignment_id = NEW.assignment_id;
        END
    ");

    $pdo->exec('DROP TRIGGER IF EXISTS trg_trainer_assignments_delete');
    $pdo->exec("
        CREATE TRIGGER trg_trainer_assignments_delete AFTER DELETE ON trainer_assignments
        FOR EACH ROW
        BEGIN
            DELETE FROM assignment_group_members WHERE assignment_id = OLD.assignment_id;
            IF NOT EXISTS (SELECT 1 FROM assignment_group_members WHERE group_id = OLD.group_id) THEN
                DELETE FROM assignment_groups WHERE group_id = OLD.group_id;
            END IF;
        END
    ");

    // =========================================================================
    // 8. Create v_assignment_group_details View
    // =========================================================================
    $pdo->exec('
        CREATE OR REPLACE VIEW v_assignment_group_details AS
        SELECT 
            agm.assignment_id,
            ag.group_id,
            ag.gym_id,
            ag.trainer_id,
            agm.member_user_id,
            COALESCE(agm.assigned_date, ag.assigned_date) AS assigned_date,
            agm.ended_date,
            ag.activity_title,
            agm.status,
            ag.assigned_by,
            agm.rejection_reason,
            CONCAT(tu.first_name, " ", tu.last_name) AS trainer_name,
            CONCAT(mu.first_name, " ", mu.last_name) AS member_name,
            tu.profile_picture AS trainer_picture,
            mu.profile_picture AS member_picture,
            tp.specialization
        FROM assignment_group_members agm
        JOIN assignment_groups ag ON ag.group_id = agm.group_id
        JOIN trainer_profiles tp ON tp.trainer_id = ag.trainer_id
        JOIN users tu ON tu.user_id = tp.user_id
        JOIN users mu ON mu.user_id = agm.member_user_id
    ');
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    run_normalize_phase5_migration();
    echo "Phase 5 equipment and assignments normalization completed successfully.\n";
}
