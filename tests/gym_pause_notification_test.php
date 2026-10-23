<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/notifications.php';

function run_gym_pause_tests(): void
{
    $pdo = db();
    $unique = substr(bin2hex(random_bytes(4)), 0, 8);
    $ownerEmail = "owner_{$unique}@example.com";
    $memberEmail = "member_{$unique}@example.com";

    $ownerId = 0;
    $memberId = 0;
    $gymId = 0;

    try {
        // Create owner and gym
        $pdo->prepare('INSERT INTO users (email, password_hash, first_name, last_name, role, status) VALUES (?, "hash", "Owner", "Test", "gym_owner", "active")')
            ->execute([$ownerEmail]);
        $ownerId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO gyms (name, owner_user_id, address, status) VALUES (?, ?, "123 Pause St", "approved")')
            ->execute(["Pause Test Gym {$unique}", $ownerId]);
        $gymId = (int) $pdo->lastInsertId();

        // Create member and associate with gym
        $pdo->prepare('INSERT INTO users (email, password_hash, first_name, last_name, role, status) VALUES (?, "hash", "Member", "Test", "member", "active")')
            ->execute([$memberEmail]);
        $memberId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO gym_members (gym_id, user_id) VALUES (?, ?)')
            ->execute([$gymId, $memberId]);

        // 1. Initial State: not paused
        if (is_gym_paused($gymId)) {
            throw new RuntimeException("FAIL: Gym should not be paused initially");
        }
        fwrite(STDOUT, "PASS: Test 1: Gym initial state is not paused\n");

        // 2. Pause Gym & Store Notice in Database
        $customNotice = "Our public listings are paused for upgrades. Active member access remains normal. See you soon!";
        $pdo->prepare('REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)')
            ->execute(['gym_paused_' . $gymId, '1']);
        $pdo->prepare('REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)')
            ->execute(['gym_pause_notice_' . $gymId, $customNotice]);

        if (!is_gym_paused($gymId)) {
            throw new RuntimeException("FAIL: is_gym_paused should return true after setting in DB");
        }
        if (get_gym_pause_notice($gymId) !== $customNotice) {
            throw new RuntimeException("FAIL: get_gym_pause_notice did not return custom notice from DB");
        }
        fwrite(STDOUT, "PASS: Test 2: Database stores pause state and custom member notice\n");

        // 3. Dispatch Notification to Members
        notify_user($memberId, 'system', 'Gym Notice: Maintenance Mode', $customNotice, $gymId);

        $notifStmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? AND reference_id = ? ORDER BY notification_id DESC LIMIT 1');
        $notifStmt->execute([$memberId, $gymId]);
        $notif = $notifStmt->fetch(PDO::FETCH_ASSOC);

        if (!$notif || $notif['message'] !== $customNotice || $notif['title'] !== 'Gym Notice: Maintenance Mode') {
            throw new RuntimeException("FAIL: In-app notification was not correctly recorded in notifications table");
        }
        fwrite(STDOUT, "PASS: Test 3: In-app notification record inserted into database for member\n");

        // 4. Resume Gym & Store Resumed State
        $pdo->prepare('REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)')
            ->execute(['gym_paused_' . $gymId, '0']);

        if (is_gym_paused($gymId)) {
            throw new RuntimeException("FAIL: is_gym_paused should return false after resume");
        }
        fwrite(STDOUT, "PASS: Test 4: Gym resumed state reflected in database\n");

        fwrite(STDOUT, "--- ALL GYM PAUSE NOTIFICATION TESTS PASSED SUCCESSFULLY ---\n");
    } finally {
        // Clean up test data
        if ($gymId > 0) {
            $pdo->prepare('DELETE FROM system_settings WHERE setting_key IN (?, ?)')->execute(['gym_paused_' . $gymId, 'gym_pause_notice_' . $gymId]);
            $pdo->prepare('DELETE FROM notifications WHERE reference_id = ?')->execute([$gymId]);
            $pdo->prepare('DELETE FROM gym_members WHERE gym_id = ?')->execute([$gymId]);
            $pdo->prepare('DELETE FROM gyms WHERE gym_id = ?')->execute([$gymId]);
        }
        if ($memberId > 0) {
            $pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$memberId]);
        }
        if ($ownerId > 0) {
            $pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$ownerId]);
        }
    }
}

run_gym_pause_tests();

