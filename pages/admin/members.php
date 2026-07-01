<?php
declare(strict_types=1);

function members_page(): void
{
    $user = require_roles(['staff']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $phone = (string) post('phone');
            if ($phone !== '') {
                $phone = preg_replace('/[^0-9]/', '', $phone);
                if (strlen($phone) !== 11) {
                    throw new Exception('Phone number must be exactly 11 digits.');
                }
            }
            $email = trim((string) post('email'));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }
            if (scalar('SELECT user_id FROM users WHERE email = ?', [$email])) {
                throw new Exception('A user with that email already exists.');
            }
            if (!is_acceptable_password((string) post('password'))) {
                throw new Exception('Password must be at least 8 characters with a letter and a number.');
            }
            $stmt = $pdo->prepare('INSERT INTO users (role, first_name, last_name, email, password_hash, phone, status, email_verified_at) VALUES ("member", ?, ?, ?, ?, ?, "active", NOW())');
            $stmt->execute([post('first_name'), post('last_name'), $email, password_hash((string) post('password'), PASSWORD_DEFAULT), $phone ?: null]);
            save_member_profile((int) $pdo->lastInsertId());
            $pdo->commit();
            flash('Member registered.', 'success');
            redirect('members');
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('Member registration failed: ' . $e->getMessage(), 'danger');
        }
    }

    $page       = max(1, (int) ($_GET['p'] ?? 1));
    $limit      = 10;
    $offset     = ($page - 1) * $limit;
    $total      = (int) scalar('SELECT COUNT(*) FROM users WHERE role = "member"');
    $totalPages = (int) ceil($total / $limit);
    $members    = db()->query('SELECT u.*, mp.weight_kg, mp.primary_goal FROM users u LEFT JOIN member_profiles mp ON mp.user_id = u.user_id WHERE u.role = "member" ORDER BY u.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset)->fetchAll();

    render_header('Members', $user);
    ?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Members</h1>
                <p><?= $total ?> total members registered</p>
            </div>
            <button onclick="document.getElementById('addMemberModal').showModal()">+ Add Member</button>
        </div>

        <!-- Add Member Modal -->
        <dialog id="addMemberModal" class="modal">
            <div class="modal-header">
                <h3>Register Member</h3>
                <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <?php render_member_form('staff'); ?>
            </div>
        </dialog>

        <?php if (!$members): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <p>No members registered yet.<br>Click <strong>+ Add Member</strong> to register the first member.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Weight</th>
                        <th>Goal</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($members as $row):
                    $initials    = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
                    $statusClass = 'badge badge-' . $row['status'];
                    $goal        = $row['primary_goal'] ? ucwords(str_replace('_', ' ', $row['primary_goal'])) : '—';
                ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <span class="avatar small"><?= h($initials) ?></span>
                                <div class="user-cell-info">
                                    <?= h($row['first_name'] . ' ' . $row['last_name']) ?>
                                    <small>Joined <?= h(date('M Y', strtotime($row['created_at']))) ?></small>
                                </div>
                            </div>
                        </td>
                        <td style="color:var(--muted)"><?= h($row['email']) ?></td>
                        <td style="color:var(--muted)"><?= h($row['phone'] ?: '—') ?></td>
                        <td><?= $row['weight_kg'] ? h($row['weight_kg']) . ' kg' : '<span class="muted">—</span>' ?></td>
                        <td style="font-size:13px"><?= h($goal) ?></td>
                        <td><span class="<?= $statusClass ?>"><?= h($row['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination($page, $totalPages, '?page=members'); ?>
        <?php endif; ?>
    </section>
    <?php
    render_footer();
}
