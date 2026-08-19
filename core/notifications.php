<?php
declare(strict_types=1);

const NOTIFICATION_TYPES = ['renewal_reminder', 'class_reminder', 'coach_message', 'milestone', 'system'];

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;



function notify_user(int $userId, string $type, string $title, string $message, ?int $referenceId = null): void
{
    if ($userId <= 0 || !in_array($type, NOTIFICATION_TYPES, true)) {
        return;
    }

    try {

        db()->prepare('INSERT INTO notifications (user_id, type, title, message, reference_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $type, mb_substr(trim($title), 0, 100), mb_substr(trim($message), 0, 500), $referenceId]);
    } catch (Throwable) {
        // Non-blocking.
    }
}

function broadcast_class_schedule_job(array $payload): void
{
    $classId = (int) ($payload['class_id'] ?? 0);
    $startTime = (string) ($payload['start_time'] ?? '');
    if ($classId <= 0) {
        return;
    }

    $classStmt = db()->prepare('SELECT class_name, gym_id FROM classes WHERE class_id = ?');
    $classStmt->execute([$classId]);
    $classData = $classStmt->fetch();
    if (!$classData) {
        return;
    }

    $className = $classData['class_name'];
    $gymId = $classData['gym_id'] ? (int) $classData['gym_id'] : null;

    if ($gymId !== null) {
        $stmt = db()->prepare('SELECT u.user_id FROM users u JOIN gym_members gm ON gm.user_id = u.user_id WHERE gm.gym_id = ? AND u.role = "member" AND u.status = "active"');
        $stmt->execute([$gymId]);
    } else {
        $stmt = db()->query('SELECT user_id FROM users WHERE role = "member" AND status = "active"');
    }

    $members = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($members as $memberId) {
        notify_user((int) $memberId, 'class_reminder', 'New Class Session', "A new session for {$className} has been scheduled on {$startTime}.");
    }
}


function send_email_job(array $payload): bool
{
    $to = (string) ($payload['to'] ?? '');
    $name = (string) ($payload['name'] ?? '');
    $subject = (string) ($payload['subject'] ?? '');
    $htmlBody = (string) ($payload['body'] ?? '');

    if (empty($to) || empty($subject) || empty($htmlBody)) {
        return false;
    }

    try {
        if (!empty($_ENV['BREVO_API_KEY'])) {
            $data = [
                'sender' => ['name' => $_ENV['SMTP_FROM_NAME'] ?? 'FITTRACKS', 'email' => $_ENV['SMTP_FROM'] ?? 'no-reply@fittracks.com'],
                'to' => [['email' => $to, 'name' => $name]],
                'subject' => $subject,
                'htmlContent' => $htmlBody
            ];
            
            $ch = curl_init('https://api.brevo.com/v3/smtp/email');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: application/json',
                'api-key: ' . $_ENV['BREVO_API_KEY'],
                'content-type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode >= 200 && $httpCode < 300) {
                return true;
            }
            throw new Exception("Brevo API Error: " . $response);
        }

        // Fallback to PHPMailer for local dev / unblocked hosts
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER'] ?? '';
        $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) ($_ENV['SMTP_PORT'] ?? 587);

        $mail->setFrom($_ENV['SMTP_FROM'] ?? 'no-reply@fittracks.com', $_ENV['SMTP_FROM_NAME'] ?? 'FITTRACKS');
        $mail->addAddress($to, $name);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        return $mail->send();
    } catch (Throwable $e) {
        error_log("Failed sending queued email to {$to}: " . $e->getMessage());
        return false;
    }
}

function queue_email(string $toEmail, string $toName, string $subject, string $htmlBody): void
{
    Queue::push('send_email_job', [
        'to' => $toEmail,
        'name' => $toName,
        'subject' => $subject,
        'body' => $htmlBody,
    ]);
}

function notify_admins(string $type, string $title, string $message): void
{
    foreach (query_all('SELECT user_id FROM users WHERE role IN ("platform_admin", "admin") AND status = "active"') as $user) {
        notify_user((int) $user['user_id'], $type, $title, $message);
    }
}

function notify_admins_email(string $subject, string $body): void
{
    $admins = query_all('SELECT email, first_name FROM users WHERE role IN ("platform_admin", "admin") AND status = "active"');
    if (!$admins) {
        return;
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER'] ?? '';
        $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) ($_ENV['SMTP_PORT'] ?? 587);

        $mail->setFrom($_ENV['SMTP_FROM'] ?? 'no-reply@fittracks.com', $_ENV['SMTP_FROM_NAME'] ?? 'FITTRACKS');
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        foreach ($admins as $admin) {
            $mail->addAddress($admin['email'], $admin['first_name']);
        }
        $mail->send();
    } catch (Throwable) {
        // Non-blocking
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
