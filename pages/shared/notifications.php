<?php
declare(strict_types=1);

function notifications_page(): void
{
    $user = require_login();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('notification_action');
        if ($action === 'mark_read') {
            mark_notification_read((int) post('notification_id'), (int) $user['user_id']);
            flash('Notification marked as read.', 'success');
        } elseif ($action === 'mark_all_read') {
            mark_all_notifications_read((int) $user['user_id']);
            flash('All notifications marked as read.', 'success');
        }
        redirect('notifications');
    }

    $rows = get_notifications((int) $user['user_id'], 50);
    $unread = unread_notification_count((int) $user['user_id']);

    render_header('Notifications', $user);
    ?>
    <section class="panel wide">
        <div class="page-header">
            <div>
                <h1>Notifications</h1>
                <p><?= $unread ? h((string) $unread) . ' unread' : 'You are all caught up.' ?></p>
            </div>
            <?php if ($unread): ?>
                <form method="post" style="margin:0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="notification_action" value="mark_all_read">
                    <button type="submit" class="btn-secondary">Mark all as read</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!$rows): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <p>No notifications yet.<br>Updates about classes, messages, and your account will appear here.</p>
            </div>
        <?php else: ?>
            <div class="notif-list">
                <?php foreach ($rows as $row): ?>
                    <article class="notif-item <?= $row['is_read'] ? '' : 'unread' ?>">
                        <div class="notif-item-head">
                            <span class="notif-type notif-type-<?= h($row['type']) ?>"><?= h(notification_type_label($row['type'])) ?></span>
                            <time><?= h(notification_time_ago($row['created_at'])) ?></time>
                        </div>
                        <h3><?= h($row['title']) ?></h3>
                        <p><?= h($row['message']) ?></p>
                        <?php if (!$row['is_read']): ?>
                            <form method="post" class="notif-item-action">
                                <?= csrf_field() ?>
                                <input type="hidden" name="notification_action" value="mark_read">
                                <input type="hidden" name="notification_id" value="<?= (int) $row['notification_id'] ?>">
                                <button type="submit" class="btn-sm">Mark read</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
    render_footer();
}
