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
        } elseif (post('action') === 'edit_user') {
            $adminPassword = (string) post('admin_password');
            $stmt = db()->prepare('SELECT password_hash FROM users WHERE user_id = ?');
            $stmt->execute([$user['user_id']]);
            $adminData = $stmt->fetch();
            
            if (!password_verify($adminPassword, $adminData['password_hash'])) {
                flash('Incorrect Admin password. Changes aborted.', 'danger');
                redirect('users');
                return;
            }

            $editUserId = (int) post('user_id');
            $newPassword = (string) post('new_password');
            $phone = post('phone') !== '' ? preg_replace('/[^0-9]/', '', (string)post('phone')) : null;
            
            if ($newPassword !== '') {
                if (!is_acceptable_password($newPassword)) {
                    flash('New password must be at least 8 characters, with a letter and a number.', 'danger');
                    redirect('users');
                    return;
                }
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                db()->prepare('UPDATE users SET first_name=?, last_name=?, email=?, phone=?, role=?, password_hash=? WHERE user_id=?')
                    ->execute([post('first_name'), post('last_name'), post('email'), $phone, post('role'), $hash, $editUserId]);
            } else {
                db()->prepare('UPDATE users SET first_name=?, last_name=?, email=?, phone=?, role=? WHERE user_id=?')
                    ->execute([post('first_name'), post('last_name'), post('email'), $phone, post('role'), $editUserId]);
            }
            
            if (post('role') === 'trainer') {
                db()->prepare('INSERT INTO trainer_profiles (user_id, specialization, bio) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE specialization = VALUES(specialization), bio = VALUES(bio)')
                    ->execute([$editUserId, post('specialization'), post('bio')]);
            }
            flash('User updated successfully.');
        } elseif (post('action') === 'delete_user') {
            $targetUserId = (int) post('user_id');
            if ($targetUserId === (int) $user['user_id']) {
                flash('You cannot delete your own account.', 'danger');
            } else {
                $targetUser = db()->prepare('SELECT role FROM users WHERE user_id = ?');
                $targetUser->execute([$targetUserId]);
                $targetUser = $targetUser->fetch();
                
                if ($targetUser) {
                    if ($targetUser['role'] === 'member') {
                        $hasActiveMembership = (bool) scalar('SELECT 1 FROM memberships WHERE user_id = ? AND status = "active"', [$targetUserId]);
                        if ($hasActiveMembership) {
                            flash('Cannot delete member: They have an active membership plan.', 'danger');
                            redirect('users');
                            return;
                        }
                    } elseif ($targetUser['role'] === 'trainer') {
                        $trainerId = scalar('SELECT trainer_id FROM trainer_profiles WHERE user_id = ?', [$targetUserId]);
                        if ($trainerId) {
                            $hasActiveClient = (bool) scalar('SELECT 1 FROM trainer_assignments WHERE trainer_id = ? AND status = "active"', [$trainerId]);
                            if ($hasActiveClient) {
                                flash('Cannot delete trainer: They have actively assigned clients.', 'danger');
                                redirect('users');
                                return;
                            }
                        }
                    }
                    
                    db()->prepare('DELETE FROM users WHERE user_id=?')->execute([$targetUserId]);
                    flash('User deleted.');
                }
            }
        }
        redirect('users');
    }

    $page = max(1, (int)($_GET['p'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;

    $tab = $_GET['tab'] ?? 'all';
    $where = '1=1';
    $u_where = '1=1';
    $params = [];
    if (in_array($tab, ['admin', 'trainer', 'member'], true)) {
        $where = 'role = ?';
        $u_where = 'u.role = ?';
        $params[] = $tab;
    }

    $total = (int) scalar('SELECT COUNT(*) FROM users WHERE ' . $where, $params);
    $totalPages = max(1, (int) ceil($total / $limit));

    $stmt = db()->prepare('SELECT u.*, tp.specialization, tp.bio FROM users u LEFT JOIN trainer_profiles tp ON u.user_id = tp.user_id WHERE ' . $u_where . ' ORDER BY u.first_name ASC, u.last_name ASC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    
    render_header('Users', $user);
    ?>
    <div class="skeleton-wrapper">
        <section class="panel">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
                <div>
                    <div class="sk sk-title" style="width:140px;margin-bottom:8px"></div>
                    <div class="sk sk-text" style="width:200px;height:12px"></div>
                </div>
                <div class="sk sk-rect" style="width:120px;height:36px;border-radius:18px"></div>
            </div>
            <div style="display:flex;justify-content:space-between;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--line)">
                <div style="display:flex;gap:16px">
                    <?php for($i=0;$i<4;$i++) echo '<div class="sk sk-text" style="width:60px;height:14px;margin:0"></div>'; ?>
                </div>
                <div class="sk sk-text" style="width:50px;height:12px;margin:0"></div>
            </div>
            <?php render_skeleton_table(6, 8); ?>
        </section>
    </div>
    <section class="panel skeleton-content sk-display-block">
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
                        <select name="role" id="new_user_role" onchange="toggleTrainerFields(this.value)">
                            <?php foreach (['admin', 'trainer', 'member'] as $role): ?>
                                <option value="<?= h($role) ?>"><?= h(ucfirst($role)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div id="trainer_fields" style="display: none; grid-column: 1 / -1; gap: 1rem;">
                        <label style="width: 100%;">Specialization <small style="font-weight:400">(trainer only)</small>
                            <input name="specialization" id="new_user_spec" placeholder="e.g. Strength & Conditioning" style="width: 100%; box-sizing: border-box;">
                        </label>
                        <label style="width: 100%; margin-top: 1rem;">Bio <small style="font-weight:400">(trainer only)</small>
                            <input name="bio" placeholder="Short bio" style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    <script>
                        function toggleTrainerFields(role) {
                            const tf = document.getElementById('trainer_fields');
                            const spec = document.getElementById('new_user_spec');
                            if (role === 'trainer') {
                                tf.style.display = 'block';
                                spec.required = true;
                            } else {
                                tf.style.display = 'none';
                                spec.required = false;
                            }
                        }
                    </script>
                    <button style="grid-column: 1 / -1; margin-top: 10px;">Create user</button>
                </form>
            </div>
        </dialog>

        <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom: 12px; border-bottom: 1px solid var(--line); padding-bottom: 8px;">
            <div style="display:flex; gap:16px;">
                <a href="?page=users&tab=all" style="color: <?= $tab === 'all' ? 'var(--lime)' : 'var(--muted)' ?>; font-weight: <?= $tab === 'all' ? '700' : '400' ?>; text-decoration:none; padding-bottom:4px; border-bottom: 2px solid <?= $tab === 'all' ? 'var(--lime)' : 'transparent' ?>;">All Users</a>
                <a href="?page=users&tab=admin" style="color: <?= $tab === 'admin' ? 'var(--lime)' : 'var(--muted)' ?>; font-weight: <?= $tab === 'admin' ? '700' : '400' ?>; text-decoration:none; padding-bottom:4px; border-bottom: 2px solid <?= $tab === 'admin' ? 'var(--lime)' : 'transparent' ?>;">Admins</a>
                <a href="?page=users&tab=trainer" style="color: <?= $tab === 'trainer' ? 'var(--lime)' : 'var(--muted)' ?>; font-weight: <?= $tab === 'trainer' ? '700' : '400' ?>; text-decoration:none; padding-bottom:4px; border-bottom: 2px solid <?= $tab === 'trainer' ? 'var(--lime)' : 'transparent' ?>;">Trainers</a>
                <a href="?page=users&tab=member" style="color: <?= $tab === 'member' ? 'var(--lime)' : 'var(--muted)' ?>; font-weight: <?= $tab === 'member' ? '700' : '400' ?>; text-decoration:none; padding-bottom:4px; border-bottom: 2px solid <?= $tab === 'member' ? 'var(--lime)' : 'transparent' ?>;">Members</a>
            </div>
            <p class="section-label" style="margin:0; border:none; padding:0;"><?= $total ?> found</p>
        </div>
        
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
                        <?php if ($tab === 'trainer'): ?>
                            <th>Specialization</th>
                        <?php endif; ?>
                        <?php if ($tab === 'member'): ?>
                            <th>Score</th>
                        <?php endif; ?>
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
                        <?php if ($tab === 'trainer'): ?>
                        <td>
                            <?php if ($row['role'] === 'trainer'): ?>
                                <span style="color:var(--ink);"><?= h($row['specialization'] ?? 'General Trainer') ?></span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <?php if ($tab === 'member'): ?>
                        <td>
                            <?php if ($row['role'] === 'member'): ?>
                                <strong style="color:var(--lime);"><?= (int)$row['engagement_score'] ?></strong>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td><span class="<?= $statusClass ?>"><?= h($row['status']) ?></span></td>
                        <td style="color:var(--muted);font-size:12px"><?= h(date('M j, Y', strtotime($row['created_at']))) ?></td>
                        <td>
                            <div style="display:flex;gap:4px;align-items:center;">
                                <form method="post" class="row-actions" style="margin:0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                    <select name="status" style="width:auto;padding:6px 10px;font-size:12px;margin:0">
                                        <option <?= selected('active', $row['status']) ?>>active</option>
                                        <option <?= selected('suspended', $row['status']) ?>>suspended</option>
                                    </select>
                                    <button type="submit" class="btn-sm btn-ghost">Update</button>
                                </form>
                                <button onclick="editUser(<?= htmlspecialchars(json_encode($row)) ?>)" class="btn btn-secondary" style="padding:4px 8px;font-size:12px;margin-left:8px;">Edit</button>
                                <?php if ((int) $row['user_id'] !== (int) $user['user_id']): ?>
                                <form method="post" style="margin:0;" onsubmit="return confirm('Delete this user? This cannot be undone.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                    <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:12px;">Delete</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination($page, $totalPages, '?page=users&tab=' . urlencode($tab)); ?>
        <?php endif; ?>
    </section>

    <script>
    function toggleEditTrainerFields(role) {
        const tf = document.getElementById('eu_trainer_fields');
        const spec = document.getElementById('eu_spec');
        if (!tf || !spec) return;
        if (role === 'trainer') {
            tf.style.display = 'flex';
            spec.required = true;
        } else {
            tf.style.display = 'none';
            spec.required = false;
        }
    }

    function editUser(u) {
        Swal.fire({
            title: 'Edit User',
            html: `
                <form id="editUserForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" id="eu_id">
                    <input type="hidden" name="admin_password" id="eu_admin_pass">
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">First name * <input name="first_name" id="eu_fn" class="form-control" required style="width: 100%; box-sizing: border-box;"></label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Last name * <input name="last_name" id="eu_ln" class="form-control" required style="width: 100%; box-sizing: border-box;"></label>
                    </div>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Email * <input type="email" name="email" id="eu_email" class="form-control" required style="width: 100%; box-sizing: border-box;"></label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Phone <input type="tel" name="phone" id="eu_phone" class="form-control" style="width: 100%; box-sizing: border-box;"></label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Role *
                        <select name="role" id="eu_role" class="form-control" style="width: 100%; box-sizing: border-box;" onchange="toggleEditTrainerFields(this.value)">
                            <option value="admin">Admin</option>
                            <option value="trainer">Trainer</option>
                            <option value="member">Member</option>
                        </select>
                    </label>
                    <div id="eu_trainer_fields" style="display: none; flex-direction: column; gap: 12px;">
                        <label style="display:block; color: var(--muted); font-size: 14px;">Specialization <small style="font-weight:400">(trainer only)</small>
                            <input name="specialization" id="eu_spec" class="form-control" placeholder="e.g. Strength & Conditioning" style="width: 100%; box-sizing: border-box;">
                        </label>
                        <label style="display:block; color: var(--muted); font-size: 14px;">Bio <small style="font-weight:400">(trainer only)</small>
                            <input name="bio" id="eu_bio" class="form-control" placeholder="Short bio" style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    <label style="display:block; color: var(--muted); font-size: 14px;">New Password <small>(leave blank to keep current)</small> <input type="password" name="new_password" id="eu_pass" class="form-control" style="width: 100%; box-sizing: border-box;"></label>
                </form>
            `,
            didOpen: () => {
                document.getElementById('eu_id').value = u.user_id;
                document.getElementById('eu_fn').value = u.first_name;
                document.getElementById('eu_ln').value = u.last_name;
                document.getElementById('eu_email').value = u.email;
                document.getElementById('eu_phone').value = u.phone || '';
                document.getElementById('eu_role').value = u.role;
                document.getElementById('eu_spec').value = u.specialization || '';
                document.getElementById('eu_bio').value = u.bio || '';
                toggleEditTrainerFields(u.role);
            },
            showCancelButton: true,
            confirmButtonText: 'Save Changes',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('editUserForm');
                if (!form.first_name.value || !form.last_name.value || !form.email.value) {
                    Swal.showValidationMessage('Name and email are required');
                    return false;
                }
                if (form.role.value === 'trainer' && !form.specialization.value) {
                    Swal.showValidationMessage('Specialization is required for trainers');
                    return false;
                }
                
                // Capture data before the first modal is destroyed
                const formData = new FormData(form);
                
                // Return a Promise that resolves when the nested Swal finishes
                return new Promise((resolve) => {
                    Swal.fire({
                        title: 'Confirm Admin Password',
                        text: 'Please enter your password to save these changes.',
                        input: 'password',
                        inputAttributes: {
                            autocapitalize: 'off',
                            autocorrect: 'off'
                        },
                        showCancelButton: true,
                        confirmButtonText: 'Confirm',
                        confirmButtonColor: 'var(--lime-dark)',
                        cancelButtonColor: 'var(--line)',
                        background: 'var(--bg)',
                        color: 'var(--ink)',
                        preConfirm: (password) => {
                            if (!password) {
                                Swal.showValidationMessage('Admin password is required');
                                return false;
                            }
                            
                            formData.set('admin_password', password);
                            
                            // Create a temporary form to submit the data
                            const tempForm = document.createElement('form');
                            tempForm.method = 'post';
                            tempForm.style.display = 'none';
                            for (let [key, value] of formData.entries()) {
                                const input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = key;
                                input.value = value;
                                tempForm.appendChild(input);
                            }
                            document.body.appendChild(tempForm);
                            tempForm.submit();
                        }
                    });
                });
            }
        });
    }
    </script>
    <?php
    render_footer();
}
