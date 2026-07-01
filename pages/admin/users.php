<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function users_page(): void
{
    $user = require_roles(['admin']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (post('action') === 'create') {
            $phone = (string)post('phone');
            if ($phone !== '') {
                $phone = preg_replace('/[^0-9]/', '', $phone);
                if (strlen($phone) !== 11) {
                    flash('Phone number must be exactly 11 digits.', 'danger');
                    redirect('users');
                    return;
                }
            }

            $email = trim((string) post('email'));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('Please enter a valid email address.', 'danger');
                redirect('users');
                return;
            }
            if (scalar('SELECT user_id FROM users WHERE email = ?', [$email])) {
                flash('A user with that email already exists.', 'danger');
                redirect('users');
                return;
            }
            if (!is_acceptable_password((string) post('password'))) {
                flash('Password must be at least 8 characters, with a letter and a number, and not be a common password.', 'danger');
                redirect('users');
                return;
            }

            $plainPassword = (string) post('password');
            $stmt = db()->prepare('INSERT INTO users (role, first_name, last_name, email, password_hash, phone, status, email_verified_at) VALUES (?, ?, ?, ?, ?, ?, "active", NOW())');
            $stmt->execute([post('role'), post('first_name'), post('last_name'), $email, password_hash($plainPassword, PASSWORD_DEFAULT), $phone ?: null]);
            $newUserId = (int) db()->lastInsertId();
            if (post('role') === 'trainer') {
                db()->prepare('INSERT INTO trainer_profiles (user_id, specialization, bio) VALUES (?, ?, ?)')->execute([$newUserId, post('specialization'), post('bio')]);
            }

            // Send credentials email to the newly created user
            $credMailSent = false;
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
                $mail->addAddress($email, post('first_name') . ' ' . post('last_name'));
                $mail->isHTML(true);
                $mail->Subject = 'Your FITTRACKS account has been created';
                $mail->Body =
                    'Hi ' . htmlspecialchars((string) post('first_name'), ENT_QUOTES, 'UTF-8') . ',<br><br>'
                    . 'An account has been created for you on <strong>FITTRACKS</strong>.<br><br>'
                    . 'Your login credentials are:<br>'
                    . '&bull; <strong>Email:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '<br>'
                    . '&bull; <strong>Password:</strong> ' . htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8') . '<br><br>'
                    . 'Please sign in and change your password as soon as possible.<br><br>'
                    . 'Thank you,<br>FITTRACKS Team';
                $mail->send();
                $credMailSent = true;
            } catch (PHPMailerException) {
                // Non-fatal — admin is informed via flash
            }

            flash($credMailSent
                ? 'User created and credentials emailed to ' . $email . '.'
                : 'User created, but the credentials email could not be sent. Please share login details manually.',
                $credMailSent ? 'success' : 'warning');

        } elseif (post('action') === 'status') {
            db()->prepare('UPDATE users SET status = ? WHERE user_id = ?')->execute([post('status'), post('user_id')]);
            flash('User status updated.');
        }
        redirect('users');
    }

    $page = max(1, (int)($_GET['p'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;

    $total = (int) scalar('SELECT COUNT(*) FROM users');
    $totalPages = (int) ceil($total / $limit);

    $rows = db()->query('SELECT * FROM users ORDER BY created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset)->fetchAll();
    render_header('Users', $user);
    ?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>User Accounts</h1>
                <p>Create and manage system users across all roles.</p>
            </div>
            <button onclick="document.getElementById('createUserModal').showModal()">+ Create User</button>
        </div>

        <dialog id="createUserModal" class="modal">
            <div class="modal-header">
                <h3>Create User</h3>
                <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="modal-body">
                <form method="post" class="form grid-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <label>First name
                        <input name="first_name" placeholder="John" required>
                    </label>
                    <label>Last name
                        <input name="last_name" placeholder="Doe" required>
                    </label>
                    <label>Email
                        <input name="email" type="email" placeholder="john@example.com" required>
                    </label>
                    <label>Phone
                        <input name="phone" type="tel" pattern="[0-9]{11}" maxlength="11" title="Please enter exactly 11 digits" placeholder="09123456789">
                    </label>
                    <label>Password
                        <input name="password" type="password" placeholder="Min. 8 characters" required>
                    </label>
                    <label>Role
                        <select name="role">
                            <?php foreach (['admin', 'staff', 'trainer', 'member'] as $role): ?>
                                <option value="<?= h($role) ?>"><?= h(ucfirst($role)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Specialization <small style="font-weight:400">(trainer only)</small>
                        <input name="specialization" placeholder="e.g. Strength & Conditioning">
                    </label>
                    <label>Bio <small style="font-weight:400">(trainer only)</small>
                        <input name="bio" placeholder="Short bio">
                    </label>
                    <button style="grid-column: 1 / -1; margin-top: 10px;">Create user</button>
                </form>
            </div>
        </dialog>

        <p class="section-label">All users (<?= $total ?>)</p>
        <?php if (!$rows): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <p>No users found.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $roleClass = 'badge badge-' . $row['role'];
                    $statusClass = 'badge badge-' . $row['status'];
                ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <?= render_avatar($row) ?>
                                <div class="user-cell-info">
                                    <?= h($row['first_name'] . ' ' . $row['last_name']) ?>
                                    <small>#<?= (int) $row['user_id'] ?></small>
                                </div>
                            </div>
                        </td>
                        <td style="color:var(--muted)"><?= h($row['email']) ?></td>
                        <td><span class="<?= $roleClass ?>"><?= h($row['role']) ?></span></td>
                        <td><span class="<?= $statusClass ?>"><?= h($row['status']) ?></span></td>
                        <td style="color:var(--muted);font-size:12px"><?= h(date('M j, Y', strtotime($row['created_at']))) ?></td>
                        <td>
                            <form method="post" class="row-actions">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="status">
                                <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                <select name="status" style="width:auto;padding:6px 10px;font-size:12px">
                                    <option <?= selected('active', $row['status']) ?>>active</option>
                                    <option <?= selected('inactive', $row['status']) ?>>inactive</option>
                                    <option <?= selected('suspended', $row['status']) ?>>suspended</option>
                                </select>
                                <button class="btn-sm btn-ghost">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination($page, $totalPages, '?page=users'); ?>
        <?php endif; ?>
    </section>
    <?php
    render_footer();
}
