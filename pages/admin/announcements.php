<?php

declare(strict_types=1);

function announcements_page(): void
{
    $user = require_roles(['platform_admin']);
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        if ($action === 'create') {
            $title = trim((string) post('title'));
            $content = trim((string) post('content'));
            $target = post('target_audience');

            if ($title && $content && in_array($target, ['all', 'gym_owners', 'trainers', 'members'], true)) {
                $pdo->prepare('INSERT INTO announcements (title, content, target_audience, created_by) VALUES (?, ?, ?, ?)')
                    ->execute([$title, $content, $target, $user['user_id']]);
                
                // Notify targeted users
                $where = $target === 'all' ? '1=1' : 'role = ' . $pdo->quote(rtrim($target, 's')); // gym_owners -> gym_owner
                $usersToNotify = $pdo->query("SELECT user_id FROM users WHERE status = 'active' AND $where")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($usersToNotify as $uid) {
                    notify_user((int)$uid, 'system', 'New Announcement: ' . $title, mb_substr($content, 0, 100) . (mb_strlen($content) > 100 ? '...' : ''));
                }
                
                flash('Announcement published successfully.', 'success');
            } else {
                flash('Please fill in all fields correctly.', 'danger');
            }
        } elseif ($action === 'delete') {
            $id = (int) post('announcement_id');
            $pdo->prepare('DELETE FROM announcements WHERE announcement_id = ?')->execute([$id]);
            flash('Announcement deleted.', 'success');
        }
        redirect('announcements');
    }

    $announcements = $pdo->query('
        SELECT a.*, u.first_name, u.last_name 
        FROM announcements a 
        JOIN users u ON u.user_id = a.created_by 
        ORDER BY a.created_at DESC
    ')->fetchAll();

    render_header('Announcements', $user);
?>
    <div class="skeleton-wrapper">
        <section class="panel">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
                <div>
                    <div class="sk sk-title" style="width:140px;margin-bottom:8px"></div>
                    <div class="sk sk-text" style="width:280px;height:12px"></div>
                </div>
                <div class="sk sk-rect" style="width:160px;height:36px;border-radius:18px"></div>
            </div>
            <div style="margin-top:24px; display: grid; gap: 20px;">
                <?php for($i = 0; $i < 3; $i++): ?>
                    <div class="sk sk-rect" style="width:100%;height:140px;border-radius:12px"></div>
                <?php endfor; ?>
            </div>
        </section>
    </div>
    <section class="panel skeleton-content sk-display-block">
        <div class="page-header">
            <div>
                <h1>Announcements</h1>
                <p>Broadcast messages to gyms, trainers, or members.</p>
            </div>
            <button onclick="document.getElementById('createAnnouncementModal').showModal()">+ New Announcement</button>
        </div>

        <dialog id="createAnnouncementModal" class="modal">
            <div class="modal-header">
                <h3>Create Announcement</h3>
                <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="modal-body">
                <form method="post" class="form grid-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    
                    <label style="grid-column: 1 / -1;">Target Audience
                        <select name="target_audience" required>
                            <option value="all">All Users</option>
                            <option value="gym_owners">Gym Owners Only</option>
                            <option value="trainers">Trainers Only</option>
                            <option value="members">Members Only</option>
                        </select>
                    </label>

                    <label style="grid-column: 1 / -1;">Title
                        <input type="text" name="title" required placeholder="Announcement Title">
                    </label>
                    
                    <label style="grid-column: 1 / -1;">Message Content
                        <textarea name="content" required rows="5" placeholder="Write your announcement here..."></textarea>
                    </label>

                    <button type="submit" class="btn-primary" style="grid-column: 1 / -1; margin-top: 10px;">Publish Announcement</button>
                </form>
            </div>
        </dialog>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Target</th>
                        <th>Title</th>
                        <th>Message</th>
                        <th>Author</th>
                        <th style="width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$announcements): ?>
                        <tr><td colspan="6" class="text-center">No announcements have been published yet.</td></tr>
                    <?php else: foreach ($announcements as $ann): ?>
                        <tr>
                            <td style="color:var(--muted); font-size:13px;"><?= h(date('M j, Y', strtotime($ann['created_at']))) ?></td>
                            <td>
                                <span class="badge" style="background: rgba(255,255,255,0.1);">
                                    <?= h(str_replace('_', ' ', strtoupper($ann['target_audience']))) ?>
                                </span>
                            </td>
                            <td>
                                <strong><?= h($ann['title']) ?></strong>
                            </td>
                            <td style="max-width: 300px; font-size: 13px; color: var(--muted); white-space: normal; line-height: 1.4;">
                                <?= nl2br(h($ann['content'])) ?>
                            </td>
                            <td><?= h($ann['first_name'] . ' ' . $ann['last_name']) ?></td>
                            <td>
                                <form method="post" action="index.php?page=announcements" style="margin:0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="announcement_id" value="<?= (int) $ann['announcement_id'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" class="btn-sm btn-ghost" style="color:var(--danger)" data-confirm="Are you sure you want to delete this announcement?" data-confirm-btn="Yes, delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php
    render_footer();
}
