<?php
declare(strict_types=1);

const NOTIFICATION_TYPES = ['renewal_reminder', 'class_reminder', 'coach_message', 'milestone', 'system'];

/**
 * Auto-migrate: add reference_id column to notifications if missing.
 * Called once per request; uses a static flag to avoid repeated checks.
 */
function ensure_notifications_reference_id(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $cols = db()->query('SHOW COLUMNS FROM notifications LIKE "reference_id"')->fetchAll();
        if (!$cols) {
            db()->exec('ALTER TABLE notifications ADD COLUMN reference_id INT UNSIGNED DEFAULT NULL AFTER message');
        }
    } catch (Throwable) {
        // Non-blocking — column may already exist.
    }
}

function notify_user(int $userId, string $type, string $title, string $message, ?int $referenceId = null): void
{
    if ($userId <= 0 || !in_array($type, NOTIFICATION_TYPES, true)) {
        return;
    }

    try {
        ensure_notifications_reference_id();
        db()->prepare('INSERT INTO notifications (user_id, type, title, message, reference_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $type, mb_substr(trim($title), 0, 100), mb_substr(trim($message), 0, 500), $referenceId]);
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

function get_notification_count(int $userId): int
{
    try {
        return (int) scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$userId]);
    } catch (Throwable) {
        return 0;
    }
}

function get_notifications(int $userId, int $limit = 8, int $offset = 0): array
{
    try {
        return query_all(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset,
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
           AND m.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
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
         WHERE user_id = ? AND type = "renewal_reminder" AND message = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)',
        [$userId, $message]
    );
    if ($alreadySent) {
        return;
    }

    notify_user($userId, 'renewal_reminder', 'Membership expiring soon', $message);
}

function maybe_notify_membership_expired(int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    $rows = query_all(
        'SELECT m.end_date, p.plan_name
         FROM memberships m
         JOIN membership_plans p ON p.plan_id = m.plan_id
         WHERE m.user_id = ? AND m.status IN ("active", "expired")
           AND m.end_date < CURDATE()
           AND m.end_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
         ORDER BY m.end_date DESC
         LIMIT 1',
        [$userId]
    );
    if (!$rows) {
        return;
    }

    $membership = $rows[0];
    $endDate = $membership['end_date'];

    $hasActive = (bool) scalar(
        'SELECT 1 FROM memberships WHERE user_id = ? AND status = "active" AND end_date >= CURDATE() LIMIT 1',
        [$userId]
    );
    if ($hasActive) {
        return;
    }

    $message = 'Your ' . $membership['plan_name'] . ' membership expired on '
        . date('M j, Y', strtotime($endDate)) . '. Please renew to continue accessing the facility.';

    $alreadySent = (int) scalar(
        'SELECT COUNT(*) FROM notifications
         WHERE user_id = ? AND type = "renewal_reminder" AND message = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)',
        [$userId, $message]
    );
    if ($alreadySent) {
        return;
    }

    notify_user($userId, 'renewal_reminder', 'Membership expired', $message);
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

/**
 * Handle clicking a notification: atomically mark the notification + related
 * chat messages as read, then redirect to the appropriate page.
 */
function handle_notification_click(): void
{
    $user = require_login();
    $userId = (int) $user['user_id'];
    $notifId = isset($_GET['nid']) ? (int) $_GET['nid'] : 0;

    if ($notifId <= 0) {
        redirect('notifications');
    }

    // Fetch the notification (must belong to this user)
    $notif = query_all(
        'SELECT * FROM notifications WHERE notification_id = ? AND user_id = ? LIMIT 1',
        [$notifId, $userId]
    );

    if (!$notif) {
        redirect('notifications');
    }

    $notif = $notif[0];
    $pdo = db();

    // Begin transaction for atomicity
    $pdo->beginTransaction();
    $senderId = null;
    try {
        // 1. Mark the notification as read
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?')
            ->execute([$notifId, $userId]);

        // 2. For coach_message notifications, also mark all chat messages from that sender as read
        if ($notif['type'] === 'coach_message') {
            $refId = $notif['reference_id'] ?? null;

            if ($refId) {
                $senderId = (int) $refId;
            } else {
                // Fallback: extract sender name from title "New message from {Name}"
                $prefix = 'New message from ';
                if (str_starts_with($notif['title'], $prefix)) {
                    $senderName = substr($notif['title'], strlen($prefix));
                    $parts = explode(' ', $senderName, 2);
                    if (count($parts) === 2) {
                        $row = $pdo->prepare('SELECT user_id FROM users WHERE first_name = ? AND last_name = ? LIMIT 1');
                        $row->execute([$parts[0], $parts[1]]);
                        $found = $row->fetch();
                        if ($found) {
                            $senderId = (int) $found['user_id'];
                        }
                    }
                }
            }

            if ($senderId) {
                // Mark all unread chat messages from this sender to the current user as read
                $pdo->prepare('UPDATE trainer_messages SET is_read = 1 WHERE sender_id = ? AND recipient_id = ? AND is_read = 0')
                    ->execute([$senderId, $userId]);

                // Also mark any other unread coach_message notifications from the same sender
                if ($refId) {
                    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND type = "coach_message" AND reference_id = ? AND is_read = 0')
                        ->execute([$userId, $senderId]);
                }
            }
        }

        $pdo->commit();
    } catch (Throwable) {
        $pdo->rollBack();
    }

    // Redirect to the appropriate page
    if ($notif['type'] === 'coach_message' && $senderId) {
        header('Location: index.php?page=messages&chat=' . $senderId);
        exit;
    } elseif ($notif['type'] === 'coach_message') {
        redirect('messages');
    } elseif ($notif['type'] === 'class_reminder') {
        redirect($user['role'] === 'member' ? 'book_classes' : 'classes');
    } elseif ($notif['type'] === 'renewal_reminder') {
        redirect('member_membership');
    } elseif ($notif['type'] === 'milestone') {
        redirect('member_progress');
    } elseif ($notif['type'] === 'system' && str_starts_with($notif['title'], 'Trainer Appointment Request')) {
        redirect('trainer_assignments');
    } else {
        redirect('notifications');
    }
}
