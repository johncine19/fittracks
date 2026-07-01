<?php

declare(strict_types=1);

function profile_page(): void
{
    $user      = require_login();
    $is_member = $user['role'] === 'member';
    $profile   = $is_member ? member_profile((int) $user['user_id']) : null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['update_account'])) {
            $email    = trim((string) ($_POST['email']    ?? ''));
            $phone    = trim((string) ($_POST['phone']    ?? ''));
            $fname    = trim((string) ($_POST['first_name'] ?? ''));
            $lname    = trim((string) ($_POST['last_name']  ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            $validator = new Validator();
            $valid = $validator->validate($_POST, [
                'first_name' => 'required|min:1|max:100',
                'last_name'  => 'required|min:1|max:100',
                'email'      => 'required|email|max:255',
            ]);

            if ($valid && $password !== '' && !is_acceptable_password($password)) {
                $valid = false;
                $validator_error = 'New password must be at least 8 characters with a letter and a number, and not a common password.';
            }

            if ($valid && $email !== $user['email']) {
                $existing = scalar('SELECT user_id FROM users WHERE email = ? AND user_id != ?', [$email, $user['user_id']]);
                if ($existing) {
                    $valid = false;
                    $validator_error = 'That email is already in use by another account.';
                }
            }

            if (!$valid) {
                flash($validator_error ?? $validator->firstError(), 'danger');
                redirect('profile');
            }

            $updates = [];
            $params  = [];

            if ($fname !== $user['first_name']) {
                $updates[] = 'first_name = ?';
                $params[] = $fname;
            }
            if ($lname !== $user['last_name']) {
                $updates[] = 'last_name = ?';
                $params[] = $lname;
            }
            if ($email !== $user['email']) {
                $updates[] = 'email = ?';
                $params[] = $email;
            }
            if ($phone !== ($user['phone'] ?? '')) {
                $updates[] = 'phone = ?';
                $params[] = $phone;
            }
            if (!empty($password)) {
                $updates[] = 'password_hash = ?';
                $params[]  = password_hash($password, PASSWORD_DEFAULT);
            }

            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                try {
                    $filename = FileUpload::storeProfilePicture($_FILES['profile_picture'], (int) $user['user_id']);
                    $updates[] = 'profile_picture = ?';
                    $params[]  = $filename;
                } catch (RuntimeException $e) {
                    flash($e->getMessage(), 'danger');
                    redirect('profile');
                }
            }

            if ($updates) {
                $params[] = $user['user_id'];
                db()->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE user_id = ?')
                    ->execute($params);
                flash('Account details updated.', 'success');
            } else {
                flash('No changes made to account.', 'info');
            }
            redirect('profile');
        } elseif (isset($_POST['height_cm']) && $is_member) {
            save_member_profile((int) $user['user_id']);
            generate_diet_plan((int) $user['user_id']);
            flash('Physical profile and nutrition targets updated.', 'success');
            redirect('profile');
        }
    }

    $user = current_user();
    render_header('Settings', $user);
?>
    <section class="panel wide">
        <h1>Settings</h1>

        <!-- Account details display -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
            <h2 style="margin:0;">Account Details</h2>
            <button type="button" onclick="document.getElementById('accountModal').showModal()">Edit Details</button>
        </div>

        <div class="profile-card">
            <?php if (!empty($user['profile_picture'])): ?>
                <img src="assets/uploads/<?= h($user['profile_picture']) ?>"
                    alt="Profile picture"
                    class="profile-avatar">
            <?php else: ?>
                <div class="profile-avatar profile-avatar-initials"><?= h(initials($user)) ?></div>
            <?php endif; ?>
            <div class="profile-info">
                <p><strong><?= h($user['first_name'] . ' ' . $user['last_name']) ?></strong></p>
                <p><span style="color:var(--muted)">Email:</span> <?= h($user['email']) ?></p>
                <p><span style="color:var(--muted)">Phone:</span> <?= h($user['phone'] ?? 'Not set') ?></p>
                <p><span style="color:var(--muted)">Role:</span> <?= h(ucfirst($user['role'])) ?></p>
            </div>
        </div>

        <!-- Account edit modal (using native <dialog>) -->
        <dialog id="accountModal" class="modal">
            <div class="modal-header">
                <h3>Edit Account Details</h3>
                <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <form method="post" enctype="multipart/form-data" class="form grid-form" style="margin-bottom:0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="update_account" value="1">

                    <div style="grid-column:1/-1;display:flex;align-items:center;gap:1rem;margin-bottom:0.5rem;">
                        <?php if (!empty($user['profile_picture'])): ?>
                            <img src="assets/uploads/<?= h($user['profile_picture']) ?>"
                                alt="Profile picture"
                                style="width:60px;height:60px;border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                            <div style="width:60px;height:60px;border-radius:50%;background:var(--panel-soft);display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:var(--muted);">
                                <?= h(initials($user)) ?>
                            </div>
                        <?php endif; ?>
                        <label>Profile Picture
                            <input type="file" name="profile_picture" accept="image/*">
                        </label>
                    </div>

                    <label>First name
                        <input name="first_name" required value="<?= h($user['first_name']) ?>">
                    </label>
                    <label>Last name
                        <input name="last_name" required value="<?= h($user['last_name']) ?>">
                    </label>
                    <label>Email
                        <input type="email" name="email" required value="<?= h($user['email']) ?>">
                    </label>
                    <label>Phone
                        <input name="phone" type="tel" maxlength="11"
                            placeholder="09xxxxxxxxx"
                            value="<?= h($user['phone'] ?? '') ?>"
                            oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)">
                    </label>
                    <label style="grid-column:1/-1">New password
                        <input type="password" name="password" placeholder="Leave blank to keep current">
                        <small class="muted" style="font-weight:400;">Min. 8 characters, with a letter and a number.</small>
                    </label>

                    <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:10px;margin-top:4px;">
                        <button type="button" onclick="this.closest('dialog').close()">Cancel</button>
                        <button type="submit" class="btn-primary">Save changes</button>
                    </div>
                </form>
            </div>
        </dialog>

        <?php if ($is_member): ?>
            <h2 style="margin-top:2rem;">Physical Profile</h2>
            <p class="muted" style="margin-bottom:1rem;font-size:13px;">
                Keeping your physical profile up to date lets FITTRACKS generate accurate nutrition targets for you.
            </p>
            <?php render_member_form('profile', $user, $profile); ?>
        <?php endif; ?>
    </section>

    <style>
        .profile-card {
            display: flex;
            gap: 1.5rem;
            align-items: center;
            padding: 1.5rem;
            border-radius: 10px;
            border: 0.5px solid var(--line);
            background: var(--panel-soft);
            margin-bottom: 2.5rem;
        }

        .profile-avatar {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
        }

        .profile-avatar-initials {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: var(--muted);
            background: var(--surface-1, var(--panel-soft));
            border: 0.5px solid var(--line);
        }

        .profile-info p {
            margin: 0 0 4px;
            font-size: 14px;
        }

        @media (max-width: 600px) {
            .profile-card {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
<?php
    render_footer();
}
