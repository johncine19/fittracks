<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/engagement_engine.php';

function run_at_risk_batch_multi_gym_tests(): void
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Register SQLite functions matching MySQL semantics
    $pdo->sqliteCreateFunction('CURDATE', fn() => '2026-10-05');
    $pdo->sqliteCreateFunction('DATEDIFF', function (?string $d1, ?string $d2): ?int {
        if ($d1 === null || $d2 === null) {
            return null;
        }
        $t1 = strtotime($d1);
        $t2 = strtotime($d2);
        if ($t1 === false || $t2 === false) {
            return null;
        }
        return (int) round(($t1 - $t2) / 86400);
    });

    // Create schema
    $pdo->exec('
        CREATE TABLE users (
            user_id INTEGER PRIMARY KEY,
            role TEXT NOT NULL,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL
        );

        CREATE TABLE gyms (
            gym_id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            inactivity_threshold_days INTEGER DEFAULT 3,
            inactivity_cooldown_days INTEGER DEFAULT 14,
            auto_inactivity_alerts INTEGER DEFAULT 1
        );

        CREATE TABLE gym_members (
            user_id INTEGER NOT NULL,
            gym_id INTEGER NOT NULL,
            PRIMARY KEY (user_id, gym_id)
        );

        CREATE TABLE memberships (
            membership_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            plan_id INTEGER NOT NULL,
            status TEXT NOT NULL
        );

        CREATE TABLE membership_plans (
            plan_id INTEGER PRIMARY KEY,
            gym_id INTEGER NOT NULL,
            plan_name TEXT NOT NULL
        );

        CREATE TABLE attendance (
            attendance_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            gym_id INTEGER,
            check_in_time TEXT NOT NULL,
            check_out_time TEXT
        );

        CREATE TABLE notifications (
            notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            title TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
    ');

    // Seed Gyms
    // Gym 1: Default threshold 3 days, cooldown 14 days, alerts enabled
    // Gym 2: Threshold 10 days, cooldown 30 days, alerts enabled
    // Gym 3: Alerts disabled (auto_inactivity_alerts = 0)
    $pdo->exec("
        INSERT INTO gyms (gym_id, name, inactivity_threshold_days, inactivity_cooldown_days, auto_inactivity_alerts) VALUES
        (1, 'Gym Alpha', 3, 14, 1),
        (2, 'Gym Beta', 10, 30, 1),
        (3, 'Gym Gamma', 3, 14, 0);
    ");

    // Seed Users & Multi-Gym Affiliations:
    // User 1: Affiliated with Gym 1 and Gym 2 in gym_members.
    //         Active membership at Gym 2, but recent attendance at Gym 1 7 days ago.
    //         Deterministic selection chooses Gym 1 (attendance precedence).
    //         Threshold is 3 days -> 7 days inactive => Candidate!
    // User 2: At batch boundary (batch_size = 2).
    //         Affiliated with Gym 1 and Gym 2 in gym_members. No attendance.
    //         Deterministic selection chooses Gym 1 (ORDER BY gm.gym_id ASC).
    //         Created 5 days ago => Candidate!
    // User 3: Across batch boundary (in batch 2).
    //         Affiliated with Gym 1 and Gym 2 in gym_members.
    //         Created 6 days ago => Candidate!
    // User 4: Affiliated with Gym 3 (alerts disabled).
    //         Created 20 days ago => Should be ignored!
    // User 5: Affiliated with Gym 1 and Gym 2.
    //         Created 20 days ago, but received reminder 2 days ago (within 14-day cooldown).
    //         Should be ignored!
    $pdo->exec("
        INSERT INTO users (user_id, role, status, created_at) VALUES
        (1, 'member', 'active', '2026-09-01 08:00:00'),
        (2, 'member', 'active', '2026-09-30 08:00:00'),
        (3, 'member', 'active', '2026-09-29 08:00:00'),
        (4, 'member', 'active', '2026-09-15 08:00:00'),
        (5, 'member', 'active', '2026-09-15 08:00:00');

        -- Multiple gym affiliations per member in gym_members composite PK (user_id, gym_id)
        INSERT INTO gym_members (user_id, gym_id) VALUES
        (1, 1), (1, 2),
        (2, 1), (2, 2),
        (3, 1), (3, 2),
        (4, 3),
        (5, 1), (5, 2);

        -- Membership plans and memberships
        INSERT INTO membership_plans (plan_id, gym_id, plan_name) VALUES
        (1, 1, 'Alpha Plan'),
        (2, 2, 'Beta Plan');

        INSERT INTO memberships (user_id, plan_id, status) VALUES
        (1, 2, 'active');

        -- Attendance: User 1 attended Gym 1 7 days ago
        INSERT INTO attendance (user_id, gym_id, check_in_time, check_out_time) VALUES
        (1, 1, '2026-09-28 10:00:00', '2026-09-28 11:30:00');

        -- User 5 already received at-risk notification 2 days ago (in cooldown)
        INSERT INTO notifications (user_id, type, title, created_at) VALUES
        (5, 'system', 'We miss you at the gym!', '2026-10-03 12:00:00');
    ");

    // Queue interceptor to track pushed jobs
    $queuedJobs = [];
    Queue::$interceptor = function (string $jobClass, array $payload) use (&$queuedJobs): void {
        $queuedJobs[] = ['job' => $jobClass, 'payload' => $payload];
    };

    // --- TEST 1: Process Batch 1 (Users 1 & 2 at batch size = 2) ---
    $queuedJobs = [];
    process_automated_at_risk_notifications([
        'pdo' => $pdo,
        'last_user_id' => 0,
        'batch_size' => 2,
    ]);

    $dispatchedReminderUsers = [];
    $nextBatchPayload = null;
    foreach ($queuedJobs as $item) {
        if ($item['job'] === 'send_at_risk_notification_job') {
            $dispatchedReminderUsers[] = $item['payload']['user_id'];
        } elseif ($item['job'] === 'process_automated_at_risk_notifications') {
            $nextBatchPayload = $item['payload'];
        }
    }

    // Assert: User 1 has 2 affiliations, but must receive EXACTLY ONE reminder
    $user1Count = count(array_keys($dispatchedReminderUsers, 1, true));
    if ($user1Count !== 1) {
        throw new RuntimeException("FAIL: User 1 expected 1 reminder job, got {$user1Count}");
    }

    // Assert: User 2 at the batch boundary has 2 affiliations, but must receive EXACTLY ONE reminder
    $user2Count = count(array_keys($dispatchedReminderUsers, 2, true));
    if ($user2Count !== 1) {
        throw new RuntimeException("FAIL: User 2 expected 1 reminder job, got {$user2Count}");
    }

    // Assert: Total reminder jobs in Batch 1 is exactly 2
    if ($dispatchedReminderUsers !== [1, 2]) {
        throw new RuntimeException("FAIL: Expected Batch 1 reminder users [1, 2], got " . json_encode($dispatchedReminderUsers));
    }

    // Assert: Next batch cursor is queued with last_user_id = 2
    if (!$nextBatchPayload || ($nextBatchPayload['last_user_id'] ?? null) !== 2) {
        throw new RuntimeException("FAIL: Expected next batch with last_user_id=2, got " . json_encode($nextBatchPayload));
    }
    fwrite(STDOUT, "PASS: Batch 1: Multi-gym members queued exactly once, batch boundary advances\n");

    // --- TEST 2: Process Batch 2 (Users 3 & 4 across cursor boundary) ---
    $queuedJobs = [];
    process_automated_at_risk_notifications([
        'pdo' => $pdo,
        'last_user_id' => 2,
        'batch_size' => 2,
    ]);

    $dispatchedReminderUsers = [];
    $nextBatchPayload = null;
    foreach ($queuedJobs as $item) {
        if ($item['job'] === 'send_at_risk_notification_job') {
            $dispatchedReminderUsers[] = $item['payload']['user_id'];
        } elseif ($item['job'] === 'process_automated_at_risk_notifications') {
            $nextBatchPayload = $item['payload'];
        }
    }

    // Assert: Users 1 and 2 are NOT re-queued across boundary
    if (in_array(1, $dispatchedReminderUsers, true) || in_array(2, $dispatchedReminderUsers, true)) {
        throw new RuntimeException("FAIL: Prior batch users re-queued across boundary: " . json_encode($dispatchedReminderUsers));
    }

    // Assert: User 3 (across boundary with 2 affiliations) is queued exactly once
    if ($dispatchedReminderUsers !== [3]) {
        throw new RuntimeException("FAIL: Expected Batch 2 reminder users [3], got " . json_encode($dispatchedReminderUsers));
    }

    // Assert: User 4 was evaluated but skipped because gym 3 disabled alerts
    if (in_array(4, $dispatchedReminderUsers, true)) {
        throw new RuntimeException("FAIL: User 4 received reminder despite gym auto_inactivity_alerts=0");
    }

    // Assert: Next batch cursor is queued with last_user_id = 4
    if (!$nextBatchPayload || ($nextBatchPayload['last_user_id'] ?? null) !== 4) {
        throw new RuntimeException("FAIL: Expected next batch with last_user_id=4, got " . json_encode($nextBatchPayload));
    }
    fwrite(STDOUT, "PASS: Batch 2: No duplicates across cursor boundary, alert disable honored\n");

    // --- TEST 3: Process Batch 3 (User 5 in cooldown) ---
    $queuedJobs = [];
    process_automated_at_risk_notifications([
        'pdo' => $pdo,
        'last_user_id' => 4,
        'batch_size' => 2,
    ]);

    $dispatchedReminderUsers = [];
    $nextBatchPayload = null;
    foreach ($queuedJobs as $item) {
        if ($item['job'] === 'send_at_risk_notification_job') {
            $dispatchedReminderUsers[] = $item['payload']['user_id'];
        } elseif ($item['job'] === 'process_automated_at_risk_notifications') {
            $nextBatchPayload = $item['payload'];
        }
    }

    // Assert: User 5 skipped due to cooldown
    if (!empty($dispatchedReminderUsers)) {
        throw new RuntimeException("FAIL: User 5 received reminder despite active cooldown: " . json_encode($dispatchedReminderUsers));
    }

    // Assert: Batch size is 1 (< 2), sweep terminates without queuing further batches
    if ($nextBatchPayload !== null) {
        throw new RuntimeException("FAIL: Expected sweep to terminate, but queued next batch: " . json_encode($nextBatchPayload));
    }
    fwrite(STDOUT, "PASS: Batch 3: Cooldown lookup honored, sweep terminates cleanly\n");

    // Reset Queue interceptor
    Queue::$interceptor = null;
}
