<?php
declare(strict_types=1);

const NOTIFICATION_TYPES = ['renewal_reminder', 'class_reminder', 'coach_message', 'milestone', 'system'];

function notify_user(int $userId, string $type, string $title, string $message): void
{
    if ($userId <= 0 || !in_array($type, NOTIFICATION_TYPES, true)) {
        return;
    }

    try {
        db()->prepare('INSERT INTO notifications (user_id, type, title, message) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $type, mb_substr(trim($title), 0, 100), mb_substr(trim($message), 0, 500)]);
    } catch (Throwable) {
        // Non-blocking.
    }
}


function notify_admins(string $type, string $title, string $message): void
{
    foreach (query_all('SELECT user_id FROM users WHERE role IN ("admin") AND status = "active"') as $user) {
        notify_user((int) $user['user_id'], $type, $title, $message);
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
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . max(1, min($limit, 50)),
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

function notification_type_label(string $type): string
{
    return match ($type) {
        'renewal_reminder' => 'Renewal',
        'class_reminder'   => 'Class',
        'coach_message'    => 'Message',
        'milestone'        => 'Milestone',
        default            => 'System',
    };
}

function notification_time_ago(string $datetime): string
{
    $seconds = time() - strtotime($datetime);
    if ($seconds < 60) {
        return 'Just now';
    }
    if ($seconds < 3600) {
        return (int) floor($seconds / 60) . 'm ago';
    }
    if ($seconds < 86400) {
        return (int) floor($seconds / 3600) . 'h ago';
    }
    if ($seconds < 604800) {
        return (int) floor($seconds / 86400) . 'd ago';
    }
    return date('M j, Y', strtotime($datetime));
}

function maybe_notify_membership_renewal(int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    $rows = query_all(
        'SELECT m.end_date, p.plan_name
         FROM memberships m
         JOIN membership_plans p ON p.plan_id = m.plan_id
         WHERE m.user_id = ? AND m.status = "active"
           AND m.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
         ORDER BY m.end_date ASC
         LIMIT 1',
        [$userId]
    );
    if (!$rows) {
        return;
    }

    $membership = $rows[0];
    $endDate = $membership['end_date'];
    $daysLeft = (int) ((strtotime($endDate) - strtotime(date('Y-m-d'))) / 86400);
    $message = 'Your ' . $membership['plan_name'] . ' membership expires on '
        . date('M j, Y', strtotime($endDate))
        . ($daysLeft === 0 ? ' (today).' : ' (' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left).');

    $alreadySent = (int) scalar(
        'SELECT COUNT(*) FROM notifications
         WHERE user_id = ? AND type = "renewal_reminder" AND message = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
        [$userId, $message]
    );
    if ($alreadySent) {
        return;
    }

    notify_user($userId, 'renewal_reminder', 'Membership expiring soon', $message);
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

    redirect(post('return_page', 'dashboard'));
}
