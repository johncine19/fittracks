<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function users_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner', 'staff']);
    $isAdmin = $user['role'] === 'platform_admin';
    $isStaff = $user['role'] === 'staff';
    $gymId = null;
    if ($isAdmin) {
        $gymId = null;
    } elseif ($isStaff) {
        $gymId = (int) scalar('SELECT gym_id FROM gym_staff WHERE user_id = ?', [$user['user_id']]);
    } else {
        $gymId = (int) scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
    }

    $availablePlans = [];
    $platformPlans = [];
    if ($gymId) {
        $availablePlans = query_all('SELECT plan_id, plan_name, duration_days, price, plan_type FROM membership_plans WHERE gym_id = ? AND is_active = 1 ORDER BY price ASC', [$gymId]);
    } elseif ($isAdmin) {
        $platformPlans = get_platform_subscription_plans();
    }

    $canManageUser = function(int $targetUserId) use ($isAdmin, $isStaff, $gymId): bool {
        if ($isStaff) return false;
        $targetRole = scalar('SELECT role FROM users WHERE user_id = ?', [$targetUserId]);
        if ($targetRole === 'platform_admin') return false;
        if ($isAdmin) return true;
        if ($targetRole === 'staff') {
            return (bool) scalar('SELECT 1 FROM gym_staff WHERE user_id = ? AND gym_id = ?', [$targetUserId, $gymId]);
        } elseif ($targetRole === 'trainer') {
            return (bool) scalar('SELECT 1 FROM trainer_profiles WHERE user_id = ? AND gym_id = ?', [$targetUserId, $gymId]);
        } elseif ($targetRole === 'member') {
            return (bool) scalar('SELECT 1 FROM gym_members WHERE user_id = ? AND gym_id = ?', [$targetUserId, $gymId]);
        }
        return false;
    };

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
            || post('ajax') === '1';

        $sendCreateResponse = function(bool $success, string $message, string $level = 'danger') use ($isAjax) {
            if ($isAjax) {
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => $success,
                    'message' => $message,
                    'csrf_token' => csrf_token(),
                ]);
                exit;
            }
            if (!$success) {
                $_SESSION['_old_create_user'] = $_POST;
            } else {
                unset($_SESSION['_old_create_user']);
            }
            flash($message, $level);
            redirect('users');
            return;
        };

        if (post('action') === 'create') {
            $roleToCreate = post('role');
            if ($isAdmin && in_array($roleToCreate, ['staff', 'trainer'], true)) {
                $sendCreateResponse(false, 'Platform administrators can only onboard Gym Owners or Members. Staff and Trainers must be added directly by their respective gym owner.');
                return;
            }
            if ($isAdmin && !in_array($roleToCreate, ['gym_owner', 'member'], true)) {
                $sendCreateResponse(false, 'Invalid role selection for platform administrator.');
                return;
            }
            if (!$isAdmin && !in_array($roleToCreate, ['trainer', 'member', 'staff'], true)) {
                $sendCreateResponse(false, 'You do not have permission to create this role.');
                return;
            }

            $phone = preg_replace('/[^0-9]/', '', (string)post('phone'));
            if (strlen($phone) !== 11) {
                $sendCreateResponse(false, 'Mobile number is required and must be exactly 11 digits.');
                return;
            }

            $email = strtolower(trim((string) post('email')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $sendCreateResponse(false, 'Please enter a valid email address.');
                return;
            }
            if (scalar('SELECT user_id FROM users WHERE email = ?', [$email])) {
                $sendCreateResponse(false, 'A user with that email already exists.');
                return;
            }
            if (!is_acceptable_password((string) post('password'))) {
                $sendCreateResponse(false, 'Password must be at least 8 characters, with a letter and a number, and not be a common password.');
                return;
            }

            if ($roleToCreate === 'member' && !$isAdmin && !gym_can_add_member($gymId)) {
                $limit = gym_member_limit();
                $activeCount = gym_active_member_count($gymId);
                $sendCreateResponse(false, "Active member capacity reached ({$activeCount}/{$limit} members). Please upgrade your subscription plan to add more members.", 'warning');
                return;
            }
            if ($roleToCreate === 'trainer' && !$isAdmin) {
                if (!gym_has_feature('trainers')) {
                    $sendCreateResponse(false, "Trainer management is a Professional & Business plan feature. Please upgrade your subscription.", 'warning');
                    return;
                }
                if (!gym_can_add_trainer($gymId)) {
                    $tLimit = gym_trainer_limit();
                    $activeTCount = gym_active_trainer_count($gymId);
                    $sendCreateResponse(false, "Trainer capacity reached ({$activeTCount}/{$tLimit} trainers). Please upgrade your subscription plan to add more trainers.", 'warning');
                    return;
                }
            }
            if ($roleToCreate === 'staff' && !$isAdmin) {
                if (!gym_has_feature('staff')) {
                    $sendCreateResponse(false, "Staff management requires an active Free Trial or a Starter/Professional/Business plan. Please upgrade your subscription.", 'warning');
                    return;
                }
                if (!gym_can_add_staff($gymId)) {
                    $sLimit = gym_staff_limit();
                    $activeSCount = gym_active_staff_count($gymId);
                    $sendCreateResponse(false, "Staff account capacity reached ({$activeSCount}/{$sLimit} staff). Please upgrade your subscription plan to add more staff.", 'warning');
                    return;
                }
            }

            $plainPassword = (string) post('password');
            $firstName = mb_convert_case(trim((string) post('first_name')), MB_CASE_TITLE, 'UTF-8');
            $lastName  = mb_convert_case(trim((string) post('last_name')), MB_CASE_TITLE, 'UTF-8');

            $staffRole = null;
            if ($roleToCreate === 'staff') {
                $allowedStaffRoles = ['front_desk', 'manager'];
                $reqStaffRole = trim((string) post('staff_role'));
                $staffRole = in_array($reqStaffRole, $allowedStaffRoles, true) ? $reqStaffRole : 'front_desk';
            } elseif ($roleToCreate === 'trainer') {
                $staffRole = 'trainer';
            }

            if ($roleToCreate === 'gym_owner' && $isAdmin) {
                $gymName = trim((string) post('gym_name'));
                if ($gymName === '') {
                    $sendCreateResponse(false, 'Gym Facility Name is required when creating a gym owner.');
                    return;
                }
            }

            db()->beginTransaction();
            try {
                $stmt = db()->prepare('INSERT INTO users (role, first_name, last_name, email, password_hash, phone, status, email_verified_at, staff_role) VALUES (?, ?, ?, ?, ?, ?, "active", NOW(), ?)');
                $stmt->execute([$roleToCreate, $firstName, $lastName, $email, password_hash($plainPassword, PASSWORD_DEFAULT), $phone ?: null, $staffRole]);
                $newUserId = (int) db()->lastInsertId();

                $planAssigned = null;
                $subPlanName = null;
                $createdGymName = null;
                if ($roleToCreate === 'gym_owner' && $isAdmin) {
                    $createdGymName = trim((string) post('gym_name'));
                    $gymAddress = trim((string) post('gym_address')) ?: 'Primary Facility';
                    $platPlanKey = trim((string) post('platform_plan'));
                    $billingCycle = (post('platform_billing_cycle') === 'yearly') ? 'yearly' : 'monthly';
                    $subPaymentStatus = (string) post('platform_payment_status');

                    $subPlanName = null;
                    $subStatus = 'inactive';
                    $renewalDate = null;
                    $amount = 0.0;

                    $platPlans = get_platform_subscription_plans();
                    if ($platPlanKey === 'free_trial' || $subPaymentStatus === 'trialing') {
                        $subPlanName = 'Free Trial';
                        $subStatus = 'trialing';
                        $renewalDate = date('Y-m-d', strtotime('+14 days'));
                        $amount = 0.0;
                    } elseif (isset($platPlans[$platPlanKey])) {
                        $pInfo = $platPlans[$platPlanKey];
                        $subPlanName = $pInfo['name'];
                        $amount = ($billingCycle === 'yearly') ? (float)($pInfo['annual_price'] ?? ($pInfo['price'] * 10)) : (float)$pInfo['price'];
                        if (in_array($subPaymentStatus, ['active_cash', 'active_online'], true)) {
                            $subStatus = 'active';
                            $renewalDate = date('Y-m-d', strtotime($billingCycle === 'yearly' ? '+1 year' : '+1 month'));
                        } else {
                            $subStatus = 'inactive';
                            $renewalDate = null;
                        }
                    }

                    $gStmt = db()->prepare('INSERT INTO gyms (owner_user_id, name, address, contact_info, status, subscription_plan, subscription_status, subscription_renewal_date, subscription_billing_cycle) VALUES (?, ?, ?, ?, "approved", ?, ?, ?, ?)');
                    $gStmt->execute([
                        $newUserId,
                        $createdGymName,
                        $gymAddress,
                        $phone ?: null,
                        $subPlanName,
                        $subStatus,
                        $renewalDate,
                        $billingCycle
                    ]);
                    $newGymId = (int) db()->lastInsertId();

                    // If active and paid, record in gym_subscription_payments
                    if ($subStatus === 'active' && $amount > 0 && in_array($subPaymentStatus, ['active_cash', 'active_online'], true)) {
                        $receiptNumber = 'SUB-ADM-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
                        $method = ($subPaymentStatus === 'active_cash') ? 'cash' : 'bank_transfer';
                        $startDate = date('Y-m-d');
                        $endDate = $renewalDate ?: date('Y-m-d', strtotime($billingCycle === 'yearly' ? '+1 year' : '+1 month'));

                        db()->prepare('
                            INSERT INTO gym_subscription_payments 
                            (gym_id, owner_user_id, plan_name, amount, billing_cycle, payment_method, status, receipt_number, payment_date, start_date, end_date)
                            VALUES (?, ?, ?, ?, ?, ?, "paid", ?, NOW(), ?, ?)
                        ')->execute([
                            $newGymId,
                            $newUserId,
                            $subPlanName,
                            $amount,
                            $billingCycle,
                            $method,
                            $receiptNumber,
                            $startDate,
                            $endDate
                        ]);
                    }

                    notify_user(
                        $newUserId,
                        'system',
                        'Welcome to FitTracks!',
                        "Welcome to FitTracks! Your gym '{$createdGymName}' has been provisioned with the " . ($subPlanName ? "{$subPlanName} Plan ({$subStatus})" : "No Plan") . "."
                    );
                } elseif ($roleToCreate === 'trainer') {
                    $trainerGymId = $isAdmin ? null : $gymId;
                    db()->prepare('INSERT INTO trainer_profiles (user_id, specialization, bio, gym_id) VALUES (?, ?, ?, ?)')->execute([$newUserId, post('specialization'), post('bio'), $trainerGymId]);
                } elseif ($roleToCreate === 'staff') {
                    $staffGymId = $isAdmin ? (int)(post('gym_id') ?: 0) : $gymId;
                    $staffTitle = ($staffRole === 'manager') ? 'Operations Manager' : 'Front Desk Staff';
                    db()->prepare('INSERT INTO gym_staff (gym_id, user_id, staff_title) VALUES (?, ?, ?)')->execute([$staffGymId, $newUserId, $staffTitle]);
                } elseif ($roleToCreate === 'member') {
                    $targetGymId = $gymId ?: (int)(post('gym_id') ?: 0);
                    if ($targetGymId > 0) {
                        db()->prepare('INSERT IGNORE INTO gym_members (gym_id, user_id) VALUES (?, ?)')->execute([$targetGymId, $newUserId]);
                    }

                    // Directly allocate membership plan if chosen by gym owner / admin
                    $selectedPlanId = (int) post('membership_plan_id');
                    if ($selectedPlanId > 0) {
                        $planStmt = db()->prepare('SELECT plan_id, plan_name, duration_days, price, gym_id FROM membership_plans WHERE plan_id = ? AND is_active = 1');
                        $planStmt->execute([$selectedPlanId]);
                        $planAssigned = $planStmt->fetch(PDO::FETCH_ASSOC);

                        if ($planAssigned && ($isAdmin || (int)$planAssigned['gym_id'] === $gymId || $planAssigned['gym_id'] === null)) {
                            // Ensure member is linked to the plan's gym facility
                            $planGymId = (int)($planAssigned['gym_id'] ?? $targetGymId);
                            if ($planGymId > 0 && $planGymId !== $targetGymId) {
                                db()->prepare('INSERT IGNORE INTO gym_members (gym_id, user_id) VALUES (?, ?)')->execute([$planGymId, $newUserId]);
                            }

                            $duration = (int)($planAssigned['duration_days'] ?? 30);
                            $startDateStr = trim((string) post('membership_start_date'));
                            if (!strtotime($startDateStr)) {
                                $startDateStr = date('Y-m-d');
                            }
                            $start = new DateTime($startDateStr);
                            $end = (clone $start)->modify('+' . $duration . ' days')->format('Y-m-d');

                            $payOption = (string) post('membership_payment_status');
                            $isPaid = in_array($payOption, ['paid_cash', 'paid_online', 'complimentary'], true);
                            $membershipStatus = $isPaid ? 'active' : 'pending';

                            $amount = (float) $planAssigned['price'];
                            $method = 'cash';
                            if ($payOption === 'paid_online') {
                                $method = 'online';
                            } elseif ($payOption === 'complimentary') {
                                $method = 'complimentary';
                                $amount = 0.0;
                            }

                            $mStmt = db()->prepare('INSERT INTO memberships (user_id, plan_id, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)');
                            $mStmt->execute([$newUserId, $selectedPlanId, $start->format('Y-m-d'), $end, $membershipStatus]);
                            $membershipId = (int) db()->lastInsertId();

                            $receiptPrefix = ($payOption === 'complimentary') ? 'COMP-' : ($isPaid ? 'RCPT-' : 'PEND-');
                            $receipt = $receiptPrefix . date('Ymd') . '-' . random_int(1000, 9999);
                            $paymentStatus = $isPaid ? 'paid' : 'pending';

                            $pStmt = db()->prepare('INSERT INTO payments (membership_id, amount, payment_date, payment_method, status, receipt_number, processed_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                            $pStmt->execute([$membershipId, $amount, $start->format('Y-m-d'), $method, $paymentStatus, $receipt, $user['user_id']]);
                            $paymentId = (int) db()->lastInsertId();

                            if ($paymentStatus === 'paid' && $amount > 0 && function_exists('process_trainer_commission')) {
                                process_trainer_commission($paymentId, $amount);
                            }

                            $facilityGymId = $planGymId ?: $gymId;
                            $gymName = $facilityGymId ? (scalar('SELECT name FROM gyms WHERE gym_id = ?', [$facilityGymId]) ?: 'our gym') : 'our gym';
                            if ($membershipStatus === 'active') {
                                notify_user($newUserId, 'system', 'Membership Active', "Welcome to {$gymName}! Your {$planAssigned['plan_name']} membership is active until " . date('M j, Y', strtotime($end)) . '.');
                            } else {
                                notify_user($newUserId, 'system', 'Membership Pending', "Your {$planAssigned['plan_name']} membership at {$gymName} has been created and is pending payment confirmation.");
                            }
                        }
                    }
                }

                audit_log($user['user_id'], 'create', 'user', (string) $newUserId, json_encode([
                    'role' => $roleToCreate,
                    'email' => $email,
                    'name' => $firstName . ' ' . $lastName,
                    'plan' => $planAssigned ? $planAssigned['plan_name'] : ($subPlanName ?? null),
                    'gym' => $createdGymName ?? null
                ]));

                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                $sendCreateResponse(false, 'Failed to create user: ' . $e->getMessage());
                return;
            }

            // Send credentials email via background queue (out-of-transaction)
            Emails::sendAccountCreated(
                $email,
                $firstName . ' ' . $lastName,
                $plainPassword
            );

            $successMsg = $planAssigned
                ? 'Member account created and ' . $planAssigned['plan_name'] . ' membership activated successfully. Login credentials emailed to ' . $email . '.'
                : 'User created successfully. Login credentials have been emailed to ' . $email . '.';

            $sendCreateResponse(true, $successMsg, 'success');
            return;

        } elseif (post('action') === 'status') {
            $targetUserId = (int) post('user_id');
            if (!$canManageUser($targetUserId)) {
                flash('Permission denied.', 'danger');
                redirect('users');
                return;
            }
            db()->prepare('UPDATE users SET status = ? WHERE user_id = ?')->execute([post('status'), $targetUserId]);
            audit_log($user['user_id'], 'update_status', 'user', (string) $targetUserId, json_encode(['new_status' => post('status')]));
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
            if (!$canManageUser($editUserId)) {
                flash('Permission denied.', 'danger');
                redirect('users');
                return;
            }
            
            $roleToEdit = post('role');
            if (!$isAdmin && !in_array($roleToEdit, ['trainer', 'member', 'staff'])) {
                flash('You do not have permission to assign this role.', 'danger');
                redirect('users');
                return;
            }
            
            $newPassword = (string) post('new_password');
            $phone = preg_replace('/[^0-9]/', '', (string)post('phone'));
            if (strlen($phone) !== 11) {
                flash('Mobile number is required and must be exactly 11 digits.', 'danger');
                redirect('users');
                return;
            }
            $editFirstName = mb_convert_case(trim((string) post('first_name')), MB_CASE_TITLE, 'UTF-8');
            $editLastName  = mb_convert_case(trim((string) post('last_name')), MB_CASE_TITLE, 'UTF-8');
            
            $editEmail = strtolower(trim((string) post('email')));
            if (!filter_var($editEmail, FILTER_VALIDATE_EMAIL)) {
                flash('Please enter a valid email address.', 'danger');
                redirect('users');
                return;
            }
            if (scalar('SELECT user_id FROM users WHERE email = ? AND user_id != ?', [$editEmail, $editUserId])) {
                flash('That email is already in use by another account.', 'danger');
                redirect('users');
                return;
            }

            if ($newPassword !== '') {
                if (!is_acceptable_password($newPassword)) {
                    flash('New password must be at least 8 characters, with a letter and a number.', 'danger');
                    redirect('users');
                    return;
                }
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                db()->prepare('UPDATE users SET first_name=?, last_name=?, email=?, phone=?, role=?, password_hash=? WHERE user_id=?')
                    ->execute([$editFirstName, $editLastName, $editEmail, $phone, post('role'), $hash, $editUserId]);
            } else {
                db()->prepare('UPDATE users SET first_name=?, last_name=?, email=?, phone=?, role=? WHERE user_id=?')
                    ->execute([$editFirstName, $editLastName, $editEmail, $phone, post('role'), $editUserId]);
            }
            
            if (post('role') === 'trainer') {
                db()->prepare('UPDATE users SET staff_role = "trainer" WHERE user_id = ?')->execute([$editUserId]);
                db()->prepare('INSERT INTO trainer_profiles (user_id, specialization, bio) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE specialization = VALUES(specialization), bio = VALUES(bio)')
                    ->execute([$editUserId, post('specialization'), post('bio')]);
            } elseif (post('role') === 'staff') {
                $allowedStaffRoles = ['front_desk', 'manager'];
                $reqStaffRole = trim((string) post('staff_role'));
                $staffRole = in_array($reqStaffRole, $allowedStaffRoles, true) ? $reqStaffRole : 'front_desk';
                $staffTitle = ($staffRole === 'manager') ? 'Operations Manager' : 'Front Desk Staff';
                db()->prepare('UPDATE users SET staff_role = ? WHERE user_id = ?')->execute([$staffRole, $editUserId]);
                $staffGymId = $isAdmin ? (int)(post('gym_id') ?: 0) : $gymId;
                db()->prepare('INSERT INTO gym_staff (gym_id, user_id, staff_title) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE staff_title = VALUES(staff_title)')
                    ->execute([$staffGymId, $editUserId, $staffTitle]);
            }
            audit_log($user['user_id'], 'edit', 'user', (string) $editUserId, json_encode(['email' => post('email'), 'role' => post('role'), 'staff_role' => post('staff_role'), 'password_changed' => $newPassword !== '']));
            flash('User updated successfully.');
        } elseif (post('action') === 'delete_user') {
            $targetUserId = (int) post('user_id');
            if (!$canManageUser($targetUserId)) {
                flash('Permission denied.', 'danger');
                redirect('users');
                return;
            }
            
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
                    
                    db()->prepare('DELETE FROM gym_staff WHERE user_id=?')->execute([$targetUserId]);
                    db()->prepare('DELETE FROM users WHERE user_id=?')->execute([$targetUserId]);
                    audit_log($user['user_id'], 'delete', 'user', (string) $targetUserId, json_encode(['role' => $targetUser['role']]));
                    flash('User deleted.');
                }
            }
        }
        redirect('users');
    }

    // ── AJAX: Member Progress Quick Summary ──────────────────────────────
    if (($_GET['action'] ?? post('action')) === 'get_member_progress') {
        if (ob_get_level()) ob_clean();
        header('Content-Type: application/json');

        $targetMemberId = (int)($_GET['member_user_id'] ?? post('member_user_id') ?? 0);
        if ($targetMemberId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid member ID.']);
            exit;
        }

        // Permission check: Gym owners can only view members of their gym
        if (!$isAdmin) {
            $isMemberOfGym = (bool) scalar('SELECT 1 FROM gym_members WHERE user_id = ? AND gym_id = ?', [$targetMemberId, $gymId]);
            if (!$isMemberOfGym) {
                echo json_encode(['success' => false, 'message' => 'Permission denied. Member does not belong to your facility.']);
                exit;
            }
        }

        $stmt = db()->prepare('
            SELECT u.user_id, u.first_name, u.last_name, u.email, u.phone, u.profile_picture, u.created_at, u.engagement_score,
                   mp.height_cm, mp.weight_kg AS baseline_weight, mp.target_weight_kg, mp.target_body_fat_percent,
                   mp.neck_cm, mp.waist_cm, mp.hip_cm, mp.chest_cm, mp.arm_cm, mp.age, mp.biological_sex,
                   mp.primary_goal, mp.weekly_workout_target, mp.fitness_tier,
                   (SELECT mp2.plan_name FROM memberships m2 JOIN membership_plans mp2 ON m2.plan_id = mp2.plan_id WHERE m2.user_id = u.user_id ORDER BY (m2.status = "active" AND m2.end_date >= CURDATE()) DESC, m2.end_date DESC LIMIT 1) AS plan_name,
                   (SELECT m3.status FROM memberships m3 WHERE m3.user_id = u.user_id ORDER BY (m3.status = "active" AND m3.end_date >= CURDATE()) DESC, m3.end_date DESC LIMIT 1) AS membership_status,
                   (SELECT CONCAT(tu.first_name, " ", tu.last_name) FROM trainer_assignments ta JOIN trainer_profiles tp ON ta.trainer_id = tp.trainer_id JOIN users tu ON tp.user_id = tu.user_id WHERE ta.member_user_id = u.user_id AND ta.status = "active" LIMIT 1) AS coach_name
            FROM users u
            LEFT JOIN member_profiles mp ON u.user_id = mp.user_id
            WHERE u.user_id = ? AND u.role = "member"
        ');
        $stmt->execute([$targetMemberId]);
        $memberData = $stmt->fetch();

        if (!$memberData) {
            echo json_encode(['success' => false, 'message' => 'Member profile not found.']);
            exit;
        }

        // Fetch all progress logs for this member
        $logs = query_all('SELECT * FROM progress_logs WHERE user_id = ? ORDER BY log_date DESC, created_at DESC, log_id DESC', [$targetMemberId]);

        $latestLog = $logs[0] ?? null;
        $earliestLog = !empty($logs) ? end($logs) : null;

        // Determine current weight
        $currentWeight = null;
        if (!empty($latestLog['weight_kg']) && (float)$latestLog['weight_kg'] > 0) {
            $currentWeight = (float)$latestLog['weight_kg'];
        } elseif (!empty($memberData['baseline_weight']) && (float)$memberData['baseline_weight'] > 0) {
            $currentWeight = (float)$memberData['baseline_weight'];
        }

        // Starting weight
        $startingWeight = null;
        if (!empty($earliestLog['weight_kg']) && (float)$earliestLog['weight_kg'] > 0) {
            $startingWeight = (float)$earliestLog['weight_kg'];
        } elseif (!empty($memberData['baseline_weight']) && (float)$memberData['baseline_weight'] > 0) {
            $startingWeight = (float)$memberData['baseline_weight'];
        }

        $targetWeight = !empty($memberData['target_weight_kg']) ? (float)$memberData['target_weight_kg'] : null;

        // Weight delta
        $weightDelta = null;
        $weightDeltaPct = null;
        $deltaType = 'neutral';
        if ($currentWeight !== null && $startingWeight !== null && $startingWeight > 0) {
            $diff = round($currentWeight - $startingWeight, 1);
            $weightDelta = ($diff > 0 ? '+' : '') . $diff . ' kg';
            $weightDeltaPct = round(($diff / $startingWeight) * 100, 1);
            if ($diff > 0) {
                $deltaType = 'gain';
            } elseif ($diff < 0) {
                $deltaType = 'loss';
            }
        }

        // Body fat: check latest log or calculate Navy Formula
        $bodyFat = null;
        if (!empty($latestLog['body_fat_percent']) && (float)$latestLog['body_fat_percent'] > 0) {
            $bodyFat = (float)$latestLog['body_fat_percent'];
        } else {
            $heightCm = (float)($memberData['height_cm'] ?? 0);
            $waistCm = (float)($latestLog['waist_cm'] ?? $memberData['waist_cm'] ?? 0);
            $neckCm = (float)($latestLog['neck_cm'] ?? $memberData['neck_cm'] ?? 0);
            $hipCm = (float)($latestLog['hips_cm'] ?? $memberData['hip_cm'] ?? 0);
            $isFemale = strtolower((string)($memberData['biological_sex'] ?? '')) === 'female';

            if ($heightCm > 0 && $neckCm > 0 && $waistCm > 0) {
                if (!$isFemale && $waistCm > $neckCm) {
                    $est = 495 / (1.0324 - 0.19077 * log10($waistCm - $neckCm) + 0.15456 * log10($heightCm)) - 450;
                    if ($est >= 3 && $est <= 55) $bodyFat = round($est, 1);
                } elseif ($isFemale && ($waistCm + $hipCm) > $neckCm) {
                    $est = 495 / (1.29579 - 0.35004 * log10($waistCm + $hipCm - $neckCm) + 0.22100 * log10($heightCm)) - 450;
                    if ($est >= 5 && $est <= 60) $bodyFat = round($est, 1);
                }
            }
        }

        // BMI calculation
        $heightCm = (float)($memberData['height_cm'] ?? 0);
        $bmi = null;
        $bmiCategory = '—';
        if ($currentWeight > 0 && $heightCm > 0) {
            $heightM = $heightCm / 100;
            $bmi = round($currentWeight / ($heightM * $heightM), 1);
            if ($bmi < 18.5) $bmiCategory = 'Underweight';
            elseif ($bmi < 25.0) $bmiCategory = 'Normal Weight';
            elseif ($bmi < 30.0) $bmiCategory = 'Overweight';
            else $bmiCategory = 'Obese';
        }

        // Goal label
        $goalKey = (string)($memberData['primary_goal'] ?? '');
        $goalLabels = [
            'weight_loss'         => 'Weight Loss',
            'muscle_gain'         => 'Muscle Gain',
            'increasing_strength' => 'Increasing Strength',
            'endurance'           => 'Cardio & Endurance',
            'general_fitness'     => 'General Fitness & Toning',
            'rehabilitation'      => 'Rehabilitation & Mobility',
        ];
        $goalLabel = $goalLabels[$goalKey] ?? (!empty($goalKey) ? ucwords(str_replace('_', ' ', $goalKey)) : 'General Fitness');

        // Total attendance
        $totalCheckins = (int) scalar('SELECT COUNT(*) FROM attendance WHERE user_id = ?', [$targetMemberId]);

        // Circumferences (from latest log or baseline)
        $waistVal = !empty($latestLog['waist_cm']) ? (float)$latestLog['waist_cm'] : (!empty($memberData['waist_cm']) ? (float)$memberData['waist_cm'] : null);
        $chestVal = !empty($latestLog['chest_cm']) ? (float)$latestLog['chest_cm'] : (!empty($memberData['chest_cm']) ? (float)$memberData['chest_cm'] : null);
        $armVal   = !empty($latestLog['arm_cm']) ? (float)$latestLog['arm_cm'] : (!empty($memberData['arm_cm']) ? (float)$memberData['arm_cm'] : null);
        $neckVal  = !empty($latestLog['neck_cm']) ? (float)$latestLog['neck_cm'] : (!empty($memberData['neck_cm']) ? (float)$memberData['neck_cm'] : null);
        $hipsVal  = !empty($latestLog['hips_cm']) ? (float)$latestLog['hips_cm'] : (!empty($memberData['hip_cm']) ? (float)$memberData['hip_cm'] : null);

        // Recent history (up to 5 logs)
        $historyList = [];
        $recentSlice = array_slice($logs, 0, 5);
        foreach ($recentSlice as $l) {
            $historyList[] = [
                'log_id' => (int)$l['log_id'],
                'date' => date('M j, Y', strtotime($l['log_date'])),
                'weight_kg' => !empty($l['weight_kg']) ? (float)$l['weight_kg'] : null,
                'body_fat' => !empty($l['body_fat_percent']) ? (float)$l['body_fat_percent'] : null,
                'chest_cm' => !empty($l['chest_cm']) ? (float)$l['chest_cm'] : null,
                'waist_cm' => !empty($l['waist_cm']) ? (float)$l['waist_cm'] : null,
                'notes' => trim((string)($l['notes'] ?? '')),
            ];
        }

        // Engagement score & tier
        $engScore = (int)($memberData['engagement_score'] ?? 0);
        $engTier = 'Moderately Engaged';
        if ($engScore >= 75) $engTier = 'Highly Engaged';
        elseif ($engScore < 45) $engTier = 'At-Risk';

        // Milestone progress calculation
        $progressPct = 50;
        if ($targetWeight !== null && $startingWeight !== null && $currentWeight !== null) {
            $totalDistance = abs($targetWeight - $startingWeight);
            if ($totalDistance > 0) {
                $covered = abs($currentWeight - $startingWeight);
                $progressPct = (int) min(100, max(5, round(($covered / $totalDistance) * 100)));
            }
        }

        echo json_encode([
            'success' => true,
            'member' => [
                'user_id' => (int)$memberData['user_id'],
                'full_name' => trim($memberData['first_name'] . ' ' . $memberData['last_name']),
                'email' => $memberData['email'],
                'phone' => $memberData['phone'] ?? '',
                'avatar_html' => render_avatar($memberData),
                'joined_date' => date('M j, Y', strtotime($memberData['created_at'])),
                'plan_name' => $memberData['plan_name'] ?? 'No active membership',
                'membership_status' => $memberData['membership_status'] ?? 'none',
                'coach_name' => $memberData['coach_name'] ?? null,
                'age' => $memberData['age'] ?? null,
                'sex' => !empty($memberData['biological_sex']) ? ucfirst($memberData['biological_sex']) : null,
                'height_cm' => $heightCm > 0 ? $heightCm : null,
            ],
            'stats' => [
                'current_weight' => $currentWeight,
                'starting_weight' => $startingWeight,
                'target_weight' => $targetWeight,
                'weight_delta' => $weightDelta,
                'weight_delta_pct' => $weightDeltaPct,
                'delta_type' => $deltaType,
                'body_fat' => $bodyFat,
                'target_body_fat' => !empty($memberData['target_body_fat_percent']) ? (float)$memberData['target_body_fat_percent'] : null,
                'bmi' => $bmi,
                'bmi_category' => $bmiCategory,
                'primary_goal' => $goalLabel,
                'weekly_target' => (int)($memberData['weekly_workout_target'] ?? 3),
                'total_checkins' => $totalCheckins,
                'total_logs' => count($logs),
                'engagement_score' => $engScore,
                'engagement_tier' => $engTier,
                'progress_pct' => $progressPct,
            ],
            'measurements' => [
                'waist_cm' => $waistVal,
                'chest_cm' => $chestVal,
                'arm_cm' => $armVal,
                'neck_cm' => $neckVal,
                'hips_cm' => $hipsVal,
                'last_log_date' => $latestLog ? date('M j, Y', strtotime($latestLog['log_date'])) : null,
            ],
            'history' => $historyList,
            'hub_url' => 'index.php?page=progress&member_user_id=' . (int)$memberData['user_id'],
            'diet_url' => 'index.php?page=diet_builder&member_user_id=' . (int)$memberData['user_id'] . '&ref=users'
        ]);
        exit;
    }

    // ── AJAX: Live Search Users ──────────────────────────────────────────
    if (($_GET['action'] ?? post('action')) === 'search_users') {
        if (ob_get_level()) ob_clean();
        header('Content-Type: application/json');

        $tab = (string) ($_GET['tab'] ?? 'all');
        $gymFilter = (int) ($_GET['gym_id'] ?? 0);
        $searchQuery = trim((string) ($_GET['q'] ?? post('q') ?? ''));

        $where = 'u.role != "platform_admin"';
        $params = [];

        if (!$isAdmin) {
            $where .= ' AND (
                (u.role = "staff" AND EXISTS (SELECT 1 FROM gym_staff gs WHERE gs.user_id = u.user_id AND gs.gym_id = ?)) OR
                (u.role = "trainer" AND EXISTS (SELECT 1 FROM trainer_profiles WHERE user_id = u.user_id AND gym_id = ?)) OR
                (u.role = "member" AND EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?))
            )';
            $params[] = $gymId;
            $params[] = $gymId;
            $params[] = $gymId;
            $where .= ' AND u.user_id <> ?';
            $params[] = (int)$user['user_id'];
        }

        if (in_array($tab, ['gym_owner', 'trainer', 'member', 'staff'], true)) {
            $where .= ' AND u.role = ?';
            $params[] = $tab;
        }

        if ($isAdmin && $gymFilter) {
            $where .= ' AND (
                (u.role = "gym_owner" AND EXISTS (SELECT 1 FROM gyms WHERE owner_user_id = u.user_id AND gym_id = ?)) OR
                (u.role = "staff" AND EXISTS (SELECT 1 FROM gym_staff gs WHERE gs.user_id = u.user_id AND gs.gym_id = ?)) OR
                (u.role = "trainer" AND EXISTS (SELECT 1 FROM trainer_profiles WHERE user_id = u.user_id AND gym_id = ?)) OR
                (u.role = "member" AND EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?))
            )';
            $params[] = $gymFilter;
            $params[] = $gymFilter;
            $params[] = $gymFilter;
            $params[] = $gymFilter;
        }

        if ($searchQuery !== '') {
            $where .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, " ", u.last_name) LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.user_id = ?)';
            $pattern = '%' . $searchQuery . '%';
            $numId = is_numeric($searchQuery) ? (int)$searchQuery : 0;
            $params[] = $pattern;
            $params[] = $pattern;
            $params[] = $pattern;
            $params[] = $pattern;
            $params[] = $pattern;
            $params[] = $numId;
        }

        $totalFound = (int) scalar('SELECT COUNT(*) FROM users u WHERE ' . $where, $params);

        $p = max(1, (int)($_GET['p'] ?? 1));
        $limit = ($searchQuery !== '') ? 50 : 10;
        $offset = ($p - 1) * $limit;
        $totalPages = max(1, (int) ceil($totalFound / $limit));

        $sql = 'SELECT u.*, tp.specialization, tp.bio, 
                (SELECT g1.name FROM gyms g1 WHERE g1.owner_user_id = u.user_id ORDER BY g1.gym_id DESC LIMIT 1) AS owner_gym_name,
                (SELECT g1.status FROM gyms g1 WHERE g1.owner_user_id = u.user_id ORDER BY g1.gym_id DESC LIMIT 1) AS owner_gym_status,
                (SELECT g2.name FROM trainer_profiles tp2 JOIN gyms g2 ON tp2.gym_id = g2.gym_id WHERE tp2.user_id = u.user_id LIMIT 1) AS trainer_gym_name,
                (SELECT g3.name FROM gym_members gm JOIN gyms g3 ON gm.gym_id = g3.gym_id WHERE gm.user_id = u.user_id LIMIT 1) AS member_gym_name,
                (SELECT g4.name FROM gym_staff gs JOIN gyms g4 ON gs.gym_id = g4.gym_id WHERE gs.user_id = u.user_id LIMIT 1) AS staff_gym_name
                FROM users u 
                LEFT JOIN trainer_profiles tp ON u.user_id = tp.user_id 
                WHERE ' . $where . ' 
                ORDER BY CASE u.role WHEN "gym_owner" THEN 1 WHEN "staff" THEN 2 WHEN "trainer" THEN 3 WHEN "member" THEN 4 ELSE 5 END ASC, u.first_name ASC, u.last_name ASC 
                LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $searchRows = $stmt->fetchAll();

        $outputUsers = [];
        foreach ($searchRows as $row) {
            $associatedGym = null;
            if ($row['role'] === 'gym_owner') $associatedGym = $row['owner_gym_name'];
            elseif ($row['role'] === 'trainer') $associatedGym = $row['trainer_gym_name'];
            elseif ($row['role'] === 'member') $associatedGym = $row['member_gym_name'];
            elseif ($row['role'] === 'staff') $associatedGym = $row['staff_gym_name'] ?? null;

            $outputUsers[] = [
                'user_id'          => (int) $row['user_id'],
                'first_name'       => $row['first_name'],
                'last_name'        => $row['last_name'],
                'full_name'        => trim($row['first_name'] . ' ' . $row['last_name']),
                'email'            => $row['email'],
                'phone'            => $row['phone'] ?? '',
                'role'             => $row['role'],
                'role_display'     => ucwords(str_replace('_', ' ', $row['role'])),
                'status'           => $row['status'],
                'avatar_html'      => render_avatar($row),
                'associated_gym'   => $associatedGym,
                'owner_gym_status' => $row['owner_gym_status'] ?? null,
                'specialization'   => $row['specialization'] ?? 'General Trainer',
                'staff_role'       => $row['staff_role'] ?? 'trainer',
                'engagement_score' => (int) ($row['engagement_score'] ?? 0),
                'joined_formatted' => date('M j, Y', strtotime($row['created_at'])),
                'can_delete'       => (int) $row['user_id'] !== (int) $user['user_id'],
                'is_member'        => $row['role'] === 'member',
                'is_trainer'       => $row['role'] === 'trainer',
                'is_gym_owner'     => $row['role'] === 'gym_owner',
                'is_staff'         => $row['role'] === 'staff',
                'can_view_progress'=> in_array($user['role'], ['gym_owner', 'platform_admin'], true) && $row['role'] === 'member',
                'raw_user'         => $row
            ];
        }

        ob_start();
        render_pagination($p, $totalPages, '?page=users&tab=' . urlencode($tab) . ($gymFilter ? '&gym_id=' . $gymFilter : '') . ($searchQuery !== '' ? '&q=' . urlencode($searchQuery) : ''));
        $paginationHtml = ob_get_clean();

        echo json_encode([
            'users' => $outputUsers,
            'total' => $totalFound,
            'page' => $p,
            'total_pages' => $totalPages,
            'pagination_html' => $paginationHtml,
            'csrf_token' => csrf_token(),
            'current_user_id' => (int) $user['user_id']
        ]);
        exit;
    }

    $page = max(1, (int)($_GET['p'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;

    $tab = $_GET['tab'] ?? 'all';
    $gymFilter = (int)($_GET['gym_id'] ?? 0);
    $searchQuery = trim((string)($_GET['q'] ?? ''));
    
    $where = 'u.role != "platform_admin"';
    $params = [];
    
    if (!$isAdmin) {
        $where .= ' AND (
            (u.role = "staff" AND EXISTS (SELECT 1 FROM gym_staff gs WHERE gs.user_id = u.user_id AND gs.gym_id = ?)) OR
            (u.role = "trainer" AND EXISTS (SELECT 1 FROM trainer_profiles WHERE user_id = u.user_id AND gym_id = ?)) OR
            (u.role = "member" AND EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?))
        )';
        $params[] = $gymId;
        $params[] = $gymId;
        $params[] = $gymId;
        $where .= ' AND u.user_id <> ?';
        $params[] = (int)$user['user_id'];
    }
    
    if (in_array($tab, ['gym_owner', 'trainer', 'member', 'staff'], true)) {
        $where .= ' AND u.role = ?';
        $params[] = $tab;
    }
    
    if ($isAdmin && $gymFilter) {
        $where .= ' AND (
            (u.role = "gym_owner" AND EXISTS (SELECT 1 FROM gyms WHERE owner_user_id = u.user_id AND gym_id = ?)) OR
            (u.role = "staff" AND EXISTS (SELECT 1 FROM gym_staff gs WHERE gs.user_id = u.user_id AND gym_id = ?)) OR
            (u.role = "trainer" AND EXISTS (SELECT 1 FROM trainer_profiles WHERE user_id = u.user_id AND gym_id = ?)) OR
            (u.role = "member" AND EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gym_id = ?))
        )';
        $params[] = $gymFilter;
        $params[] = $gymFilter;
        $params[] = $gymFilter;
        $params[] = $gymFilter;
    }

    if ($searchQuery !== '') {
        $where .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, " ", u.last_name) LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.user_id = ?)';
        $pattern = '%' . $searchQuery . '%';
        $numId = is_numeric($searchQuery) ? (int)$searchQuery : 0;
        $params[] = $pattern;
        $params[] = $pattern;
        $params[] = $pattern;
        $params[] = $pattern;
        $params[] = $pattern;
        $params[] = $numId;
    }

    // Calculate facility-scoped user breakdown stats
    $baseWhere = 'u.role != "platform_admin"';
    $baseParams = [];
    
    if (!$isAdmin) {
        $baseWhere .= ' AND (
            (u.role = "staff" AND EXISTS (SELECT 1 FROM gym_staff gs WHERE gs.user_id = u.user_id AND gs.gym_id = ?)) OR
            (u.role = "trainer" AND EXISTS (SELECT 1 FROM trainer_profiles WHERE user_id = u.user_id AND gym_id = ?)) OR
            (u.role = "member" AND EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?))
        )';
        $baseParams[] = $gymId;
        $baseParams[] = $gymId;
        $baseParams[] = $gymId;
        $baseWhere .= ' AND u.user_id <> ?';
        $baseParams[] = (int)$user['user_id'];
    }
    
    if ($isAdmin && $gymFilter) {
        $baseWhere .= ' AND (
            (u.role = "gym_owner" AND EXISTS (SELECT 1 FROM gyms WHERE owner_user_id = u.user_id AND gym_id = ?)) OR
            (u.role = "staff" AND EXISTS (SELECT 1 FROM gym_staff gs WHERE gs.user_id = u.user_id AND gym_id = ?)) OR
            (u.role = "trainer" AND EXISTS (SELECT 1 FROM trainer_profiles WHERE user_id = u.user_id AND gym_id = ?)) OR
            (u.role = "member" AND EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gym_id = ?))
        )';
        $baseParams[] = $gymFilter;
        $baseParams[] = $gymFilter;
        $baseParams[] = $gymFilter;
        $baseParams[] = $gymFilter;
    }

    $statsStmt = db()->prepare("
        SELECT 
            COUNT(*) as count_all,
            SUM(CASE WHEN u.role = 'member' THEN 1 ELSE 0 END) as count_member,
            SUM(CASE WHEN u.role = 'trainer' THEN 1 ELSE 0 END) as count_trainer,
            SUM(CASE WHEN u.role = 'staff' THEN 1 ELSE 0 END) as count_staff,
            SUM(CASE WHEN u.role = 'gym_owner' THEN 1 ELSE 0 END) as count_owner,
            SUM(CASE WHEN u.status = 'active' THEN 1 ELSE 0 END) as count_active,
            AVG(CASE WHEN u.role = 'member' AND u.status = 'active' AND u.engagement_score > 0 THEN u.engagement_score ELSE NULL END) as avg_member_score
        FROM users u 
        WHERE {$baseWhere}
    ");
    $statsStmt->execute($baseParams);
    $statsData = $statsStmt->fetch() ?: [];

    $countAll = (int)($statsData['count_all'] ?? 0);
    $countMember = (int)($statsData['count_member'] ?? 0);
    $countTrainer = (int)($statsData['count_trainer'] ?? 0);
    $countStaff = (int)($statsData['count_staff'] ?? 0);
    $countOwner = (int)($statsData['count_owner'] ?? 0);
    $countActive = (int)($statsData['count_active'] ?? 0);
    $avgMemberScore = round((float)($statsData['avg_member_score'] ?? 0));

    $total = (int) scalar('SELECT COUNT(*) FROM users u WHERE ' . $where, $params);
    $totalPages = max(1, (int) ceil($total / $limit));

    $sql = 'SELECT u.*, tp.specialization, tp.bio, 
            (SELECT g1.name FROM gyms g1 WHERE g1.owner_user_id = u.user_id ORDER BY g1.gym_id DESC LIMIT 1) AS owner_gym_name,
            (SELECT g1.status FROM gyms g1 WHERE g1.owner_user_id = u.user_id ORDER BY g1.gym_id DESC LIMIT 1) AS owner_gym_status,
            (SELECT g2.name FROM trainer_profiles tp2 JOIN gyms g2 ON tp2.gym_id = g2.gym_id WHERE tp2.user_id = u.user_id LIMIT 1) AS trainer_gym_name,
            (SELECT g3.name FROM gym_members gm JOIN gyms g3 ON gm.gym_id = g3.gym_id WHERE gm.user_id = u.user_id LIMIT 1) AS member_gym_name,
            (SELECT g4.name FROM gym_staff gs JOIN gyms g4 ON gs.gym_id = g4.gym_id WHERE gs.user_id = u.user_id LIMIT 1) AS staff_gym_name
            FROM users u 
            LEFT JOIN trainer_profiles tp ON u.user_id = tp.user_id 
            WHERE ' . $where . ' 
            ORDER BY CASE u.role WHEN "gym_owner" THEN 1 WHEN "staff" THEN 2 WHEN "trainer" THEN 3 WHEN "member" THEN 4 ELSE 5 END ASC, u.first_name ASC, u.last_name ASC 
            LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    
    $allGyms = db()->query('SELECT gym_id, name FROM gyms ORDER BY name ASC')->fetchAll();

    $renderGymVerifyMark = function(?string $status, bool $hasGym): string {
        if ($hasGym && $status === 'approved') {
            return '<span class="gym-verify-mark is-verified" title="Verified Gym" aria-label="Verified"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>';
        }
        $hint = 'Not Verified';
        if (!$hasGym) {
            $hint = 'Not Verified (No Gym Submitted)';
        } elseif ($status === 'pending') {
            $hint = 'Not Verified (Pending Approval)';
        } elseif ($status === 'rejected') {
            $hint = 'Not Verified (Rejected)';
        }
        return '<span class="gym-verify-mark is-unverified" title="' . htmlspecialchars($hint) . '" aria-label="' . htmlspecialchars($hint) . '">*</span>';
    };
    $renderGymBadge = $renderGymVerifyMark;
    
    render_header('Users', $user);
    ?>
    <link rel="stylesheet" href="<?= h(asset_url('css/pages/users.css')) ?>">
    <div class="skeleton-wrapper">
        <section class="panel">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
                <div>
                    <div class="sk sk-title" style="width:180px;margin-bottom:8px"></div>
                    <div class="sk sk-text" style="width:260px;height:12px"></div>
                </div>
                <div class="sk sk-rect" style="width:140px;height:38px;border-radius:10px"></div>
            </div>
            <!-- Stats Skeleton -->
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px;">
                <?php for($i=0;$i<4;$i++): ?>
                    <div class="sk sk-rect" style="height:86px;border-radius:12px"></div>
                <?php endfor; ?>
            </div>
            <div style="display:flex;justify-content:space-between;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--line)">
                <div style="display:flex;gap:12px">
                    <?php for($i=0;$i<4;$i++) echo '<div class="sk sk-rect" style="width:75px;height:28px;border-radius:20px"></div>'; ?>
                </div>
                <div class="sk sk-text" style="width:70px;height:14px;margin:0"></div>
            </div>
            <?php render_skeleton_table(6, 8); ?>
        </section>
    </div>
    <section class="panel skeleton-content sk-display-block">
        <div class="page-header users-page-header">
            <div class="users-header-title-wrap">
                <div class="users-title-badge-row">
                    <h1>User Accounts</h1>
                </div>
                <p>Manage member profiles, coaching staff, gym personnel, and system privileges.</p>
            </div>
            <button type="button" class="btn btn-lime btn-create-user-hero" onclick="document.getElementById('createUserModal').showModal()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <span>Create User</span>
            </button>
        </div>

        <!-- High-Impact Summary Stats Grid -->
        <div class="users-stats-grid">
            <div class="users-stat-card">
                <div class="users-stat-icon-wrap" style="color: var(--lime); background: color-mix(in srgb, var(--lime) 12%, transparent);">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                </div>
                <div class="users-stat-content">
                    <span class="users-stat-label">Total Accounts</span>
                    <div class="users-stat-value"><?= number_format($countAll) ?></div>
                    <span class="users-stat-sub">Facility roster</span>
                </div>
            </div>

            <div class="users-stat-card">
                <div class="users-stat-icon-wrap" style="color: #38bdf8; background: rgba(56, 189, 248, 0.12);">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18M4 22h16M10 14.66V17c0 .55-.45 1-1 1H7c-.55 0-1-.45-1-1v-2.34M18 14.66V17c0 .55-.45 1-1 1h-2c-.55 0-1-.45-1-1v-2.34M8 2h8a2 2 0 0 1 2 2v7a6 6 0 0 1-12 0V4a2 2 0 0 1 2-2z"></path>
                    </svg>
                </div>
                <div class="users-stat-content">
                    <span class="users-stat-label">Active Members</span>
                    <div class="users-stat-value"><?= number_format($countMember) ?></div>
                    <span class="users-stat-sub">Athletes enrolled</span>
                </div>
            </div>

            <div class="users-stat-card">
                <div class="users-stat-icon-wrap" style="color: #a855f7; background: rgba(168, 85, 247, 0.12);">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                        <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                    </svg>
                </div>
                <div class="users-stat-content">
                    <span class="users-stat-label">Coaches & Staff</span>
                    <div class="users-stat-value"><?= number_format($countTrainer + $countStaff) ?></div>
                    <span class="users-stat-sub"><?= $countTrainer ?> trainer<?= $countTrainer == 1 ? '' : 's' ?>, <?= $countStaff ?> staff</span>
                </div>
            </div>

            <div class="users-stat-card">
                <div class="users-stat-icon-wrap" style="color: #10b981; background: rgba(16, 185, 129, 0.12);">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                        <line x1="9" y1="9" x2="9.01" y2="9"></line>
                        <line x1="15" y1="9" x2="15.01" y2="9"></line>
                    </svg>
                </div>
                <div class="users-stat-content">
                    <span class="users-stat-label">Avg. Engagement</span>
                    <div class="users-stat-value"><?= $avgMemberScore ?><span class="users-stat-unit">/100</span></div>
                    <span class="users-stat-sub">
                        <?= $avgMemberScore >= 75 ? '🔥 High attendance' : ($avgMemberScore >= 45 ? '⚡ Consistent' : 'Steady building') ?>
                    </span>
                </div>
            </div>
        </div>

        <?php
            $oldUser = $_SESSION['_old_create_user'] ?? [];
            unset($_SESSION['_old_create_user']);
        ?>
        <dialog id="createUserModal" class="modal">
            <div class="modal-header">
                <h3>Create User</h3>
                <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="modal-body">
                <form id="createUserForm" method="post" class="form grid-form" style="align-items: start;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <label><span class="label-title">First name <span class="req-star">*</span></span>
                        <input name="first_name" value="<?= h($oldUser['first_name'] ?? '') ?>" placeholder="John" required autocapitalize="words" style="text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())">
                    </label>
                    <label><span class="label-title">Last name <span class="req-star">*</span></span>
                        <input name="last_name" value="<?= h($oldUser['last_name'] ?? '') ?>" placeholder="Doe" required autocapitalize="words" style="text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())">
                    </label>
                    <label><span class="label-title">Email <span class="req-star">*</span></span>
                        <input name="email" type="email" value="<?= h($oldUser['email'] ?? '') ?>" placeholder="john@example.com" required style="text-transform: lowercase;" oninput="this.value = this.value.toLowerCase()" onblur="this.value = this.value.trim().toLowerCase()">
                    </label>
                    <label><span class="label-title">Mobile Number <span class="req-star">*</span></span>
                        <input name="phone" type="tel" pattern="[0-9]{11}" maxlength="11" title="Please enter exactly 11 digits" placeholder="09123456789" value="<?= h($oldUser['phone'] ?? '') ?>" required oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)">
                    </label>
                    <label><span class="label-title">Password <span class="req-star">*</span></span>
                        <input name="password" id="new_user_password" type="password" minlength="8" placeholder="Min. 8 characters" required autocomplete="new-password">
                        <small id="new_user_pass_hint" style="display:block; font-size:12px; margin-top:4px; color:var(--muted); font-weight:400;">
                            Must be at least 8 characters with a letter and a number.
                        </small>
                    </label>
                    <label><span class="label-title">Role <span class="req-star">*</span></span>
                        <div id="roleComboboxWrap"></div>
                    </label>
                    <?php if (!$isAdmin): ?>
                    <div id="trainer_fields" style="display: <?= ($oldUser['role'] ?? '') === 'trainer' ? 'grid' : 'none' ?>; grid-column: 1 / -1; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px;">
                        <label><span class="label-title">Specialization <span class="req-star" id="specReqStar" style="display: <?= ($oldUser['role'] ?? '') === 'trainer' ? 'inline' : 'none' ?>;">*</span></span>
                            <input name="specialization" id="new_user_spec" value="<?= h($oldUser['specialization'] ?? '') ?>" placeholder="e.g. Strength & Conditioning">
                        </label>
                        <label><span class="label-title">Bio</span>
                            <input name="bio" value="<?= h($oldUser['bio'] ?? '') ?>" placeholder="Short bio">
                        </label>
                    </div>
                    <?php endif; ?>
                    <?php if (!$isAdmin): ?>
                    <div id="staff_fields" style="display: <?= ($oldUser['role'] ?? '') === 'staff' ? 'block' : 'none' ?>; grid-column: 1 / -1;">
                        <label><span class="label-title">Staff Permission Level</span>
                            <div id="staffRoleComboboxWrap"></div>
                        </label>
                    </div>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                    <div id="gym_owner_fields" style="display: <?= ($oldUser['role'] ?? 'gym_owner') === 'gym_owner' ? 'block' : 'none' ?>; grid-column: 1 / -1;">
                        <div class="user-modal-subcard">
                            <div class="user-subcard-header" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                                <span style="font-size: 13.5px; font-weight: 700; color: var(--ink);">Gym Facility & Platform Subscription</span>
                                <span class="user-subcard-badge">Platform Tier</span>
                            </div>

                            <div class="gym-owner-sub-grid">
                                <label style="margin: 0;">
                                    <span class="label-title">Gym Facility Name <span class="req-star">*</span></span>
                                    <input name="gym_name" id="new_user_gym_name" value="<?= h($oldUser['gym_name'] ?? '') ?>" placeholder="e.g. Iron Titan Fitness" <?= ($oldUser['role'] ?? 'gym_owner') === 'gym_owner' ? 'required' : '' ?>>
                                </label>

                                <label style="margin: 0;">
                                    <span class="label-title">Facility Address</span>
                                    <input name="gym_address" id="new_user_gym_address" value="<?= h($oldUser['gym_address'] ?? '') ?>" placeholder="e.g. 123 Main St, City">
                                </label>

                                <label style="margin: 0; grid-column: 1 / -1;">
                                    <span class="label-title">Platform Subscription Plan</span>
                                    <select name="platform_plan" id="new_user_platform_plan" class="form-control" style="width: 100%;" onchange="togglePlatformPlanDetails(this.value)">
                                        <option value="free_trial" <?= selected($oldUser['platform_plan'] ?? 'free_trial', 'free_trial') ?>>Free Trial (14 Days) — ₱0.00</option>
                                        <?php foreach ($platformPlans as $k => $p): ?>
                                            <option value="<?= h($k) ?>" data-price="<?= (float)$p['price'] ?>" data-annual="<?= (float)($p['annual_price'] ?? ($p['price'] * 10)) ?>" <?= selected($oldUser['platform_plan'] ?? '', $k) ?>>
                                                <?= h($p['name']) ?> — <?= money((float)$p['price']) ?>/mo
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="none" <?= selected($oldUser['platform_plan'] ?? '', 'none') ?>>No Subscription (Inactive)</option>
                                    </select>
                                </label>

                                <div id="platform_plan_details" style="display: contents;">
                                    <label style="margin: 0;">
                                        <span class="label-title">Billing Cycle</span>
                                        <select name="platform_billing_cycle" id="new_user_platform_cycle" class="form-control" style="width: 100%;">
                                            <option value="monthly" <?= selected($oldUser['platform_billing_cycle'] ?? 'monthly', 'monthly') ?>>Monthly Billing</option>
                                            <option value="yearly" <?= selected($oldUser['platform_billing_cycle'] ?? '', 'yearly') ?>>Annual (2 Months Free)</option>
                                        </select>
                                    </label>

                                    <label style="margin: 0;">
                                        <span class="label-title">Payment / Activation Status</span>
                                        <select name="platform_payment_status" id="new_user_platform_status" class="form-control" style="width: 100%;">
                                            <option value="trialing" <?= selected($oldUser['platform_payment_status'] ?? 'trialing', 'trialing') ?>>Free Trial (14 Days)</option>
                                            <option value="active_cash" <?= selected($oldUser['platform_payment_status'] ?? '', 'active_cash') ?>>Paid Cash (Active)</option>
                                            <option value="active_online" <?= selected($oldUser['platform_payment_status'] ?? '', 'active_online') ?>>Paid Online (Active)</option>
                                            <option value="inactive" <?= selected($oldUser['platform_payment_status'] ?? '', 'inactive') ?>>Pending Payment</option>
                                        </select>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!$isAdmin): ?>
                    <div id="member_fields" style="display: <?= ($oldUser['role'] ?? '') === 'member' ? 'block' : 'none' ?>; grid-column: 1 / -1;">
                        <div class="user-modal-subcard">
                            <div class="user-subcard-header" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                        <line x1="16" y1="2" x2="16" y2="6"></line>
                                        <line x1="8" y1="2" x2="8" y2="6"></line>
                                        <line x1="3" y1="10" x2="21" y2="10"></line>
                                    </svg>
                                    <span style="font-size: 13.5px; font-weight: 700; color: var(--ink);">Membership Plan Allocation</span>
                                </div>
                                <span class="user-subcard-badge">Optional</span>
                            </div>

                            <div class="member-plan-sub-grid">
                                <label style="margin: 0; grid-column: 1 / -1;">
                                    <span class="label-title">Choose Membership Plan</span>
                                    <select name="membership_plan_id" id="new_user_plan_id" class="form-control" style="width: 100%;" onchange="toggleMemberPaymentFields(this.value)">
                                        <option value="">No Plan (Account Only / Walk-in)</option>
                                        <?php foreach ($availablePlans as $p): ?>
                                            <option value="<?= (int)$p['plan_id'] ?>" data-price="<?= (float)$p['price'] ?>" data-duration="<?= (int)$p['duration_days'] ?>" <?= selected((string)($oldUser['membership_plan_id'] ?? ''), (string)$p['plan_id']) ?>>
                                                <?= h($p['plan_name']) ?> — <?= money((float)$p['price']) ?> (<?= (int)$p['duration_days'] ?> days)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>

                                <div id="member_plan_details" style="display: <?= !empty($oldUser['membership_plan_id']) ? 'contents' : 'none' ?>;">
                                    <label style="margin: 0;">
                                        <span class="label-title">Start Date</span>
                                        <input type="date" name="membership_start_date" id="new_user_start_date" value="<?= h($oldUser['membership_start_date'] ?? date('Y-m-d')) ?>">
                                    </label>

                                    <label style="margin: 0;">
                                        <span class="label-title">Payment / Activation Status</span>
                                        <select name="membership_payment_status" id="new_user_payment_status" class="form-control" style="width: 100%;">
                                            <option value="paid_cash">Paid Cash (Active)</option>
                                            <option value="paid_online">Paid Online (Active)</option>
                                            <option value="complimentary">Complimentary (₱0.00)</option>
                                            <option value="pending">Pending Payment</option>
                                        </select>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <button id="createUserSubmitBtn" class="btn btn-lime" style="grid-column: 1 / -1; margin-top: 10px;">Create user</button>
                </form>
            </div>
        </dialog>

        <!-- Member Progress Modal (Quick View for Gym Owners & Admins) -->
        <dialog id="memberProgressModal" class="modal member-progress-modal">
            <div class="modal-header">
                <div class="mp-modal-title-group">
                    <h3 id="mpModalTitle">Member Progress</h3>
                    <p id="mpModalSubtitle" class="mp-modal-sub">Live fitness tracking & metrics overview</p>
                </div>
                <button type="button" class="modal-close" onclick="document.getElementById('memberProgressModal').close()" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body" id="mpModalBody">
                <!-- Loading State Skeleton -->
                <div id="mpLoadingSkeleton" class="mp-loading-skeleton" style="display: flex; flex-direction: column; gap: 14px;">
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px;">
                        <div class="sk sk-rect" style="height: 74px; border-radius: 10px;"></div>
                        <div class="sk sk-rect" style="height: 74px; border-radius: 10px;"></div>
                        <div class="sk sk-rect" style="height: 74px; border-radius: 10px;"></div>
                        <div class="sk sk-rect" style="height: 74px; border-radius: 10px;"></div>
                    </div>
                    <div class="sk sk-rect" style="height: 80px; border-radius: 10px;"></div>
                    <div class="sk sk-rect" style="height: 100px; border-radius: 10px;"></div>
                </div>

                <!-- Loaded Content State -->
                <div id="mpLoadedContent" style="display: none; flex-direction: column; gap: 16px;">
                    <!-- Key Metric Cards Grid (4 KPI Cards) -->
                    <div class="mp-kpi-grid">
                        <div class="mp-kpi-card">
                            <div class="mp-kpi-top-row">
                                <span class="mp-kpi-label">Weight</span>
                                <span id="mpWeightDeltaPill" class="mp-delta-pill is-neutral">—</span>
                            </div>
                            <div class="mp-kpi-val-row">
                                <span id="mpCurrentWeight" class="mp-kpi-val">—</span>
                            </div>
                            <span id="mpWeightSub" class="mp-kpi-sub">Baseline: —</span>
                        </div>
                        <div class="mp-kpi-card">
                            <span class="mp-kpi-label">Body Fat</span>
                            <div class="mp-kpi-val-row">
                                <span id="mpBodyFat" class="mp-kpi-val">—</span>
                            </div>
                            <span id="mpTargetBfLabel" class="mp-kpi-sub">Target: —</span>
                        </div>
                        <div class="mp-kpi-card">
                            <span class="mp-kpi-label">BMI & Index</span>
                            <div class="mp-kpi-val-row">
                                <span id="mpBmi" class="mp-kpi-val">—</span>
                            </div>
                            <span id="mpBmiCategory" class="mp-kpi-sub">—</span>
                        </div>
                        <div class="mp-kpi-card">
                            <span class="mp-kpi-label">Engagement</span>
                            <div class="mp-kpi-val-row">
                                <span id="mpScore" class="mp-kpi-val" style="color: var(--lime);">—</span>
                            </div>
                            <span id="mpCheckinsLabel" class="mp-kpi-sub">0 Check-ins</span>
                        </div>
                    </div>

                    <!-- Fitness Goal & Target Bar -->
                    <div class="mp-goal-card">
                        <div class="mp-goal-header">
                            <div class="mp-goal-label-group">
                                <span class="mp-section-title">Primary Fitness Goal</span>
                                <span id="mpGoalName" class="mp-goal-name">General Fitness</span>
                            </div>
                            <div class="mp-goal-meta">
                                <span id="mpWeeklyTarget" class="mp-goal-tag">3 workouts / week</span>
                                <span id="mpTargetWeight" class="mp-goal-tag">Target: —</span>
                            </div>
                        </div>
                        <div class="mp-progress-track">
                            <div id="mpProgressBar" class="mp-progress-fill" style="width: 50%;"></div>
                        </div>
                        <div class="mp-progress-legend">
                            <span id="mpStartWeightLegend">Start: —</span>
                            <span id="mpProgressPctLegend">Progress toward target</span>
                            <span id="mpTargetWeightLegend">Target: —</span>
                        </div>
                    </div>

                    <!-- Body Measurements Grid -->
                    <div class="mp-circ-section">
                        <div class="mp-section-header">
                            <span class="mp-section-title">Latest Body Measurements</span>
                            <small id="mpCircLastDate" style="color: var(--muted); font-size: 11px;">Recorded —</small>
                        </div>
                        <div class="mp-circ-grid">
                            <div class="mp-circ-box">
                                <span class="mp-circ-label">Waist</span>
                                <span id="mpCircWaist" class="mp-circ-val">—</span>
                            </div>
                            <div class="mp-circ-box">
                                <span class="mp-circ-label">Chest</span>
                                <span id="mpCircChest" class="mp-circ-val">—</span>
                            </div>
                            <div class="mp-circ-box">
                                <span class="mp-circ-label">Arms</span>
                                <span id="mpCircArms" class="mp-circ-val">—</span>
                            </div>
                            <div class="mp-circ-box">
                                <span class="mp-circ-label">Neck</span>
                                <span id="mpCircNeck" class="mp-circ-val">—</span>
                            </div>
                            <div class="mp-circ-box">
                                <span class="mp-circ-label">Hips</span>
                                <span id="mpCircHips" class="mp-circ-val">—</span>
                            </div>
                        </div>
                    </div>

                    <!-- Recent History Table -->
                    <div class="mp-history-section">
                        <div class="mp-section-header">
                            <span class="mp-section-title">Recent Progress Logs</span>
                            <small id="mpTotalLogsCount" style="color: var(--muted); font-size: 11px;">0 logs total</small>
                        </div>
                        <div id="mpHistoryEmpty" class="mp-history-empty" style="display: none;">
                            No recorded logs yet for this member.
                        </div>
                        <div id="mpHistoryTableWrap" class="mp-history-table-wrap">
                            <table class="mp-history-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Weight</th>
                                        <th>Body Fat</th>
                                        <th>Waist / Chest</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody id="mpHistoryTableBody">
                                    <!-- Injected by JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Error State -->
                <div id="mpErrorState" style="display: none; text-align: center; padding: 32px 16px;">
                    <p id="mpErrorMessage" style="color: var(--danger, #ef4444); margin-bottom: 12px;">Failed to load member progress.</p>
                    <button type="button" class="btn btn-secondary btn-sm" id="mpRetryBtn">Retry</button>
                </div>
            </div>
            <div class="modal-footer">
                <div class="modal-footer-left">
                    <a id="mpDietPlanLink" href="#" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 5px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                        Diet Plan
                    </a>
                </div>
                <div class="modal-footer-right">
                    <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('memberProgressModal').close()">Close</button>
                    <a id="mpFullHubLink" href="#" class="btn btn-lime btn-sm btn-mp-full-hub" style="display: inline-flex; align-items: center; gap: 5px; font-weight: 600;">
                        <span class="mp-hub-text-desktop">Open Full Progress Hub</span>
                        <span class="mp-hub-text-mobile">Progress Hub</span>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    </a>
                </div>
            </div>
        </dialog>

        <!-- Modern Segmented Role Filter Tabs -->
        <div class="users-controls-bar">
            <div class="users-tabs-pills" role="tablist">
                <a href="?page=users&tab=all<?= $gymFilter ? '&gym_id='.$gymFilter : '' ?>" data-tab="all" class="users-nav-pill <?= $tab === 'all' ? 'is-active' : '' ?>">
                    <span class="pill-title">All Accounts</span>
                    <span class="pill-chip"><?= number_format($countAll) ?></span>
                </a>
                <?php if ($isAdmin): ?>
                <a href="?page=users&tab=gym_owner<?= $gymFilter ? '&gym_id='.$gymFilter : '' ?>" data-tab="gym_owner" class="users-nav-pill <?= $tab === 'gym_owner' ? 'is-active' : '' ?>">
                    <span class="pill-title">Gym Owners</span>
                    <span class="pill-chip"><?= number_format($countOwner) ?></span>
                </a>
                <?php endif; ?>
                <a href="?page=users&tab=staff<?= $gymFilter ? '&gym_id='.$gymFilter : '' ?>" data-tab="staff" class="users-nav-pill <?= $tab === 'staff' ? 'is-active' : '' ?>">
                    <span class="pill-title">Staff</span>
                    <span class="pill-chip"><?= number_format($countStaff) ?></span>
                </a>
                <a href="?page=users&tab=trainer<?= $gymFilter ? '&gym_id='.$gymFilter : '' ?>" data-tab="trainer" class="users-nav-pill <?= $tab === 'trainer' ? 'is-active' : '' ?>">
                    <span class="pill-title">Trainers</span>
                    <span class="pill-chip"><?= number_format($countTrainer) ?></span>
                </a>
                <a href="?page=users&tab=member<?= $gymFilter ? '&gym_id='.$gymFilter : '' ?>" data-tab="member" class="users-nav-pill <?= $tab === 'member' ? 'is-active' : '' ?>">
                    <span class="pill-title">Members</span>
                    <span class="pill-chip"><?= number_format($countMember) ?></span>
                </a>
            </div>

            <?php if ($isAdmin): ?>
            <div class="users-admin-gym-filter">
                <form method="get" class="users-gym-filter-form">
                    <input type="hidden" name="page" value="users">
                    <input type="hidden" name="tab" value="<?= h($tab) ?>">
                    <select name="gym_id" onchange="this.form.submit()" class="users-gym-select">
                        <option value="">All Gym Facilities</option>
                        <?php foreach ($allGyms as $g): ?>
                            <option value="<?= $g['gym_id'] ?>" <?= selected((string)$g['gym_id'], (string)$gymFilter) ?>><?= h($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <!-- Live Search & Control Toolbar -->
        <div class="user-search-toolbar">
            <div class="users-search-box-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="users-search-icon">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text"
                       id="userSearchInput"
                       value="<?= h($searchQuery) ?>"
                       placeholder="Search by user name, email, mobile phone, or ID..."
                       autocomplete="off"
                       class="users-search-input"
                >
                <div class="users-search-hints">
                    <button type="button"
                            id="userSearchClear"
                            onclick="clearUserSearch()"
                            title="Clear search"
                            class="users-search-clear-btn"
                            style="display: <?= $searchQuery !== '' ? 'flex' : 'none' ?>;">
                        ✕
                    </button>
                    <span class="users-kbd-hint">/</span>
                </div>
            </div>

            <div class="users-toolbar-meta">
                <div id="userSearchStatus" class="users-search-status" style="display: none;"></div>
                <div class="users-counter-chip" id="userCountBadge">
                    <span class="users-dot-pulse"></span>
                    <span id="userCountLabel"><?= number_format($total) ?> found</span>
                </div>
            </div>
        </div>
        
        <!-- Empty State Container -->
        <div id="userEmptyState" class="empty-state users-empty-state" style="<?= empty($rows) ? 'display: block;' : 'display: none;' ?>">
            <div class="users-empty-icon-wrap">
                <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
            </div>
            <p id="userEmptyStateText" class="users-empty-title">No user accounts found matching your filter.</p>
        </div>

        <!-- Desktop / Tablet Table View (>= 769px) -->
        <div class="users-desktop-table table-wrap" style="<?= empty($rows) ? 'display: none;' : '' ?>">
            <table class="users-master-table">
                <thead>
                    <tr>
                        <th>User Profile</th>
                        <th>Email Address</th>
                        <th>Role & Access</th>
                        <?php if ($isAdmin): ?>
                            <th>Gym / Branch</th>
                        <?php endif; ?>
                        <th class="col-spec" style="<?= $tab === 'trainer' ? '' : 'display: none;' ?>">Specialization</th>
                        <th class="col-score" style="<?= $tab === 'member' ? '' : 'display: none;' ?>">Engagement</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="userTableBody">
                <?php foreach ($rows as $row):
                    $roleDisplayName = ucwords(str_replace('_', ' ', $row['role']));
                ?>
                    <tr class="user-table-row" data-name="<?= strtolower(h($row['first_name'] . ' ' . $row['last_name'])) ?>" data-email="<?= strtolower(h($row['email'])) ?>" data-phone="<?= strtolower(h($row['phone'] ?? '')) ?>" data-role="<?= strtolower(h($row['role'])) ?>" data-id="<?= (int)$row['user_id'] ?>">
                        <td>
                            <div class="user-cell">
                                <div class="user-cell-avatar">
                                    <?= render_avatar($row) ?>
                                </div>
                                <div class="user-cell-info">
                                    <span class="user-full-name"><?= h($row['first_name'] . ' ' . $row['last_name']) ?></span>
                                    <span class="user-id-chip">#<?= (int) $row['user_id'] ?></span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="user-email-text" title="<?= h($row['email']) ?>"><?= h($row['email']) ?></span>
                        </td>
                        <td>
                            <div class="user-role-wrap">
                                <span class="badge-role badge-role-<?= h($row['role']) ?>"><?= h($roleDisplayName) ?></span>
                                <?php if ($row['role'] === 'staff'): ?>
                                    <span class="badge-staff-subrole">
                                        <?= ($row['staff_role'] ?? '') === 'manager' ? 'Operations Manager' : 'Front Desk' ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php if ($isAdmin): ?>
                        <?php
                            $associatedGym = null;
                            if ($row['role'] === 'gym_owner') $associatedGym = $row['owner_gym_name'];
                            elseif ($row['role'] === 'trainer') $associatedGym = $row['trainer_gym_name'];
                            elseif ($row['role'] === 'member') $associatedGym = $row['member_gym_name'];
                            elseif ($row['role'] === 'staff') $associatedGym = $row['staff_gym_name'] ?? null;
                        ?>
                        <td class="user-gym-td">
                            <?php if ($associatedGym): ?>
                                <div class="user-gym-cell">
                                    <span class="user-gym-name" title="<?= h($associatedGym) ?>"><?= h($associatedGym) ?></span>
                                    <?php if ($row['role'] === 'gym_owner'): ?>
                                        <?= $renderGymVerifyMark($row['owner_gym_status'] ?? null, true) ?>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($row['role'] === 'gym_owner'): ?>
                                <div class="user-gym-cell">
                                    <span class="muted">—</span>
                                    <?= $renderGymVerifyMark(null, false) ?>
                                </div>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td class="col-spec" style="<?= $tab === 'trainer' ? '' : 'display: none;' ?>">
                            <?php if ($row['role'] === 'trainer'): ?>
                                <span class="user-spec-text"><?= h($row['specialization'] ?? 'General Trainer') ?></span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-score" style="<?= $tab === 'member' ? '' : 'display: none;' ?>">
                            <?php if ($row['role'] === 'member'): 
                                $score = (int)$row['engagement_score'];
                                $scoreTierClass = $score >= 75 ? 'tier-high' : ($score >= 45 ? 'tier-mid' : 'tier-low');
                            ?>
                                <div class="user-score-badge <?= $scoreTierClass ?>">
                                    <span class="score-val"><?= $score ?></span>
                                    <span class="score-unit">/100</span>
                                </div>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="user-status-pill is-<?= h($row['status']) ?>">
                                <span class="status-dot"></span>
                                <?= ucfirst(h($row['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <span class="user-joined-date"><?= h(date('M j, Y', strtotime($row['created_at']))) ?></span>
                        </td>
                        <td style="text-align: right;">
                            <div class="user-actions-cluster">
                                <?php if (!$isStaff): ?>
                                <form method="post" class="user-quick-status-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                    <select name="status" class="user-status-select-sm" onchange="this.form.submit()" title="Change Account Status">
                                        <option value="active" <?= selected('active', $row['status']) ?>>Active</option>
                                        <option value="suspended" <?= selected('suspended', $row['status']) ?>>Suspended</option>
                                    </select>
                                </form>
                                <?php endif; ?>

                                <button type="button" class="btn-user-action btn-edit-user" data-user="<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>" title="Edit User">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                    <span>Edit</span>
                                </button>

                                <?php if ($isStaff === false && in_array($user['role'], ['gym_owner', 'platform_admin'], true) && $row['role'] === 'member'): ?>
                                    <button type="button" class="btn-user-action btn-user-progress btn-member-progress" data-member-id="<?= (int)$row['user_id'] ?>" data-member-name="<?= h($row['first_name'] . ' ' . $row['last_name']) ?>" title="View Member Progress">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                                        <span>Progress</span>
                                    </button>
                                    <a href="index.php?page=diet_builder&member_user_id=<?= (int)$row['user_id'] ?>&ref=users" class="btn-user-action btn-user-diet" title="Manage Diet Plan">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                        <span>Diet</span>
                                    </a>
                                <?php endif; ?>

                                <?php if (!$isStaff && (int) $row['user_id'] !== (int) $user['user_id']): ?>
                                <button type="button" 
                                    class="btn-user-action btn-action-delete btn-delete-user" 
                                    data-user-id="<?= (int) $row['user_id'] ?>" 
                                    data-user-name="<?= h($row['first_name'] . ' ' . $row['last_name']) ?>" 
                                    data-user-role="<?= h($row['role']) ?>" 
                                    title="Delete User">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Card-List View (< 768px: Zero Horizontal Scroll) -->
        <div id="userMobileCards" class="users-mobile-cards" style="<?= empty($rows) ? 'display: none;' : '' ?>">
            <?php foreach ($rows as $row):
                $roleDisplayName = ucwords(str_replace('_', ' ', $row['role']));
                
                $associatedGym = null;
                if ($row['role'] === 'gym_owner') $associatedGym = $row['owner_gym_name'];
                elseif ($row['role'] === 'trainer') $associatedGym = $row['trainer_gym_name'];
                elseif ($row['role'] === 'member') $associatedGym = $row['member_gym_name'];
            ?>
                <div class="user-card-item" data-name="<?= strtolower(h($row['first_name'] . ' ' . $row['last_name'])) ?>" data-email="<?= strtolower(h($row['email'])) ?>" data-phone="<?= strtolower(h($row['phone'] ?? '')) ?>" data-role="<?= strtolower(h($row['role'])) ?>" data-id="<?= (int)$row['user_id'] ?>">
                    <div class="user-card-header">
                        <div class="user-card-identity">
                            <div class="user-card-avatar-wrap">
                                <?= render_avatar($row) ?>
                            </div>
                            <div class="user-card-names">
                                <div class="user-card-fullname"><?= h($row['first_name'] . ' ' . $row['last_name']) ?></div>
                                <div class="user-card-id-sub">
                                    <span class="user-id-chip">#<?= (int) $row['user_id'] ?></span>
                                    <span class="user-card-date"><?= h(date('M j, Y', strtotime($row['created_at']))) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="user-card-badges">
                            <span class="badge-role badge-role-<?= h($row['role']) ?>"><?= h($roleDisplayName) ?></span>
                            <?php if ($row['role'] === 'staff'): ?>
                                <span class="badge-staff-subrole">
                                    <?= ($row['staff_role'] ?? '') === 'manager' ? 'Manager' : 'Front Desk' ?>
                                </span>
                            <?php endif; ?>
                            <span class="user-status-pill is-<?= h($row['status']) ?>">
                                <span class="status-dot"></span>
                                <?= ucfirst(h($row['status'])) ?>
                            </span>
                        </div>
                    </div>

                    <div class="user-card-details">
                        <div class="user-card-detail-item">
                            <span class="user-card-detail-label">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                Email
                            </span>
                            <span class="user-card-detail-value email-value" title="<?= h($row['email']) ?>"><?= h($row['email']) ?></span>
                        </div>

                        <?php if ($isAdmin && ($associatedGym || $row['role'] === 'gym_owner')): ?>
                        <div class="user-card-detail-item">
                            <span class="user-card-detail-label">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M3 7v14M21 7v14M6 11h4M6 15h4M14 11h4M14 15h4M9 21v-4h6v4M3 7l9-4 9 4"/></svg>
                                Gym / Branch
                            </span>
                            <div class="user-card-detail-value" style="display:inline-flex; align-items:center; justify-content:flex-end; gap:2px; max-width:65%;">
                                <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= h($associatedGym ?: '—') ?>"><?= h($associatedGym ?: '—') ?></span>
                                <?php if ($row['role'] === 'gym_owner'): ?>
                                    <?= $renderGymVerifyMark($row['owner_gym_status'] ?? null, !empty($associatedGym)) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($row['role'] === 'trainer' && !empty($row['specialization'])): ?>
                        <div class="user-card-detail-item">
                            <span class="user-card-detail-label">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18M4 22h16M10 14.66V17c0 .55-.45 1-1 1H7c-.55 0-1-.45-1-1v-2.34M18 14.66V17c0 .55-.45 1-1 1h-2c-.55 0-1-.45-1-1v-2.34M8 2h8a2 2 0 0 1 2 2v7a6 6 0 0 1-12 0V4a2 2 0 0 1 2-2z"/></svg>
                                Specialization
                            </span>
                            <span class="user-card-detail-value"><?= h($row['specialization']) ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ($row['role'] === 'member'): ?>
                        <div class="user-card-detail-item">
                            <span class="user-card-detail-label">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                Engagement
                            </span>
                            <span class="user-card-detail-value"><strong style="color:var(--lime);"><?= (int)$row['engagement_score'] ?></strong> / 100</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="user-card-actions">
                        <?php if (!$isStaff): ?>
                        <form method="post" class="user-card-status-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                            <span class="user-card-status-label">Status:</span>
                            <select name="status" class="user-card-status-select" onchange="this.form.submit()">
                                <option value="active" <?= selected('active', $row['status']) ?>>Active</option>
                                <option value="suspended" <?= selected('suspended', $row['status']) ?>>Suspended</option>
                            </select>
                        </form>
                        <?php endif; ?>
                        
                        <div class="user-card-btn-group">
                            <button type="button" class="btn-card-action btn-edit-user" data-user="<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                <span>Edit</span>
                            </button>
                            <?php if (!$isStaff && in_array($user['role'], ['gym_owner', 'platform_admin'], true) && $row['role'] === 'member'): ?>
                                <button type="button" class="btn-card-action btn-card-progress btn-member-progress" data-member-id="<?= (int)$row['user_id'] ?>" data-member-name="<?= h($row['first_name'] . ' ' . $row['last_name']) ?>" title="View Member Progress">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                                    <span>Progress</span>
                                </button>
                                <a href="index.php?page=diet_builder&member_user_id=<?= (int)$row['user_id'] ?>&ref=users" class="btn-card-action btn-card-diet" title="Manage Diet Plan">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                    <span>Diet</span>
                                </a>
                            <?php endif; ?>
                            <?php if (!$isStaff && (int) $row['user_id'] !== (int) $user['user_id']): ?>
                            <button type="button" 
                                class="btn-card-action btn-card-danger btn-delete-user" 
                                data-user-id="<?= (int) $row['user_id'] ?>" 
                                data-user-name="<?= h($row['first_name'] . ' ' . $row['last_name']) ?>" 
                                data-user-role="<?= h($row['role']) ?>"
                                title="Delete User">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                <span>Delete</span>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div id="userPagination" style="<?= empty($rows) ? 'display: none;' : '' ?>">
            <?php render_pagination($page, $totalPages, '?page=users&tab=' . urlencode($tab) . ($gymFilter ? '&gym_id=' . $gymFilter : '') . ($searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '')); ?>
        </div>
    </section>

    <!-- Users Management Configuration & Script -->
    <script>
    window.USERS_CONFIG = {
        csrfToken: <?= json_encode(csrf_token()) ?>,
        currentTab: <?= json_encode($tab) ?>,
        gymFilter: <?= json_encode($gymFilter) ?>,
        isAdmin: <?= $isAdmin ? 'true' : 'false' ?>,
        hasOldUser: <?= json_encode(!empty($oldUser)) ?>,
        initialRole: <?= json_encode($oldUser['role'] ?? ($isAdmin ? 'gym_owner' : 'trainer')) ?>,
        oldUserStaffRole: <?= json_encode($oldUser['staff_role'] ?? 'front_desk') ?>
    };
    </script>
    <script src="<?= h(asset_url('js/pages/users.js')) ?>"></script>
    <?php
    render_footer();
}
