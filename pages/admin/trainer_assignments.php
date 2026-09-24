<?php
declare(strict_types=1);

function trainer_assignments_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner']);
    if ($user['role'] === 'gym_owner') {
        require_gym_feature('trainers');
    }
    $gymId = null;
    if ($user['role'] === 'gym_owner') {
        $gymId = (int) scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
    }

    // ── AJAX: Live Search for Members / Trainers ─────────────────────────
    if (($_GET['action'] ?? post('action')) === 'search_assign_data') {
        if (ob_get_level()) ob_clean();
        header('Content-Type: application/json');

        $type = (string)($_GET['type'] ?? 'member');
        $q = trim((string)($_GET['q'] ?? post('q') ?? ''));
        $pattern = '%' . $q . '%';

        if ($type === 'trainer') {
            if ($user['role'] === 'platform_admin') {
                $sql = 'SELECT cp.trainer_id, CONCAT(u.first_name, " ", u.last_name, " - ", COALESCE(cp.specialization, "trainer")) AS name, u.first_name, u.last_name, cp.specialization
                        FROM trainer_profiles cp
                        JOIN users u ON u.user_id = cp.user_id
                        WHERE u.status = "active"';
                $params = [];
                if ($q !== '') {
                    $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR cp.specialization LIKE ?)';
                    $params = [$pattern, $pattern, $pattern];
                }
                $sql .= ' ORDER BY u.first_name LIMIT 30';
                $res = query_all($sql, $params);
            } else {
                $sql = 'SELECT cp.trainer_id, CONCAT(u.first_name, " ", u.last_name, " - ", COALESCE(cp.specialization, "trainer")) AS name, u.first_name, u.last_name, cp.specialization
                        FROM trainer_profiles cp
                        JOIN users u ON u.user_id = cp.user_id
                        WHERE u.status = "active" AND cp.gym_id = ?';
                $params = [$gymId];
                if ($q !== '') {
                    $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR cp.specialization LIKE ?)';
                    $params[] = $pattern;
                    $params[] = $pattern;
                    $params[] = $pattern;
                }
                $sql .= ' ORDER BY u.first_name LIMIT 30';
                $res = query_all($sql, $params);
            }
            $items = array_map(function($c) {
                $parts = preg_split('/\s+/', trim($c['first_name'] . ' ' . $c['last_name']));
                $ini = (!empty($parts[0]) ? strtoupper(substr($parts[0], 0, 1)) : '') . (!empty($parts[1]) ? strtoupper(substr($parts[1], 0, 1)) : '');
                return [
                    'id' => (int)$c['trainer_id'],
                    'name' => $c['name'],
                    'initials' => $ini ?: 'T',
                    'specialization' => $c['specialization'] ?? 'Trainer'
                ];
            }, $res);
            echo json_encode(['results' => $items]);
            exit;
        } else {
            // Member search
            if ($user['role'] === 'platform_admin') {
                $sql = 'SELECT u.user_id, u.first_name, u.last_name,
                               IF(EXISTS(SELECT 1 FROM memberships m WHERE m.user_id = u.user_id AND m.status = "active" AND m.end_date >= CURDATE()), 1, 0) as has_plan
                        FROM users u
                        WHERE u.role = "member" AND u.status = "active"';
                $params = [];
                if ($q !== '') {
                    $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, " ", u.last_name) LIKE ?)';
                    $params = [$pattern, $pattern, $pattern];
                }
                $sql .= ' ORDER BY u.first_name LIMIT 30';
                $res = query_all($sql, $params);
            } else {
                $sql = 'SELECT DISTINCT u.user_id, u.first_name, u.last_name,
                               IF(EXISTS(SELECT 1 FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = u.user_id AND m.status = "active" AND m.end_date >= CURDATE() AND mp.gym_id = ?), 1, 0) as has_plan
                        FROM users u
                        WHERE u.role = "member" AND u.status = "active" AND (
                            EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?) OR
                            EXISTS (SELECT 1 FROM memberships m2 JOIN membership_plans mp2 ON mp2.plan_id = m2.plan_id WHERE m2.user_id = u.user_id AND mp2.gym_id = ?)
                        )';
                $params = [$gymId, $gymId, $gymId];
                if ($q !== '') {
                    $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, " ", u.last_name) LIKE ?)';
                    $params[] = $pattern;
                    $params[] = $pattern;
                    $params[] = $pattern;
                }
                $sql .= ' ORDER BY u.first_name LIMIT 30';
                $res = query_all($sql, $params);
            }
            $items = array_map(function($m) {
                $parts = preg_split('/\s+/', trim($m['first_name'] . ' ' . $m['last_name']));
                $ini = (!empty($parts[0]) ? strtoupper(substr($parts[0], 0, 1)) : '') . (!empty($parts[1]) ? strtoupper(substr($parts[1], 0, 1)) : '');
                $fullName = trim($m['first_name'] . ' ' . $m['last_name']);
                return [
                    'id' => (int)$m['user_id'],
                    'name' => $fullName . ($m['has_plan'] ? ' (Has Plan)' : ' (No Plan)'),
                    'full_name' => $fullName,
                    'initials' => $ini ?: 'M',
                    'has_plan' => (bool)$m['has_plan']
                ];
            }, $res);
            echo json_encode(['results' => $items]);
            exit;
        }
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (post('action') === 'create_trainer') {
            $email = trim((string) post('email'));
            if (scalar('SELECT user_id FROM users WHERE email = ?', [$email])) {
                flash('A user with that email already exists.', 'danger');
            } else {
                $plainPassword = (string) post('password');
                $firstName = mb_convert_case(trim((string) post('first_name')), MB_CASE_TITLE, 'UTF-8');
                $lastName  = mb_convert_case(trim((string) post('last_name')), MB_CASE_TITLE, 'UTF-8');

                db()->prepare('INSERT INTO users (role, first_name, last_name, email, password_hash, status, email_verified_at) VALUES ("trainer", ?, ?, ?, ?, "active", NOW())')
                    ->execute([$firstName, $lastName, $email, password_hash($plainPassword, PASSWORD_DEFAULT)]);
                $newUserId = (int) db()->lastInsertId();
                db()->prepare('INSERT INTO trainer_profiles (user_id, specialization, gym_id) VALUES (?, ?, ?)')
                    ->execute([$newUserId, post('specialization'), $gymId]);
                flash('Trainer created successfully.', 'success');
            }
        } elseif (post('action') === 'end') {
            db()->prepare('UPDATE trainer_assignments SET status = "ended", ended_date = CURDATE() WHERE assignment_id = ?')->execute([post('assignment_id')]);
            audit_log($user['user_id'], 'end', 'trainer_assignment', (string) post('assignment_id'));
            flash('Trainer assignment ended.');
        } elseif (post('action') === 'forward') {
            db()->prepare('UPDATE trainer_assignments SET status = "pending_trainer" WHERE assignment_id = ?')->execute([post('assignment_id')]);
            $stmt = db()->prepare('SELECT member_user_id, trainer_id FROM trainer_assignments WHERE assignment_id = ?');
            $stmt->execute([post('assignment_id')]);
            $assignment = $stmt->fetch();
            if ($assignment) {
                $trainerUserId = scalar('SELECT user_id FROM trainer_profiles WHERE trainer_id = ?', [$assignment['trainer_id']]);
                $memberName = scalar('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE user_id = ?', [$assignment['member_user_id']]);
                if ($trainerUserId && $memberName) {
                    notify_user((int)$trainerUserId, 'system', 'New Appointment Request', $memberName . ' has requested an appointment with you.');
                }
            }
            audit_log($user['user_id'], 'forward', 'trainer_assignment', (string) post('assignment_id'));
            flash('Request forwarded to trainer.');
        } elseif (post('action') === 'reject_admin') {
            db()->prepare('UPDATE trainer_assignments SET status = "rejected", rejection_reason = "Rejected by admin" WHERE assignment_id = ?')->execute([post('assignment_id')]);
            $stmt = db()->prepare('SELECT member_user_id FROM trainer_assignments WHERE assignment_id = ?');
            $stmt->execute([post('assignment_id')]);
            $assignment = $stmt->fetch();
            if ($assignment) {
                notify_user((int)$assignment['member_user_id'], 'system', 'Appointment Request Rejected', 'Your trainer appointment request was rejected by the admin.');
            }
            audit_log($user['user_id'], 'reject', 'trainer_assignment', (string) post('assignment_id'));
            flash('Request rejected.');
        } else {
            $trainerId = (int) post('trainer_id');
            $memberUserId = (int) post('member_user_id');
            $assignedDate = post('assigned_date');
            
            $hasActivePlan = (bool) scalar("SELECT 1 FROM memberships WHERE user_id = ? AND status = 'active' AND end_date >= CURDATE()", [$memberUserId]);
            $endedDate = $hasActivePlan ? null : $assignedDate;

            db()->prepare('INSERT INTO trainer_assignments (trainer_id, member_user_id, assigned_date, ended_date, status, assigned_by) VALUES (?, ?, ?, ?, "active", ?)')->execute([$trainerId, $memberUserId, $assignedDate, $endedDate, $user['user_id']]);
            
            grant_retroactive_commission($memberUserId);

            $names = query_all(
                'SELECT CONCAT(tu.first_name, " ", tu.last_name) AS trainer_name, tu.user_id AS trainer_user_id,
                        CONCAT(mu.first_name, " ", mu.last_name) AS member_name
                 FROM trainer_profiles tp
                 JOIN users tu ON tu.user_id = tp.user_id
                 JOIN users mu ON mu.user_id = ?
                 WHERE tp.trainer_id = ?',
                 [$memberUserId, $trainerId]
            );
            if ($names) {
                $pair = $names[0];
                notify_user($memberUserId, 'system', 'Trainer assigned', 'You have been assigned to ' . $pair['trainer_name'] . '.');
                notify_user((int) $pair['trainer_user_id'], 'system', 'New client assigned', $pair['member_name'] . ' has been assigned to you.');
            }

            audit_log($user['user_id'], 'create', 'trainer_assignment', (string) db()->lastInsertId(), json_encode(['trainer_id' => $trainerId, 'member_user_id' => $memberUserId]));
            flash('Trainer assigned to member.');
        }
        redirect('trainer_assignments');
    }

    // Automatically end trainer assignments if the member's active membership has expired
    db()->query('UPDATE trainer_assignments ca
                 JOIN memberships m ON m.user_id = ca.member_user_id
                 SET ca.status = "ended", ca.ended_date = m.end_date
                 WHERE ca.status = "active" AND m.end_date < CURDATE()');

    if ($user['role'] === 'platform_admin') {
        $coaches = db()->query('SELECT cp.trainer_id, CONCAT(u.first_name, " ", u.last_name, " - ", COALESCE(cp.specialization, "trainer")) AS name FROM trainer_profiles cp JOIN users u ON u.user_id = cp.user_id WHERE u.status = "active" ORDER BY u.first_name')->fetchAll();
        $members = db()->query('SELECT u.user_id, CONCAT(u.first_name, " ", u.last_name, IF(EXISTS(SELECT 1 FROM memberships m WHERE m.user_id = u.user_id AND m.status = "active" AND m.end_date >= CURDATE()), " (Has Plan)", " (No Plan - 1 Day)")) AS name FROM users u WHERE u.role = "member" AND u.status = "active" ORDER BY u.first_name')->fetchAll();
        $allGymMembers = $members;
        $rows = db()->query('SELECT ca.*, (SELECT end_date FROM memberships WHERE user_id = ca.member_user_id AND end_date >= CURDATE() ORDER BY end_date DESC LIMIT 1) as membership_end_date, CONCAT(cu.first_name, " ", cu.last_name) AS trainer, CONCAT(mu.first_name, " ", mu.last_name) AS member, cu.first_name AS coach_fn, cu.last_name AS coach_ln, cu.profile_picture AS coach_picture, mu.first_name AS member_fn, mu.last_name AS member_ln, mu.profile_picture AS member_picture FROM trainer_assignments ca JOIN trainer_profiles cp ON cp.trainer_id = ca.trainer_id JOIN users cu ON cu.user_id = cp.user_id JOIN users mu ON mu.user_id = ca.member_user_id ORDER BY CASE ca.status WHEN "active" THEN 1 WHEN "pending_admin" THEN 2 WHEN "pending_trainer" THEN 3 WHEN "ended" THEN 4 WHEN "rejected" THEN 5 ELSE 6 END, ca.assigned_date DESC')->fetchAll();
    } else {
        $coaches = db()->query('SELECT cp.trainer_id, CONCAT(u.first_name, " ", u.last_name, " - ", COALESCE(cp.specialization, "trainer")) AS name FROM trainer_profiles cp JOIN users u ON u.user_id = cp.user_id WHERE u.status = "active" AND cp.gym_id = ' . $gymId . ' ORDER BY u.first_name')->fetchAll();
        // Members who have active plan at this gym
        $members = db()->query('SELECT DISTINCT u.user_id, CONCAT(u.first_name, " ", u.last_name, " (Has Plan)") AS name FROM users u JOIN memberships m ON m.user_id = u.user_id JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE u.role = "member" AND u.status = "active" AND m.status="active" AND m.end_date >= CURDATE() AND mp.gym_id = ' . $gymId . ' ORDER BY name')->fetchAll();
        // All members in this gym for direct diet plan access
        $allGymMembers = db()->query('
            SELECT DISTINCT u.user_id, CONCAT(u.first_name, " ", u.last_name) AS name 
            FROM users u 
            WHERE u.role = "member" AND u.status = "active" AND (
                EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ' . (int)$gymId . ') OR
                EXISTS (SELECT 1 FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = u.user_id AND mp.gym_id = ' . (int)$gymId . ')
            )
            ORDER BY name ASC
        ')->fetchAll();
        if (empty($allGymMembers)) {
            $allGymMembers = db()->query('SELECT user_id, CONCAT(first_name, " ", last_name) AS name FROM users WHERE role = "member" AND status = "active" ORDER BY name ASC')->fetchAll();
        }
        $rows = db()->query('SELECT ca.*, (SELECT end_date FROM memberships WHERE user_id = ca.member_user_id AND end_date >= CURDATE() ORDER BY end_date DESC LIMIT 1) as membership_end_date, CONCAT(cu.first_name, " ", cu.last_name) AS trainer, CONCAT(mu.first_name, " ", mu.last_name) AS member, cu.first_name AS coach_fn, cu.last_name AS coach_ln, cu.profile_picture AS coach_picture, mu.first_name AS member_fn, mu.last_name AS member_ln, mu.profile_picture AS member_picture FROM trainer_assignments ca JOIN trainer_profiles cp ON cp.trainer_id = ca.trainer_id JOIN users cu ON cu.user_id = cp.user_id JOIN users mu ON mu.user_id = ca.member_user_id WHERE cp.gym_id = ' . $gymId . ' ORDER BY CASE ca.status WHEN "active" THEN 1 WHEN "pending_admin" THEN 2 WHEN "pending_trainer" THEN 3 WHEN "ended" THEN 4 WHEN "rejected" THEN 5 ELSE 6 END, ca.assigned_date DESC')->fetchAll();
    }

    render_header('Trainer Assignments', $user);
    ?>
    <style>
    /* Responsive Header Action Buttons */
    .assignments-header-actions {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
    }

    @media (max-width: 768px) {
        .assignments-header-actions {
            width: 100%;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .assignments-header-actions .btn-primary-action {
            grid-column: 1 / -1;
            width: 100%;
            justify-content: center;
            height: 38px;
        }
        .assignments-header-actions .btn-sub-action {
            width: 100%;
            justify-content: center;
            height: 38px;
        }
    }

    /* Desktop vs Mobile Toggle */
    .assignments-desktop-table {
        display: block;
    }
    .assignments-mobile-cards {
        display: none;
    }

    @media (max-width: 768px) {
        .assignments-desktop-table {
            display: none !important;
        }
        .assignments-mobile-cards {
            display: flex !important;
            flex-direction: column;
            gap: 12px;
        }
    }

    /* Mobile Assignment Card Styles */
    .assignment-card-item {
        background: color-mix(in srgb, var(--panel-soft) 45%, transparent);
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 14px 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        transition: all 0.2s ease;
    }
    html[data-theme="light"] .assignment-card-item,
    [data-theme="light"] .assignment-card-item {
        background: #ffffff;
        border-color: #e2e8f0;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    }
    .assignment-card-pairing {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--line);
    }
    .assignment-person {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
        flex: 1;
    }
    .assignment-person-info {
        min-width: 0;
        display: flex;
        flex-direction: column;
    }
    .assignment-person-role {
        font-size: 10.5px;
        text-transform: uppercase;
        font-weight: 700;
        letter-spacing: 0.5px;
        color: var(--muted);
    }
    .assignment-person-name {
        font-size: 13px;
        font-weight: 600;
        color: var(--ink);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .assignment-card-connector {
        color: var(--muted);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .assignment-card-meta {
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 12px;
        color: var(--muted);
        flex-wrap: wrap;
        gap: 6px;
    }
    .assignment-card-actions {
        display: flex;
        gap: 8px;
        align-items: center;
        padding-top: 4px;
    }
    .assignment-card-actions .btn-sm,
    .assignment-card-actions a.btn-sm,
    .assignment-card-actions form {
        flex: 1;
    }
    .assignment-card-actions form button {
        width: 100%;
        justify-content: center;
    }
    </style>

    <section class="panel">
        <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 16px; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 220px;">
                <h1 style="margin: 0 0 4px; font-size: 22px;">Trainer Assignments</h1>
                <p style="margin: 0; color: var(--muted); font-size: 13px;">Link coaches to members and manage active pairings.</p>
            </div>
            <div class="assignments-header-actions">
                <button onclick="addAssignment()" class="btn btn-primary-action" style="background: var(--lime); color: var(--bg); font-weight: 700; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; padding: 0 14px; height: 36px; font-size: 12.5px; border-radius: 8px; border: none; cursor: pointer;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                    <span>New Assignment</span>
                </button>
                <button onclick="openDietPlanSelector()" class="btn btn-secondary btn-sub-action" style="white-space: nowrap; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; padding: 0 12px; height: 36px; font-size: 12.5px; border-radius: 8px;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                    <span>Diet Plan</span>
                </button>
                <button onclick="addTrainer()" class="btn btn-secondary btn-sub-action" style="white-space: nowrap; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; padding: 0 12px; height: 36px; font-size: 12.5px; border-radius: 8px;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                    <span>Add Trainer</span>
                </button>
            </div>
        </div>

        <!-- Live Search Toolbar for Assignments -->
        <div style="margin-bottom: 16px; display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap;">
            <div style="position: relative; flex: 1; min-width: 240px; max-width: 440px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"
                     style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--muted); pointer-events: none;">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text"
                       id="assignmentSearchInput"
                       placeholder="Search assignments by trainer, member, or status..."
                       autocomplete="off"
                       style="width: 100%; box-sizing: border-box; padding: 9px 36px 9px 36px; border-radius: 8px; border: 1px solid var(--line); background: var(--panel); color: var(--ink); font-size: 0.9rem; outline: none; transition: border-color 0.2s;"
                       onfocus="this.style.borderColor='var(--lime)';"
                       onblur="this.style.borderColor='var(--line)';"
                >
                <button type="button"
                        id="assignmentSearchClear"
                        onclick="clearAssignmentSearch()"
                        title="Clear search"
                        style="display: none; position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--muted); cursor: pointer; padding: 2px 6px; border-radius: 50%; font-size: 14px; line-height: 1;">
                    ✕
                </button>
            </div>
            <div>
                <p class="section-label" id="assignmentCountLabel" style="margin: 0; border: none; padding: 0;"><?= count($rows) ?> assignments</p>
            </div>
        </div>

        <!-- Empty Search State -->
        <div id="assignmentEmptyState" class="empty-state" style="display: none; padding: 32px 20px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.5; margin-bottom: 8px;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <p id="assignmentEmptyStateText" style="margin: 0;">No assignments found matching your search.</p>
        </div>

        <?php if (!$rows): ?>
            <div class="empty-state" style="padding: 40px 20px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                <p style="margin: 10px 0 0;">No active trainer-member assignments yet.<br>Use the action buttons above to create an assignment or manage meal plans.</p>
            </div>
        <?php else: ?>
        <div class="assignments-desktop-table table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Trainer</th>
                        <th>Member</th>
                        <th>Assigned</th>
                        <th>Ended</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="assignmentTableBody">
                <?php foreach ($rows as $row):
                    $statusClass = 'badge badge-' . str_replace(' ', '_', $row['status']);
                    $coachData = ['first_name' => $row['coach_fn'], 'last_name' => $row['coach_ln'], 'profile_picture' => $row['coach_picture']];
                    $memberData = ['first_name' => $row['member_fn'], 'last_name' => $row['member_ln'], 'profile_picture' => $row['member_picture']];
                ?>
                    <tr class="assignment-row" data-coach="<?= strtolower(h($row['trainer'])) ?>" data-member="<?= strtolower(h($row['member'])) ?>" data-status="<?= strtolower(h($row['status'])) ?>">
                        <td>
                            <div class="user-cell">
                                <?= render_avatar($coachData) ?>
                                <span><?= h($row['trainer']) ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="user-cell">
                                <?= render_avatar($memberData) ?>
                                <span><?= h($row['member']) ?></span>
                            </div>
                        </td>
                        <td><?= h(date('M j, Y', strtotime($row['assigned_date']))) ?></td>
                        <td>
                            <?php if ($row['ended_date']): ?>
                                <?= h(date('M j, Y', strtotime($row['ended_date']))) ?>
                            <?php elseif ($row['status'] === 'active' && $row['membership_end_date']): ?>
                                <span style="color:var(--muted); font-size: 11px;">Expires<br><?= h(date('M j, Y', strtotime($row['membership_end_date']))) ?></span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="<?= $statusClass ?>"><?= h($row['status']) ?></span>
                            <?php if ($row['status'] === 'rejected' && $row['rejection_reason']): ?>
                                <div style="font-size: 11px; color: var(--muted); margin-top: 4px;">Reason: <?= h($row['rejection_reason']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <?php if ($row['status'] === 'active'): ?>
                                        <a href="index.php?page=diet_builder&member_user_id=<?= (int)$row['member_user_id'] ?>&ref=trainer_assignments" class="btn-sm btn-ghost" style="text-decoration: none; display: inline-flex; align-items: center; gap: 4px; border: 1px solid var(--line); font-weight: 600;" title="Edit Member Meal Plan">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                            Diet Plan
                                        </a>
                                        <?php
                                            $confirmTitle = "End Assignment?";
                                            $confirmHtml = "Are you sure you want to end this trainer assignment?";
                                            $btnText = "Yes, end it";
                                            
                                            if ($row['membership_end_date'] && strtotime($row['membership_end_date']) > time()) {
                                                $exp = date('M j, Y', strtotime($row['membership_end_date']));
                                                $confirmHtml = "This assignment is officially scheduled to end on <strong>$exp</strong>.<br><br>Are you sure you want to end it early?";
                                                $btnText = "Yes, end it early";
                                            }
                                        ?>
                                        <form method="post" onsubmit="event.preventDefault(); Swal.fire({title: '<?= $confirmTitle ?>', html: '<?= addslashes($confirmHtml) ?>', icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444', confirmButtonText: '<?= $btnText ?>'}).then((result) => { if (result.isConfirmed) { this.submit(); } });">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="end">
                                            <input type="hidden" name="assignment_id" value="<?= (int) $row['assignment_id'] ?>">
                                            <button class="btn-sm btn-danger">End</button>
                                        </form>
                                    <?php elseif ($row['status'] === 'pending_admin'): ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="forward">
                                        <input type="hidden" name="assignment_id" value="<?= (int) $row['assignment_id'] ?>">
                                        <button class="btn-sm" style="background: var(--lime); color: var(--bg);">Forward</button>
                                    </form>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reject_admin">
                                        <input type="hidden" name="assignment_id" value="<?= (int) $row['assignment_id'] ?>">
                                        <button class="btn-sm btn-danger">Reject</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="assignmentMobileCards" class="assignments-mobile-cards">
        <?php foreach ($rows as $row):
            $statusClass = 'badge badge-' . str_replace(' ', '_', $row['status']);
            $coachData = ['first_name' => $row['coach_fn'], 'last_name' => $row['coach_ln'], 'profile_picture' => $row['coach_picture']];
            $memberData = ['first_name' => $row['member_fn'], 'last_name' => $row['member_ln'], 'profile_picture' => $row['member_picture']];
        ?>
            <div class="assignment-card-item" data-coach="<?= strtolower(h($row['trainer'])) ?>" data-member="<?= strtolower(h($row['member'])) ?>" data-status="<?= strtolower(h($row['status'])) ?>">
                <div class="assignment-card-pairing">
                    <div class="assignment-person">
                        <?= render_avatar($coachData) ?>
                        <div class="assignment-person-info">
                            <span class="assignment-person-role">Trainer</span>
                            <span class="assignment-person-name"><?= h($row['trainer']) ?></span>
                        </div>
                    </div>
                    <div class="assignment-card-connector">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    </div>
                    <div class="assignment-person" style="justify-content: flex-end; text-align: right;">
                        <div class="assignment-person-info" style="align-items: flex-end;">
                            <span class="assignment-person-role">Member</span>
                            <span class="assignment-person-name"><?= h($row['member']) ?></span>
                        </div>
                        <?= render_avatar($memberData) ?>
                    </div>
                </div>

                <div class="assignment-card-meta">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span class="<?= $statusClass ?>"><?= h($row['status']) ?></span>
                        <span><?= h(date('M j, Y', strtotime($row['assigned_date']))) ?></span>
                    </div>
                    <div>
                        <?php if ($row['ended_date']): ?>
                            <span>Ended: <?= h(date('M j, Y', strtotime($row['ended_date']))) ?></span>
                        <?php elseif ($row['status'] === 'active' && $row['membership_end_date']): ?>
                            <span style="font-size: 11px;">Expires: <?= h(date('M j, Y', strtotime($row['membership_end_date']))) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($row['status'] === 'rejected' && $row['rejection_reason']): ?>
                    <div style="font-size: 11.5px; color: var(--muted); background: rgba(239, 68, 68, 0.08); border-left: 3px solid #ef4444; padding: 6px 10px; border-radius: 4px;">
                        <strong>Reason:</strong> <?= h($row['rejection_reason']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($row['status'] === 'active' || $row['status'] === 'pending_admin'): ?>
                <div class="assignment-card-actions">
                    <?php if ($row['status'] === 'active'): ?>
                        <a href="index.php?page=diet_builder&member_user_id=<?= (int)$row['member_user_id'] ?>&ref=trainer_assignments" class="btn-sm btn-ghost" style="text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; border: 1px solid var(--line); font-weight: 600; height: 34px;" title="Edit Member Meal Plan">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                            <span>Diet Plan</span>
                        </a>
                        <?php
                            $confirmTitle = "End Assignment?";
                            $confirmHtml = "Are you sure you want to end this trainer assignment?";
                            $btnText = "Yes, end it";
                            
                            if ($row['membership_end_date'] && strtotime($row['membership_end_date']) > time()) {
                                $exp = date('M j, Y', strtotime($row['membership_end_date']));
                                $confirmHtml = "This assignment is officially scheduled to end on <strong>$exp</strong>.<br><br>Are you sure you want to end it early?";
                                $btnText = "Yes, end it early";
                            }
                        ?>
                        <form method="post" onsubmit="event.preventDefault(); Swal.fire({title: '<?= $confirmTitle ?>', html: '<?= addslashes($confirmHtml) ?>', icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444', confirmButtonText: '<?= $btnText ?>'}).then((result) => { if (result.isConfirmed) { this.submit(); } });">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="end">
                            <input type="hidden" name="assignment_id" value="<?= (int) $row['assignment_id'] ?>">
                            <button class="btn-sm btn-danger" style="height: 34px; width: 100%;">End Assignment</button>
                        </form>
                    <?php elseif ($row['status'] === 'pending_admin'): ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="forward">
                            <input type="hidden" name="assignment_id" value="<?= (int) $row['assignment_id'] ?>">
                            <button class="btn-sm" style="background: var(--lime); color: var(--bg); height: 34px; width: 100%; font-weight: 700;">Forward</button>
                        </form>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reject_admin">
                            <input type="hidden" name="assignment_id" value="<?= (int) $row['assignment_id'] ?>">
                            <button class="btn-sm btn-danger" style="height: 34px; width: 100%;">Reject</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
    
    <script>
    function addTrainer() {
        Swal.fire({
            title: 'Add Trainer',
            html: `
                <form id="addTrainerForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_trainer">
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">First Name *
                        <input name="first_name" class="form-control" style="width: 100%; box-sizing: border-box; text-transform: capitalize;" autocapitalize="words" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())" required>
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Last Name *
                        <input name="last_name" class="form-control" style="width: 100%; box-sizing: border-box; text-transform: capitalize;" autocapitalize="words" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())" required>
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Email *
                        <input type="email" name="email" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Password *
                        <input type="password" name="password" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Specialization *
                        <input name="specialization" class="form-control" placeholder="e.g. Strength & Conditioning" style="width: 100%; box-sizing: border-box;" required>
                    </label>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Create Trainer',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('addTrainerForm');
                if (!form.first_name.value || !form.last_name.value || !form.email.value || !form.password.value || !form.specialization.value) {
                    Swal.showValidationMessage('Please fill all required fields');
                    return false;
                }
                form.submit();
            }
        });
    }

    function addAssignment() {
        Swal.fire({
            title: 'New Assignment',
            width: '460px',
            html: `
                <form id="addAssignmentForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Trainer *
                        <select name="trainer_id" class="form-control" style="width: 100%; box-sizing: border-box; background-color: #1a2230; color: #ffffff; border: 1px solid #334155; padding: 10px 12px; border-radius: 8px;" required>
                            <option value="" style="background-color: #1a2230; color: #ffffff;">Select Trainer...</option>
                            <?php foreach ($coaches as $trainer): ?>
                                <option value="<?= (int) $trainer['trainer_id'] ?>" style="background-color: #1a2230; color: #ffffff;"><?= h($trainer['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Member *
                        <select name="member_user_id" class="form-control" style="width: 100%; box-sizing: border-box; background-color: #1a2230; color: #ffffff; border: 1px solid #334155; padding: 10px 12px; border-radius: 8px;" required>
                            <option value="" style="background-color: #1a2230; color: #ffffff;">Select Member...</option>
                            <?php foreach ($members as $member): ?>
                                <option value="<?= (int) $member['user_id'] ?>" style="background-color: #1a2230; color: #ffffff;"><?= h($member['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Assigned date *
                        <input type="date" name="assigned_date" class="form-control" value="<?= h(date('Y-m-d')) ?>" style="width: 100%; box-sizing: border-box; background-color: #1a2230; color: #ffffff; border: 1px solid #334155; padding: 10px 12px; border-radius: 8px;" required>
                    </label>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Assign',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('addAssignmentForm');
                if (!form.trainer_id.value || !form.member_user_id.value || !form.assigned_date.value) {
                    Swal.showValidationMessage('Please fill all required fields');
                    return false;
                }
                form.submit();
            }
        });
    }

    <?php
    $coachesList = array_values(array_map(function($c) {
        $parts = preg_split('/\s+/', trim($c['name']));
        $initials = '';
        if (!empty($parts[0])) $initials .= strtoupper(substr($parts[0], 0, 1));
        if (count($parts) > 1 && !empty($parts[1])) $initials .= strtoupper(substr($parts[1], 0, 1));
        return [
            'id' => (int) $c['trainer_id'],
            'name' => $c['name'],
            'initials' => $initials ?: 'T'
        ];
    }, $coaches));

    $assignMembersList = array_values(array_map(function($m) {
        $parts = preg_split('/\s+/', trim($m['name']));
        $initials = '';
        if (!empty($parts[0])) $initials .= strtoupper(substr($parts[0], 0, 1));
        if (count($parts) > 1 && !empty($parts[1])) $initials .= strtoupper(substr($parts[1], 0, 1));
        $hasPlan = str_contains($m['name'], '(Has Plan)');
        return [
            'id' => (int) $m['user_id'],
            'name' => $m['name'],
            'initials' => $initials ?: 'M',
            'has_plan' => $hasPlan
        ];
    }, $members));

    $dietMembersList = array_values(array_map(function($m) {
        $parts = preg_split('/\s+/', trim($m['name']));
        $initials = '';
        if (!empty($parts[0])) $initials .= strtoupper(substr($parts[0], 0, 1));
        if (count($parts) > 1 && !empty($parts[count($parts)-1])) {
            $initials .= strtoupper(substr($parts[count($parts)-1], 0, 1));
        }
        return [
            'id' => (int)$m['user_id'],
            'name' => $m['name'],
            'initials' => $initials ?: 'M'
        ];
    }, $allGymMembers));
    ?>

    const ftCoachesData = <?= json_encode($coachesList) ?>;
    const ftMembersData = <?= json_encode($assignMembersList) ?>;
    let ftDietMembers = <?= json_encode($dietMembersList) ?>;

    function addAssignment() {
        let selectedTrainerId = null;
        let selectedMemberId = null;

        Swal.fire({
            title: 'New Assignment',
            width: '460px',
            html: `
                <form id="addAssignmentForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 15px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="trainer_id" id="na_trainer_id" value="">
                    <input type="hidden" name="member_user_id" id="na_member_id" value="">
                    
                    <!-- Trainer Searchable Combobox -->
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Trainer *</label>
                        <div style="position: relative; width: 100%;">
                            <div id="naTrainerTrigger" style="width: 100%; box-sizing: border-box; padding: 10px 14px; border-radius: 8px; font-size: 14px; background: #1a2230; color: #ffffff; border: 1px solid #334155; display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none;">
                                <span id="naTrainerText" style="color: #94a3b8; display: flex; align-items: center; gap: 8px;">Select Trainer...</span>
                                <svg id="naTrainerChevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" style="transition: transform 0.2s;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                            </div>
                            <div id="naTrainerMenu" style="display: none; position: absolute; left: 0; right: 0; margin-top: 6px; background: #161f30; border: 1px solid #334155; border-radius: 8px; max-height: 220px; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.6); z-index: 1050;">
                                <div style="padding: 8px; border-bottom: 1px solid #283548; background: #131b28; position: sticky; top: 0; z-index: 2;">
                                    <input type="text" id="naTrainerSearch" placeholder="Search trainer..." autocomplete="off" style="width: 100%; box-sizing: border-box; padding: 7px 10px; background: #1a2230; border: 1px solid #334155; border-radius: 6px; color: #ffffff; font-size: 13px; outline: none;">
                                </div>
                                <div id="naTrainerList" style="padding: 4px;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Member Searchable Combobox -->
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Member *</label>
                        <div style="position: relative; width: 100%;">
                            <div id="naMemberTrigger" style="width: 100%; box-sizing: border-box; padding: 10px 14px; border-radius: 8px; font-size: 14px; background: #1a2230; color: #ffffff; border: 1px solid #334155; display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none;">
                                <span id="naMemberText" style="color: #94a3b8; display: flex; align-items: center; gap: 8px;">Select Member...</span>
                                <svg id="naMemberChevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" style="transition: transform 0.2s;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                            </div>
                            <div id="naMemberMenu" style="display: none; position: absolute; left: 0; right: 0; margin-top: 6px; background: #161f30; border: 1px solid #334155; border-radius: 8px; max-height: 220px; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.6); z-index: 1050;">
                                <div style="padding: 8px; border-bottom: 1px solid #283548; background: #131b28; position: sticky; top: 0; z-index: 2;">
                                    <input type="text" id="naMemberSearch" placeholder="Search member..." autocomplete="off" style="width: 100%; box-sizing: border-box; padding: 7px 10px; background: #1a2230; border: 1px solid #334155; border-radius: 6px; color: #ffffff; font-size: 13px; outline: none;">
                                </div>
                                <div id="naMemberList" style="padding: 4px;"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Assigned date *</label>
                        <input type="date" name="assigned_date" class="form-control" value="<?= h(date('Y-m-d')) ?>" style="width: 100%; box-sizing: border-box; background-color: #1a2230; color: #ffffff; border: 1px solid #334155; padding: 10px 12px; border-radius: 8px; font-size: 13.5px;" required>
                    </div>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Assign',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            didOpen: () => {
                // Trainer Combobox Logic
                const tTrigger = document.getElementById('naTrainerTrigger');
                const tMenu = document.getElementById('naTrainerMenu');
                const tChevron = document.getElementById('naTrainerChevron');
                const tSearch = document.getElementById('naTrainerSearch');
                const tList = document.getElementById('naTrainerList');
                const tHidden = document.getElementById('na_trainer_id');
                const tText = document.getElementById('naTrainerText');

                function renderTrainers(query = '') {
                    const q = query.trim().toLowerCase();
                    const filtered = ftCoachesData.filter(c => c.name.toLowerCase().includes(q));
                    if (filtered.length === 0) {
                        tList.innerHTML = '<div style="padding: 12px; text-align: center; color: var(--muted); font-size: 13px;">No trainers found</div>';
                        return;
                    }
                    tList.innerHTML = filtered.map(c => `
                        <div class="na-trainer-item" data-id="${c.id}" data-name="${encodeURIComponent(c.name)}" data-ini="${c.initials}"
                             style="padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; border-radius: 6px; cursor: pointer; margin-bottom: 2px; transition: background 0.15s; background: ${selectedTrainerId === c.id ? 'rgba(132, 204, 22, 0.15)' : 'transparent'};">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="width: 28px; height: 28px; border-radius: 50%; background: #223049; color: var(--lime); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; border: 1px solid rgba(132, 204, 22, 0.3);">
                                    ${c.initials}
                                </div>
                                <span style="color: #f1f5f9; font-size: 13px; font-weight: 500;">${c.name}</span>
                            </div>
                            ${selectedTrainerId === c.id ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}
                        </div>
                    `).join('');

                    tList.querySelectorAll('.na-trainer-item').forEach(el => {
                        el.addEventListener('mouseenter', () => { if (parseInt(el.getAttribute('data-id'), 10) !== selectedTrainerId) el.style.background = '#253349'; });
                        el.addEventListener('mouseleave', () => { if (parseInt(el.getAttribute('data-id'), 10) !== selectedTrainerId) el.style.background = 'transparent'; });
                        el.addEventListener('click', () => {
                            selectedTrainerId = parseInt(el.getAttribute('data-id'), 10);
                            tHidden.value = selectedTrainerId;
                            const name = decodeURIComponent(el.getAttribute('data-name'));
                            const ini = el.getAttribute('data-ini');
                            tText.innerHTML = `
                                <span style="width: 22px; height: 22px; border-radius: 50%; background: #223049; color: var(--lime); display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700;">${ini}</span>
                                <span style="color: #ffffff; font-weight: 600;">${name}</span>
                            `;
                            tMenu.style.display = 'none';
                            tChevron.style.transform = 'rotate(0deg)';
                            tTrigger.style.borderColor = 'var(--lime)';
                        });
                    });
                }

                renderTrainers();

                tTrigger.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isOpen = tMenu.style.display === 'block';
                    tMenu.style.display = isOpen ? 'none' : 'block';
                    tChevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
                    if (!isOpen && tSearch) setTimeout(() => tSearch.focus(), 50);
                });

                if (tSearch) {
                    tSearch.addEventListener('input', (e) => renderTrainers(e.target.value));
                    tSearch.addEventListener('click', (e) => e.stopPropagation());
                }

                // Member Combobox Logic (Hybrid Search: 0ms local + 250ms AJAX)
                const mTrigger = document.getElementById('naMemberTrigger');
                const mMenu = document.getElementById('naMemberMenu');
                const mChevron = document.getElementById('naMemberChevron');
                const mSearch = document.getElementById('naMemberSearch');
                const mList = document.getElementById('naMemberList');
                const mHidden = document.getElementById('na_member_id');
                const mText = document.getElementById('naMemberText');
                let memberDebounceTimer = null;

                function renderMembers(query = '') {
                    const q = query.trim().toLowerCase();
                    const filtered = ftMembersData.filter(m => m.name.toLowerCase().includes(q));

                    if (filtered.length === 0) {
                        mList.innerHTML = '<div style="padding: 12px; text-align: center; color: var(--muted); font-size: 13px;">No members found locally...</div>';
                    } else {
                        mList.innerHTML = filtered.map(m => `
                            <div class="na-member-item" data-id="${m.id}" data-name="${encodeURIComponent(m.name)}" data-ini="${m.initials}"
                                 style="padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; border-radius: 6px; cursor: pointer; margin-bottom: 2px; transition: background 0.15s; background: ${selectedMemberId === m.id ? 'rgba(132, 204, 22, 0.15)' : 'transparent'};">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 28px; height: 28px; border-radius: 50%; background: #223049; color: var(--lime); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; border: 1px solid rgba(132, 204, 22, 0.3);">
                                        ${m.initials}
                                    </div>
                                    <span style="color: #f1f5f9; font-size: 13px; font-weight: 500;">${m.name}</span>
                                </div>
                                ${selectedMemberId === m.id ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}
                            </div>
                        `).join('');

                        bindMemberClicks();
                    }

                    // Debounced server search fallback
                    if (q) {
                        if (memberDebounceTimer) clearTimeout(memberDebounceTimer);
                        memberDebounceTimer = setTimeout(() => {
                            fetch('index.php?page=trainer_assignments&action=search_assign_data&type=member&q=' + encodeURIComponent(q), {
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (mSearch.value.trim().toLowerCase() !== q) return;
                                const results = data.results || [];
                                results.forEach(rm => {
                                    if (!ftMembersData.some(m => m.id === rm.id)) {
                                        ftMembersData.push(rm);
                                    }
                                });
                                // Re-render with newly discovered members
                                const updatedFiltered = ftMembersData.filter(m => m.name.toLowerCase().includes(q));
                                if (updatedFiltered.length === 0) {
                                    mList.innerHTML = `<div style="padding: 12px; text-align: center; color: var(--muted); font-size: 13px;">No members found matching "${q}"</div>`;
                                } else {
                                    mList.innerHTML = updatedFiltered.map(m => `
                                        <div class="na-member-item" data-id="${m.id}" data-name="${encodeURIComponent(m.name)}" data-ini="${m.initials}"
                                             style="padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; border-radius: 6px; cursor: pointer; margin-bottom: 2px; transition: background 0.15s; background: ${selectedMemberId === m.id ? 'rgba(132, 204, 22, 0.15)' : 'transparent'};">
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <div style="width: 28px; height: 28px; border-radius: 50%; background: #223049; color: var(--lime); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; border: 1px solid rgba(132, 204, 22, 0.3);">
                                                    ${m.initials}
                                                </div>
                                                <span style="color: #f1f5f9; font-size: 13px; font-weight: 500;">${m.name}</span>
                                            </div>
                                            ${selectedMemberId === m.id ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}
                                        </div>
                                    `).join('');
                                    bindMemberClicks();
                                }
                            })
                            .catch(err => console.error('Member search error', err));
                        }, 250);
                    }
                }

                function bindMemberClicks() {
                    mList.querySelectorAll('.na-member-item').forEach(el => {
                        el.addEventListener('mouseenter', () => { if (parseInt(el.getAttribute('data-id'), 10) !== selectedMemberId) el.style.background = '#253349'; });
                        el.addEventListener('mouseleave', () => { if (parseInt(el.getAttribute('data-id'), 10) !== selectedMemberId) el.style.background = 'transparent'; });
                        el.addEventListener('click', () => {
                            selectedMemberId = parseInt(el.getAttribute('data-id'), 10);
                            mHidden.value = selectedMemberId;
                            const name = decodeURIComponent(el.getAttribute('data-name'));
                            const ini = el.getAttribute('data-ini');
                            mText.innerHTML = `
                                <span style="width: 22px; height: 22px; border-radius: 50%; background: #223049; color: var(--lime); display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700;">${ini}</span>
                                <span style="color: #ffffff; font-weight: 600;">${name}</span>
                            `;
                            mMenu.style.display = 'none';
                            mChevron.style.transform = 'rotate(0deg)';
                            mTrigger.style.borderColor = 'var(--lime)';
                        });
                    });
                }

                renderMembers();

                mTrigger.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isOpen = mMenu.style.display === 'block';
                    mMenu.style.display = isOpen ? 'none' : 'block';
                    mChevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
                    if (!isOpen && mSearch) setTimeout(() => mSearch.focus(), 50);
                });

                if (mSearch) {
                    mSearch.addEventListener('input', (e) => renderMembers(e.target.value));
                    mSearch.addEventListener('click', (e) => e.stopPropagation());
                }

                document.addEventListener('click', function closeMenus(e) {
                    if (tTrigger && tMenu && !tTrigger.contains(e.target) && !tMenu.contains(e.target)) {
                        tMenu.style.display = 'none';
                        tChevron.style.transform = 'rotate(0deg)';
                    }
                    if (mTrigger && mMenu && !mTrigger.contains(e.target) && !mMenu.contains(e.target)) {
                        mMenu.style.display = 'none';
                        mChevron.style.transform = 'rotate(0deg)';
                    }
                });
            },
            preConfirm: () => {
                const form = document.getElementById('addAssignmentForm');
                if (!form.trainer_id.value || !form.member_user_id.value || !form.assigned_date.value) {
                    Swal.showValidationMessage('Please select both a trainer and a member');
                    return false;
                }
                form.submit();
            }
        });
    }

    function openDietPlanSelector() {
        let selectedId = null;
        let selectedName = '';
        let dietDebounceTimer = null;

        Swal.fire({
            title: 'Member Diet Plan',
            width: '460px',
            html: `
                <div style="text-align: left; margin-top: 15px;">
                    <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 8px; font-weight: 500;">
                        Select a member to view or edit their meal plan:
                    </label>
                    <div style="position: relative; width: 100%;">
                        <div id="ftCustomSelectTrigger" style="width: 100%; box-sizing: border-box; padding: 11px 14px; border-radius: 8px; font-size: 14px; background: #1a2230; color: #ffffff; border: 1px solid #334155; display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none;">
                            <span id="ftSelectedMemberText" style="color: #94a3b8; display: flex; align-items: center; gap: 8px;">Select Member...</span>
                            <svg id="ftSelectChevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" style="transition: transform 0.2s;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </div>
                        <div id="ftCustomDropdownMenu" style="display: none; width: 100%; box-sizing: border-box; margin-top: 6px; background: #161f30; border: 1px solid #334155; border-radius: 8px; max-height: 230px; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.6); z-index: 1050;">
                            <div style="padding: 8px; border-bottom: 1px solid #283548; background: #131b28; position: sticky; top: 0; z-index: 2;">
                                <input type="text" id="ftMemberSearch" placeholder="Search member..." autocomplete="off" style="width: 100%; box-sizing: border-box; padding: 8px 12px; background: #1a2230; border: 1px solid #334155; border-radius: 6px; color: #ffffff; font-size: 13px; outline: none;">
                            </div>
                            <div id="ftMemberListContainer" style="padding: 4px;"></div>
                        </div>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Open Diet Plan',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            didOpen: () => {
                const trigger = document.getElementById('ftCustomSelectTrigger');
                const menu = document.getElementById('ftCustomDropdownMenu');
                const chevron = document.getElementById('ftSelectChevron');
                const listContainer = document.getElementById('ftMemberListContainer');
                const searchInput = document.getElementById('ftMemberSearch');

                function bindDietClicks() {
                    listContainer.querySelectorAll('.ft-member-item').forEach(el => {
                        el.addEventListener('mouseenter', () => {
                            if (parseInt(el.getAttribute('data-id'), 10) !== selectedId) el.style.background = '#253349';
                        });
                        el.addEventListener('mouseleave', () => {
                            if (parseInt(el.getAttribute('data-id'), 10) !== selectedId) el.style.background = 'transparent';
                        });
                        el.addEventListener('click', () => {
                            selectedId = parseInt(el.getAttribute('data-id'), 10);
                            selectedName = decodeURIComponent(el.getAttribute('data-name'));
                            const ini = el.getAttribute('data-initials');

                            const label = document.getElementById('ftSelectedMemberText');
                            if (label) {
                                label.innerHTML = `
                                    <span style="width: 22px; height: 22px; border-radius: 50%; background: #223049; color: var(--lime); display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700;">${ini}</span>
                                    <span style="color: #ffffff; font-weight: 600;">${selectedName}</span>
                                `;
                            }
                            menu.style.display = 'none';
                            chevron.style.transform = 'rotate(0deg)';
                            trigger.style.borderColor = 'var(--lime)';
                        });
                    });
                }

                function renderMembers(query = '') {
                    const q = query.trim().toLowerCase();
                    const filtered = ftDietMembers.filter(m => m.name.toLowerCase().includes(q));
                    
                    if (filtered.length === 0) {
                        listContainer.innerHTML = '<div style="padding: 14px; text-align: center; color: var(--muted); font-size: 13px;">Searching members...</div>';
                    } else {
                        listContainer.innerHTML = filtered.map(m => {
                            const isSelected = selectedId === m.id;
                            return `
                                <div class="ft-member-item" data-id="${m.id}" data-name="${encodeURIComponent(m.name)}" data-initials="${m.initials}"
                                     style="padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; border-radius: 6px; cursor: pointer; margin-bottom: 2px; transition: background 0.15s; background: ${isSelected ? 'rgba(132, 204, 22, 0.15)' : 'transparent'};">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="width: 30px; height: 30px; border-radius: 50%; background: #223049; color: var(--lime); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; border: 1px solid rgba(132, 204, 22, 0.3);">
                                            ${m.initials}
                                        </div>
                                        <span style="color: #f1f5f9; font-size: 13.5px; font-weight: 600;">${m.name}</span>
                                    </div>
                                    ${isSelected ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}
                                </div>
                            `;
                        }).join('');

                        bindDietClicks();
                    }

                    // Hybrid debounced server search
                    if (q) {
                        if (dietDebounceTimer) clearTimeout(dietDebounceTimer);
                        dietDebounceTimer = setTimeout(() => {
                            fetch('index.php?page=trainer_assignments&action=search_assign_data&type=member&q=' + encodeURIComponent(q), {
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (searchInput.value.trim().toLowerCase() !== q) return;
                                const results = data.results || [];
                                results.forEach(rm => {
                                    if (!ftDietMembers.some(m => m.id === rm.id)) {
                                        ftDietMembers.push({ id: rm.id, name: rm.full_name, initials: rm.initials });
                                    }
                                });
                                const updatedFiltered = ftDietMembers.filter(m => m.name.toLowerCase().includes(q));
                                if (updatedFiltered.length === 0) {
                                    listContainer.innerHTML = `<div style="padding: 14px; text-align: center; color: var(--muted); font-size: 13px;">No members found matching "${q}"</div>`;
                                } else {
                                    listContainer.innerHTML = updatedFiltered.map(m => {
                                        const isSelected = selectedId === m.id;
                                        return `
                                            <div class="ft-member-item" data-id="${m.id}" data-name="${encodeURIComponent(m.name)}" data-initials="${m.initials}"
                                                 style="padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; border-radius: 6px; cursor: pointer; margin-bottom: 2px; transition: background 0.15s; background: ${isSelected ? 'rgba(132, 204, 22, 0.15)' : 'transparent'};">
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <div style="width: 30px; height: 30px; border-radius: 50%; background: #223049; color: var(--lime); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; border: 1px solid rgba(132, 204, 22, 0.3);">
                                                        ${m.initials}
                                                    </div>
                                                    <span style="color: #f1f5f9; font-size: 13.5px; font-weight: 600;">${m.name}</span>
                                                </div>
                                                ${isSelected ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}
                                            </div>
                                        `;
                                    }).join('');
                                    bindDietClicks();
                                }
                            })
                            .catch(err => console.error('Diet member search error', err));
                        }, 250);
                    }
                }

                renderMembers();

                trigger.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isOpen = menu.style.display === 'block';
                    menu.style.display = isOpen ? 'none' : 'block';
                    chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
                    if (!isOpen && searchInput) {
                        setTimeout(() => searchInput.focus(), 50);
                    }
                });

                if (searchInput) {
                    searchInput.addEventListener('input', (e) => {
                        renderMembers(e.target.value);
                    });
                    searchInput.addEventListener('click', (e) => e.stopPropagation());
                }

                document.addEventListener('click', function closeMenu(e) {
                    if (trigger && menu && !trigger.contains(e.target) && !menu.contains(e.target)) {
                        menu.style.display = 'none';
                        chevron.style.transform = 'rotate(0deg)';
                    }
                });
            },
            preConfirm: () => {
                if (!selectedId) {
                    Swal.showValidationMessage('Please select a member');
                    return false;
                }
                window.location.href = 'index.php?page=diet_builder&member_user_id=' + selectedId + '&ref=trainer_assignments';
            }
        });
    }

    // ── Live Search for Assignments Table & Mobile Cards ─────────────────
    const assignSearchInput = document.getElementById('assignmentSearchInput');
    const assignSearchClear = document.getElementById('assignmentSearchClear');
    const assignCountLabel = document.getElementById('assignmentCountLabel');
    const assignEmptyState = document.getElementById('assignmentEmptyState');
    const assignEmptyStateText = document.getElementById('assignmentEmptyStateText');
    const assignTableWrap = document.querySelector('.assignments-desktop-table');
    const assignCardsWrap = document.getElementById('assignmentMobileCards');

    function clearAssignmentSearch() {
        if (assignSearchInput) assignSearchInput.value = '';
        if (assignSearchClear) assignSearchClear.style.display = 'none';
        
        const rows = document.querySelectorAll('.assignment-row');
        const cards = document.querySelectorAll('.assignment-card-item');
        
        rows.forEach(r => r.style.display = '');
        cards.forEach(c => c.style.display = '');

        if (assignCountLabel) assignCountLabel.textContent = rows.length + ' assignments';
        if (assignEmptyState) assignEmptyState.style.display = 'none';
        if (assignTableWrap) assignTableWrap.style.display = '';
        if (assignCardsWrap) assignCardsWrap.style.display = '';

        if (assignSearchInput) assignSearchInput.focus();
    }

    if (assignSearchInput) {
        assignSearchInput.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();

            if (assignSearchClear) {
                assignSearchClear.style.display = query ? 'flex' : 'none';
            }

            const rows = document.querySelectorAll('.assignment-row');
            const cards = document.querySelectorAll('.assignment-card-item');
            let matches = 0;

            rows.forEach(r => {
                const coach = r.getAttribute('data-coach') || '';
                const member = r.getAttribute('data-member') || '';
                const status = r.getAttribute('data-status') || '';
                if (!query || coach.includes(query) || member.includes(query) || status.includes(query)) {
                    r.style.display = '';
                    matches++;
                } else {
                    r.style.display = 'none';
                }
            });

            cards.forEach(c => {
                const coach = c.getAttribute('data-coach') || '';
                const member = c.getAttribute('data-member') || '';
                const status = c.getAttribute('data-status') || '';
                if (!query || coach.includes(query) || member.includes(query) || status.includes(query)) {
                    c.style.display = '';
                } else {
                    c.style.display = 'none';
                }
            });

            if (assignCountLabel) {
                assignCountLabel.textContent = (query ? matches : rows.length) + ' assignments' + (query ? ' found' : '');
            }

            if (query && matches === 0) {
                if (assignEmptyState) {
                    assignEmptyState.style.display = 'block';
                    if (assignEmptyStateText) {
                        assignEmptyStateText.textContent = `No assignments found matching "${query}".`;
                    }
                }
                if (assignTableWrap) assignTableWrap.style.display = 'none';
                if (assignCardsWrap) assignCardsWrap.style.display = 'none';
            } else {
                if (assignEmptyState) assignEmptyState.style.display = 'none';
                if (assignTableWrap) assignTableWrap.style.display = '';
                if (assignCardsWrap) assignCardsWrap.style.display = '';
            }
        });
    }
    </script>
    <?php
    render_footer();
}
