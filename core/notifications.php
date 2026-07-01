<?php
declare(strict_types=1);

function notify_user(int $userId, string $type, string $title, string $message): void
{
    try {
        db()->prepare('INSERT INTO notifications (user_id, type, title, message) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $type, $title, mb_substr($message, 0, 500)]);
    } catch (Throwable) {
        // Non-blocking.
    }
}

function notify_admins(string $type, string $title, string $message): void
{
    $admins = query_all('SELECT user_id FROM users WHERE role = "admin" AND status = "active"');
    foreach ($admins as $admin) {
        notify_user((int) $admin['user_id'], $type, $title, $message);
    }
}

function unread_notification_count(int $userId): int
{
    try {
        return (int) scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$userId]);
    } catch (Throwable) {
        return 0;
    }
}

function get_notifications(int $userId, int $limit = 8): array
{
    try {
        return query_all(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . max(1, min($limit, 20)),
            [$userId]
        );
    } catch (Throwable) {
        return [];
    }
}

function mark_notification_read(int $notificationId, int $userId): void
{
    db()->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?')
        ->execute([$notificationId, $userId]);
}

function mark_all_notifications_read(int $userId): void
{
    db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')
        ->execute([$userId]);
}

function handle_notification_actions(): void
{
    $user = require_login();
    $action = post('notification_action');

    if ($action === 'mark_read') {
        mark_notification_read((int) post('notification_id'), (int) $user['user_id']);
    } elseif ($action === 'mark_all_read') {
        mark_all_notifications_read((int) $user['user_id']);
    }

    $return = post('return_page', 'dashboard');
    redirect($return);
}
