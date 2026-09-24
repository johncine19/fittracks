<?php
declare(strict_types=1);

function messages_page(): void
{
    $user = require_login();
    $role = $user['role'] ?? '';
    $isGymOwner = in_array($role, ['gym_owner', 'admin'], true);
    $currentGymId = 0;

    if ($isGymOwner) {
        $currentGymId = (int) ($user['gym_id'] ?? 0);
        if (!$currentGymId) {
            $currentGymId = (int) scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ? LIMIT 1', [$user['user_id']]);
        }
        if (!$currentGymId) {
            $cg = get_user_gym($user);
            $currentGymId = (int) ($cg['gym_id'] ?? 0);
        }
    } elseif ($role === 'trainer' || $role === 'member') {
        $cg = get_user_gym($user);
        $currentGymId = (int) ($cg['gym_id'] ?? 0);
    }

    $canMessageUser = function(int $targetUserId) use ($user, $role, $isGymOwner, $currentGymId): bool {
        if ($targetUserId <= 0 || (int)$user['user_id'] === $targetUserId) {
            return false;
        }

        if ($role === 'platform_admin') {
            return (bool) scalar('SELECT 1 FROM users WHERE user_id = ? AND status = "active"', [$targetUserId]);
        }

        if ($isGymOwner) {
            if ($currentGymId <= 0) {
                return false;
            }
            return (bool) scalar('
                SELECT 1 FROM users u
                WHERE u.user_id = ?
                  AND u.status = "active"
                  AND (
                      (u.role = "trainer" AND (
                          EXISTS (SELECT 1 FROM trainer_profiles tp WHERE tp.user_id = u.user_id AND tp.gym_id = ?) OR
                          EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?)
                      )) OR
                      (u.role = "member" AND (
                          EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?) OR
                          EXISTS (SELECT 1 FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = u.user_id AND mp.gym_id = ?)
                      ))
                  )
                LIMIT 1',
                [$targetUserId, $currentGymId, $currentGymId, $currentGymId, $currentGymId]
            );
        }

        if ($role === 'trainer') {
            return (bool) scalar('
                SELECT 1 FROM users u
                WHERE u.user_id = ?
                  AND u.status = "active"
                  AND (
                      (u.role IN ("admin", "gym_owner") AND EXISTS (SELECT 1 FROM gyms g WHERE g.owner_user_id = u.user_id AND g.gym_id = ?))
                      OR u.user_id IN (
                          SELECT ta.member_user_id FROM trainer_assignments ta 
                          JOIN trainer_profiles tp ON tp.trainer_id = ta.trainer_id 
                          WHERE tp.user_id = ? AND ta.status = "active"
                      )
                  )
                LIMIT 1',
                [$targetUserId, $currentGymId, $user['user_id']]
            );
        }

        if ($role === 'member') {
            return (bool) scalar('
                SELECT 1 FROM users u
                WHERE u.user_id = ?
                  AND u.status = "active"
                  AND (
                      (u.role = "trainer" AND (
                          EXISTS (SELECT 1 FROM trainer_profiles tp WHERE tp.user_id = u.user_id AND tp.gym_id = ?)
                          OR u.user_id IN (
                              SELECT tp.user_id FROM trainer_assignments ta 
                              JOIN trainer_profiles tp ON tp.trainer_id = ta.trainer_id 
                              WHERE ta.member_user_id = ? AND ta.status = "active"
                          )
                      ))
                      OR (u.role IN ("admin", "gym_owner") AND EXISTS (SELECT 1 FROM gyms g WHERE g.owner_user_id = u.user_id AND g.gym_id = ?))
                  )
                LIMIT 1',
                [$targetUserId, $currentGymId, $user['user_id'], $currentGymId]
            );
        }

        return false;
    };

    $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
              || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));

    // ── AJAX: Search Contacts for Compose Modal ─────────────────────────
    if (($_GET['action'] ?? post('action')) === 'search_contacts') {
        if (ob_get_level()) ob_clean();
        header('Content-Type: application/json');

        $searchQuery = trim((string) ($_GET['q'] ?? post('q') ?? ''));
        $searchPattern = '%' . $searchQuery . '%';

        $results = [];
        if ($isGymOwner) {
            if ($currentGymId > 0) {
                $sql = 'SELECT u.user_id, u.first_name, u.last_name, u.profile_picture, u.role 
                        FROM users u
                        WHERE u.user_id != ?
                          AND u.status = "active"
                          AND (
                              (u.role = "trainer" AND (
                                  EXISTS (SELECT 1 FROM trainer_profiles tp WHERE tp.user_id = u.user_id AND tp.gym_id = ?) OR
                                  EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?)
                              )) OR
                              (u.role = "member" AND (
                                  EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?) OR
                                  EXISTS (SELECT 1 FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = u.user_id AND mp.gym_id = ?)
                              ))
                          )';
                $params = [$user['user_id'], $currentGymId, $currentGymId, $currentGymId, $currentGymId];
                if ($searchQuery !== '') {
                    $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, " ", u.last_name) LIKE ?)';
                    $params[] = $searchPattern;
                    $params[] = $searchPattern;
                    $params[] = $searchPattern;
                }
                $sql .= ' ORDER BY u.first_name ASC LIMIT 50';
                $results = query_all($sql, $params);
            }
        } elseif ($role === 'trainer') {
            $sql = 'SELECT u.user_id, u.first_name, u.last_name, u.profile_picture, u.role FROM users u
                    WHERE u.user_id != ?
                      AND u.status = "active"
                      AND (
                          (u.role IN ("admin", "gym_owner") AND EXISTS (SELECT 1 FROM gyms g WHERE g.owner_user_id = u.user_id AND g.gym_id = ?))
                          OR u.user_id IN (
                              SELECT ta.member_user_id FROM trainer_assignments ta 
                              JOIN trainer_profiles tp ON tp.trainer_id = ta.trainer_id 
                              WHERE tp.user_id = ? AND ta.status = "active"
                          )
                      )';
            $params = [$user['user_id'], $currentGymId, $user['user_id']];
            if ($searchQuery !== '') {
                $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, " ", u.last_name) LIKE ?)';
                $params[] = $searchPattern;
                $params[] = $searchPattern;
                $params[] = $searchPattern;
            }
            $sql .= ' ORDER BY u.first_name ASC LIMIT 50';
            $results = query_all($sql, $params);
        } elseif ($role === 'member') {
            $sql = 'SELECT u.user_id, u.first_name, u.last_name, u.profile_picture, u.role FROM users u
                    WHERE u.user_id != ?
                      AND u.status = "active"
                      AND (
                          (u.role = "trainer" AND (
                              EXISTS (SELECT 1 FROM trainer_profiles tp WHERE tp.user_id = u.user_id AND tp.gym_id = ?)
                              OR u.user_id IN (
                                  SELECT tp.user_id FROM trainer_assignments ta 
                                  JOIN trainer_profiles tp ON tp.trainer_id = ta.trainer_id 
                                  WHERE ta.member_user_id = ? AND ta.status = "active"
                              )
                          ))
                          OR (u.role IN ("admin", "gym_owner") AND EXISTS (SELECT 1 FROM gyms g WHERE g.owner_user_id = u.user_id AND g.gym_id = ?))
                      )';
            $params = [$user['user_id'], $currentGymId, $user['user_id'], $currentGymId];
            if ($searchQuery !== '') {
                $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, " ", u.last_name) LIKE ?)';
                $params[] = $searchPattern;
                $params[] = $searchPattern;
                $params[] = $searchPattern;
            }
            $sql .= ' ORDER BY u.first_name ASC LIMIT 50';
            $results = query_all($sql, $params);
        } else {
            $sql = 'SELECT user_id, first_name, last_name, profile_picture, role FROM users
                    WHERE user_id != ?
                      AND status = "active"
                      AND role IN ("gym_owner", "trainer", "member", "admin")';
            $params = [$user['user_id']];
            if ($searchQuery !== '') {
                $sql .= ' AND (first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, " ", last_name) LIKE ?)';
                $params[] = $searchPattern;
                $params[] = $searchPattern;
                $params[] = $searchPattern;
            }
            $sql .= ' ORDER BY first_name ASC LIMIT 50';
            $results = query_all($sql, $params);
        }

        $contactsData = [];
        foreach ($results as $c) {
            $contactsData[] = [
                'user_id'    => (int) $c['user_id'],
                'first_name' => $c['first_name'],
                'last_name'  => $c['last_name'],
                'full_name'  => trim($c['first_name'] . ' ' . $c['last_name']),
                'role'       => ucfirst($c['role']),
                'avatar_html'=> render_avatar($c),
                'chat_url'   => 'index.php?page=messages&chat=' . (int) $c['user_id']
            ];
        }

        echo json_encode(['contacts' => $contactsData]);
        exit;
    }

    // ── AJAX: Poll for new messages ──────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'poll_messages' && $isAjax) {
        if (ob_get_level()) ob_clean();
        header('Content-Type: application/json');
        $recipientId = (int) post('recipient_id');
        $lastId      = (int) post('last_message_id');
        if ($recipientId <= 0 || !$canMessageUser($recipientId)) { echo json_encode(['messages' => []]); exit; }
        $newRows = query_all(
            'SELECT m.message_id, m.sender_id, m.message_text, m.sent_at,
                    CONCAT(s.first_name, " ", s.last_name) AS sender_name
             FROM trainer_messages m
             JOIN users s ON s.user_id = m.sender_id
             WHERE ((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?))
               AND m.message_id > ?
             ORDER BY m.sent_at ASC',
            [$user['user_id'], $recipientId, $recipientId, $user['user_id'], $lastId]
        );
        // Mark incoming as read
        if ($newRows) {
            db()->prepare('UPDATE trainer_messages SET is_read = 1 WHERE sender_id = ? AND recipient_id = ? AND is_read = 0')
               ->execute([$recipientId, $user['user_id']]);
        }
        echo json_encode(['messages' => $newRows, 'my_id' => (int) $user['user_id']]);
        exit;
    }

    // Handle sending a message
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'send') {
        $recipientId = (int) post('recipient_id');
        $text = trim((string) post('message_text'));
        $newMessageId = null;
        $error = null;

        if ($recipientId && $text !== '') {
            if (!$canMessageUser($recipientId)) {
                $error = 'You can only message members and trainers within your gym.';
            } elseif (mb_strlen($text) > 1000) {
                $error = 'Message is too long. Maximum 1000 characters.';
            } else {
                $pdo = db();
                $pdo->prepare('INSERT INTO trainer_messages (sender_id, recipient_id, message_text) VALUES (?, ?, ?)')
                   ->execute([$user['user_id'], $recipientId, $text]);
                $newMessageId = (int) $pdo->lastInsertId();
                $senderName = $user['first_name'] . ' ' . $user['last_name'];
                $title = 'New message from ' . $senderName;
                $unreadCount = (int) scalar('SELECT COUNT(*) FROM trainer_messages WHERE sender_id = ? AND recipient_id = ? AND is_read = 0', [$user['user_id'], $recipientId]);
                if ($unreadCount > 1) {
                    $msgText = 'You have ' . $unreadCount . ' new messages.';
                    db()->prepare('UPDATE notifications SET message = ?, reference_id = ?, created_at = CURRENT_TIMESTAMP WHERE user_id = ? AND title = ? AND type = "coach_message" AND is_read = 0')
                      ->execute([$msgText, $user['user_id'], $recipientId, $title]);
                } else {
                    notify_user($recipientId, 'coach_message', $title, $text, (int) $user['user_id']);
                }
            }
        } elseif (!$recipientId) {
            $error = 'Invalid recipient.';
        } elseif ($text === '') {
            $error = 'Message cannot be empty.';
        }

        // AJAX response
        if ($isAjax) {
            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json');
            if ($error) {
                echo json_encode(['success' => false, 'error' => $error]);
            } else {
                $sentAtRaw = scalar('SELECT sent_at FROM trainer_messages WHERE message_id = ?', [$newMessageId]);
                $sentFormatted = $sentAtRaw ? date('g:i A', strtotime($sentAtRaw)) : date('g:i A');
                echo json_encode([
                    'success'    => true,
                    'message_id' => $newMessageId,
                    'sent_at'    => $sentFormatted,
                    'text'       => $text,
                ]);
            }
            exit;
        }

        // Fallback: normal redirect
        flash($error ?? 'Message sent.', $error ? 'danger' : 'success');
        header('Location: index.php?page=messages' . ($recipientId && !$error ? '&chat=' . $recipientId : ''));
        exit;
    }

    // Get list of users this person has conversed with
    if ($isGymOwner) {
        $conversations = query_all(
            'SELECT u.user_id, u.first_name, u.last_name, u.profile_picture, u.role,
                    (SELECT message_text FROM trainer_messages WHERE (sender_id = u.user_id AND recipient_id = ?) OR (sender_id = ? AND recipient_id = u.user_id) ORDER BY sent_at DESC LIMIT 1) as last_message,
                    (SELECT sent_at FROM trainer_messages WHERE (sender_id = u.user_id AND recipient_id = ?) OR (sender_id = ? AND recipient_id = u.user_id) ORDER BY sent_at DESC LIMIT 1) as last_time
             FROM users u
             WHERE (
                u.user_id IN (
                   SELECT sender_id FROM trainer_messages WHERE recipient_id = ?
                   UNION
                   SELECT recipient_id FROM trainer_messages WHERE sender_id = ?
                )
                OR (u.role = "trainer" AND u.status = "active" AND (
                    EXISTS (SELECT 1 FROM trainer_profiles tp WHERE tp.user_id = u.user_id AND tp.gym_id = ?) OR
                    EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?)
                ))
             )
             AND (
                (u.role = "trainer" AND (
                    EXISTS (SELECT 1 FROM trainer_profiles tp WHERE tp.user_id = u.user_id AND tp.gym_id = ?) OR
                    EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?)
                )) OR
                (u.role = "member" AND (
                    EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?) OR
                    EXISTS (SELECT 1 FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = u.user_id AND mp.gym_id = ?)
                ))
             )
             ORDER BY last_time DESC, u.first_name ASC',
            [
                $user['user_id'], $user['user_id'], $user['user_id'], $user['user_id'],
                $user['user_id'], $user['user_id'],
                $currentGymId, $currentGymId,
                $currentGymId, $currentGymId, $currentGymId, $currentGymId
            ]
        );
    } elseif ($role === 'trainer') {
        $conversations = query_all(
            'SELECT u.user_id, u.first_name, u.last_name, u.profile_picture, u.role,
                    (SELECT message_text FROM trainer_messages WHERE (sender_id = u.user_id AND recipient_id = ?) OR (sender_id = ? AND recipient_id = u.user_id) ORDER BY sent_at DESC LIMIT 1) as last_message,
                    (SELECT sent_at FROM trainer_messages WHERE (sender_id = u.user_id AND recipient_id = ?) OR (sender_id = ? AND recipient_id = u.user_id) ORDER BY sent_at DESC LIMIT 1) as last_time
             FROM users u
             WHERE (
                u.user_id IN (
                   SELECT sender_id FROM trainer_messages WHERE recipient_id = ?
                   UNION
                   SELECT recipient_id FROM trainer_messages WHERE sender_id = ?
                )
                OR (u.role IN ("admin", "gym_owner") AND u.status = "active" AND EXISTS (SELECT 1 FROM gyms g WHERE g.owner_user_id = u.user_id AND g.gym_id = ?))
                OR u.user_id IN (SELECT ta.member_user_id FROM trainer_assignments ta JOIN trainer_profiles tp ON tp.trainer_id = ta.trainer_id WHERE tp.user_id = ? AND ta.status = "active")
             )
             ORDER BY last_time DESC, u.first_name ASC',
            [
                $user['user_id'], $user['user_id'], $user['user_id'], $user['user_id'],
                $user['user_id'], $user['user_id'],
                $currentGymId,
                $user['user_id']
            ]
        );
    } else {
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
             ORDER BY last_time DESC, u.first_name ASC',
            [
                $user['user_id'], $user['user_id'], $user['user_id'], $user['user_id'],
                $user['user_id'], $user['user_id']
            ]
        );
    }

    $activeChatId = isset($_GET['chat']) ? (int) $_GET['chat'] : null;
    $activeUser = null;

    if ($activeChatId) {
        if (!$canMessageUser($activeChatId)) {
            $activeChatId = null;
            flash('You can only message members and trainers within your gym.', 'danger');
        } else {
            $found = false;
            foreach ($conversations as $c) {
                if ((int)$c['user_id'] === $activeChatId) {
                    $found = true;
                    $activeUser = $c;
                    break;
                }
            }
            if (!$found) {
                $stmt = db()->prepare('SELECT user_id, first_name, last_name, profile_picture, role FROM users WHERE user_id = ? AND status = "active"');
                $stmt->execute([$activeChatId]);
                $activeUser = $stmt->fetch();
                if ($activeUser) {
                    array_unshift($conversations, $activeUser);
                }
            }
        }
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
    if ($isGymOwner) {
        $contacts = query_all(
            'SELECT u.user_id, u.first_name, u.last_name, u.profile_picture, u.role 
             FROM users u
             WHERE u.user_id != ?
               AND u.status = "active"
               AND (
                   (u.role = "trainer" AND (
                       EXISTS (SELECT 1 FROM trainer_profiles tp WHERE tp.user_id = u.user_id AND tp.gym_id = ?) OR
                       EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?)
                   )) OR
                   (u.role = "member" AND (
                       EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?) OR
                       EXISTS (SELECT 1 FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = u.user_id AND mp.gym_id = ?)
                   ))
               )
             ORDER BY u.first_name ASC',
            [$user['user_id'], $currentGymId, $currentGymId, $currentGymId, $currentGymId]
        );
    } elseif ($role === 'trainer') {
        $contacts = query_all(
            'SELECT u.user_id, u.first_name, u.last_name, u.profile_picture, u.role FROM users u
             WHERE u.user_id != ?
               AND u.status = "active"
               AND (
                   (u.role IN ("admin", "gym_owner") AND EXISTS (SELECT 1 FROM gyms g WHERE g.owner_user_id = u.user_id AND g.gym_id = ?))
                   OR u.user_id IN (
                       SELECT ta.member_user_id FROM trainer_assignments ta 
                       JOIN trainer_profiles tp ON tp.trainer_id = ta.trainer_id 
                       WHERE tp.user_id = ? AND ta.status = "active"
                   )
               )
             ORDER BY u.first_name ASC',
            [$user['user_id'], $currentGymId, $user['user_id']]
        );
    } elseif ($role === 'member') {
        $contacts = query_all(
            'SELECT u.user_id, u.first_name, u.last_name, u.profile_picture, u.role FROM users u
             WHERE u.user_id != ?
               AND u.status = "active"
               AND (
                   (u.role = "trainer" AND (
                       EXISTS (SELECT 1 FROM trainer_profiles tp WHERE tp.user_id = u.user_id AND tp.gym_id = ?)
                       OR u.user_id IN (
                           SELECT tp.user_id FROM trainer_assignments ta 
                           JOIN trainer_profiles tp ON tp.trainer_id = ta.trainer_id 
                           WHERE ta.member_user_id = ? AND ta.status = "active"
                       )
                   ))
                   OR (u.role IN ("admin", "gym_owner") AND EXISTS (SELECT 1 FROM gyms g WHERE g.owner_user_id = u.user_id AND g.gym_id = ?))
               )
             ORDER BY u.first_name ASC',
            [$user['user_id'], $currentGymId, $user['user_id'], $currentGymId]
        );
    } else {
        $contacts = query_all(
            'SELECT user_id, first_name, last_name, profile_picture, role FROM users
             WHERE user_id != ?
               AND status = "active"
               AND role IN ("gym_owner", "trainer", "member", "admin")
             ORDER BY first_name ASC',
            [$user['user_id']]
        );
    }

    render_header('Messages', $user);
    ?>
    <?php render_skeleton_chat(); ?>
    <section class="panel wide skeleton-content sk-display-flex msg-container <?= $activeChatId ? 'has-active-chat' : '' ?>">
        
        <!-- Sidebar -->
        <div class="msg-sidebar">
            <div style="padding: 20px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center;">
                <h2 style="margin: 0; font-size: 1.2rem;">Messages</h2>
                <button onclick="openComposeModal()" style="background: none; border: none; color: var(--lime); cursor: pointer; padding: 5px;" title="New Message">
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
        <div class="msg-main">
            <?php if ($activeUser): 
                $aName = h($activeUser['first_name'] . ' ' . $activeUser['last_name']);
            ?>
                <!-- Chat Header -->
                <div class="chat-header" style="flex-shrink: 0; padding: 15px 20px; border-bottom: 1px solid var(--line); display: flex; align-items: center; gap: 12px; background: var(--surface);">
                    <a href="index.php?page=messages" class="mobile-back-btn" title="Back to messages">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                            <path d="M19 12H5M12 19l-7-7 7-7"/>
                        </svg>
                    </a>
                    <div style="display: flex; align-items: center; gap: 12px; flex: 1;">
                        <?= render_avatar($activeUser, 'small') ?>
                        <div>
                            <h3 style="margin: 0; font-size: 1.1rem; color: var(--ink);"><?= $aName ?></h3>
                            <p style="margin: 0; font-size: 0.8rem; color: var(--muted); text-transform: capitalize;"><?= h($activeUser['role']) ?></p>
                        </div>
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
                        <?php
                        $prevSenderId = null;
                        foreach ($rows as $msg):
                            $isMine      = (int) $msg['sender_id'] === (int) $user['user_id'];
                            $time        = date('g:i A', strtotime($msg['sent_at']));
                            $isSameSender = $prevSenderId === (int) $msg['sender_id'];
                            $prevSenderId = (int) $msg['sender_id'];
                            $gap = $isSameSender ? '4px' : '14px';
                        ?>
                            <div data-msg-id="<?= (int) $msg['message_id'] ?>" data-sender-id="<?= (int) $msg['sender_id'] ?>" style="display: flex; flex-direction: column; max-width: 75%; margin-bottom: <?= $gap ?>; <?= $isMine ? 'align-self: flex-end; align-items: flex-end;' : 'align-self: flex-start; align-items: flex-start;' ?>">
                                <?php if (!$isMine && !$isSameSender): ?>
                                    <span style="font-size: 0.8rem; color: #3b82f6; margin-bottom: 4px; padding-left: 2px;"><?= h($msg['sender_name']) ?></span>
                                <?php endif; ?>
                                <div style="padding: 10px 14px; border-radius: 14px; font-size: 0.95rem; line-height: 1.4; word-break: break-word; <?= $isMine ? 'background: var(--lime); color: #000; border-bottom-right-radius: 4px;' : 'background: var(--surface); color: var(--ink); border-bottom-left-radius: 4px; border: 1px solid var(--line);' ?>">
                                    <?= nl2br(h($msg['message_text'])) ?>
                                </div>
                                <?php if (!$isSameSender || true): // always show time on last bubble – simplified: always show ?>
                                    <span style="font-size: 0.7rem; color: var(--muted); margin-top: 4px;"><?= h($time) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Chat Input -->
                <div style="padding: 15px 20px; border-top: 1px solid var(--line); background: var(--surface);">
                    <form id="msg-send-form" method="post" style="display: flex; gap: 10px; align-items: flex-end;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="send">
                        <input type="hidden" name="recipient_id" id="msg-recipient-id" value="<?= $activeChatId ?>">
                        <textarea id="msg-textarea" name="message_text" rows="1" placeholder="Type a message..." required style="flex: 1; resize: none; border-radius: 20px; padding: 12px 16px; border: 1px solid var(--line); background: var(--bg); color: var(--ink); font-family: inherit; font-size: 0.95rem; outline: none; line-height: 1.5; overflow-y: hidden;" oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px'"></textarea>
                        <button id="msg-send-btn" type="submit" style="background: var(--lime); color: var(--bg); border: none; border-radius: 50%; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0; transition: all 0.2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="margin-right: 2px;">
                                <line x1="22" y1="2" x2="11" y2="13"></line>
                                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                            </svg>
                        </button>
                    </form>
                </div>
                
                <script>
                (function() {
                    const chatBox   = document.getElementById('chat-messages');
                    const form      = document.getElementById('msg-send-form');
                    const textarea  = document.getElementById('msg-textarea');
                    const sendBtn   = document.getElementById('msg-send-btn');
                    const recipientId = document.getElementById('msg-recipient-id').value;
                    const csrfToken = form.querySelector('[name="csrf_token"]').value;
                    const myId      = <?= (int) $user['user_id'] ?>;

                    // Track highest seen message ID and last sender for grouping
                    const allMsgs = chatBox.querySelectorAll('[data-msg-id]');
                    let lastMsgId    = allMsgs.length ? parseInt(allMsgs[allMsgs.length - 1].dataset.msgId)    : 0;
                    let lastSenderId = allMsgs.length ? parseInt(allMsgs[allMsgs.length - 1].dataset.senderId) : null;

                    // Scroll to bottom
                    chatBox.scrollTop = chatBox.scrollHeight;

                    /**
                     * Build a chat bubble element.
                     * @param {string}  text
                     * @param {string}  timeStr   — displayed below the bubble
                     * @param {boolean} isMine
                     * @param {string}  senderName — only shown when showName = true
                     * @param {number}  msgId
                     * @param {boolean} showName   — false when same sender as previous bubble
                     * @param {string}  senderId   — stored as data-sender-id for future grouping checks
                     */
                    function buildBubble(text, timeStr, isMine, senderName, msgId, showName, senderId) {
                        const wrap = document.createElement('div');
                        wrap.dataset.msgId    = msgId   || '';
                        wrap.dataset.senderId = senderId || (isMine ? myId : '');
                        const gap = showName ? '14px' : '4px';
                        wrap.style.cssText = `display:flex;flex-direction:column;max-width:75%;margin-bottom:${gap};${
                            isMine ? 'align-self:flex-end;align-items:flex-end;' : 'align-self:flex-start;align-items:flex-start;'
                        }`;
                        if (!isMine && showName) {
                            const nameEl = document.createElement('span');
                            nameEl.style.cssText = 'font-size:0.8rem;color:#3b82f6;margin-bottom:4px;padding-left:2px;';
                            nameEl.textContent = senderName;
                            wrap.appendChild(nameEl);
                        }
                        const bubble = document.createElement('div');
                        bubble.style.cssText = `padding:10px 14px;border-radius:14px;font-size:0.95rem;line-height:1.4;word-break:break-word;${
                            isMine
                                ? 'background:var(--lime);color:#000;border-bottom-right-radius:4px;'
                                : 'background:var(--surface);color:var(--ink);border-bottom-left-radius:4px;border:1px solid var(--line);'
                        }`;
                        bubble.innerHTML = text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
                        wrap.appendChild(bubble);
                        const timeEl = document.createElement('span');
                        timeEl.style.cssText = 'font-size:0.7rem;color:var(--muted);margin-top:4px;';
                        timeEl.textContent = timeStr;
                        wrap.appendChild(timeEl);
                        return wrap;
                    }

                    // ── Send via AJAX ─────────────────────────────────────────
                    form.addEventListener('submit', async function(e) {
                        e.preventDefault();
                        const text = textarea.value.trim();
                        if (!text) return;

                        // Optimistic UI — group with previous if I was last sender
                        const isSameAsPrev = lastSenderId === myId;
                        const bubble = buildBubble(text, '···', true, '', 0, !isSameAsPrev, myId);
                        chatBox.appendChild(bubble);
                        chatBox.scrollTop = chatBox.scrollHeight;
                        lastSenderId = myId; // update grouping tracker
                        textarea.value = '';
                        textarea.style.height = '';
                        sendBtn.disabled = true;
                        sendBtn.style.opacity = '0.6';

                        try {
                            const body = new URLSearchParams({
                                action: 'send',
                                recipient_id: recipientId,
                                message_text: text,
                                csrf_token: csrfToken
                            });
                            const res = await fetch('index.php?page=messages', {
                                method: 'POST',
                                headers: { 'X-Requested-With': 'XMLHttpRequest',
                                           'Content-Type': 'application/x-www-form-urlencoded' },
                                body: body.toString()
                            });
                            const data = await res.json();
                            if (data.success) {
                                bubble.dataset.msgId = data.message_id;
                                lastMsgId = Math.max(lastMsgId, data.message_id);
                                const timeEl = bubble.querySelector('span:last-child');
                                if (timeEl) timeEl.textContent = data.sent_at + ' ✓';
                            } else {
                                bubble.remove();
                                lastSenderId = null; // reset since bubble was removed
                                Swal.fire({icon:'error', title:'Error', text: data.error || 'Could not send message.', background:'var(--bg)', color:'var(--ink)'});
                            }
                        } catch (err) {
                            bubble.remove();
                            lastSenderId = null;
                            Swal.fire({icon:'error', title:'Network Error', text:'Message not sent. Please try again.', background:'var(--bg)', color:'var(--ink)'});
                        } finally {
                            sendBtn.disabled = false;
                            sendBtn.style.opacity = '1';
                        }
                    });

                    // Enter = send (Shift+Enter = newline)
                    textarea.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            form.dispatchEvent(new Event('submit', {cancelable: true}));
                        }
                    });

                    // ── Poll for incoming messages every 4s ──────────────────
                    setInterval(async function() {
                        if (document.hidden) return;
                        try {
                            const body = new URLSearchParams({
                                action: 'poll_messages',
                                recipient_id: recipientId,
                                last_message_id: lastMsgId,
                                csrf_token: csrfToken
                            });
                            const res = await fetch('index.php?page=messages', {
                                method: 'POST',
                                headers: { 'X-Requested-With': 'XMLHttpRequest',
                                           'Content-Type': 'application/x-www-form-urlencoded' },
                                body: body.toString()
                            });
                            const data = await res.json();
                            if (data.messages && data.messages.length) {
                                const wasAtBottom = chatBox.scrollHeight - chatBox.scrollTop - chatBox.clientHeight < 60;
                                data.messages.forEach(function(m) {
                                    const isMine   = parseInt(m.sender_id) === myId;
                                    const senderId = parseInt(m.sender_id);
                                    // Skip messages already rendered (optimistic or duplicate)
                                    if (chatBox.querySelector('[data-msg-id="' + m.message_id + '"]')) return;
                                    // Format time from DB timestamp (same as PHP template)
                                    const t = new Date(m.sent_at.replace(' ', 'T'));
                                    const timeStr = t.toLocaleTimeString([], {hour:'numeric', minute:'2-digit'});
                                    const showName = senderId !== lastSenderId;
                                    chatBox.appendChild(buildBubble(m.message_text, timeStr, isMine, m.sender_name, m.message_id, showName, senderId));
                                    lastMsgId    = Math.max(lastMsgId, parseInt(m.message_id));
                                    lastSenderId = senderId;
                                });
                                if (wasAtBottom) chatBox.scrollTop = chatBox.scrollHeight;
                            }
                        } catch (_) {}
                    }, 4000);
                })();
                </script>


            <?php else: ?>
                <div style="margin: auto; text-align: center; color: var(--muted);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48" style="margin-bottom: 15px; opacity: 0.5;">
                        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
                    </svg>
                    <h2>Your Messages</h2>
                    <p>Select a conversation or start a new one.</p>
                    <button type="button" class="btn btn-outline" style="margin-top: 15px;" onclick="openComposeModal()">Start a conversation</button>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Compose modal -->
    <dialog id="composeModal" class="modal" style="width: 100%; max-width: 480px; overflow: hidden; padding: 0;">
        <div class="modal-header" style="padding: 16px 20px; border-bottom: 1px solid var(--line);">
            <h3 style="margin: 0; font-size: 1.1rem; display: flex; align-items: center; gap: 8px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="color: var(--lime);">
                    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                </svg>
                New Message
            </h3>
            <button class="modal-close" onclick="closeComposeModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <!-- Sticky Live Search Bar -->
        <div style="padding: 12px 16px; border-bottom: 1px solid var(--line); background: var(--surface);">
            <div style="position: relative; display: flex; align-items: center;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"
                     style="position: absolute; left: 12px; color: var(--muted); pointer-events: none;">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text"
                       id="contactSearchInput"
                       placeholder="Search members or trainers..."
                       autocomplete="off"
                       style="width: 100%; padding: 9px 36px 9px 36px; border-radius: 8px; border: 1px solid var(--line); background: var(--panel); color: var(--ink); font-size: 0.9rem; outline: none; transition: border-color 0.2s, box-shadow 0.2s;"
                       onfocus="this.style.borderColor='var(--lime)';"
                       onblur="this.style.borderColor='var(--line)';"
                >
                <button type="button"
                        id="contactSearchClear"
                        onclick="clearContactSearch()"
                        title="Clear search"
                        style="display: none; position: absolute; right: 10px; background: none; border: none; color: var(--muted); cursor: pointer; padding: 2px 6px; border-radius: 50%; font-size: 14px; line-height: 1;">
                    ✕
                </button>
            </div>
        </div>

        <div class="modal-body" style="padding: 0;">
            <div id="composeContactsList" style="max-height: 380px; overflow-y: auto;">
                <?php foreach ($contacts as $c): ?>
                    <a href="index.php?page=messages&chat=<?= (int) $c['user_id'] ?>"
                       class="compose-contact-item"
                       data-user-id="<?= (int) $c['user_id'] ?>"
                       data-name="<?= strtolower(h($c['first_name'] . ' ' . $c['last_name'])) ?>"
                       data-role="<?= strtolower(h($c['role'])) ?>"
                       style="display: flex; align-items: center; gap: 12px; padding: 12px 18px; border-bottom: 1px solid var(--line); text-decoration: none; color: inherit; transition: background 0.2s;"
                       onmouseover="this.style.background='var(--panel-hover)'"
                       onmouseout="this.style.background='transparent'">
                        <?= render_avatar($c) ?>
                        <div style="flex: 1; min-width: 0;">
                            <strong style="display: block; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= h($c['first_name'] . ' ' . $c['last_name']) ?></strong>
                            <small style="color: var(--muted); text-transform: capitalize;"><?= h($c['role']) ?></small>
                        </div>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="color: var(--muted); flex-shrink: 0; opacity: 0.6;">
                            <path d="M9 18l6-6-6-6"/>
                        </svg>
                    </a>
                <?php endforeach; ?>
                
                <!-- Empty State -->
                <div id="contactNoResults" style="<?= empty($contacts) ? 'display: block;' : 'display: none;' ?> padding: 32px 20px; text-align: center; color: var(--muted);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="40" height="40" style="margin-bottom: 8px; opacity: 0.4;">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <div id="contactNoResultsTitle" style="font-weight: 500; color: var(--ink); margin-bottom: 4px;">
                        <?= empty($contacts) ? 'No contacts available' : 'No matches found' ?>
                    </div>
                    <p id="contactNoResultsSub" style="margin: 0; font-size: 0.85rem;">
                        <?= empty($contacts) ? 'There are no active members or trainers you can message.' : 'Try a different name or role keyword.' ?>
                    </p>
                </div>
            </div>
        </div>
    </dialog>

    <script>
    function openComposeModal() {
        const modal = document.getElementById('composeModal');
        if (!modal) return;
        if (typeof modal.showModal === 'function') {
            modal.showModal();
        } else {
            modal.setAttribute('open', '');
        }
        const input = document.getElementById('contactSearchInput');
        if (input) {
            input.value = '';
            clearContactSearch(false);
            setTimeout(() => input.focus(), 60);
        }
    }

    function closeComposeModal() {
        const modal = document.getElementById('composeModal');
        if (modal) {
            if (typeof modal.close === 'function') modal.close();
            else modal.removeAttribute('open');
        }
    }

    let contactSearchTimer = null;
    const contactSearchInput = document.getElementById('contactSearchInput');
    const contactSearchClear = document.getElementById('contactSearchClear');
    const contactsContainer = document.getElementById('composeContactsList');
    const contactNoResults = document.getElementById('contactNoResults');
    const contactNoResultsTitle = document.getElementById('contactNoResultsTitle');
    const contactNoResultsSub = document.getElementById('contactNoResultsSub');

    function clearContactSearch(focusInput = true) {
        if (contactSearchInput) contactSearchInput.value = '';
        if (contactSearchClear) contactSearchClear.style.display = 'none';

        const items = contactsContainer ? contactsContainer.querySelectorAll('.compose-contact-item') : [];
        items.forEach(el => el.style.display = 'flex');

        if (contactNoResults) {
            contactNoResults.style.display = items.length === 0 ? 'block' : 'none';
            if (contactNoResultsTitle) contactNoResultsTitle.textContent = 'No contacts available';
            if (contactNoResultsSub) contactNoResultsSub.textContent = 'There are no active members or trainers you can message.';
        }

        if (focusInput && contactSearchInput) {
            contactSearchInput.focus();
        }
    }

    function escapeContactHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, function(m) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[m];
        });
    }

    if (contactSearchInput) {
        contactSearchInput.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();

            // Toggle clear button
            if (contactSearchClear) {
                contactSearchClear.style.display = query ? 'flex' : 'none';
            }

            // STEP 1: Instant Local Filter (0ms)
            const items = contactsContainer ? contactsContainer.querySelectorAll('.compose-contact-item') : [];
            let localMatches = 0;

            items.forEach(el => {
                const name = el.getAttribute('data-name') || '';
                const role = el.getAttribute('data-role') || '';
                if (!query || name.includes(query) || role.includes(query)) {
                    el.style.display = 'flex';
                    localMatches++;
                } else {
                    el.style.display = 'none';
                }
            });

            if (localMatches > 0) {
                if (contactNoResults) contactNoResults.style.display = 'none';
            } else if (query) {
                if (contactNoResults) {
                    contactNoResults.style.display = 'block';
                    if (contactNoResultsTitle) contactNoResultsTitle.textContent = 'Searching...';
                    if (contactNoResultsSub) contactNoResultsSub.textContent = 'Checking members and trainers in your gym...';
                }
            }

            // STEP 2: Debounced Server Search (250ms)
            if (contactSearchTimer) clearTimeout(contactSearchTimer);

            if (!query) {
                if (items.length > 0 && contactNoResults) contactNoResults.style.display = 'none';
                return;
            }

            contactSearchTimer = setTimeout(() => {
                fetch('index.php?page=messages&action=search_contacts&q=' + encodeURIComponent(query), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(res => res.json())
                .then(data => {
                    // Check if input value changed while request was in flight
                    if (contactSearchInput.value.trim().toLowerCase() !== query) return;

                    const remoteContacts = data.contacts || [];
                    const existingMap = new Map();
                    contactsContainer.querySelectorAll('.compose-contact-item').forEach(el => {
                        const uid = el.getAttribute('data-user-id');
                        if (uid) existingMap.set(uid, el);
                    });

                    remoteContacts.forEach(rc => {
                        const uidStr = String(rc.user_id);
                        if (existingMap.has(uidStr)) {
                            const existingEl = existingMap.get(uidStr);
                            existingEl.style.display = 'flex';
                        } else {
                            const a = document.createElement('a');
                            a.href = rc.chat_url;
                            a.className = 'compose-contact-item';
                            a.setAttribute('data-user-id', rc.user_id);
                            a.setAttribute('data-name', (rc.full_name || '').toLowerCase());
                            a.setAttribute('data-role', (rc.role || '').toLowerCase());
                            a.style.cssText = 'display: flex; align-items: center; gap: 12px; padding: 12px 18px; border-bottom: 1px solid var(--line); text-decoration: none; color: inherit; transition: background 0.2s;';
                            a.onmouseover = () => a.style.background = 'var(--panel-hover)';
                            a.onmouseout = () => a.style.background = 'transparent';
                            a.innerHTML = `
                                ${rc.avatar_html}
                                <div style="flex: 1; min-width: 0;">
                                    <strong style="display: block; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeContactHtml(rc.full_name)}</strong>
                                    <small style="color: var(--muted); text-transform: capitalize;">${escapeContactHtml(rc.role)}</small>
                                </div>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="color: var(--muted); flex-shrink: 0; opacity: 0.6;">
                                    <path d="M9 18l6-6-6-6"/>
                                </svg>
                            `;
                            if (contactNoResults) {
                                contactsContainer.insertBefore(a, contactNoResults);
                            } else {
                                contactsContainer.appendChild(a);
                            }
                        }
                    });

                    let totalVisible = 0;
                    contactsContainer.querySelectorAll('.compose-contact-item').forEach(el => {
                        if (el.style.display === 'flex') totalVisible++;
                    });

                    if (totalVisible === 0) {
                        if (contactNoResults) {
                            contactNoResults.style.display = 'block';
                            if (contactNoResultsTitle) contactNoResultsTitle.textContent = 'No matches found';
                            if (contactNoResultsSub) contactNoResultsSub.textContent = `No members or trainers found matching "${query}".`;
                        }
                    } else {
                        if (contactNoResults) contactNoResults.style.display = 'none';
                    }
                })
                .catch(err => {
                    console.error('Contact search error:', err);
                });
            }, 250);
        });
    }
    </script>
    <?php
    render_footer();
}

