<?php
declare(strict_types=1);

function handle_profile_post_actions(array $user, ?array $profile, bool $is_member): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['update_account'])) {
            $email    = strtolower(trim((string) ($_POST['email']    ?? '')));
            $phone    = preg_replace('/[^0-9]/', '', (string) ($_POST['phone'] ?? ''));
            $fname    = mb_convert_case(trim((string) ($_POST['first_name'] ?? '')), MB_CASE_TITLE, 'UTF-8');
            $lname    = mb_convert_case(trim((string) ($_POST['last_name']  ?? '')), MB_CASE_TITLE, 'UTF-8');
            $password = (string) ($_POST['password'] ?? '');

            $_POST['email'] = $email;
            $_POST['phone'] = $phone;
            $validator = new Validator();
            $valid = $validator->validate($_POST, [
                'first_name' => 'required|min:1|max:100',
                'last_name'  => 'required|min:1|max:100',
                'email'      => 'required|email|max:255',
                'phone'      => 'required|digits:11',
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
                'height_cm'                     => 'numeric|min_num:100|max_num:250',
                'weight_kg'                     => 'numeric|min_num:20|max_num:300',
                'age'                           => 'numeric|min_num:16|max_num:120',
                'neck_cm'                       => 'numeric|min_num:20|max_num:100',
                'waist_cm'                      => 'numeric|min_num:30|max_num:200',
                'hip_cm'                        => 'numeric|min_num:30|max_num:200',
                'chest_cm'                      => 'numeric|min_num:30|max_num:200',
                'arm_cm'                        => 'numeric|min_num:10|max_num:100',
                'target_weight_kg'              => 'numeric|min_num:20|max_num:300',
                'target_body_fat_percent'       => 'numeric|min_num:1|max_num:70',
                'target_arm_cm'                 => 'numeric|min_num:15|max_num:70',
                'target_chest_cm'               => 'numeric|min_num:40|max_num:180',
                'target_waist_cm'               => 'numeric|min_num:30|max_num:200',
                'current_strength_max_kg'       => 'numeric|min_num:0|max_num:500',
                'target_strength_max_kg'        => 'numeric|min_num:1|max_num:500',
                'current_endurance_distance_km' => 'numeric|min_num:0.1|max_num:200',
                'current_endurance_time_mins'   => 'numeric|min_num:1|max_num:1440',
                'target_endurance_distance_km'  => 'numeric|min_num:0.1|max_num:200',
                'target_endurance_time_mins'    => 'numeric|min_num:1|max_num:1440',
                'weekly_workout_target'         => 'numeric|min_num:1|max_num:7',
                'preferred_duration_mins'       => 'numeric|min_num:15|max_num:180',
            ]);
            
            $newGoal = (string) post('primary_goal');
            $currStrength = (float) post('current_strength_max_kg');
            $targStrength = (float) post('target_strength_max_kg');
            if ($valid && $currStrength > 0 && $targStrength > 0 && $targStrength <= $currStrength) {
                $goalCat = resolve_goal_category($newGoal);
                if ($goalCat === 'increasing_strength') {
                    $valid = false;
                    $validator_error = 'Target should be greater than your current maximum.';
                }
            }
            
            if (!$valid) {
                flash($validator_error ?? ($validator->firstError() ?? 'Invalid measurements provided.'), 'danger');
                redirect('profile');
            }

            $oldGoal = $profile['primary_goal'] ?? '';
            $newGoal = post('primary_goal');
            
            save_member_profile((int) $user['user_id']);
            
            $memberUserId = (int) $user['user_id'];
            if (can_recalculate_workout($memberUserId)) {
                generate_workout_plan($memberUserId);
                notify_user($memberUserId, 'system', 'Workout plan updated', 'Your self-guided workout plan was recalculated from your updated physical profile.');
                flash('Physical profile and workout plan updated.', 'success');
            } else {
                // Member is under coach guidance: keep custom plan intact and notify trainer if goal changed
                if ($oldGoal !== $newGoal && $newGoal) {
                    $trainerUserId = get_member_active_trainer_user_id($memberUserId);
                    if ($trainerUserId) {
                        $clientName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Your client';
                        $goalFormatted = ucwords(str_replace('_', ' ', (string) $newGoal));
                        notify_user(
                            $trainerUserId,
                            'client_goal',
                            'Client Fitness Goal Updated',
                            $clientName . ' updated their primary fitness goal to "' . $goalFormatted . '". Review and update their training routine when convenient.'
                        );
                    }
                }
                flash('Physical profile updated.', 'success');
            }
            $hasMembership = scalar('SELECT 1 FROM memberships WHERE user_id = ? AND status IN ("active", "pending")', [$user['user_id']]);
            $hasGym = scalar('SELECT 1 FROM gym_members WHERE user_id = ?', [$user['user_id']]);
            if (!$hasMembership && !$hasGym) {
                redirect('gym_selection');
            }
            redirect('profile');
        } elseif (isset($_POST['update_platform_settings']) && $user['role'] === 'platform_admin') {
            $keys = [
                'platform_name', 
                'contact_email', 
                'registration_enabled',
                'at_risk_inactivity_days',
                'at_risk_notification_cooldown'
            ];
            $pdo = db();
            foreach ($keys as $key) {
                if (isset($_POST[$key])) {
                    $pdo->prepare('REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)')
                        ->execute([$key, trim((string)$_POST[$key])]);
                }
            }
            $submittedSettings = array_intersect_key($_POST, array_flip($keys));
            $submittedSettings = array_map(static fn($value) => trim((string)$value), $submittedSettings);
            audit_log((int)$user['user_id'], 'edit', 'platform_settings', null, (string) json_encode($submittedSettings));
            flash('Platform settings updated successfully.', 'success');
            redirect('profile&tab=platform_settings');
        } elseif ((isset($_POST['action']) && in_array($_POST['action'], ['update_id_policy', 'add_id_type'], true)) && $user['role'] === 'platform_admin') {
            require_once __DIR__ . '/../../core/verification_policy.php';
            $action = post('action');
            $pdo = db();

            if ($action === 'update_id_policy') {
                $idTypeId = (int) post('id_type_id');
                $category = post('category') === 'secondary' ? 'secondary' : 'primary';
                $isActive = post('is_active') ? 1 : 0;
                update_id_type_policy($idTypeId, $category, $isActive);
                audit_log((int)$user['user_id'], 'edit', 'id_verification_policy', (string)$idTypeId, "Updated ID policy {$idTypeId} to {$category}, active={$isActive}");
                flash('ID classification updated successfully.', 'success');
            } elseif ($action === 'add_id_type') {
                $name = trim((string) post('new_id_name'));
                $category = post('new_category') === 'secondary' ? 'secondary' : 'primary';
                if ($name !== '') {
                    $code = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $name)));
                    $check = $pdo->prepare("SELECT COUNT(*) FROM gym_verification_id_types WHERE id_name = ? OR id_code = ?");
                    $check->execute([$name, $code]);
                    if ((int)$check->fetchColumn() === 0) {
                        $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(display_order), 0) FROM gym_verification_id_types")->fetchColumn();
                        $stmt = $pdo->prepare("INSERT INTO gym_verification_id_types (id_code, id_name, category, requires_supporting_id, display_order, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                        $stmt->execute([$code, $name, $category, $category === 'secondary' ? 1 : 0, $maxOrder + 10]);
                        audit_log((int)$user['user_id'], 'create', 'id_verification_policy', null, "Added ID document '{$name}' ({$category})");
                        flash("Added '{$name}' to accepted ID policy.", 'success');
                    } else {
                        flash("ID type '{$name}' already exists in policy.", 'danger');
                    }
                }
            }
            redirect('profile&tab=platform_settings');
        } elseif (isset($_POST['switch_home_gym']) && $is_member) {
            $newGymId = (int) post('new_gym_id');
            $pdo = db();
            try {
                $pdo->beginTransaction();
                // Keep the membership rule enforced here, even if the request bypasses the UI.
                $membershipStmt = $pdo->prepare('SELECT membership_id FROM memberships WHERE user_id = ? AND status = "active" LIMIT 1 FOR UPDATE');
                $membershipStmt->execute([(int) $user['user_id']]);
                if ($membershipStmt->fetchColumn()) {
                    $pdo->rollBack();
                    flash('You cannot change your home gym while you have an active membership.', 'danger');
                    redirect('profile');
                }

                $gymStmt = $pdo->prepare('SELECT name FROM gyms WHERE gym_id = ? AND status = "approved" FOR UPDATE');
                $gymStmt->execute([$newGymId]);
                $gym = $gymStmt->fetch(PDO::FETCH_ASSOC);
                if (!$gym) {
                    $pdo->rollBack();
                    flash('Invalid gym selected.', 'danger');
                    redirect('profile');
                }

                $pdo->prepare('INSERT IGNORE INTO gym_members (user_id, gym_id) VALUES (?, ?)')->execute([(int) $user['user_id'], $newGymId]);
                $pdo->commit();

                $_SESSION['current_gym_id'] = $newGymId;
                flash('Active home gym switched to ' . $gym['name'] . '. Your historical data (workouts, diet, progress) is now linked to this profile.', 'success');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Failed to switch member home gym: ' . $e->getMessage());
                flash('Unable to update your home gym right now. Please try again.', 'danger');
            }
            redirect('profile');
        } elseif (post('action') === 'leave_gym_facility' && $is_member) {
            $userId = (int) $user['user_id'];
            $requestedGymId = (int) post('leave_gym_id');
            if ($requestedGymId > 0) {
                $targetGym = query_one('SELECT gym_id, name FROM gyms WHERE gym_id = ? LIMIT 1', [$requestedGymId]);
            } else {
                $targetGym = get_user_gym($user);
            }
            if (!$targetGym || empty($targetGym['gym_id'])) {
                flash('You are not currently affiliated with that gym facility.', 'info');
                redirect('profile');
            }
            $gymId = (int) $targetGym['gym_id'];
            $gymName = (string) ($targetGym['name'] ?? 'Gym');

            // Guardrail: Block leaving a gym if the member has an active or pending membership there
            $activeMem = scalar(
                'SELECT m.membership_id FROM memberships m
                 JOIN membership_plans mp ON mp.plan_id = m.plan_id
                 WHERE m.user_id = ? AND mp.gym_id = ? AND m.status IN ("active", "pending") AND (m.end_date IS NULL OR m.end_date >= CURDATE())
                 LIMIT 1',
                [$userId, $gymId]
            );
            if ($activeMem) {
                flash("You cannot leave {$gymName} while you have an active or pending membership at this facility.", 'danger');
                redirect('profile');
            }

            $pdo = db();
            try {
                $pdo->beginTransaction();
                if (function_exists('release_user_equipment_on_checkout')) {
                    release_user_equipment_on_checkout($userId, $gymId);
                }
                // End active trainer assignments belonging to trainers at this gym
                $pdo->prepare(
                    'UPDATE trainer_assignments ta
                     JOIN trainer_profiles tp ON tp.user_id = ta.trainer_user_id
                     SET ta.status = "ended"
                     WHERE ta.member_user_id = ? AND tp.gym_id = ? AND ta.status = "active"'
                )->execute([$userId, $gymId]);
                $pdo->prepare('DELETE FROM gym_members WHERE user_id = ? AND gym_id = ?')
                    ->execute([$userId, $gymId]);
                audit_log($userId, 'leave_gym', 'gym', (string) $gymId, "Member unlinked from gym facility {$gymName}");
                $pdo->commit();

                if ((int) ($_SESSION['current_gym_id'] ?? 0) === $gymId) {
                    unset($_SESSION['current_gym_id']);
                }
                unset($_SESSION['member_gyms_cache_' . $userId]);
                flash("You have cleanly unlinked from {$gymName}. That facility's staff and trainers can no longer view your roster profile.", 'success');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Leave gym facility error: ' . $e->getMessage());
                flash('Unable to leave gym facility right now. Please try again.', 'danger');
            }
            redirect('profile');
        } elseif (post('action') === 'reset_health_data' && $is_member) {
            $userId = (int) $user['user_id'];
            $confirmWord = strtoupper(trim((string) post('confirm_reset_text')));
            $currentPassword = (string) post('current_password');
            $hasPassword = !empty($user['password_hash']);

            if ($confirmWord !== 'RESET') {
                flash('Please type RESET in capital letters to confirm clearing your health history.', 'danger');
                redirect('profile');
            }

            if ($hasPassword && !password_verify($currentPassword, (string) $user['password_hash'])) {
                flash('Incorrect current password. Health data reset was cancelled.', 'danger');
                redirect('profile');
            }

            $pdo = db();
            try {
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM progress_logs WHERE user_id = ?')->execute([$userId]);
                $pdo->prepare('DELETE FROM member_meal_logs WHERE user_id = ?')->execute([$userId]);
                $pdo->prepare('DELETE FROM daily_macros WHERE user_id = ?')->execute([$userId]);
                $pdo->prepare('DELETE FROM exercise_completions WHERE user_id = ?')->execute([$userId]);
                audit_log($userId, 'reset_health_data', 'user', (string) $userId, 'Member cleared exercise completions, daily macros, meal logs, and progress logs');
                $pdo->commit();
                flash('Your workout history, meal logs, and progress logs have been cleared. You can now start fresh!', 'success');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Reset health data error: ' . $e->getMessage());
                flash('Unable to clear health data right now. Please try again.', 'danger');
            }
            redirect('profile');
        } elseif (post('action') === 'toggle_gym_pause' && ($user['role'] ?? '') === 'gym_owner') {
            $userId = (int) $user['user_id'];
            $ownerGym = query_one('SELECT gym_id, name FROM gyms WHERE owner_user_id = ? LIMIT 1', [$userId]);
            if (!$ownerGym) {
                flash('No gym facility found for your owner account.', 'danger');
                redirect('profile');
            }
            $gymId = (int) $ownerGym['gym_id'];
            $gymName = (string) ($ownerGym['name'] ?? 'Your gym');
            $settingKey = 'gym_paused_' . $gymId;
            $noticeKey = 'gym_pause_notice_' . $gymId;
            $currentlyPaused = (string) scalar('SELECT setting_value FROM system_settings WHERE setting_key = ?', [$settingKey]) === '1';
            $newValue = $currentlyPaused ? '0' : '1';

            require_once __DIR__ . '/../../core/notifications.php';

            if ($newValue === '1') {
                $rawNotice = trim((string) post('pause_notice'));
                $defaultNotice = 'Our public listings and new sign-ups are temporarily paused for maintenance/holiday mode. Your active member access remains normal. See you at your workout!';
                $noticeText = $rawNotice !== '' ? mb_substr($rawNotice, 0, 255) : $defaultNotice;

                // Save pause status and notice in database
                db()->prepare('REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)')
                    ->execute([$settingKey, '1']);
                db()->prepare('REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)')
                    ->execute([$noticeKey, $noticeText]);

                audit_log($userId, 'toggle_gym_pause', 'gym', (string) $gymId, 'Gym directory visibility set to Paused (Maintenance / Holiday Mode)');

                // Clean up previous operational notices for this gym to avoid cluttering inboxes
                db()->prepare('DELETE FROM notifications WHERE reference_id = ? AND type = "system" AND title LIKE ?')
                    ->execute([$gymId, "Gym Notice: {$gymName}%"]);

                // Query all active members of this gym who have completed onboarding
                $memberIdsStmt = db()->prepare('
                    SELECT DISTINCT u.user_id
                    FROM users u
                    LEFT JOIN gym_members gm ON gm.user_id = u.user_id AND gm.gym_id = ?
                    LEFT JOIN memberships m ON m.user_id = u.user_id AND m.status = "active"
                    LEFT JOIN membership_plans mp ON mp.plan_id = m.plan_id AND mp.gym_id = ?
                    JOIN member_profiles prof ON prof.user_id = u.user_id AND prof.primary_goal IS NOT NULL AND prof.primary_goal != ""
                    WHERE u.role = "member"
                      AND u.status = "active"
                      AND (gm.gym_id = ? OR mp.gym_id = ?)
                ');
                $memberIdsStmt->execute([$gymId, $gymId, $gymId, $gymId]);
                $memberIds = $memberIdsStmt->fetchAll(PDO::FETCH_COLUMN);

                $notifTitle = "Gym Notice: {$gymName} Maintenance Mode";
                foreach ($memberIds as $mId) {
                    notify_user((int) $mId, 'system', $notifTitle, $noticeText, $gymId);
                }

                $count = count($memberIds);
                flash("{$gymName} is now in Maintenance / Holiday Mode. Hidden from public directory and {$count} member(s) notified in-app.", 'warning');
            } else {
                // Resume gym listing
                db()->prepare('REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)')
                    ->execute([$settingKey, '0']);

                audit_log($userId, 'toggle_gym_pause', 'gym', (string) $gymId, 'Gym directory visibility set to Active in Public Directory');

                // Clean up previous "Maintenance Mode" notices for this facility as it is now open and active
                db()->prepare('DELETE FROM notifications WHERE reference_id = ? AND type = "system" AND title LIKE ?')
                    ->execute([$gymId, "Gym Notice: {$gymName} Maintenance Mode%"]);

                // Clean up any older "Fully Active" notices for this facility so members don't receive duplicate active alerts
                db()->prepare('DELETE FROM notifications WHERE reference_id = ? AND type = "system" AND title LIKE ?')
                    ->execute([$gymId, "Gym Notice: {$gymName} is Fully Active%"]);

                // Query all active members of this gym who have completed onboarding
                $memberIdsStmt = db()->prepare('
                    SELECT DISTINCT u.user_id
                    FROM users u
                    LEFT JOIN gym_members gm ON gm.user_id = u.user_id AND gm.gym_id = ?
                    LEFT JOIN memberships m ON m.user_id = u.user_id AND m.status = "active"
                    LEFT JOIN membership_plans mp ON mp.plan_id = m.plan_id AND mp.gym_id = ?
                    JOIN member_profiles prof ON prof.user_id = u.user_id AND prof.primary_goal IS NOT NULL AND prof.primary_goal != ""
                    WHERE u.role = "member"
                      AND u.status = "active"
                      AND (gm.gym_id = ? OR mp.gym_id = ?)
                ');
                $memberIdsStmt->execute([$gymId, $gymId, $gymId, $gymId]);
                $memberIds = $memberIdsStmt->fetchAll(PDO::FETCH_COLUMN);

                $resumeTitle = "Gym Notice: {$gymName} is Fully Active";
                $resumeMsg = "Good news! {$gymName} regular operations and public listings have resumed. Have a great workout!";
                foreach ($memberIds as $mId) {
                    notify_user((int) $mId, 'system', $resumeTitle, $resumeMsg, $gymId);
                }

                flash("{$gymName} is now live and visible in the public Gym Directory again. Members have been notified.", 'success');
            }
            redirect('profile');
        } elseif ((post('action') === 'send_delete_account_otp' || isset($_POST['send_delete_account_otp'])) && in_array($user['role'] ?? '', ['member', 'gym_owner'], true)) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/json; charset=UTF-8');

            $userId = (int) $user['user_id'];
            $role = (string) ($user['role'] ?? '');
            $currentPassword = (string) post('current_password');
            $hasPassword = !empty($user['password_hash']);

            // Rate Limit: max 3 OTP send requests per 10 minutes per user / 5 per IP
            if (
                RateLimiter::tooManyAttempts('delete_otp_send:' . $userId, 3, 600) ||
                RateLimiter::tooManyAttempts('delete_otp_ip', 5, 600)
            ) {
                $wait = max(
                    RateLimiter::secondsRemaining('delete_otp_send:' . $userId, 600),
                    RateLimiter::secondsRemaining('delete_otp_ip', 600)
                );
                http_response_code(429);
                echo json_encode([
                    'ok' => false,
                    'error' => "Too many verification code requests. Please wait {$wait} seconds before trying again.",
                    'retry_after' => $wait
                ]);
                exit;
            }

            // Password Check Guardrail (for accounts with a password set)
            if ($hasPassword && !password_verify($currentPassword, (string) $user['password_hash'])) {
                RateLimiter::hit('delete_otp_send:' . $userId, 600);
                http_response_code(403);
                echo json_encode([
                    'ok' => false,
                    'error' => 'Incorrect current password. Please enter your current account password to request a deletion code.'
                ]);
                exit;
            }

            // Active Membership Guardrail:
            // 1. If Gym Owner, block if their gym still has active paid member subscriptions
            if ($role === 'gym_owner') {
                $ownerGym = query_one('SELECT gym_id, name FROM gyms WHERE owner_user_id = ? LIMIT 1', [$userId]);
                if ($ownerGym) {
                    $activeMemberCount = (int) scalar(
                        'SELECT COUNT(*) FROM memberships m
                         JOIN membership_plans mp ON mp.plan_id = m.plan_id
                         WHERE mp.gym_id = ? AND m.status = "active" AND m.end_date >= CURDATE()',
                        [(int) $ownerGym['gym_id']]
                    );
                    if ($activeMemberCount > 0) {
                        http_response_code(422);
                        echo json_encode([
                            'ok' => false,
                            'error' => "Your gym ({$ownerGym['name']}) still has {$activeMemberCount} active member subscription(s). For billing and member protection, all active memberships must expire or be resolved before you can delete your gym owner account."
                        ]);
                        exit;
                    }
                }
            } elseif ($role === 'member') {
                // 2. If Member, block if they currently have an active paid membership
                $activeMem = query_one(
                    'SELECT g.name AS gym_name, m.end_date
                     FROM memberships m
                     JOIN membership_plans mp ON mp.plan_id = m.plan_id
                     JOIN gyms g ON g.gym_id = mp.gym_id
                     WHERE m.user_id = ? AND m.status = "active" AND m.end_date >= CURDATE()
                     ORDER BY m.end_date DESC LIMIT 1',
                    [$userId]
                );
                if ($activeMem) {
                    http_response_code(422);
                    echo json_encode([
                        'ok' => false,
                        'error' => "You currently have an active paid membership at {$activeMem['gym_name']} (valid until {$activeMem['end_date']}). To protect your paid subscription, account deletion is blocked until your active membership expires or is cancelled by the gym."
                    ]);
                    exit;
                }
            }

            RateLimiter::hit('delete_otp_send:' . $userId, 600);
            RateLimiter::hit('delete_otp_ip', 600);

            $otp = (string) random_int(100000, 999999);
            $otpKey = 'del:' . strtolower((string) $user['email']);
            db()->prepare('REPLACE INTO password_resets (email, token, created_at) VALUES (?, ?, NOW())')
                ->execute([$otpKey, $otp]);

            try {
                Emails::sendAccountDeletionOtp((string) $user['email'], (string) $user['first_name'], $otp, true);
            } catch (Throwable $e) {
                error_log('Account deletion OTP email error: ' . $e->getMessage());
            }

            audit_log($userId, 'request_delete_otp', 'user', (string) $userId, "Requested account deletion verification OTP ({$role})");

            echo json_encode([
                'ok' => true,
                'message' => 'A 6-digit verification code has been sent to ' . $user['email'] . '. It expires in 10 minutes.',
                'cooldown' => 60
            ]);
            exit;
        } elseif ((post('action') === 'confirm_delete_account' || isset($_POST['confirm_delete_account'])) && in_array($user['role'] ?? '', ['member', 'gym_owner'], true)) {
            $isAjax = (post('ajax') === '1' || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')));
            $respondError = static function (string $msg, int $status = 400) use ($isAjax): void {
                if ($isAjax) {
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    http_response_code($status);
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode(['ok' => false, 'error' => $msg]);
                    exit;
                }
                flash($msg, 'danger');
                redirect('profile');
            };

            $userId = (int) $user['user_id'];
            $role = (string) ($user['role'] ?? '');
            $otpInput = preg_replace('/[^0-9]/', '', (string) post('otp_code'));
            $confirmWord = strtoupper(trim((string) post('confirm_text')));

            // Rate Limit OTP verification attempts: max 5 attempts per 10 minutes
            if (RateLimiter::tooManyAttempts('delete_otp_verify:' . $userId, 5, 600)) {
                $wait = RateLimiter::secondsRemaining('delete_otp_verify:' . $userId, 600);
                $respondError("Too many failed verification attempts. Please wait {$wait} seconds before trying again.", 429);
            }

            if ($confirmWord !== 'DELETE') {
                $respondError('Please type DELETE in the confirmation box to proceed.');
            }

            if (strlen($otpInput) !== 6) {
                $respondError('Please enter a valid 6-digit verification code.');
            }

            $otpKey = 'del:' . strtolower((string) $user['email']);
            $resetRow = query_one(
                'SELECT * FROM password_resets WHERE email = ? AND created_at >= NOW() - INTERVAL 10 MINUTE',
                [$otpKey]
            );

            if (!$resetRow || !hash_equals((string) $resetRow['token'], $otpInput)) {
                RateLimiter::hit('delete_otp_verify:' . $userId, 600);
                $respondError('Invalid or expired verification code. Please check your email and try again.');
            }

            $pdo = db();
            try {
                $pdo->beginTransaction();

                if ($role === 'gym_owner') {
                    $ownerGym = query_one('SELECT gym_id, name FROM gyms WHERE owner_user_id = ? LIMIT 1 FOR UPDATE', [$userId]);
                    if ($ownerGym) {
                        $gymId = (int) $ownerGym['gym_id'];
                        $activeMemberCount = (int) scalar(
                            'SELECT COUNT(*) FROM memberships m
                             JOIN membership_plans mp ON mp.plan_id = m.plan_id
                             WHERE mp.gym_id = ? AND m.status = "active" AND m.end_date >= CURDATE()',
                            [$gymId]
                        );
                        if ($activeMemberCount > 0) {
                            $pdo->rollBack();
                            $respondError("Cannot delete account while {$ownerGym['name']} has {$activeMemberCount} active member subscription(s).", 422);
                        }

                        // Deactivate gym and its plans
                        $pdo->prepare('UPDATE gyms SET status = "rejected", subscription_status = "expired" WHERE gym_id = ?')
                            ->execute([$gymId]);
                        $pdo->prepare('UPDATE membership_plans SET is_active = 0 WHERE gym_id = ?')
                            ->execute([$gymId]);
                    }
                } elseif ($role === 'member') {
                    $activeMem = query_one(
                        'SELECT g.name AS gym_name, m.end_date
                         FROM memberships m
                         JOIN membership_plans mp ON mp.plan_id = m.plan_id
                         JOIN gyms g ON g.gym_id = mp.gym_id
                         WHERE m.user_id = ? AND m.status = "active" AND m.end_date >= CURDATE()
                         LIMIT 1 FOR UPDATE',
                        [$userId]
                    );
                    if ($activeMem) {
                        $pdo->rollBack();
                        $respondError("Cannot delete account while you have an active membership at {$activeMem['gym_name']} (valid until {$activeMem['end_date']}).", 422);
                    }

                    // Release any active equipment usage/queues
                    if (function_exists('release_user_equipment_on_checkout')) {
                        release_user_equipment_on_checkout($userId);
                    }
                    // Cancel any remaining pending memberships
                    $pdo->prepare('UPDATE memberships SET status = "cancelled" WHERE user_id = ? AND status = "pending"')
                        ->execute([$userId]);
                    // End active trainer assignments
                    $pdo->prepare('UPDATE trainer_assignments SET status = "ended" WHERE member_user_id = ? AND status = "active"')
                        ->execute([$userId]);
                    // Remove personal health/biometric & log data
                    $pdo->prepare('DELETE FROM member_profiles WHERE user_id = ?')->execute([$userId]);
                    $pdo->prepare('DELETE FROM progress_logs WHERE user_id = ?')->execute([$userId]);
                    $pdo->prepare('DELETE FROM member_meal_logs WHERE user_id = ?')->execute([$userId]);
                    $pdo->prepare('DELETE FROM daily_macros WHERE user_id = ?')->execute([$userId]);
                    $pdo->prepare('DELETE FROM exercise_completions WHERE user_id = ?')->execute([$userId]);
                    $pdo->prepare('DELETE FROM gym_members WHERE user_id = ?')->execute([$userId]);
                }

                // Consume OTP token
                $pdo->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$otpKey]);

                // Anonymize & deactivate the user row while preserving FK integrity for financial audit logs
                $anonEmail = 'deleted_' . $role . '_' . $userId . '_' . time() . '@deleted.local';
                $anonLabel = $role === 'gym_owner' ? 'Owner' : 'Member';
                $pdo->prepare(
                    'UPDATE users
                     SET first_name = "Deleted",
                         last_name = ?,
                         email = ?,
                         phone = NULL,
                         password_hash = "",
                         google_id = NULL,
                         profile_picture = NULL,
                         status = "inactive",
                         session_token = NULL
                     WHERE user_id = ?'
                )->execute([$anonLabel, $anonEmail, $userId]);

                audit_log($userId, 'delete_account', 'user', (string) $userId, "Permanently deleted and anonymized {$role} account via Email OTP");
                $pdo->commit();

                RateLimiter::clear('delete_otp_send:' . $userId);
                RateLimiter::clear('delete_otp_verify:' . $userId);

                // Destroy authenticated session and set flash on clean session
                $_SESSION = [];
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_destroy();
                }
                session_start();
                flash('Your account and personal data have been permanently deleted.', 'success');

                if ($isAjax) {
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode([
                        'ok' => true,
                        'redirect' => '?page=login'
                    ]);
                    exit;
                }
                redirect('login');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Account deletion failed for user ' . $userId . ': ' . $e->getMessage());
                $respondError('An unexpected error occurred while deleting your account. Please try again.', 500);
            }
        }
    }

}
