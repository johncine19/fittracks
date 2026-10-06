<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function users_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner']);
    $isAdmin = $user['role'] === 'platform_admin';
    $gymId = null;
    if (!$isAdmin) {
        $gymId = (int) scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
    }

    $canManageUser = function(int $targetUserId) use ($isAdmin, $gymId): bool {
        $targetRole = scalar('SELECT role FROM users WHERE user_id = ?', [$targetUserId]);
        if ($targetRole === 'platform_admin') return false;
        if ($isAdmin) return true;
        if ($targetRole === 'trainer') {
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
            if (!$isAdmin && !in_array($roleToCreate, ['trainer', 'member'])) {
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

            $plainPassword = (string) post('password');
            $firstName = mb_convert_case(trim((string) post('first_name')), MB_CASE_TITLE, 'UTF-8');
            $lastName  = mb_convert_case(trim((string) post('last_name')), MB_CASE_TITLE, 'UTF-8');

            $staffRole = null;
            if ($roleToCreate === 'trainer') {
                $allowedStaffRoles = ['trainer', 'front_desk', 'manager'];
                $reqStaffRole = trim((string) post('staff_role'));
                $staffRole = in_array($reqStaffRole, $allowedStaffRoles, true) ? $reqStaffRole : 'trainer';
            }

            $stmt = db()->prepare('INSERT INTO users (role, first_name, last_name, email, password_hash, phone, status, email_verified_at, staff_role) VALUES (?, ?, ?, ?, ?, ?, "active", NOW(), ?)');
            $stmt->execute([$roleToCreate, $firstName, $lastName, $email, password_hash($plainPassword, PASSWORD_DEFAULT), $phone ?: null, $staffRole]);
            $newUserId = (int) db()->lastInsertId();
            if ($roleToCreate === 'trainer') {
                $trainerGymId = $isAdmin ? null : $gymId;
                db()->prepare('INSERT INTO trainer_profiles (user_id, specialization, bio, gym_id) VALUES (?, ?, ?, ?)')->execute([$newUserId, post('specialization'), post('bio'), $trainerGymId]);
            } elseif ($roleToCreate === 'member' && !$isAdmin) {
                db()->prepare('INSERT IGNORE INTO gym_members (gym_id, user_id) VALUES (?, ?)')->execute([$gymId, $newUserId]);
            }
            audit_log($user['user_id'], 'create', 'user', (string) $newUserId, json_encode(['role' => $roleToCreate, 'email' => $email, 'name' => $firstName . ' ' . $lastName]));

            // Send credentials email via background queue
            Emails::sendAccountCreated(
                $email,
                $firstName . ' ' . $lastName,
                $plainPassword
            );

            $sendCreateResponse(true, 'User created successfully. Login credentials have been emailed to ' . $email . '.', 'success');
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
            if (!$isAdmin && !in_array($roleToEdit, ['trainer', 'member'])) {
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
                $allowedStaffRoles = ['trainer', 'front_desk', 'manager'];
                $reqStaffRole = trim((string) post('staff_role'));
                $staffRole = in_array($reqStaffRole, $allowedStaffRoles, true) ? $reqStaffRole : 'trainer';
                db()->prepare('UPDATE users SET staff_role = ? WHERE user_id = ?')->execute([$staffRole, $editUserId]);

                db()->prepare('INSERT INTO trainer_profiles (user_id, specialization, bio) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE specialization = VALUES(specialization), bio = VALUES(bio)')
                    ->execute([$editUserId, post('specialization'), post('bio')]);
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
                    
                    db()->prepare('DELETE FROM users WHERE user_id=?')->execute([$targetUserId]);
                    audit_log($user['user_id'], 'delete', 'user', (string) $targetUserId, json_encode(['role' => $targetUser['role']]));
                    flash('User deleted.');
                }
            }
        }
        redirect('users');
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
                (u.role = "trainer" AND EXISTS (SELECT 1 FROM trainer_profiles WHERE user_id = u.user_id AND gym_id = ?)) OR
                (u.role = "member" AND EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?))
            )';
            $params[] = $gymId;
            $params[] = $gymId;
        }

        if (in_array($tab, ['gym_owner', 'trainer', 'member'], true)) {
            $where .= ' AND u.role = ?';
            $params[] = $tab;
        }

        if ($isAdmin && $gymFilter) {
            $where .= ' AND (
                (u.role = "gym_owner" AND EXISTS (SELECT 1 FROM gyms WHERE owner_user_id = u.user_id AND gym_id = ?)) OR
                (u.role = "trainer" AND EXISTS (SELECT 1 FROM trainer_profiles WHERE user_id = u.user_id AND gym_id = ?)) OR
                (u.role = "member" AND EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?))
            )';
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

        $sql = 'SELECT u.*, tp.specialization, tp.bio, 
                (SELECT g1.name FROM gyms g1 WHERE g1.owner_user_id = u.user_id ORDER BY g1.gym_id DESC LIMIT 1) AS owner_gym_name,
                (SELECT g1.status FROM gyms g1 WHERE g1.owner_user_id = u.user_id ORDER BY g1.gym_id DESC LIMIT 1) AS owner_gym_status,
                (SELECT g2.name FROM trainer_profiles tp2 JOIN gyms g2 ON tp2.gym_id = g2.gym_id WHERE tp2.user_id = u.user_id LIMIT 1) AS trainer_gym_name,
                (SELECT g3.name FROM gym_members gm JOIN gyms g3 ON gm.gym_id = g3.gym_id WHERE gm.user_id = u.user_id LIMIT 1) AS member_gym_name
                FROM users u 
                LEFT JOIN trainer_profiles tp ON u.user_id = tp.user_id 
                WHERE ' . $where . ' 
                ORDER BY CASE u.role WHEN "gym_owner" THEN 1 WHEN "trainer" THEN 2 WHEN "member" THEN 3 ELSE 4 END ASC, u.first_name ASC, u.last_name ASC 
                LIMIT 50';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $searchRows = $stmt->fetchAll();

        $outputUsers = [];
        foreach ($searchRows as $row) {
            $associatedGym = null;
            if ($row['role'] === 'gym_owner') $associatedGym = $row['owner_gym_name'];
            elseif ($row['role'] === 'trainer') $associatedGym = $row['trainer_gym_name'];
            elseif ($row['role'] === 'member') $associatedGym = $row['member_gym_name'];

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
                'raw_user'         => $row
            ];
        }

        echo json_encode([
            'users' => $outputUsers,
            'total' => $totalFound,
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
            (u.role = "trainer" AND EXISTS (SELECT 1 FROM trainer_profiles WHERE user_id = u.user_id AND gym_id = ?)) OR
            (u.role = "member" AND EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?))
        )';
        $params[] = $gymId;
        $params[] = $gymId;
    }
    
    if (in_array($tab, ['gym_owner', 'trainer', 'member'], true)) {
        $where .= ' AND u.role = ?';
        $params[] = $tab;
    }
    
    if ($isAdmin && $gymFilter) {
        $where .= ' AND (
            (u.role = "gym_owner" AND EXISTS (SELECT 1 FROM gyms WHERE owner_user_id = u.user_id AND gym_id = ?)) OR
            (u.role = "trainer" AND EXISTS (SELECT 1 FROM trainer_profiles WHERE user_id = u.user_id AND gym_id = ?)) OR
            (u.role = "member" AND EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gym_id = ?))
        )';
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

    $total = (int) scalar('SELECT COUNT(*) FROM users u WHERE ' . $where, $params);
    $totalPages = max(1, (int) ceil($total / $limit));

    $sql = 'SELECT u.*, tp.specialization, tp.bio, 
            (SELECT g1.name FROM gyms g1 WHERE g1.owner_user_id = u.user_id ORDER BY g1.gym_id DESC LIMIT 1) AS owner_gym_name,
            (SELECT g1.status FROM gyms g1 WHERE g1.owner_user_id = u.user_id ORDER BY g1.gym_id DESC LIMIT 1) AS owner_gym_status,
            (SELECT g2.name FROM trainer_profiles tp2 JOIN gyms g2 ON tp2.gym_id = g2.gym_id WHERE tp2.user_id = u.user_id LIMIT 1) AS trainer_gym_name,
            (SELECT g3.name FROM gym_members gm JOIN gyms g3 ON gm.gym_id = g3.gym_id WHERE gm.user_id = u.user_id LIMIT 1) AS member_gym_name
            FROM users u 
            LEFT JOIN trainer_profiles tp ON u.user_id = tp.user_id 
            WHERE ' . $where . ' 
            ORDER BY CASE u.role WHEN "gym_owner" THEN 1 WHEN "trainer" THEN 2 WHEN "member" THEN 3 ELSE 4 END ASC, u.first_name ASC, u.last_name ASC 
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
                    <label>First name
                        <input name="first_name" value="<?= h($oldUser['first_name'] ?? '') ?>" placeholder="John" required autocapitalize="words" style="text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())">
                    </label>
                    <label>Last name
                        <input name="last_name" value="<?= h($oldUser['last_name'] ?? '') ?>" placeholder="Doe" required autocapitalize="words" style="text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())">
                    </label>
                    <label>Email
                        <input name="email" type="email" value="<?= h($oldUser['email'] ?? '') ?>" placeholder="john@example.com" required style="text-transform: lowercase;" oninput="this.value = this.value.toLowerCase()" onblur="this.value = this.value.trim().toLowerCase()">
                    </label>
                    <label>Mobile Number *
                        <input name="phone" type="tel" pattern="[0-9]{11}" maxlength="11" title="Please enter exactly 11 digits" placeholder="09123456789" value="<?= h($oldUser['phone'] ?? '') ?>" required oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)">
                    </label>
                    <label>Password
                        <input name="password" id="new_user_password" type="password" minlength="8" placeholder="Min. 8 characters" required autocomplete="new-password">
                        <small id="new_user_pass_hint" style="display:block; font-size:12px; margin-top:4px; color:var(--muted); font-weight:400;">
                            Must be at least 8 characters with a letter and a number.
                        </small>
                    </label>
                    <label>Role
                        <div id="roleComboboxWrap"></div>
                    </label>
                    <div id="trainer_fields" style="display: <?= ($oldUser['role'] ?? '') === 'trainer' ? 'grid' : 'none' ?>; grid-column: 1 / -1; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px;">
                        <label>Specialization <small style="font-weight:400">(trainer only)</small>
                            <input name="specialization" id="new_user_spec" value="<?= h($oldUser['specialization'] ?? '') ?>" placeholder="e.g. Strength & Conditioning">
                        </label>
                        <label>Staff Permission Level
                            <select name="staff_role" id="new_user_staff_role" style="width: 100%; box-sizing: border-box; padding: 8px 10px; border-radius: 6px; background: var(--bg); border: 1px solid var(--line); color: var(--ink);">
                                <option value="trainer" <?= selected('trainer', $oldUser['staff_role'] ?? 'trainer') ?>>Fitness Trainer (Floor Staff)</option>
                                <option value="front_desk" <?= selected('front_desk', $oldUser['staff_role'] ?? '') ?>>Front Desk / Cashier (Financials Masked)</option>
                                <option value="manager" <?= selected('manager', $oldUser['staff_role'] ?? '') ?>>Operations Manager (Full Staff Access)</option>
                            </select>
                        </label>
                        <label style="grid-column: 1 / -1;">Bio <small style="font-weight:400">(trainer only)</small>
                            <input name="bio" value="<?= h($oldUser['bio'] ?? '') ?>" placeholder="Short bio">
                        </label>
                    </div>
                    <button id="createUserSubmitBtn" style="grid-column: 1 / -1; margin-top: 10px;">Create user</button>
                </form>
            </div>
        </dialog>

        <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom: 12px; border-bottom: 1px solid var(--line); padding-bottom: 8px; flex-wrap:wrap; gap:16px;">
            <div style="display:flex; gap:16px; overflow-x:auto;">
                <a href="?page=users&tab=all<?= $gymFilter ? '&gym_id='.$gymFilter : '' ?>" style="white-space:nowrap; color: <?= $tab === 'all' ? 'var(--lime)' : 'var(--muted)' ?>; font-weight: <?= $tab === 'all' ? '700' : '400' ?>; text-decoration:none; padding-bottom:4px; border-bottom: 2px solid <?= $tab === 'all' ? 'var(--lime)' : 'transparent' ?>;">All Users</a>
                <?php if ($isAdmin): ?>
                    <a href="?page=users&tab=gym_owner<?= $gymFilter ? '&gym_id='.$gymFilter : '' ?>" style="white-space:nowrap; color: <?= $tab === 'gym_owner' ? 'var(--lime)' : 'var(--muted)' ?>; font-weight: <?= $tab === 'gym_owner' ? '700' : '400' ?>; text-decoration:none; padding-bottom:4px; border-bottom: 2px solid <?= $tab === 'gym_owner' ? 'var(--lime)' : 'transparent' ?>;">Gym Owners</a>
                <?php endif; ?>
                <a href="?page=users&tab=trainer<?= $gymFilter ? '&gym_id='.$gymFilter : '' ?>" style="white-space:nowrap; color: <?= $tab === 'trainer' ? 'var(--lime)' : 'var(--muted)' ?>; font-weight: <?= $tab === 'trainer' ? '700' : '400' ?>; text-decoration:none; padding-bottom:4px; border-bottom: 2px solid <?= $tab === 'trainer' ? 'var(--lime)' : 'transparent' ?>;">Trainers</a>
                <a href="?page=users&tab=member<?= $gymFilter ? '&gym_id='.$gymFilter : '' ?>" style="white-space:nowrap; color: <?= $tab === 'member' ? 'var(--lime)' : 'var(--muted)' ?>; font-weight: <?= $tab === 'member' ? '700' : '400' ?>; text-decoration:none; padding-bottom:4px; border-bottom: 2px solid <?= $tab === 'member' ? 'var(--lime)' : 'transparent' ?>;">Members</a>
            </div>
            
            <?php if ($isAdmin): ?>
            <div style="display:flex; align-items:center; gap: 16px;">
                <form method="get" style="margin:0; display:flex; gap:8px; align-items:center;">
                    <input type="hidden" name="page" value="users">
                    <input type="hidden" name="tab" value="<?= h($tab) ?>">
                    <select name="gym_id" onchange="this.form.submit()" style="padding:4px 8px; font-size:13px; border-radius:6px; background:var(--bg); border:1px solid var(--line); color:var(--ink);">
                        <option value="">All Gyms</option>
                        <?php foreach ($allGyms as $g): ?>
                            <option value="<?= $g['gym_id'] ?>" <?= selected((string)$g['gym_id'], (string)$gymFilter) ?>><?= h($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <?php endif; ?>
            <div style="display:flex; align-items:center;">
                <p class="section-label" id="userCountLabel" style="margin:0; border:none; padding:0;"><?= $total ?> found</p>
            </div>
        </div>

        <!-- Live Search Toolbar -->
        <div class="user-search-toolbar" style="margin-bottom: 16px; display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap;">
            <div style="position: relative; flex: 1; min-width: 260px; max-width: 460px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"
                     style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--muted); pointer-events: none;">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text"
                       id="userSearchInput"
                       value="<?= h($searchQuery) ?>"
                       placeholder="Search by name, email, phone, or ID..."
                       autocomplete="off"
                       style="width: 100%; box-sizing: border-box; padding: 9px 36px 9px 36px; border-radius: 8px; border: 1px solid var(--line); background: var(--panel); color: var(--ink); font-size: 0.9rem; outline: none; transition: border-color 0.2s, box-shadow 0.2s;"
                       onfocus="this.style.borderColor='var(--lime)';"
                       onblur="this.style.borderColor='var(--line)';"
                >
                <button type="button"
                        id="userSearchClear"
                        onclick="clearUserSearch()"
                        title="Clear search"
                        style="display: <?= $searchQuery !== '' ? 'flex' : 'none' ?>; position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--muted); cursor: pointer; padding: 2px 6px; border-radius: 50%; font-size: 14px; line-height: 1;">
                    ✕
                </button>
            </div>
            <div id="userSearchStatus" style="font-size: 13px; color: var(--muted); display: none;"></div>
        </div>
        
        <!-- Empty State Container -->
        <div id="userEmptyState" class="empty-state" style="<?= empty($rows) ? 'display: block;' : 'display: none;' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <p id="userEmptyStateText">No users found.</p>
        </div>

        <!-- Desktop / Tablet Table View (>= 769px) -->
        <div class="users-desktop-table table-wrap" style="<?= empty($rows) ? 'display: none;' : '' ?>">
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Role</th>
                        <?php if ($isAdmin): ?>
                            <th>Gym / Branch</th>
                        <?php endif; ?>
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
                <tbody id="userTableBody">
                <?php foreach ($rows as $row):
                    $roleClass = 'badge badge-' . $row['role'];
                    $statusClass = 'badge badge-' . $row['status'];
                    $roleDisplayName = ucwords(str_replace('_', ' ', $row['role']));
                ?>
                    <tr class="user-table-row" data-name="<?= strtolower(h($row['first_name'] . ' ' . $row['last_name'])) ?>" data-email="<?= strtolower(h($row['email'])) ?>" data-phone="<?= strtolower(h($row['phone'] ?? '')) ?>" data-role="<?= strtolower(h($row['role'])) ?>" data-id="<?= (int)$row['user_id'] ?>">
                        <td>
                            <div class="user-cell">
                                <?= render_avatar($row) ?>
                                <div class="user-cell-info">
                                    <?= h($row['first_name'] . ' ' . $row['last_name']) ?>
                                    <small>#<?= (int) $row['user_id'] ?></small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="<?= $roleClass ?>"><?= h($roleDisplayName) ?></span>
                            <?php if ($row['role'] === 'trainer' && !empty($row['staff_role']) && $row['staff_role'] !== 'trainer'): ?>
                                <span class="badge" style="font-size:10px; margin-left:4px; background:rgba(59,130,246,0.15); color:#93c5fd; border:1px solid rgba(59,130,246,0.3);">
                                    <?= $row['staff_role'] === 'front_desk' ? 'Front Desk' : 'Manager' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <?php if ($isAdmin): ?>
                        <?php
                            $associatedGym = null;
                            if ($row['role'] === 'gym_owner') $associatedGym = $row['owner_gym_name'];
                            elseif ($row['role'] === 'trainer') $associatedGym = $row['trainer_gym_name'];
                            elseif ($row['role'] === 'member') $associatedGym = $row['member_gym_name'];
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
                                <button type="button" class="btn btn-secondary btn-edit-user" data-user="<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>" style="padding:4px 8px;font-size:12px;margin-left:8px;">Edit</button>
                                <?php if ($row['role'] === 'member'): ?>
                                    <a href="index.php?page=diet_builder&member_user_id=<?= (int)$row['user_id'] ?>&ref=users" class="btn btn-secondary" style="padding:4px 8px;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;gap:4px;" title="Manage Diet Plan">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                        Diet Plan
                                    </a>
                                <?php endif; ?>
                                <?php if ((int) $row['user_id'] !== (int) $user['user_id']): ?>
                                <button type="button" 
                                    class="btn btn-danger btn-delete-user" 
                                    data-user-id="<?= (int) $row['user_id'] ?>" 
                                    data-user-name="<?= h($row['first_name'] . ' ' . $row['last_name']) ?>" 
                                    data-user-role="<?= h($row['role']) ?>" 
                                    style="padding:4px 8px;font-size:12px;">
                                    Delete
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
                $roleClass = 'badge badge-' . $row['role'];
                $statusClass = 'badge badge-' . $row['status'];
                $roleDisplayName = ucwords(str_replace('_', ' ', $row['role']));
                
                $associatedGym = null;
                if ($row['role'] === 'gym_owner') $associatedGym = $row['owner_gym_name'];
                elseif ($row['role'] === 'trainer') $associatedGym = $row['trainer_gym_name'];
                elseif ($row['role'] === 'member') $associatedGym = $row['member_gym_name'];
            ?>
                <div class="user-card-item" data-name="<?= strtolower(h($row['first_name'] . ' ' . $row['last_name'])) ?>" data-email="<?= strtolower(h($row['email'])) ?>" data-phone="<?= strtolower(h($row['phone'] ?? '')) ?>" data-role="<?= strtolower(h($row['role'])) ?>" data-id="<?= (int)$row['user_id'] ?>">
                    <div class="user-card-header">
                        <div class="user-card-identity">
                            <?= render_avatar($row) ?>
                            <div class="user-card-names">
                                <div class="user-card-fullname"><?= h($row['first_name'] . ' ' . $row['last_name']) ?></div>
                                <div class="user-card-id">#<?= (int) $row['user_id'] ?></div>
                            </div>
                        </div>
                        <div class="user-card-badges">
                            <span class="<?= $roleClass ?>"><?= h($roleDisplayName) ?></span>
                            <?php if ($row['role'] === 'trainer' && !empty($row['staff_role']) && $row['staff_role'] !== 'trainer'): ?>
                                <span class="badge" style="font-size:10px; background:rgba(59,130,246,0.15); color:#93c5fd; border:1px solid rgba(59,130,246,0.3);">
                                    <?= $row['staff_role'] === 'front_desk' ? 'Front Desk' : 'Manager' ?>
                                </span>
                            <?php endif; ?>
                            <span class="<?= $statusClass ?>"><?= h($row['status']) ?></span>
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
                                Score
                            </span>
                            <span class="user-card-detail-value"><strong style="color:var(--lime);"><?= (int)$row['engagement_score'] ?></strong> / 100</span>
                        </div>
                        <?php endif; ?>

                        <div class="user-card-detail-item">
                            <span class="user-card-detail-label">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                Joined
                            </span>
                            <span class="user-card-detail-value"><?= h(date('M j, Y', strtotime($row['created_at']))) ?></span>
                        </div>
                    </div>

                    <div class="user-card-actions">
                        <form method="post" class="user-card-status-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                            <span class="user-card-status-label">Status:</span>
                            <select name="status">
                                <option value="active" <?= selected('active', $row['status']) ?>>Active</option>
                                <option value="suspended" <?= selected('suspended', $row['status']) ?>>Suspended</option>
                            </select>
                            <button type="submit" class="btn-sm btn-ghost">Update</button>
                        </form>
                        
                        <div class="user-card-btn-group">
                            <button type="button" class="btn btn-secondary btn-sm btn-edit-user" data-user="<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                Edit
                            </button>
                            <?php if ($row['role'] === 'member'): ?>
                                <a href="index.php?page=diet_builder&member_user_id=<?= (int)$row['user_id'] ?>&ref=users" class="btn btn-secondary btn-sm" title="Manage Diet Plan">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                    Diet
                                </a>
                            <?php endif; ?>
                            <?php if ((int) $row['user_id'] !== (int) $user['user_id']): ?>
                            <button type="button" 
                                class="btn btn-danger btn-sm btn-delete-user" 
                                data-user-id="<?= (int) $row['user_id'] ?>" 
                                data-user-name="<?= h($row['first_name'] . ' ' . $row['last_name']) ?>" 
                                data-user-role="<?= h($row['role']) ?>">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                Delete
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
        initialRole: <?= json_encode($oldUser['role'] ?? ($isAdmin ? 'gym_owner' : 'trainer')) ?>
    };
    </script>
    <script src="<?= h(asset_url('js/pages/users.js')) ?>"></script>
    <?php
    render_footer();
}
