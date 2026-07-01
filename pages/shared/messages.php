<?php
declare(strict_types=1);

function messages_page(): void
{
    $user = require_login();

    // Handle sending a message
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'send') {
        $recipientId = (int) post('recipient_id');
        $text = trim((string) post('message_text'));
        if ($recipientId && $text !== '') {
            db()->prepare('INSERT INTO trainer_messages (sender_id, recipient_id, message_text) VALUES (?, ?, ?)')
               ->execute([$user['user_id'], $recipientId, $text]);
            $senderName = $user['first_name'] . ' ' . $user['last_name'];
            notify_user($recipientId, 'coach_message', 'New message from ' . $senderName, $text);
            flash('Message sent.', 'success');
        }
        redirect('messages');
    }

    // Mark received messages as read
    db()->prepare('UPDATE trainer_messages SET is_read = 1 WHERE recipient_id = ? AND is_read = 0')
       ->execute([$user['user_id']]);

    $rows = query_all(
        'SELECT m.*, CONCAT(s.first_name, " ", s.last_name) AS sender_name,
                CONCAT(r.first_name, " ", r.last_name) AS recipient_name
         FROM trainer_messages m
         JOIN users s ON s.user_id = m.sender_id
         JOIN users r ON r.user_id = m.recipient_id
         WHERE m.sender_id = ? OR m.recipient_id = ?
         ORDER BY m.sent_at ASC',
        [$user['user_id'], $user['user_id']]
    );

    // Get list of users this person can message
    $roleFilter = match($user['role']) {
        'member'  => '"trainer"',
        'trainer' => '"member","admin","staff"',
        default   => '"trainer","member","admin","staff"',
    };
    $contacts = db()->query(
        'SELECT user_id, first_name, last_name, role FROM users
         WHERE user_id != ' . (int)$user['user_id'] . '
           AND status = "active"
           AND role IN (' . $roleFilter . ')
         ORDER BY first_name'
    )->fetchAll();

    render_header('Messages', $user);
    ?>
    <section class="panel wide">
        <div class="page-header">
            <div>
                <h1>Messages</h1>
                <p>Send and receive messages with your trainer or gym staff.</p>
            </div>
            <button onclick="document.getElementById('composeModal').showModal()">+ New Message</button>
        </div>

        <!-- Compose modal -->
        <dialog id="composeModal" class="modal">
            <div class="modal-header">
                <h3>New message</h3>
                <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <form method="post" class="form grid-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send">
                    <label style="grid-column:1/-1">To
                        <select name="recipient_id" required>
                            <option value="">— select recipient —</option>
                            <?php foreach ($contacts as $c): ?>
                                <option value="<?= (int) $c['user_id'] ?>">
                                    <?= h($c['first_name'] . ' ' . $c['last_name']) ?> (<?= h(ucfirst($c['role'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label style="grid-column:1/-1">Message
                        <textarea name="message_text" rows="4" placeholder="Type your message…" required style="resize:vertical;"></textarea>
                    </label>
                    <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:10px;">
                        <button type="button" onclick="this.closest('dialog').close()">Cancel</button>
                        <button type="submit" class="btn-primary">Send</button>
                    </div>
                </form>
            </div>
        </dialog>

        <!-- Conversation thread -->
        <?php if (!$rows): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
                <p>No messages yet.<br>Click <strong>+ New Message</strong> to start a conversation.</p>
            </div>
        <?php else: ?>
            <div class="msg-thread">
                <?php foreach ($rows as $msg):
                    $isMine = (int) $msg['sender_id'] === (int) $user['user_id'];
                    $name   = $isMine ? 'You' : $msg['sender_name'];
                    $time   = date('M j, g:i A', strtotime($msg['sent_at']));
                ?>
                <div class="msg-row <?= $isMine ? 'msg-mine' : 'msg-theirs' ?>">
                    <div class="msg-meta"><?= h($name) ?> · <time><?= h($time) ?></time></div>
                    <div class="msg-bubble"><?= h($msg['message_text']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <style>
    .msg-thread { display:flex; flex-direction:column; gap:16px; margin-top:1.5rem; }
    .msg-row { display:flex; flex-direction:column; max-width:70%; }
    .msg-mine { align-self:flex-end; align-items:flex-end; }
    .msg-theirs { align-self:flex-start; align-items:flex-start; }
    .msg-meta { font-size:12px; color:var(--muted); margin-bottom:4px; }
    .msg-bubble {
        padding: 10px 14px;
        border-radius: 14px;
        font-size: 14px;
        line-height: 1.5;
        word-break: break-word;
    }
    .msg-mine .msg-bubble { background:var(--lime); color:#000; border-bottom-right-radius:4px; }
    .msg-theirs .msg-bubble { background:var(--panel-soft); color:var(--ink); border-bottom-left-radius:4px; }
    </style>
    <?php
    render_footer();
}
