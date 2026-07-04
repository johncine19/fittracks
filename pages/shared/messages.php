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
            $title = 'New message from ' . $senderName;
            
            $unreadCount = (int) scalar('SELECT COUNT(*) FROM trainer_messages WHERE sender_id = ? AND recipient_id = ? AND is_read = 0', [$user['user_id'], $recipientId]);
            
            if ($unreadCount > 1) {
                $msgText = 'You have ' . $unreadCount . ' new messages.';
                ensure_notifications_reference_id();
                db()->prepare('UPDATE notifications SET message = ?, reference_id = ?, created_at = CURRENT_TIMESTAMP WHERE user_id = ? AND title = ? AND type = "coach_message" AND is_read = 0')
                  ->execute([$msgText, $user['user_id'], $recipientId, $title]);
            } else {
                notify_user($recipientId, 'coach_message', $title, $text, (int) $user['user_id']);
            }
        }
        header('Location: index.php?page=messages&chat=' . $recipientId);
        exit;
    }

    // Get list of users this person has conversed with
    $conversations = query_all(
        'SELECT u.user_id, u.first_name, u.last_name, u.profile_picture, u.role,
                (SELECT message_text FROM trainer_messages WHERE (sender_id = u.user_id AND recipient_id = ?) OR (sender_id = ? AND recipient_id = u.user_id) ORDER BY sent_at DESC LIMIT 1) as last_message,
                (SELECT sent_at FROM trainer_messages WHERE (sender_id = u.user_id AND recipient_id = ?) OR (sender_id = ? AND recipient_id = u.user_id) ORDER BY sent_at DESC LIMIT 1) as last_time
         FROM users u
         WHERE u.user_id IN (
            SELECT sender_id FROM trainer_messages WHERE recipient_id = ?
            UNION
            SELECT recipient_id FROM trainer_messages WHERE sender_id = ?
         )
         ' . ($user['role'] === 'admin' ? ' OR (u.role = "trainer" AND u.status = "active") ' : '') . '
         ORDER BY last_time DESC',
        [$user['user_id'], $user['user_id'], $user['user_id'], $user['user_id'], $user['user_id'], $user['user_id']]
    );

    $activeChatId = isset($_GET['chat']) ? (int) $_GET['chat'] : null;
    $activeUser = null;

    if ($activeChatId) {
        $found = false;
        foreach ($conversations as $c) {
            if ((int)$c['user_id'] === $activeChatId) {
                $found = true;
                $activeUser = $c;
                break;
            }
        }
        if (!$found) {
            $stmt = db()->prepare('SELECT user_id, first_name, last_name, profile_picture, role FROM users WHERE user_id = ?');
            $stmt->execute([$activeChatId]);
            $activeUser = $stmt->fetch();
            if ($activeUser) {
                array_unshift($conversations, $activeUser);
            }
        }
    } else if (count($conversations) > 0) {
        $activeChatId = (int)$conversations[0]['user_id'];
        $activeUser = $conversations[0];
    }

    $rows = [];
    if ($activeChatId) {
        $rows = query_all(
            'SELECT m.*, CONCAT(s.first_name, " ", s.last_name) AS sender_name,
                    CONCAT(r.first_name, " ", r.last_name) AS recipient_name
             FROM trainer_messages m
             JOIN users s ON s.user_id = m.sender_id
             JOIN users r ON r.user_id = m.recipient_id
             WHERE (m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?)
             ORDER BY m.sent_at ASC',
            [$user['user_id'], $activeChatId, $activeChatId, $user['user_id']]
        );
        
        // Mark as read
        db()->prepare('UPDATE trainer_messages SET is_read = 1 WHERE sender_id = ? AND recipient_id = ? AND is_read = 0')
           ->execute([$activeChatId, $user['user_id']]);
    }

    // Get list of users this person can message for the New Message modal
    $roleFilter = match($user['role']) {
        'member'  => '"trainer"',
        'trainer' => '"member","admin"',
        default   => '"trainer","member","admin"',
    };
    $contacts = db()->query(
        'SELECT user_id, first_name, last_name, profile_picture, role FROM users
         WHERE user_id != ' . (int)$user['user_id'] . '
           AND status = "active"
           AND role IN (' . $roleFilter . ')
         ORDER BY first_name'
    )->fetchAll();

    render_header('Messages', $user);
    ?>
    <?php render_skeleton_chat(); ?>
    <section class="panel wide skeleton-content sk-display-flex" style="padding: 0; height: calc(100vh - 120px); min-height: 500px; overflow: hidden; background: var(--surface); border: 1px solid var(--line);">
        
        <!-- Sidebar -->
        <div style="width: 300px; border-right: 1px solid var(--line); display: flex; flex-direction: column; background: var(--surface);">
            <div style="padding: 20px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center;">
                <h2 style="margin: 0; font-size: 1.2rem;">Messages</h2>
                <button onclick="document.getElementById('composeModal').showModal()" style="background: none; border: none; color: var(--lime); cursor: pointer; padding: 5px;" title="New Message">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M12 5v14M5 12h14"></path>
                    </svg>
                </button>
            </div>
            
            <div style="flex: 1; overflow-y: auto;">
                <?php if (!$conversations): ?>
                    <p style="padding: 20px; color: var(--muted); text-align: center; font-size: 0.9rem;">No conversations yet.</p>
                <?php endif; ?>
                
                <?php foreach ($conversations as $c): 
                    $isActive = $activeChatId === (int)$c['user_id'];
                    $cName = h($c['first_name'] . ' ' . $c['last_name']);
                    $cAvatar = render_avatar($c, 'small');
                    $lastMsg = h($c['last_message'] ?? 'New conversation');
                ?>
                    <a href="index.php?page=messages&chat=<?= (int)$c['user_id'] ?>" style="display: flex; gap: 12px; padding: 15px 20px; text-decoration: none; color: inherit; border-bottom: 1px solid var(--line); background: <?= $isActive ? 'color-mix(in srgb, var(--lime) 10%, transparent)' : 'transparent' ?>; align-items: center; transition: background 0.2s;">
                        <?= $cAvatar ?>
                        <div style="overflow: hidden;">
                            <div style="font-weight: <?= $isActive ? 'bold' : 'normal' ?>; font-size: 1rem; color: var(--ink); white-space: nowrap; text-overflow: ellipsis; overflow: hidden;"><?= $cName ?></div>
                            <div style="font-size: 0.8rem; color: var(--muted); white-space: nowrap; text-overflow: ellipsis; overflow: hidden; margin-top: 2px;"><?= $lastMsg ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Main Chat Area -->
        <div style="flex: 1; display: flex; flex-direction: column; background: var(--bg);">
            <?php if ($activeUser): 
                $aName = h($activeUser['first_name'] . ' ' . $activeUser['last_name']);
            ?>
                <!-- Chat Header -->
                <div style="padding: 15px 20px; border-bottom: 1px solid var(--line); display: flex; align-items: center; gap: 12px; background: var(--surface);">
                    <?= render_avatar($activeUser, 'small') ?>
                    <div>
                        <h3 style="margin: 0; font-size: 1.1rem; color: var(--ink);"><?= $aName ?></h3>
                        <p style="margin: 0; font-size: 0.8rem; color: var(--muted); text-transform: capitalize;"><?= h($activeUser['role']) ?></p>
                    </div>
                </div>
                
                <!-- Chat Messages -->
                <div id="chat-messages" style="flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 16px;">
                    <?php if (!$rows): ?>
                        <div style="margin: auto; text-align: center; color: var(--muted);">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48" style="margin-bottom: 10px;">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                            </svg>
                            <p>Send a message to start the conversation with <?= $aName ?>.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($rows as $msg):
                            $isMine = (int) $msg['sender_id'] === (int) $user['user_id'];
                            $time   = date('g:i A', strtotime($msg['sent_at']));
                        ?>
                            <div style="display: flex; flex-direction: column; max-width: 75%; <?= $isMine ? 'align-self: flex-end; align-items: flex-end;' : 'align-self: flex-start; align-items: flex-start;' ?>">
                                <div style="padding: 10px 14px; border-radius: 14px; font-size: 0.95rem; line-height: 1.4; word-break: break-word; <?= $isMine ? 'background: var(--lime); color: #000; border-bottom-right-radius: 4px;' : 'background: var(--surface); color: var(--ink); border-bottom-left-radius: 4px; border: 1px solid var(--line);' ?>">
                                    <?= nl2br(h($msg['message_text'])) ?>
                                </div>
                                <span style="font-size: 0.7rem; color: var(--muted); margin-top: 4px;"><?= h($time) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Chat Input -->
                <div style="padding: 15px 20px; border-top: 1px solid var(--line); background: var(--surface);">
                    <form method="post" style="display: flex; gap: 10px; align-items: flex-end;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="send">
                        <input type="hidden" name="recipient_id" value="<?= $activeChatId ?>">
                        <textarea name="message_text" rows="1" placeholder="Type a message..." required style="flex: 1; resize: none; border-radius: 20px; padding: 12px 16px; border: 1px solid var(--line); background: var(--bg); color: var(--ink); font-family: inherit; font-size: 0.95rem; outline: none; line-height: 1.5; overflow-y: hidden;" oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px'"></textarea>
                        <button type="submit" style="background: var(--lime); color: var(--bg); border: none; border-radius: 50%; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="margin-right: 2px;">
                                <line x1="22" y1="2" x2="11" y2="13"></line>
                                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                            </svg>
                        </button>
                    </form>
                </div>
                
                <script>
                    // Scroll to bottom of chat
                    const chatBox = document.getElementById('chat-messages');
                    chatBox.scrollTop = chatBox.scrollHeight;
                </script>
            <?php else: ?>
                <div style="margin: auto; text-align: center; color: var(--muted);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48" style="margin-bottom: 15px; opacity: 0.5;">
                        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
                    </svg>
                    <h2>Your Messages</h2>
                    <p>Select a conversation or start a new one.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

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
        <div class="modal-body" style="padding: 0;">
            <div style="max-height: 400px; overflow-y: auto;">
                <?php foreach ($contacts as $c): ?>
                    <a href="index.php?page=messages&chat=<?= (int) $c['user_id'] ?>" style="display: flex; align-items: center; gap: 12px; padding: 12px 20px; border-bottom: 1px solid var(--line); text-decoration: none; color: inherit; transition: background 0.2s;" onmouseover="this.style.background='var(--panel-hover)'" onmouseout="this.style.background='transparent'">
                        <?= render_avatar($c) ?>
                        <div>
                            <strong style="display: block; color: var(--ink);"><?= h($c['first_name'] . ' ' . $c['last_name']) ?></strong>
                            <small style="color: var(--muted); text-transform: capitalize;"><?= h($c['role']) ?></small>
                        </div>
                    </a>
                <?php endforeach; ?>
                <?php if (!$contacts): ?>
                    <div style="padding: 20px; text-align: center; color: var(--muted);">No contacts available.</div>
                <?php endif; ?>
            </div>
        </div>
    </dialog>
    <?php
    render_footer();
}

