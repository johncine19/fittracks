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
            audit_log((int)$user['user_id'], 'edit', 'platform_settings', null, json_encode($submittedSettings));
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
        }
    }

}
