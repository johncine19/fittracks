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

            $stmt = db()->prepare('INSERT INTO users (role, first_name, last_name, email, password_hash, phone, status, email_verified_at) VALUES (?, ?, ?, ?, ?, ?, "active", NOW())');
            $stmt->execute([$roleToCreate, $firstName, $lastName, $email, password_hash($plainPassword, PASSWORD_DEFAULT), $phone ?: null]);
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
                db()->prepare('INSERT INTO trainer_profiles (user_id, specialization, bio) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE specialization = VALUES(specialization), bio = VALUES(bio)')
                    ->execute([$editUserId, post('specialization'), post('bio')]);
            }
            audit_log($user['user_id'], 'edit', 'user', (string) $editUserId, json_encode(['email' => post('email'), 'role' => post('role'), 'password_changed' => $newPassword !== '']));
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

    $renderGymBadge = function(?string $status, bool $hasGym): string {
        if (!$hasGym) {
            return '<span class="badge badge-gym-unsubmitted" style="font-size: 10px; padding: 1px 6px; letter-spacing: 0.3px;">Not Submitted</span>';
        }
        if ($status === 'approved') {
            return '<span class="badge badge-gym-approved" style="font-size: 10px; padding: 1px 6px; letter-spacing: 0.3px;">✓ Verified</span>';
        }
        if ($status === 'rejected') {
            return '<span class="badge badge-gym-rejected" style="font-size: 10px; padding: 1px 6px; letter-spacing: 0.3px;">✕ Rejected</span>';
        }
        if ($status === 'pending') {
            return '<span class="badge badge-gym-pending" style="font-size: 10px; padding: 1px 6px; letter-spacing: 0.3px;">⏳ Pending</span>';
        }
        return '<span class="badge badge-gym-rejected" style="font-size: 10px; padding: 1px 6px; letter-spacing: 0.3px;">' . htmlspecialchars(ucfirst($status ?? 'Unknown')) . '</span>';
    };
    
    render_header('Users', $user);
    ?>
    <style>
    /* User Role Badges */
    .badge-platform_admin { background: rgba(199, 255, 34, 0.12); color: #c7ff22; border: 1px solid rgba(199, 255, 34, 0.25); }
    .badge-gym_owner      { background: rgba(168, 85, 247, 0.12); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.25); }
    .badge-trainer        { background: rgba(59, 130, 246, 0.12); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.25); }
    .badge-member         { background: rgba(20, 184, 166, 0.12); color: #2dd4bf; border: 1px solid rgba(20, 184, 166, 0.25); }

    /* Gym Verification Badges */
    .badge-gym-approved    { background: rgba(34, 197, 94, 0.14); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.3); font-weight: 600; border-radius: 4px; }
    .badge-gym-pending     { background: rgba(234, 179, 8, 0.14); color: #eab308; border: 1px solid rgba(234, 179, 8, 0.3); font-weight: 600; border-radius: 4px; }
    .badge-gym-rejected    { background: rgba(239, 68, 68, 0.14); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); font-weight: 600; border-radius: 4px; }
    .badge-gym-unsubmitted { background: rgba(148, 163, 184, 0.12); color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.25); font-weight: 600; border-radius: 4px; }

    html[data-theme="light"] .badge-platform_admin,
    [data-theme="light"] .badge-platform_admin { background: rgba(132, 204, 22, 0.15); color: #4d7c0f; border-color: rgba(132, 204, 22, 0.35); }
    html[data-theme="light"] .badge-gym_owner,
    [data-theme="light"] .badge-gym_owner { background: rgba(168, 85, 247, 0.12); color: #7e22ce; border-color: rgba(168, 85, 247, 0.3); }
    html[data-theme="light"] .badge-trainer,
    [data-theme="light"] .badge-trainer { background: rgba(59, 130, 246, 0.12); color: #1d4ed8; border-color: rgba(59, 130, 246, 0.3); }
    html[data-theme="light"] .badge-member,
    [data-theme="light"] .badge-member { background: rgba(20, 184, 166, 0.12); color: #0f766e; border-color: rgba(20, 184, 166, 0.3); }

    html[data-theme="light"] .badge-gym-approved,
    [data-theme="light"] .badge-gym-approved { background: rgba(22, 163, 74, 0.12); color: #15803d; border-color: rgba(22, 163, 74, 0.3); }
    html[data-theme="light"] .badge-gym-pending,
    [data-theme="light"] .badge-gym-pending { background: rgba(202, 138, 4, 0.12); color: #a16207; border-color: rgba(202, 138, 4, 0.3); }
    html[data-theme="light"] .badge-gym-rejected,
    [data-theme="light"] .badge-gym-rejected { background: rgba(239, 68, 68, 0.12); color: #b91c1c; border-color: rgba(239, 68, 68, 0.3); }
    html[data-theme="light"] .badge-gym-unsubmitted,
    [data-theme="light"] .badge-gym-unsubmitted { background: rgba(100, 116, 139, 0.12); color: #64748b; border-color: rgba(100, 116, 139, 0.25); }

    /* Desktop vs Mobile Toggle */
    .users-desktop-table {
        display: block;
    }
    .users-mobile-cards {
        display: none;
    }

    @media (max-width: 768px) {
        .users-desktop-table {
            display: none !important;
        }
        .users-mobile-cards {
            display: flex !important;
            flex-direction: column;
            gap: 12px;
        }
    }

    /* Mobile User Card Styles */
    .user-card-item {
        background: color-mix(in srgb, var(--panel-soft) 45%, transparent);
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 14px 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        transition: all 0.2s ease;
    }
    html[data-theme="light"] .user-card-item,
    [data-theme="light"] .user-card-item {
        background: #ffffff;
        border-color: #e2e8f0;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
    }
    .user-card-item:hover {
        border-color: color-mix(in srgb, var(--lime) 30%, transparent);
    }

    .user-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 10px;
        padding-bottom: 10px;
        border-bottom: 1px solid color-mix(in srgb, var(--line) 60%, transparent);
    }
    .user-card-identity {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 0;
    }
    .user-card-names {
        min-width: 0;
        overflow: hidden;
    }
    .user-card-fullname {
        font-weight: 700;
        font-size: 15px;
        color: var(--ink);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .user-card-id {
        font-size: 12px;
        color: var(--muted);
        font-family: ui-monospace, monospace;
    }
    .user-card-badges {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 4px;
        flex-shrink: 0;
    }

    .user-card-details {
        display: grid;
        grid-template-columns: 1fr;
        gap: 8px;
        font-size: 13px;
    }
    .user-card-detail-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        min-width: 0;
    }
    .user-card-detail-label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: var(--muted);
        font-size: 12px;
        flex-shrink: 0;
    }
    .user-card-detail-label svg {
        opacity: 0.75;
    }
    .user-card-detail-value {
        color: var(--ink);
        font-weight: 500;
        text-align: right;
        word-break: break-all;
        max-width: 65%;
    }
    .user-card-detail-value.email-value {
        color: var(--muted);
        font-size: 12.5px;
    }

    .user-card-actions {
        display: flex;
        flex-direction: column;
        gap: 10px;
        padding-top: 12px;
        border-top: 1px solid color-mix(in srgb, var(--line) 60%, transparent);
    }
    .user-card-status-form {
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 0;
        width: 100%;
    }
    .user-card-status-label {
        font-size: 12px;
        color: var(--muted);
        font-weight: 600;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .user-card-status-form select {
        flex: 1;
        min-width: 0;
        padding: 6px 10px;
        font-size: 12.5px;
        border-radius: 6px;
        background: var(--bg);
        border: 1px solid var(--line);
        color: var(--ink);
    }
    .user-card-status-form button {
        padding: 6px 14px;
        font-size: 12px;
        font-weight: 600;
        border-radius: 6px;
        flex-shrink: 0;
    }
    .user-card-btn-group {
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
    }
    .user-card-btn-group .btn-sm,
    .user-card-btn-group a.btn-sm,
    .user-card-btn-group form {
        flex: 1;
        min-width: 0;
        margin: 0;
    }
    .user-card-btn-group .btn-sm,
    .user-card-btn-group a.btn-sm {
        display: inline-flex;
        justify-content: center;
        align-items: center;
        gap: 5px;
        padding: 7px 10px;
        font-size: 12px;
        font-weight: 600;
        border-radius: 6px;
        text-align: center;
        white-space: nowrap;
        text-decoration: none;
        box-sizing: border-box;
    }
    .user-card-btn-group form {
        display: flex;
    }
    .user-card-btn-group form button {
        width: 100%;
        padding: 7px 10px;
        font-size: 12px;
        font-weight: 600;
        border-radius: 6px;
        white-space: nowrap;
        box-sizing: border-box;
    }

    /* ── Create User Modal: Compact 2-by-2 Layout & Mobile Tuning ── */
    #createUserModal.modal {
        max-width: 520px;
        overflow: visible !important;
    }
    #createUserModal .modal-body {
        overflow: visible !important;
    }
    #createUserModal .grid-form {
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        align-items: start !important;
        gap: 12px 14px;
    }
    #createUserModal #trainer_fields {
        grid-column: 1 / -1;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px 14px;
    }

    #createUserModal .fit-dropdown-wrap {
        width: 100%;
        position: relative;
    }
    #createUserModal .fit-dropdown-trigger {
        height: 38px;
        border-radius: 7px;
        padding: 8px 10px;
    }
    #createUserModal .fit-dropdown-menu {
        z-index: 999999 !important;
    }

    @media (max-width: 640px) {
        #createUserModal.modal {
            width: 95% !important;
            max-width: 400px !important;
            border-radius: 10px !important;
            margin: auto !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.45) !important;
        }
        #createUserModal .modal-header {
            padding: 10px 14px !important;
        }
        #createUserModal .modal-header h3 {
            font-size: 15px !important;
            font-weight: 700 !important;
        }
        #createUserModal .modal-close {
            padding: 3px !important;
        }
        #createUserModal .modal-close svg {
            width: 16px !important;
            height: 16px !important;
        }
        #createUserModal .modal-body {
            padding: 10px 12px 14px !important;
        }
        #createUserModal .grid-form {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 7px 10px !important;
        }
        #createUserModal #trainer_fields {
            gap: 7px 10px !important;
        }
        #createUserModal label {
            font-size: 11px !important;
            font-weight: 600 !important;
            gap: 3px !important;
            color: var(--muted) !important;
            margin: 0 !important;
        }
        #createUserModal input,
        #createUserModal select {
            height: 33px !important;
            padding: 5px 8px !important;
            font-size: 12px !important;
            border-radius: 6px !important;
            border-width: 1px !important;
            box-sizing: border-box !important;
        }
        #createUserModal .pwd-toggle-wrap input {
            padding-right: 30px !important;
        }
        #createUserModal .pwd-toggle-btn {
            right: 6px !important;
            padding: 2px !important;
        }
        #createUserModal .pwd-toggle-btn svg {
            width: 14px !important;
            height: 14px !important;
        }
        #createUserModal #new_user_pass_hint {
            font-size: 10px !important;
            margin-top: 2px !important;
            line-height: 1.15 !important;
        }
        #createUserModal #createUserSubmitBtn {
            grid-column: 1 / -1 !important;
            margin-top: 5px !important;
            padding: 6px 12px !important;
            font-size: 12.5px !important;
            height: 35px !important;
            font-weight: 700 !important;
            border-radius: 6px !important;
        }
        #createUserModal .fit-dropdown-trigger {
            height: 33px !important;
            padding: 4px 8px !important;
            font-size: 12px !important;
            border-radius: 6px !important;
        }
        #createUserModal .fit-dropdown-trigger-content span {
            font-size: 12px !important;
        }
        #createUserModal .fit-dropdown-chevron {
            width: 13px !important;
            height: 13px !important;
        }
        #createUserModal .fit-dropdown-item {
            padding: 6px 8px !important;
            font-size: 12px !important;
        }
    }
    </style>
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
                        <label>Bio <small style="font-weight:400">(trainer only)</small>
                            <input name="bio" value="<?= h($oldUser['bio'] ?? '') ?>" placeholder="Short bio">
                        </label>
                    </div>
                    <script>
                        function toggleTrainerFields(role) {
                            const tf = document.getElementById('trainer_fields');
                            const spec = document.getElementById('new_user_spec');
                            if (!tf) return;
                            if (role === 'trainer') {
                                tf.style.display = 'grid';
                                tf.style.gridTemplateColumns = 'repeat(2, minmax(0, 1fr))';
                                if (spec) spec.required = true;
                            } else {
                                tf.style.display = 'none';
                                if (spec) spec.required = false;
                            }
                        }
                    </script>
                    <button id="createUserSubmitBtn" style="grid-column: 1 / -1; margin-top: 10px;">Create user</button>
                </form>
            </div>
        </dialog>
        <?php if (!empty($oldUser)): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    document.getElementById('createUserModal')?.showModal();
                });
            </script>
        <?php endif; ?>

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
                        <td style="color:var(--muted)"><?= h($row['email']) ?></td>
                        <td><span class="<?= $roleClass ?>"><?= h($roleDisplayName) ?></span></td>
                        <?php if ($isAdmin): ?>
                        <?php
                            $associatedGym = null;
                            if ($row['role'] === 'gym_owner') $associatedGym = $row['owner_gym_name'];
                            elseif ($row['role'] === 'trainer') $associatedGym = $row['trainer_gym_name'];
                            elseif ($row['role'] === 'member') $associatedGym = $row['member_gym_name'];
                        ?>
                        <td>
                            <?php if ($associatedGym): ?>
                                <div style="display: flex; flex-direction: column; gap: 4px; align-items: flex-start;">
                                    <span style="font-weight: 500; color: var(--ink);"><?= h($associatedGym) ?></span>
                                    <?php if ($row['role'] === 'gym_owner'): ?>
                                        <?= $renderGymBadge($row['owner_gym_status'] ?? null, true) ?>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($row['role'] === 'gym_owner'): ?>
                                <div style="display: flex; flex-direction: column; gap: 4px; align-items: flex-start;">
                                    <span class="muted">—</span>
                                    <?= $renderGymBadge(null, false) ?>
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
                            <div class="user-card-detail-value" style="display:flex; flex-direction:column; align-items:flex-end; gap:3px;">
                                <span><?= h($associatedGym ?: '—') ?></span>
                                <?php if ($row['role'] === 'gym_owner'): ?>
                                    <?= $renderGymBadge($row['owner_gym_status'] ?? null, !empty($associatedGym)) ?>
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
                            <form method="post" onsubmit="return confirm('Delete this user? This cannot be undone.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    Delete
                                </button>
                            </form>
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

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-edit-user');
        if (btn) {
            try {
                const userData = JSON.parse(btn.getAttribute('data-user') || '{}');
                editUser(userData);
            } catch (err) {
                console.error('Invalid user data', err);
            }
        }
    });

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
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">First name * <input name="first_name" id="eu_fn" class="form-control" required autocapitalize="words" style="width: 100%; box-sizing: border-box; text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())"></label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Last name * <input name="last_name" id="eu_ln" class="form-control" required autocapitalize="words" style="width: 100%; box-sizing: border-box; text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())"></label>
                    </div>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Email * <input type="email" name="email" id="eu_email" class="form-control" required style="width: 100%; box-sizing: border-box; text-transform: lowercase;" oninput="this.value = this.value.toLowerCase()" onblur="this.value = this.value.trim().toLowerCase()"></label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Mobile Number * <input type="tel" name="phone" id="eu_phone" class="form-control" pattern="[0-9]{11}" maxlength="11" title="Please enter exactly 11 digits" placeholder="09123456789" required style="width: 100%; box-sizing: border-box;"></label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Role *
                        <select name="role" id="eu_role" class="form-control" style="width: 100%; box-sizing: border-box;" onchange="toggleEditTrainerFields(this.value)">
                            ${<?= $isAdmin ? 'true' : 'false' ?> ? `
                                <option value="gym_owner">Gym Owner</option>
                            ` : ''}
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
                const newPass = form.new_password ? form.new_password.value.trim() : '';
                if (newPass !== '') {
                    if (newPass.length < 8 || !/[a-zA-Z]/.test(newPass) || !/[0-9]/.test(newPass)) {
                        Swal.showValidationMessage('New password must be at least 8 characters, with a letter and a number.');
                        return false;
                    }
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

    // ── Live Search for Users Page ───────────────────────────────────────
    const CURRENT_TAB = <?= json_encode($tab) ?>;
    const GYM_FILTER = <?= json_encode($gymFilter) ?>;
    const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
    let CURRENT_CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;

    const userSearchInput = document.getElementById('userSearchInput');
    const userSearchClear = document.getElementById('userSearchClear');
    const userSearchStatus = document.getElementById('userSearchStatus');
    const userCountLabel = document.getElementById('userCountLabel');
    const userTableBody = document.getElementById('userTableBody');
    const userMobileCards = document.getElementById('userMobileCards');
    const userEmptyState = document.getElementById('userEmptyState');
    const userEmptyStateText = document.getElementById('userEmptyStateText');
    const userDesktopTableWrap = document.querySelector('.users-desktop-table');
    const userPagination = document.getElementById('userPagination');

    // Cache initial server-rendered content so clearing search is instantaneous
    const initialTableHtml = userTableBody ? userTableBody.innerHTML : '';
    const initialCardsHtml = userMobileCards ? userMobileCards.innerHTML : '';
    const initialCountText = userCountLabel ? userCountLabel.textContent : '';
    const initialEmptyStateDisplay = userEmptyState ? userEmptyState.style.display : 'none';

    let userSearchDebounceTimer = null;

    function escapeUserHtml(str) {
        if (!str && str !== 0) return '';
        return String(str).replace(/[&<>"']/g, function(m) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[m];
        });
    }

    function clearUserSearch(focusInput = true) {
        if (userSearchInput) userSearchInput.value = '';
        if (userSearchClear) userSearchClear.style.display = 'none';
        if (userSearchStatus) userSearchStatus.style.display = 'none';

        // Restore initial server rendered HTML
        if (userTableBody) userTableBody.innerHTML = initialTableHtml;
        if (userMobileCards) userMobileCards.innerHTML = initialCardsHtml;
        if (userCountLabel) userCountLabel.textContent = initialCountText;
        if (userEmptyState) userEmptyState.style.display = initialEmptyStateDisplay;
        if (userDesktopTableWrap) userDesktopTableWrap.style.display = initialEmptyStateDisplay === 'block' ? 'none' : '';
        if (userMobileCards) userMobileCards.style.display = initialEmptyStateDisplay === 'block' ? 'none' : '';
        if (userPagination) userPagination.style.display = initialEmptyStateDisplay === 'block' ? 'none' : '';

        if (focusInput && userSearchInput) {
            userSearchInput.focus();
        }
    }

    function renderDesktopRow(u, csrfToken) {
        const roleClass = 'badge badge-' + u.role;
        const statusClass = 'badge badge-' + u.status;
        const roleDisplayName = escapeUserHtml(u.role_display);

        let gymBadgeHtml = '';
        if (u.is_gym_owner) {
            if (u.owner_gym_status === 'approved') {
                gymBadgeHtml = '<span class="badge badge-gym-approved" style="font-size: 10px; padding: 1px 6px; letter-spacing: 0.3px;">✓ Verified</span>';
            } else if (u.owner_gym_status === 'rejected') {
                gymBadgeHtml = '<span class="badge badge-gym-rejected" style="font-size: 10px; padding: 1px 6px; letter-spacing: 0.3px;">✕ Rejected</span>';
            } else if (u.owner_gym_status === 'pending') {
                gymBadgeHtml = '<span class="badge badge-gym-pending" style="font-size: 10px; padding: 1px 6px; letter-spacing: 0.3px;">⏳ Pending</span>';
            } else if (!u.associated_gym) {
                gymBadgeHtml = '<span class="badge badge-gym-unsubmitted" style="font-size: 10px; padding: 1px 6px; letter-spacing: 0.3px;">Not Submitted</span>';
            }
        }

        const associatedGymHtml = u.associated_gym 
            ? `<div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start;"><span style="font-weight: 500; color: var(--ink);">${escapeUserHtml(u.associated_gym)}</span>${gymBadgeHtml}</div>`
            : (gymBadgeHtml ? `<div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start;"><span class="muted">—</span>${gymBadgeHtml}</div>` : `<span class="muted">—</span>`);
        const specHtml = u.is_trainer
            ? `<span style="color:var(--ink);">${escapeUserHtml(u.specialization)}</span>`
            : `<span class="muted">—</span>`;
        const scoreHtml = u.is_member
            ? `<strong style="color:var(--lime);">${u.engagement_score}</strong>`
            : `<span class="muted">—</span>`;
        const rawUserJson = escapeUserHtml(JSON.stringify(u.raw_user));

        return `
        <tr class="user-table-row" data-name="${escapeUserHtml(u.full_name.toLowerCase())}" data-email="${escapeUserHtml(u.email.toLowerCase())}" data-phone="${escapeUserHtml(u.phone)}" data-role="${escapeUserHtml(u.role)}" data-id="${u.user_id}">
            <td>
                <div class="user-cell">
                    ${u.avatar_html}
                    <div class="user-cell-info">
                        ${escapeUserHtml(u.full_name)}
                        <small>#${u.user_id}</small>
                    </div>
                </div>
            </td>
            <td style="color:var(--muted)">${escapeUserHtml(u.email)}</td>
            <td><span class="${roleClass}">${roleDisplayName}</span></td>
            ${IS_ADMIN ? `<td>${associatedGymHtml}</td>` : ''}
            ${CURRENT_TAB === 'trainer' ? `<td>${specHtml}</td>` : ''}
            ${CURRENT_TAB === 'member' ? `<td>${scoreHtml}</td>` : ''}
            <td><span class="${statusClass}">${escapeUserHtml(u.status)}</span></td>
            <td style="color:var(--muted);font-size:12px">${escapeUserHtml(u.joined_formatted)}</td>
            <td>
                <div style="display:flex;gap:4px;align-items:center;">
                    <form method="post" class="row-actions" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="${csrfToken}">
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="user_id" value="${u.user_id}">
                        <select name="status" style="width:auto;padding:6px 10px;font-size:12px;margin:0">
                            <option value="active" ${u.status === 'active' ? 'selected' : ''}>active</option>
                            <option value="suspended" ${u.status === 'suspended' ? 'selected' : ''}>suspended</option>
                        </select>
                        <button type="submit" class="btn-sm btn-ghost">Update</button>
                    </form>
                    <button type="button" class="btn btn-secondary btn-edit-user" data-user="${rawUserJson}" style="padding:4px 8px;font-size:12px;margin-left:8px;">Edit</button>
                    ${u.is_member ? `
                        <a href="index.php?page=diet_builder&member_user_id=${u.user_id}&ref=users" class="btn btn-secondary" style="padding:4px 8px;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;gap:4px;" title="Manage Diet Plan">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                            Diet Plan
                        </a>
                    ` : ''}
                    ${u.can_delete ? `
                        <form method="post" style="margin:0;" onsubmit="return confirm('Delete this user? This cannot be undone.');">
                            <input type="hidden" name="csrf_token" value="${csrfToken}">
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="user_id" value="${u.user_id}">
                            <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:12px;">Delete</button>
                        </form>
                    ` : ''}
                </div>
            </td>
        </tr>
        `;
    }

    function renderMobileCard(u, csrfToken) {
        const roleClass = 'badge badge-' + u.role;
        const statusClass = 'badge badge-' + u.status;
        const roleDisplayName = escapeUserHtml(u.role_display);
        const rawUserJson = escapeUserHtml(JSON.stringify(u.raw_user));

        let gymBadgeHtml = '';
        if (u.is_gym_owner) {
            if (u.owner_gym_status === 'approved') {
                gymBadgeHtml = '<span class="badge badge-gym-approved" style="font-size: 10px; padding: 1px 6px; letter-spacing: 0.3px;">✓ Verified</span>';
            } else if (u.owner_gym_status === 'rejected') {
                gymBadgeHtml = '<span class="badge badge-gym-rejected" style="font-size: 10px; padding: 1px 6px; letter-spacing: 0.3px;">✕ Rejected</span>';
            } else if (u.owner_gym_status === 'pending') {
                gymBadgeHtml = '<span class="badge badge-gym-pending" style="font-size: 10px; padding: 1px 6px; letter-spacing: 0.3px;">⏳ Pending</span>';
            } else if (!u.associated_gym) {
                gymBadgeHtml = '<span class="badge badge-gym-unsubmitted" style="font-size: 10px; padding: 1px 6px; letter-spacing: 0.3px;">Not Submitted</span>';
            }
        }

        return `
        <div class="user-card-item" data-name="${escapeUserHtml(u.full_name.toLowerCase())}" data-email="${escapeUserHtml(u.email.toLowerCase())}" data-phone="${escapeUserHtml(u.phone)}" data-role="${escapeUserHtml(u.role)}" data-id="${u.user_id}">
            <div class="user-card-header">
                <div class="user-card-identity">
                    ${u.avatar_html}
                    <div class="user-card-names">
                        <div class="user-card-fullname">${escapeUserHtml(u.full_name)}</div>
                        <div class="user-card-id">#${u.user_id}</div>
                    </div>
                </div>
                <div class="user-card-badges">
                    <span class="${roleClass}">${roleDisplayName}</span>
                    <span class="${statusClass}">${escapeUserHtml(u.status)}</span>
                </div>
            </div>

            <div class="user-card-details">
                <div class="user-card-detail-item">
                    <span class="user-card-detail-label">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        Email
                    </span>
                    <span class="user-card-detail-value email-value" title="${escapeUserHtml(u.email)}">${escapeUserHtml(u.email)}</span>
                </div>

                ${IS_ADMIN && (u.associated_gym || u.is_gym_owner) ? `
                <div class="user-card-detail-item">
                    <span class="user-card-detail-label">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M3 7v14M21 7v14M6 11h4M6 15h4M14 11h4M14 15h4M9 21v-4h6v4M3 7l9-4 9 4"/></svg>
                        Gym / Branch
                    </span>
                    <div class="user-card-detail-value" style="display:flex; flex-direction:column; align-items:flex-end; gap:3px;">
                        <span>${escapeUserHtml(u.associated_gym || '—')}</span>
                        ${gymBadgeHtml}
                    </div>
                </div>
                ` : ''}

                ${u.role === 'trainer' && u.specialization ? `
                <div class="user-card-detail-item">
                    <span class="user-card-detail-label">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18M4 22h16M10 14.66V17c0 .55-.45 1-1 1H7c-.55 0-1-.45-1-1v-2.34M18 14.66V17c0 .55-.45 1-1 1h-2c-.55 0-1-.45-1-1v-2.34M8 2h8a2 2 0 0 1 2 2v7a6 6 0 0 1-12 0V4a2 2 0 0 1 2-2z"/></svg>
                        Specialization
                    </span>
                    <span class="user-card-detail-value">${escapeUserHtml(u.specialization)}</span>
                </div>
                ` : ''}

                ${u.role === 'member' ? `
                <div class="user-card-detail-item">
                    <span class="user-card-detail-label">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Score
                    </span>
                    <span class="user-card-detail-value"><strong style="color:var(--lime);">${u.engagement_score}</strong> / 100</span>
                </div>
                ` : ''}

                <div class="user-card-detail-item">
                    <span class="user-card-detail-label">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Joined
                    </span>
                    <span class="user-card-detail-value">${escapeUserHtml(u.joined_formatted)}</span>
                </div>
            </div>

            <div class="user-card-actions">
                <form method="post" class="user-card-status-form">
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="user_id" value="${u.user_id}">
                    <span class="user-card-status-label">Status:</span>
                    <select name="status">
                        <option value="active" ${u.status === 'active' ? 'selected' : ''}>Active</option>
                        <option value="suspended" ${u.status === 'suspended' ? 'selected' : ''}>Suspended</option>
                    </select>
                    <button type="submit" class="btn-sm btn-ghost">Update</button>
                </form>
                
                <div class="user-card-btn-group">
                    <button type="button" class="btn btn-secondary btn-sm btn-edit-user" data-user="${rawUserJson}">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                        Edit
                    </button>
                    ${u.is_member ? `
                        <a href="index.php?page=diet_builder&member_user_id=${u.user_id}&ref=users" class="btn btn-secondary btn-sm" title="Manage Diet Plan">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                            Diet
                        </a>
                    ` : ''}
                    ${u.can_delete ? `
                    <form method="post" onsubmit="return confirm('Delete this user? This cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="${csrfToken}">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="user_id" value="${u.user_id}">
                        <button type="submit" class="btn btn-danger btn-sm">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            Delete
                        </button>
                    </form>
                    ` : ''}
                </div>
            </div>
        </div>
        `;
    }

    if (userSearchInput) {
        userSearchInput.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();

            // Toggle clear button
            if (userSearchClear) {
                userSearchClear.style.display = query ? 'flex' : 'none';
            }

            // STEP 1: Instant Local Filter (0ms)
            const tableRows = userTableBody ? userTableBody.querySelectorAll('.user-table-row') : [];
            const cardItems = userMobileCards ? userMobileCards.querySelectorAll('.user-card-item') : [];
            let localMatches = 0;

            tableRows.forEach(row => {
                const name = row.getAttribute('data-name') || '';
                const email = row.getAttribute('data-email') || '';
                const phone = row.getAttribute('data-phone') || '';
                const role = row.getAttribute('data-role') || '';
                const id = row.getAttribute('data-id') || '';

                if (!query || name.includes(query) || email.includes(query) || phone.includes(query) || role.includes(query) || id.includes(query)) {
                    row.style.display = '';
                    localMatches++;
                } else {
                    row.style.display = 'none';
                }
            });

            cardItems.forEach(card => {
                const name = card.getAttribute('data-name') || '';
                const email = card.getAttribute('data-email') || '';
                const phone = card.getAttribute('data-phone') || '';
                const role = card.getAttribute('data-role') || '';
                const id = card.getAttribute('data-id') || '';

                if (!query || name.includes(query) || email.includes(query) || phone.includes(query) || role.includes(query) || id.includes(query)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });

            if (query) {
                if (userPagination) userPagination.style.display = 'none';
                if (localMatches > 0) {
                    if (userCountLabel) userCountLabel.textContent = localMatches + ' found';
                    if (userEmptyState) userEmptyState.style.display = 'none';
                    if (userDesktopTableWrap) userDesktopTableWrap.style.display = '';
                    if (userMobileCards) userMobileCards.style.display = '';
                } else {
                    if (userSearchStatus) {
                        userSearchStatus.style.display = 'block';
                        userSearchStatus.textContent = 'Searching database...';
                    }
                }
            } else {
                clearUserSearch(false);
                return;
            }

            // STEP 2: Debounced Server Search (250ms)
            if (userSearchDebounceTimer) clearTimeout(userSearchDebounceTimer);

            userSearchDebounceTimer = setTimeout(() => {
                if (userSearchStatus) {
                    userSearchStatus.style.display = 'block';
                    userSearchStatus.textContent = 'Searching database...';
                }

                const url = `index.php?page=users&action=search_users&q=${encodeURIComponent(query)}&tab=${encodeURIComponent(CURRENT_TAB)}&gym_id=${encodeURIComponent(GYM_FILTER)}`;

                fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(res => res.json())
                .then(data => {
                    // Ensure query hasn't changed in the meantime
                    if (userSearchInput.value.trim().toLowerCase() !== query) return;

                    if (userSearchStatus) userSearchStatus.style.display = 'none';
                    if (data.csrf_token) CURRENT_CSRF_TOKEN = data.csrf_token;

                    const users = data.users || [];
                    const total = data.total || 0;

                    if (userCountLabel) {
                        userCountLabel.textContent = total + ' found';
                    }

                    if (users.length === 0) {
                        if (userTableBody) userTableBody.innerHTML = '';
                        if (userMobileCards) userMobileCards.innerHTML = '';
                        if (userDesktopTableWrap) userDesktopTableWrap.style.display = 'none';
                        if (userMobileCards) userMobileCards.style.display = 'none';
                        if (userEmptyState) {
                            userEmptyState.style.display = 'block';
                            if (userEmptyStateText) {
                                userEmptyStateText.textContent = `No users found matching "${query}". Try a different name, email, phone number, or ID.`;
                            }
                        }
                    } else {
                        if (userEmptyState) userEmptyState.style.display = 'none';
                        if (userDesktopTableWrap) userDesktopTableWrap.style.display = '';
                        if (userMobileCards) userMobileCards.style.display = '';

                        // Render Desktop Rows
                        if (userTableBody) {
                            userTableBody.innerHTML = users.map(u => renderDesktopRow(u, CURRENT_CSRF_TOKEN)).join('');
                        }

                        // Render Mobile Cards
                        if (userMobileCards) {
                            userMobileCards.innerHTML = users.map(u => renderMobileCard(u, CURRENT_CSRF_TOKEN)).join('');
                        }
                    }
                })
                .catch(err => {
                    console.error('User search error:', err);
                    if (userSearchStatus) {
                        userSearchStatus.style.display = 'block';
                        userSearchStatus.textContent = 'Search failed. Please try again.';
                    }
                });
            }, 250);
        });
    }

    // ── Create User Form: Real-time Password Checking & AJAX Submission ───
    const createUserForm = document.getElementById('createUserForm');
    const createUserPass = document.getElementById('new_user_password');
    const createUserHint = document.getElementById('new_user_pass_hint');

    if (createUserPass && createUserHint) {
        createUserPass.addEventListener('input', function() {
            const val = this.value;
            if (!val) {
                createUserHint.style.color = 'var(--muted)';
                createUserHint.textContent = 'Must be at least 8 characters with a letter and a number.';
                return;
            }
            const hasLength = val.length >= 8;
            const hasLetter = /[a-zA-Z]/.test(val);
            const hasNumber = /[0-9]/.test(val);

            if (hasLength && hasLetter && hasNumber) {
                createUserHint.style.color = 'var(--lime, #84cc16)';
                createUserHint.textContent = '✓ Password meets requirements';
            } else {
                const missing = [];
                if (!hasLength) missing.push(`${val.length}/8 characters`);
                if (!hasLetter) missing.push('letter');
                if (!hasNumber) missing.push('number');
                createUserHint.style.color = 'var(--danger, #ef4444)';
                createUserHint.textContent = 'Requires: ' + missing.join(', ');
            }
        });
    }

    if (createUserForm) {
        createUserForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const pass = createUserPass ? createUserPass.value : '';
            if (pass.length < 8 || !/[a-zA-Z]/.test(pass) || !/[0-9]/.test(pass)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Password Requirement',
                    text: 'Password must be at least 8 characters, with a letter and a number, and not be a common password.',
                    confirmButtonColor: 'var(--lime-dark)'
                });
                createUserPass?.focus();
                return;
            }

            const submitBtn = document.getElementById('createUserSubmitBtn') || createUserForm.querySelector('button[type="submit"]') || createUserForm.querySelector('button:not([type="button"])');
            const origBtnText = submitBtn ? submitBtn.innerHTML : 'Create user';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="loader" style="width:14px;height:14px;border:2px solid currentColor;border-bottom-color:transparent;border-radius:50%;display:inline-block;animation:rotation 1s linear infinite;margin-right:8px;vertical-align:-2px;"></span> Creating user...';
            }

            try {
                const formData = new FormData(createUserForm);
                formData.append('ajax', '1');

                const response = await fetch('index.php?page=users', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });

                const data = await response.json();

                if (!data.success) {
                    // KEEP MODAL OPEN, ALL CREDENTIALS INTACT!
                    Swal.fire({
                        icon: 'error',
                        title: 'Unable to Create User',
                        text: data.message || 'An error occurred.',
                        confirmButtonColor: 'var(--lime-dark)'
                    });
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = origBtnText;
                    }
                    return;
                }

                // Success
                document.getElementById('createUserModal')?.close();
                Swal.fire({
                    icon: 'success',
                    title: 'User Created',
                    text: data.message,
                    confirmButtonColor: 'var(--lime-dark)'
                }).then(() => {
                    window.location.reload();
                });

            } catch (err) {
                console.error('Create user fetch error:', err);
                createUserForm.submit();
            }
        });
    }

    // ── Global Custom Role Dropdown Initialization ─────────────────────────
    const roleItems = [
        <?php if ($isAdmin): ?>
        {
            id: 'gym_owner',
            label: 'Gym Owner',
            icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#c084fc" stroke-width="2"><path d="M3 21h18M3 7v14M21 7v14M6 11h3M6 15h3M15 11h3M15 15h3M9 3l3 3 3-3"/></svg>'
        },
        <?php endif; ?>
        {
            id: 'trainer',
            label: 'Trainer',
            icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2"><path d="M6 4v16M18 4v16M2 8h4M18 8h4M2 16h4M18 16h4M6 12h12"/></svg>'
        },
        {
            id: 'member',
            label: 'Member',
            icon: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2dd4bf" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>'
        }
    ];

    const initialRole = <?= json_encode($oldUser['role'] ?? ($isAdmin ? 'gym_owner' : 'trainer')) ?>;

    if (typeof FitDropdown !== 'undefined') {
        window.createUserRoleDropdown = new FitDropdown({
            container: '#roleComboboxWrap',
            name: 'role',
            id: 'new_user_role',
            value: initialRole,
            searchable: false,
            zIndex: 100,
            items: roleItems,
            onChange: function(val, item) {
                toggleTrainerFields(val);
            }
        });
        toggleTrainerFields(initialRole);
    }
    </script>
    <?php
    render_footer();
}
