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
            $validator = new Validator();
            $valid = $validator->validate($_POST, [
                'height_cm' => 'numeric|min_num:100|max_num:250',
                'weight_kg' => 'numeric|min_num:20|max_num:300',
                'age'       => 'numeric|min_num:16|max_num:120',
                'neck_cm'   => 'numeric|min_num:20|max_num:100',
                'waist_cm'  => 'numeric|min_num:30|max_num:200',
                'hip_cm'    => 'numeric|min_num:30|max_num:200',
            ]);
            
            if (!$valid) {
                flash($validator->firstError() ?? 'Invalid measurements provided.', 'danger');
                redirect('profile');
            }

            $oldGoal = $profile['primary_goal'] ?? '';
            $newGoal = post('primary_goal');
            
            save_member_profile((int) $user['user_id']);
            
            if ($oldGoal !== $newGoal || can_recalculate_workout((int) $user['user_id'])) {
                generate_workout_plan((int) $user['user_id']);
                notify_user((int) $user['user_id'], 'system', 'Workout plan updated', 'Your workout plan was recalculated from your updated physical profile.');
                flash('Physical profile and workout plan updated.', 'success');
            } else {
                flash('Physical profile updated.', 'success');
            }
            redirect('profile');
        } elseif (isset($_POST['self_checkout'])) {
            // Auto-create checkout_ratings table once
            static $ratingTableChecked = false;
            if (!$ratingTableChecked) {
                $ratingTableChecked = true;
                try {
                    db()->exec("CREATE TABLE IF NOT EXISTS checkout_ratings (
                        rating_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        attendance_id INT UNSIGNED NOT NULL,
                        user_id       INT UNSIGNED NOT NULL,
                        rating        TINYINT UNSIGNED NOT NULL,
                        comment       TEXT DEFAULT NULL,
                        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_attendance (attendance_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
                } catch (Throwable) {}
            }

            $attendanceId = (int) $_POST['attendance_id'];
            db()->prepare('UPDATE attendance SET check_out_time = NOW() WHERE attendance_id = ? AND user_id = ?')
               ->execute([$attendanceId, $user['user_id']]);

            // Save optional satisfaction rating
            $rating = isset($_POST['rating']) ? (int) $_POST['rating'] : 0;
            if ($rating >= 1 && $rating <= 5) {
                $comment = mb_substr(trim((string) ($_POST['comment'] ?? '')), 0, 1000) ?: null;
                try {
                    db()->prepare('INSERT IGNORE INTO checkout_ratings (attendance_id, user_id, rating, comment) VALUES (?, ?, ?, ?)')
                       ->execute([$attendanceId, $user['user_id'], $rating, $comment]);
                } catch (Throwable) {}
            }

            flash('You have successfully checked out.', 'success');
            redirect('dashboard');
        }
    }

    $user = current_user();
    
    render_header('Settings', $user);
?>
    <?php render_skeleton_profile(); ?>
    <div class="skeleton-content sk-display-block">
    <h1 style="margin-bottom: 1.5rem;">Settings</h1>
    <div style="display: flex; flex-wrap: wrap; gap: 2rem; align-items: flex-start;">
        <section class="panel" style="flex: 1; min-width: 350px; max-width: 650px; margin: 0;">


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
                <form method="post" enctype="multipart/form-data" class="form grid-form" style="margin-bottom:0;" onsubmit="const btn = this.querySelector('button[type=submit]'); btn.disabled = true; btn.innerHTML = '<span class=\'loader\' style=\'width:16px;height:16px;border:2px solid var(--bg);border-bottom-color:transparent;border-radius:50%;display:inline-block;box-sizing:border-box;animation:rotation 1s linear infinite;margin-right:8px;vertical-align:-2px;\'></span> Saving...';">
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
    </section>

    <?php if ($is_member): ?>
        <section class="panel" style="flex: 1; min-width: 350px; max-width: 650px; margin: 0;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
            <h2 style="margin:0;">Physical Profile</h2>
            <button type="button" onclick="document.getElementById('physicalProfileModal').showModal()">Edit Profile</button>
        </div>
        <p class="muted" style="margin-bottom:1.5rem;font-size:13px;">
            Keeping your physical profile up to date lets FITTRACKS generate an accurate workout plan for you.
        </p>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 2rem;">
                <div class="panel plan-card-glow" style="padding: 1rem; background: var(--panel-soft); margin-top: 0;">
                    <p class="muted" style="margin: 0 0 4px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Height</p>
                    <strong style="font-size: 1.2rem;"><?= h($profile['height_cm'] ?? 'N/A') ?> cm</strong>
                </div>
                <div class="panel plan-card-glow" style="padding: 1rem; background: var(--panel-soft); margin-top: 0;">
                    <p class="muted" style="margin: 0 0 4px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Weight</p>
                    <strong style="font-size: 1.2rem;"><?= h($profile['weight_kg'] ?? 'N/A') ?> kg</strong>
                </div>
                <div class="panel plan-card-glow" style="padding: 1rem; background: var(--panel-soft); margin-top: 0;">
                    <p class="muted" style="margin: 0 0 4px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Age</p>
                    <strong style="font-size: 1.2rem;"><?= h($profile['age'] ?? 'N/A') ?></strong>
                </div>
                <div class="panel plan-card-glow" style="padding: 1rem; background: var(--panel-soft); margin-top: 0;">
                    <p class="muted" style="margin: 0 0 4px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Neck</p>
                    <strong style="font-size: 1.2rem;"><?= !empty($profile['neck_cm']) ? h($profile['neck_cm']) . ' cm' : 'N/A' ?></strong>
                </div>
                <div class="panel plan-card-glow" style="padding: 1rem; background: var(--panel-soft); margin-top: 0;">
                    <p class="muted" style="margin: 0 0 4px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Waist</p>
                    <strong style="font-size: 1.2rem;"><?= !empty($profile['waist_cm']) ? h($profile['waist_cm']) . ' cm' : 'N/A' ?></strong>
                </div>
                <?php if (($profile['biological_sex'] ?? '') === 'female'): ?>
                <div class="panel plan-card-glow" style="padding: 1rem; background: var(--panel-soft); margin-top: 0;">
                    <p class="muted" style="margin: 0 0 4px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Hip</p>
                    <strong style="font-size: 1.2rem;"><?= !empty($profile['hip_cm']) ? h($profile['hip_cm']) . ' cm' : 'N/A' ?></strong>
                </div>
                <?php endif; ?>
                <div class="panel plan-card-glow" style="padding: 1rem; background: var(--panel-soft); margin-top: 0;">
                    <p class="muted" style="margin: 0 0 4px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Sex</p>
                    <strong style="font-size: 1.2rem;"><?= h(ucwords(str_replace('_', ' ', $profile['biological_sex'] ?? 'N/A'))) ?></strong>
                </div>
                <div class="panel plan-card-glow" style="padding: 1rem; background: var(--panel-soft); margin-top: 0;">
                    <p class="muted" style="margin: 0 0 4px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Activity</p>
                    <strong style="font-size: 1.2rem;"><?= h(ucwords(str_replace('_', ' ', $profile['activity_level'] ?? 'N/A'))) ?></strong>
                </div>
                <div class="panel plan-card-glow" style="padding: 1rem; background: var(--panel-soft); margin-top: 0;">
                    <p class="muted" style="margin: 0 0 4px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Goal</p>
                    <strong style="font-size: 1.2rem;"><?= h(ucwords(str_replace('_', ' ', $profile['primary_goal'] ?? 'N/A'))) ?></strong>
                </div>
            </div>

            <dialog id="physicalProfileModal" class="modal">
                <div class="modal-header">
                    <h3>Edit Physical Profile</h3>
                    <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                </div>
                <div class="modal-body">
                    <?php render_member_form('profile', $user, $profile); ?>
                </div>
            </dialog>
        </section>
        <?php endif; ?>

        <section class="panel" style="flex: 1; min-width: 350px; max-width: 650px; margin: 0;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <h2 style="margin:0;">Preferences</h2>
            </div>
            <p class="muted" style="margin-bottom:1.5rem;font-size:13px;">
                Customize your app experience.
            </p>
            
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 1rem; background: var(--panel-soft); border-radius: 12px; border: 1px solid color-mix(in srgb, var(--line) 50%, transparent);">
                <div>
                    <strong style="display: block; margin-bottom: 4px;">Video Background</strong>
                    <span class="muted" style="font-size: 13px;">Show an animated video instead of a static image.</span>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" id="toggleVideoBg" <?= (!isset($_COOKIE['fittracks_video_bg']) || $_COOKIE['fittracks_video_bg'] !== 'off') ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
        </section>
    </div>

    <script>
    document.getElementById('toggleVideoBg').addEventListener('change', function(e) {
        const isEnabled = e.target.checked;
        document.cookie = "fittracks_video_bg=" + (isEnabled ? "on" : "off") + "; path=/; max-age=31536000";
        
        const video = document.getElementById('app-bg-video');
        if (!isEnabled && video) {
            video.pause();
            video.style.display = 'none';
        } else if (isEnabled) {
            if (video) {
                video.style.display = 'block';
                video.play();
            } else {
                location.reload();
            }
        }
    });
    </script>

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

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 48px;
            height: 24px;
            flex-shrink: 0;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: color-mix(in srgb, var(--muted) 50%, transparent);
            transition: .4s;
            border-radius: 24px;
        }
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: var(--ink);
            transition: .4s cubic-bezier(0.16, 1, 0.3, 1);
            border-radius: 50%;
        }
        input:checked + .toggle-slider {
            background-color: var(--lime);
        }
        input:checked + .toggle-slider:before {
            transform: translateX(24px);
            background-color: var(--bg);
        }
    </style>
    </div>
    </div>
<?php
    render_footer();
}
