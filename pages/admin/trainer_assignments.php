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
            $activeOtherAssignments = query_all("
                SELECT ta.member_user_id, CONCAT(u.first_name, ' ', u.last_name) as trainer_name
                FROM trainer_assignments ta
                JOIN trainer_profiles tp ON tp.trainer_id = ta.trainer_id
                JOIN users u ON u.user_id = tp.user_id
                WHERE ta.status = 'active'
            ");
            $otherAssignMap = [];
            foreach ($activeOtherAssignments as $oa) {
                $mid = (int)$oa['member_user_id'];
                if (!isset($otherAssignMap[$mid])) $otherAssignMap[$mid] = [];
                $otherAssignMap[$mid][] = $oa['trainer_name'];
            }

            $items = array_map(function($m) use ($otherAssignMap) {
                $parts = preg_split('/\s+/', trim($m['first_name'] . ' ' . $m['last_name']));
                $ini = (!empty($parts[0]) ? strtoupper(substr($parts[0], 0, 1)) : '') . (!empty($parts[1]) ? strtoupper(substr($parts[1], 0, 1)) : '');
                $fullName = trim($m['first_name'] . ' ' . $m['last_name']);
                $uid = (int)$m['user_id'];
                $activeWith = isset($otherAssignMap[$uid]) ? implode(', ', array_unique($otherAssignMap[$uid])) : null;
                return [
                    'id' => $uid,
                    'name' => $fullName . ($m['has_plan'] ? ' (Has Plan)' : ' (No Plan)'),
                    'full_name' => $fullName,
                    'initials' => $ini ?: 'M',
                    'has_plan' => (bool)$m['has_plan'],
                    'active_trainer' => $activeWith ? "Coach {$activeWith}" : null
                ];
            }, $res);
            echo json_encode(['results' => $items]);
            exit;
        }
    }

    // ── AJAX: Get Group Details (Members & Available Gym Members) ────────
    if (($_GET['action'] ?? post('action')) === 'get_group_details') {
        if (ob_get_level()) ob_clean();
        header('Content-Type: application/json');

        $groupId = trim((string)($_GET['group_id'] ?? post('group_id') ?? ''));
        if ($groupId === '') {
            echo json_encode(['error' => 'Group ID required']);
            exit;
        }

        // Migrate single legacy assignment on demand if group_id starts with single_
        if (str_starts_with($groupId, 'single_')) {
            $singleAssignId = (int) substr($groupId, 7);
            $newGid = bin2hex(random_bytes(16));
            db()->prepare('UPDATE trainer_assignments SET group_id = ? WHERE assignment_id = ?')->execute([$newGid, $singleAssignId]);
            $groupId = $newGid;
        }

        $members = query_all('
            SELECT ca.assignment_id, ca.group_id, ca.trainer_id, ca.member_user_id, ca.assigned_date, ca.ended_date, ca.status, ca.activity_title,
                   u.first_name, u.last_name, u.profile_picture, u.email,
                   IF(EXISTS(SELECT 1 FROM memberships m WHERE m.user_id = u.user_id AND m.status = "active" AND m.end_date >= CURDATE()), 1, 0) as has_plan,
                   (SELECT end_date FROM memberships WHERE user_id = u.user_id AND end_date >= CURDATE() ORDER BY end_date DESC LIMIT 1) as membership_end_date
            FROM trainer_assignments ca
            JOIN users u ON u.user_id = ca.member_user_id
            WHERE ca.group_id = ?
            ORDER BY u.first_name, u.last_name
        ', [$groupId]);

        if (empty($members)) {
            echo json_encode(['error' => 'Assignment not found']);
            exit;
        }

        $first = $members[0];
        $trainerId = (int) $first['trainer_id'];
        $trainerInfo = query_all('
            SELECT tp.trainer_id, CONCAT(u.first_name, " ", u.last_name) as trainer_name, cp.specialization
            FROM trainer_profiles tp
            JOIN users u ON u.user_id = tp.user_id
            LEFT JOIN trainer_profiles cp ON cp.trainer_id = tp.trainer_id
            WHERE tp.trainer_id = ?
        ', [$trainerId]);
        $trainerName = $trainerInfo[0]['trainer_name'] ?? 'Trainer';
        $specialization = $trainerInfo[0]['specialization'] ?? 'Trainer';

        // Exclude currently assigned members from the available members search
        $assignedUserIds = array_column($members, 'member_user_id');
        $placeholders = empty($assignedUserIds) ? '0' : implode(',', array_map('intval', $assignedUserIds));

        if ($user['role'] === 'platform_admin') {
            $avail = query_all('
                SELECT u.user_id, u.first_name, u.last_name,
                       IF(EXISTS(SELECT 1 FROM memberships m WHERE m.user_id = u.user_id AND m.status = "active" AND m.end_date >= CURDATE()), 1, 0) as has_plan
                FROM users u
                WHERE u.role = "member" AND u.status = "active" AND u.user_id NOT IN (' . $placeholders . ')
                ORDER BY u.first_name, u.last_name
            ');
        } else {
            $avail = query_all('
                SELECT DISTINCT u.user_id, u.first_name, u.last_name,
                       IF(EXISTS(SELECT 1 FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = u.user_id AND m.status = "active" AND m.end_date >= CURDATE() AND mp.gym_id = ?), 1, 0) as has_plan
                FROM users u
                WHERE u.role = "member" AND u.status = "active" AND u.user_id NOT IN (' . $placeholders . ') AND (
                    EXISTS (SELECT 1 FROM gym_members gm WHERE gm.user_id = u.user_id AND gm.gym_id = ?) OR
                    EXISTS (SELECT 1 FROM memberships m2 JOIN membership_plans mp2 ON mp2.plan_id = m2.plan_id WHERE m2.user_id = u.user_id AND mp2.gym_id = ?)
                )
                ORDER BY u.first_name, u.last_name
            ', [$gymId, $gymId, $gymId]);
        }

        $activeOtherAssignments = query_all("
            SELECT ta.member_user_id, CONCAT(u.first_name, ' ', u.last_name) as trainer_name
            FROM trainer_assignments ta
            JOIN trainer_profiles tp ON tp.trainer_id = ta.trainer_id
            JOIN users u ON u.user_id = tp.user_id
            WHERE ta.status = 'active'
        ");
        $otherAssignMap = [];
        foreach ($activeOtherAssignments as $oa) {
            $mid = (int)$oa['member_user_id'];
            if (!isset($otherAssignMap[$mid])) $otherAssignMap[$mid] = [];
            $otherAssignMap[$mid][] = $oa['trainer_name'];
        }

        $availFormatted = array_map(function($m) use ($otherAssignMap) {
            $parts = preg_split('/\s+/', trim($m['first_name'] . ' ' . $m['last_name']));
            $ini = (!empty($parts[0]) ? strtoupper(substr($parts[0], 0, 1)) : '') . (!empty($parts[1]) ? strtoupper(substr($parts[1], 0, 1)) : '');
            $fullName = trim($m['first_name'] . ' ' . $m['last_name']);
            $uid = (int)$m['user_id'];
            $activeWith = isset($otherAssignMap[$uid]) ? implode(', ', array_unique($otherAssignMap[$uid])) : null;
            return [
                'id' => $uid,
                'name' => $fullName . ($m['has_plan'] ? ' (Has Plan)' : ' (No Plan)'),
                'full_name' => $fullName,
                'initials' => $ini ?: 'M',
                'has_plan' => (bool)$m['has_plan'],
                'active_trainer' => $activeWith ? "Coach {$activeWith}" : null
            ];
        }, $avail);

        $membersFormatted = array_map(function($m) {
            $parts = preg_split('/\s+/', trim($m['first_name'] . ' ' . $m['last_name']));
            $ini = (!empty($parts[0]) ? strtoupper(substr($parts[0], 0, 1)) : '') . (!empty($parts[1]) ? strtoupper(substr($parts[1], 0, 1)) : '');
            return [
                'assignment_id' => (int)$m['assignment_id'],
                'user_id' => (int)$m['member_user_id'],
                'full_name' => trim($m['first_name'] . ' ' . $m['last_name']),
                'email' => $m['email'],
                'initials' => $ini ?: 'M',
                'status' => $m['status'],
                'has_plan' => (bool)$m['has_plan'],
                'assigned_date' => $m['assigned_date'] ? date('M j, Y', strtotime($m['assigned_date'])) : '—',
                'ended_date' => $m['ended_date'] ? date('M j, Y', strtotime($m['ended_date'])) : null,
                'membership_end_date' => $m['membership_end_date'] ? date('M j, Y', strtotime($m['membership_end_date'])) : null,
                'profile_picture' => $m['profile_picture']
            ];
        }, $members);

        echo json_encode([
            'success' => true,
            'group_id' => $groupId,
            'activity_title' => $first['activity_title'] ?: 'Personal Training',
            'trainer_id' => $trainerId,
            'trainer_name' => $trainerName,
            'specialization' => $specialization,
            'assigned_date' => date('M j, Y', strtotime($first['assigned_date'])),
            'members' => $membersFormatted,
            'available_members' => $availFormatted
        ]);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || post('is_ajax') == 1;

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
        } elseif (post('action') === 'end_group') {
            $groupId = trim((string) post('group_id'));
            if (str_starts_with($groupId, 'single_')) {
                $assignId = (int) substr($groupId, 7);
                db()->prepare('UPDATE trainer_assignments SET status = "ended", ended_date = CURDATE() WHERE assignment_id = ?')->execute([$assignId]);
            } else {
                db()->prepare('UPDATE trainer_assignments SET status = "ended", ended_date = CURDATE() WHERE group_id = ? AND status = "active"')->execute([$groupId]);
            }
            audit_log($user['user_id'], 'end_group', 'trainer_assignment', $groupId);
            flash('Assignment ended for all group members.');
        } elseif (post('action') === 'delete_group') {
            $groupId = trim((string) post('group_id'));
            if (str_starts_with($groupId, 'single_')) {
                $assignId = (int) substr($groupId, 7);
                db()->prepare('DELETE FROM trainer_assignments WHERE assignment_id = ?')->execute([$assignId]);
            } else {
                db()->prepare('DELETE FROM trainer_assignments WHERE group_id = ?')->execute([$groupId]);
            }
            audit_log($user['user_id'], 'delete_group', 'trainer_assignment', $groupId);
            flash('Assignment deleted successfully.', 'success');
        } elseif (post('action') === 'add_members_to_group') {
            $groupId = trim((string) post('group_id'));
            if (str_starts_with($groupId, 'single_')) {
                $singleAssignId = (int) substr($groupId, 7);
                $newGid = bin2hex(random_bytes(16));
                db()->prepare('UPDATE trainer_assignments SET group_id = ? WHERE assignment_id = ?')->execute([$newGid, $singleAssignId]);
                $groupId = $newGid;
            }

            $memberIds = [];
            if (!empty($_POST['member_user_ids'])) {
                if (is_array($_POST['member_user_ids'])) {
                    $memberIds = array_map('intval', $_POST['member_user_ids']);
                } else {
                    $memberIds = array_filter(array_map('intval', explode(',', (string)$_POST['member_user_ids'])));
                }
            }
            $memberIds = array_values(array_unique(array_filter($memberIds, fn($id) => $id > 0)));

            $groupRow = query_all('SELECT trainer_id, activity_title, assigned_date FROM trainer_assignments WHERE group_id = ? LIMIT 1', [$groupId]);
            if (!$groupRow || empty($memberIds)) {
                if ($isAjax) {
                    echo json_encode(['success' => false, 'error' => 'Invalid group or no members selected']);
                    exit;
                }
                flash('Could not add members to assignment.', 'danger');
                redirect('trainer_assignments');
            }

            $trainerId = (int)$groupRow[0]['trainer_id'];
            $activityTitle = $groupRow[0]['activity_title'] ?: 'Group Activity';
            $assignedDate = $groupRow[0]['assigned_date'] ?: date('Y-m-d H:i:s');

            $trainerUser = query_all('SELECT tu.user_id, CONCAT(tu.first_name, " ", tu.last_name) as name FROM trainer_profiles tp JOIN users tu ON tu.user_id = tp.user_id WHERE tp.trainer_id = ?', [$trainerId]);
            $trainerName = $trainerUser[0]['name'] ?? 'Trainer';
            $trainerUserId = (int)($trainerUser[0]['user_id'] ?? 0);

            $addedCount = 0;
            foreach ($memberIds as $mid) {
                // Check if already active in this group
                $already = scalar('SELECT 1 FROM trainer_assignments WHERE group_id = ? AND member_user_id = ? AND status = "active"', [$groupId, $mid]);
                if ($already) continue;

                $hasActivePlan = (bool) scalar("SELECT 1 FROM memberships WHERE user_id = ? AND status = 'active' AND end_date >= CURDATE()", [$mid]);
                $endedDate = $hasActivePlan ? null : $assignedDate;

                db()->prepare('INSERT INTO trainer_assignments (group_id, trainer_id, member_user_id, assigned_date, ended_date, activity_title, status, assigned_by) VALUES (?, ?, ?, ?, ?, ?, "active", ?)')
                    ->execute([$groupId, $trainerId, $mid, $assignedDate, $endedDate, $activityTitle, $user['user_id']]);

                grant_retroactive_commission($mid);
                notify_user($mid, 'system', 'Trainer assigned', 'You have been added to ' . $trainerName . '\'s assignment (' . $activityTitle . ').');
                $addedCount++;
            }

            if ($trainerUserId && $addedCount > 0) {
                notify_user($trainerUserId, 'system', 'Members Added to Assignment', $addedCount . ' member(s) were added to your assignment (' . $activityTitle . ').');
            }

            audit_log($user['user_id'], 'add_members', 'trainer_assignment', $groupId, json_encode(['added' => $memberIds]));

            if ($isAjax) {
                echo json_encode([
                    'success' => true,
                    'group_id' => $groupId,
                    'message' => "Added {$addedCount} member(s) successfully."
                ]);
                exit;
            }
            flash("Added {$addedCount} member(s) to assignment.", 'success');
            redirect('trainer_assignments');
        } elseif (post('action') === 'remove_group_member') {
            $assignmentId = (int) post('assignment_id');
            $stmt = db()->prepare('SELECT member_user_id, group_id, activity_title FROM trainer_assignments WHERE assignment_id = ?');
            $stmt->execute([$assignmentId]);
            $assign = $stmt->fetch();

            if ($assign) {
                db()->prepare('DELETE FROM trainer_assignments WHERE assignment_id = ?')->execute([$assignmentId]);
                notify_user((int)$assign['member_user_id'], 'system', 'Assignment Removed', 'You were removed from the assignment (' . ($assign['activity_title'] ?: 'Activity') . ').');
                audit_log($user['user_id'], 'remove_member', 'trainer_assignment', (string)$assignmentId, json_encode($assign));
            }

            if ($isAjax) {
                echo json_encode(['success' => true, 'message' => 'Member removed from assignment.']);
                exit;
            }
            flash('Member removed from assignment.');
            redirect('trainer_assignments');
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
            // New Assignment Creation (Supports Single or Multiple Members)
            $trainerId = (int) post('trainer_id');
            $activityTitle = trim((string) post('activity_title'));
            $assignedDate = post('assigned_date') ?: date('Y-m-d H:i:s');

            $memberIds = [];
            if (!empty($_POST['member_user_ids'])) {
                if (is_array($_POST['member_user_ids'])) {
                    $memberIds = array_map('intval', $_POST['member_user_ids']);
                } else {
                    $memberIds = array_filter(array_map('intval', explode(',', (string)$_POST['member_user_ids'])));
                }
            } elseif (!empty($_POST['member_user_id'])) {
                $memberIds = [(int) post('member_user_id')];
            }
            $memberIds = array_values(array_unique(array_filter($memberIds, fn($id) => $id > 0)));

            if (empty($memberIds) || !$trainerId) {
                flash('Please select a trainer and at least one member.', 'danger');
                redirect('trainer_assignments');
            }

            $groupId = bin2hex(random_bytes(16));
            $finalTitle = $activityTitle !== '' ? $activityTitle : (count($memberIds) > 1 ? 'Group Activity' : 'Personal Training');

            $trainerUser = query_all('SELECT tu.user_id, CONCAT(tu.first_name, " ", tu.last_name) as name FROM trainer_profiles tp JOIN users tu ON tu.user_id = tp.user_id WHERE tp.trainer_id = ?', [$trainerId]);
            $trainerName = $trainerUser[0]['name'] ?? 'Trainer';
            $trainerUserId = (int)($trainerUser[0]['user_id'] ?? 0);

            $createdCount = 0;
            foreach ($memberIds as $mid) {
                $hasActivePlan = (bool) scalar("SELECT 1 FROM memberships WHERE user_id = ? AND status = 'active' AND end_date >= CURDATE()", [$mid]);
                $endedDate = $hasActivePlan ? null : $assignedDate;

                db()->prepare('INSERT INTO trainer_assignments (group_id, trainer_id, member_user_id, assigned_date, ended_date, activity_title, status, assigned_by) VALUES (?, ?, ?, ?, ?, ?, "active", ?)')
                    ->execute([$groupId, $trainerId, $mid, $assignedDate, $endedDate, $finalTitle, $user['user_id']]);

                grant_retroactive_commission($mid);
                notify_user($mid, 'system', 'Trainer assigned', 'You have been assigned to ' . $trainerName . ' for ' . $finalTitle . '.');
                $createdCount++;
            }

            if ($trainerUserId) {
                $msg = $createdCount === 1 ? 'A new client has been assigned to you for ' . $finalTitle . '.' : $createdCount . ' members have been assigned to you for ' . $finalTitle . '.';
                notify_user($trainerUserId, 'system', 'New Assignment', $msg);
            }

            audit_log($user['user_id'], 'create', 'trainer_assignment', $groupId, json_encode(['group_id' => $groupId, 'trainer_id' => $trainerId, 'member_count' => $createdCount, 'title' => $finalTitle]));
            flash("Assignment created successfully for {$createdCount} member(s).", 'success');
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
        $rows = db()->query('SELECT ca.*, (SELECT end_date FROM memberships WHERE user_id = ca.member_user_id AND end_date >= CURDATE() ORDER BY end_date DESC LIMIT 1) as membership_end_date, CONCAT(cu.first_name, " ", cu.last_name) AS trainer, CONCAT(mu.first_name, " ", mu.last_name) AS member, cu.first_name AS coach_fn, cu.last_name AS coach_ln, cu.profile_picture AS coach_picture, cp.specialization, mu.first_name AS member_fn, mu.last_name AS member_ln, mu.profile_picture AS member_picture FROM trainer_assignments ca JOIN trainer_profiles cp ON cp.trainer_id = ca.trainer_id JOIN users cu ON cu.user_id = cp.user_id JOIN users mu ON mu.user_id = ca.member_user_id ORDER BY CASE ca.status WHEN "active" THEN 1 WHEN "pending_admin" THEN 2 WHEN "pending_trainer" THEN 3 WHEN "ended" THEN 4 WHEN "rejected" THEN 5 ELSE 6 END, ca.assigned_date DESC')->fetchAll();
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
        $rows = db()->query('SELECT ca.*, (SELECT end_date FROM memberships WHERE user_id = ca.member_user_id AND end_date >= CURDATE() ORDER BY end_date DESC LIMIT 1) as membership_end_date, CONCAT(cu.first_name, " ", cu.last_name) AS trainer, CONCAT(mu.first_name, " ", mu.last_name) AS member, cu.first_name AS coach_fn, cu.last_name AS coach_ln, cu.profile_picture AS coach_picture, cp.specialization, mu.first_name AS member_fn, mu.last_name AS member_ln, mu.profile_picture AS member_picture FROM trainer_assignments ca JOIN trainer_profiles cp ON cp.trainer_id = ca.trainer_id JOIN users cu ON cu.user_id = cp.user_id JOIN users mu ON mu.user_id = ca.member_user_id WHERE cp.gym_id = ' . $gymId . ' ORDER BY CASE ca.status WHEN "active" THEN 1 WHEN "pending_admin" THEN 2 WHEN "pending_trainer" THEN 3 WHEN "ended" THEN 4 WHEN "rejected" THEN 5 ELSE 6 END, ca.assigned_date DESC')->fetchAll();
    }

    // Group assignments by group_id (or single assignment_id for legacy rows)
    $assignmentGroups = [];
    foreach ($rows as $row) {
        $gid = !empty($row['group_id']) ? $row['group_id'] : ('single_' . $row['assignment_id']);
        if (!isset($assignmentGroups[$gid])) {
            $assignmentGroups[$gid] = [
                'group_id' => $gid,
                'is_real_group' => !empty($row['group_id']),
                'activity_title' => $row['activity_title'] ?: 'Personal Training',
                'trainer_id' => (int) $row['trainer_id'],
                'trainer' => $row['trainer'],
                'coach_fn' => $row['coach_fn'],
                'coach_ln' => $row['coach_ln'],
                'coach_picture' => $row['coach_picture'],
                'specialization' => $row['specialization'] ?? 'Trainer',
                'assigned_date' => $row['assigned_date'],
                'ended_date' => $row['ended_date'],
                'assigned_by' => $row['assigned_by'],
                'members' => [],
            ];
        }
        $assignmentGroups[$gid]['members'][] = [
            'assignment_id' => (int) $row['assignment_id'],
            'member_user_id' => (int) $row['member_user_id'],
            'member' => $row['member'],
            'member_fn' => $row['member_fn'],
            'member_ln' => $row['member_ln'],
            'member_picture' => $row['member_picture'],
            'status' => $row['status'],
            'rejection_reason' => $row['rejection_reason'],
            'assigned_date' => $row['assigned_date'],
            'ended_date' => $row['ended_date'],
            'membership_end_date' => $row['membership_end_date'],
        ];
    }

    foreach ($assignmentGroups as $gid => &$g) {
        $memberCount = count($g['members']);
        $g['is_group'] = $memberCount > 1;

        $statuses = array_column($g['members'], 'status');
        if (in_array('active', $statuses)) {
            $g['status'] = 'active';
        } elseif (in_array('pending_admin', $statuses)) {
            $g['status'] = 'pending_admin';
        } elseif (in_array('pending_trainer', $statuses)) {
            $g['status'] = 'pending_trainer';
        } elseif (in_array('ended', $statuses)) {
            $g['status'] = 'ended';
        } elseif (in_array('rejected', $statuses)) {
            $g['status'] = 'rejected';
        } else {
            $g['status'] = 'active';
        }

        $g['active_count'] = count(array_filter($statuses, fn($s) => $s === 'active'));
        $g['total_count'] = $memberCount;
    }
    unset($g);

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

    /* Assignment Pagination Bar */
    .assign-pagination-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 14px;
        margin-top: 18px;
        padding-top: 14px;
        border-top: 1px solid var(--line);
    }
    .assign-pagination-info {
        font-size: 13px;
        color: var(--muted);
    }
    .assign-pagination-info strong {
        color: var(--ink);
        font-weight: 600;
    }
    .assign-pagination-controls {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        user-select: none;
        flex-wrap: wrap;
    }
    .assign-page-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 34px;
        height: 34px;
        padding: 0 10px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
        color: var(--ink);
        border: 1px solid var(--line);
        background: var(--panel-soft, rgba(255, 255, 255, 0.05));
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .assign-page-btn:hover:not(.disabled):not(.active) {
        background: var(--panel);
        border-color: var(--lime);
        color: var(--lime);
    }
    .assign-page-btn.active {
        background: var(--lime);
        color: #000;
        border-color: var(--lime);
        font-weight: 700;
        cursor: default;
    }
    .assign-page-btn.disabled {
        opacity: 0.35;
        cursor: not-allowed;
        pointer-events: none;
    }
    .assign-page-ellipsis {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 24px;
        height: 34px;
        color: var(--muted);
        font-size: 14px;
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
            <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--muted);">
                    <span>Show:</span>
                    <select id="assignPerPageSelect" style="padding: 5px 8px; border-radius: 6px; border: 1px solid var(--line); background: var(--panel); color: var(--ink); font-size: 13px; outline: none; cursor: pointer;">
                        <option value="10" selected>10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                </div>
                <p class="section-label" id="assignmentCountLabel" style="margin: 0; border: none; padding: 0;"><?= count($assignmentGroups) ?> assignments</p>
            </div>
        </div>

        <!-- Empty Search State -->
        <div id="assignmentEmptyState" class="empty-state" style="display: none; padding: 32px 20px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.5; margin-bottom: 8px;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <p id="assignmentEmptyStateText" style="margin: 0;">No assignments found matching your search.</p>
        </div>

        <?php if (!$assignmentGroups): ?>
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
                        <th>Activity / Task</th>
                        <th>Type</th>
                        <th>Assigned Members</th>
                        <th>Assigned Date</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="assignmentTableBody">
                <?php foreach ($assignmentGroups as $group):
                    $statusClass = 'badge badge-' . str_replace(' ', '_', $group['status']);
                    $coachData = ['first_name' => $group['coach_fn'], 'last_name' => $group['coach_ln'], 'profile_picture' => $group['coach_picture']];
                    $membersSearchStr = implode(' ', array_column($group['members'], 'member'));
                    $typeStr = $group['is_group'] ? 'group team' : 'individual';
                ?>
                    <tr class="assignment-row"
                        data-coach="<?= strtolower(h($group['trainer'])) ?>"
                        data-title="<?= strtolower(h($group['activity_title'])) ?>"
                        data-type="<?= $typeStr ?>"
                        data-member="<?= strtolower(h($membersSearchStr)) ?>"
                        data-status="<?= strtolower(h($group['status'])) ?>"
                        data-group-id="<?= h($group['group_id']) ?>">
                        <td>
                            <div class="user-cell">
                                <?= render_avatar($coachData) ?>
                                <div>
                                    <div style="font-weight: 600; color: var(--ink);"><?= h($group['trainer']) ?></div>
                                    <div style="font-size: 11.5px; color: var(--muted);"><?= h($group['specialization']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span style="font-weight: 600; color: var(--ink); font-size: 13.5px;"><?= h($group['activity_title']) ?></span>
                        </td>
                        <td>
                            <?php if ($group['is_group']): ?>
                                <span class="badge" style="background: rgba(132, 204, 22, 0.12); color: var(--lime); border: 1px solid rgba(132, 204, 22, 0.25); font-size: 11px; font-weight: 700; padding: 4px 9px; border-radius: 12px; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap;">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                    Team / Group (<?= count($group['members']) ?>)
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background: var(--panel-soft); color: var(--muted); font-size: 11px; font-weight: 600; padding: 4px 9px; border-radius: 12px; border: 1px solid var(--line); white-space: nowrap;">
                                    Individual (1)
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (count($group['members']) === 1): 
                                $singleM = $group['members'][0];
                                $singlePic = ['first_name' => $singleM['member_fn'], 'last_name' => $singleM['member_ln'], 'profile_picture' => $singleM['member_picture']];
                                $singleDot = $singleM['status'] === 'active' ? '#22c55e' : ($singleM['status'] === 'ended' ? '#94a3b8' : '#f59e0b');
                            ?>
                                <div style="display: flex; align-items: center; gap: 9px;">
                                    <?= render_avatar($singlePic, 'small') ?>
                                    <div style="display: inline-flex; align-items: center; gap: 5px; font-size: 13px; font-weight: 600; color: var(--ink);">
                                        <span style="width: 6px; height: 6px; border-radius: 50%; background: <?= $singleDot ?>;" title="Status: <?= h($singleM['status']) ?>"></span>
                                        <?= h($singleM['member']) ?>
                                    </div>
                                </div>
                            <?php else: 
                                $allMemberNames = implode(', ', array_column($group['members'], 'member'));
                            ?>
                                <div style="display: flex; align-items: center; gap: 10px; <?= $group['status'] === 'active' ? 'cursor: pointer;' : '' ?>" <?= $group['status'] === 'active' ? 'onclick="manageGroupMembers(\''.h($group['group_id']).'\')"' : '' ?> title="Members: <?= h($allMemberNames) ?><?= $group['status'] === 'active' ? ' (Click to manage)' : '' ?>">
                                    <!-- Stacked Member Avatars -->
                                    <div style="display: flex; align-items: center; flex-shrink: 0;">
                                        <?php 
                                        $previewMembers = array_slice($group['members'], 0, 4);
                                        foreach ($previewMembers as $i => $m): 
                                            $mPic = ['first_name' => $m['member_fn'], 'last_name' => $m['member_ln'], 'profile_picture' => $m['member_picture']];
                                        ?>
                                            <div style="margin-left: <?= $i > 0 ? '-8px' : '0' ?>; z-index: <?= 10 - $i ?>; border: 2px solid var(--panel); border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.15);" title="<?= h($m['member']) ?> (<?= h($m['status']) ?>)">
                                                <?= render_avatar($mPic, 'small') ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (count($group['members']) > 4): ?>
                                            <div style="margin-left: -8px; width: 28px; height: 28px; border-radius: 50%; background: var(--panel-soft); color: var(--lime); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; border: 2px solid var(--panel); z-index: 5; box-shadow: 0 1px 3px rgba(0,0,0,0.15);" title="+<?= count($group['members']) - 4 ?> more members">
                                                +<?= count($group['members']) - 4 ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Clean Member Count -->
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <span style="font-size: 13px; font-weight: 600; color: var(--ink); white-space: nowrap;">
                                            <?= count($group['members']) ?> Members
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?= h(date('M j, Y', strtotime($group['assigned_date']))) ?></div>
                            <?php if ($group['ended_date']): ?>
                                <div style="font-size: 11px; color: var(--muted);">Ended: <?= h(date('M j, Y', strtotime($group['ended_date']))) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="<?= $statusClass ?>"><?= h($group['status']) ?></span>
                            <?php if ($group['is_group']): ?>
                                <div style="font-size: 11px; color: var(--muted); margin-top: 3px;">
                                    <?= $group['active_count'] ?> of <?= $group['total_count'] ?> active
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 6px; align-items: center;">
                                <?php if ($group['status'] === 'active'): ?>
                                    <button type="button" onclick="manageGroupMembers('<?= h($group['group_id']) ?>')" class="btn-sm" style="display: inline-flex; align-items: center; gap: 4px; background: var(--panel-soft); border: 1px solid var(--line); color: var(--ink); font-weight: 600;" title="Manage Assigned Members">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                        Manage
                                    </button>
                                    <?php if (!$group['is_group'] && !empty($group['members'][0])): ?>
                                        <a href="index.php?page=diet_builder&member_user_id=<?= (int)$group['members'][0]['member_user_id'] ?>&ref=trainer_assignments" class="btn-sm btn-ghost" style="text-decoration: none; display: inline-flex; align-items: center; gap: 4px; border: 1px solid var(--line); font-weight: 600;" title="Diet Plan">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                            Diet
                                        </a>
                                    <?php endif; ?>
                                    <form method="post" onsubmit="event.preventDefault(); Swal.fire({title: 'End Assignment?', html: 'Are you sure you want to end this assignment for all assigned members?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444', confirmButtonText: 'Yes, end it'}).then((res) => { if (res.isConfirmed) this.submit(); });">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="end_group">
                                        <input type="hidden" name="group_id" value="<?= h($group['group_id']) ?>">
                                        <button class="btn-sm btn-danger">End</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" onsubmit="event.preventDefault(); Swal.fire({title: 'Delete Assignment?', html: 'Are you sure you want to permanently delete this assignment record? This action cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444', confirmButtonText: 'Yes, delete it'}).then((res) => { if (res.isConfirmed) this.submit(); });">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_group">
                                        <input type="hidden" name="group_id" value="<?= h($group['group_id']) ?>">
                                        <button class="btn-sm btn-danger" style="display: inline-flex; align-items: center; gap: 4px;" title="Delete Assignment">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                            Delete
                                        </button>
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
        <?php foreach ($assignmentGroups as $group):
            $statusClass = 'badge badge-' . str_replace(' ', '_', $group['status']);
            $coachData = ['first_name' => $group['coach_fn'], 'last_name' => $group['coach_ln'], 'profile_picture' => $group['coach_picture']];
            $membersSearchStr = implode(' ', array_column($group['members'], 'member'));
            $typeStr = $group['is_group'] ? 'group team' : 'individual';
        ?>
            <div class="assignment-card-item"
                 data-coach="<?= strtolower(h($group['trainer'])) ?>"
                 data-title="<?= strtolower(h($group['activity_title'])) ?>"
                 data-type="<?= $typeStr ?>"
                 data-member="<?= strtolower(h($membersSearchStr)) ?>"
                 data-status="<?= strtolower(h($group['status'])) ?>"
                 data-group-id="<?= h($group['group_id']) ?>">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                    <div>
                        <h4 style="margin: 0 0 4px; font-size: 15px; font-weight: 700; color: var(--ink);"><?= h($group['activity_title']) ?></h4>
                        <div style="font-size: 11.5px; color: var(--muted);"><?= h(date('M j, Y', strtotime($group['assigned_date']))) ?></div>
                    </div>
                    <span class="badge badge-<?= str_replace(' ', '_', $group['status']) ?>"><?= h($group['status']) ?></span>
                </div>

                <!-- Trainer info -->
                <div style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; background: var(--panel); border: 1px solid var(--line); border-radius: 8px;">
                    <?= render_avatar($coachData, 'small') ?>
                    <div>
                        <div style="font-size: 10.5px; text-transform: uppercase; font-weight: 700; color: var(--muted);">Trainer</div>
                        <div style="font-size: 13px; font-weight: 600; color: var(--ink);"><?= h($group['trainer']) ?></div>
                    </div>
                </div>

                <!-- Members info -->
                <div style="display: flex; flex-direction: column; gap: 6px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: var(--muted);">Assigned Members (<?= count($group['members']) ?>)</span>
                        <?php if ($group['status'] === 'active'): ?>
                            <button type="button" onclick="manageGroupMembers('<?= h($group['group_id']) ?>')" class="btn-sm btn-ghost" style="padding: 2px 8px; font-size: 11px; border: 1px solid var(--line); color: var(--lime); font-weight: 600;">
                                + / - Edit
                            </button>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                        <?php foreach (array_slice($group['members'], 0, 3) as $m): 
                            $mStatusDot = $m['status'] === 'active' ? '#22c55e' : ($m['status'] === 'ended' ? '#94a3b8' : '#f59e0b');
                        ?>
                            <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 11.5px; padding: 4px 8px; background: var(--panel); border: 1px solid var(--line); border-radius: 6px; color: var(--ink);">
                                <span style="width: 6px; height: 6px; border-radius: 50%; background: <?= $mStatusDot ?>;"></span>
                                <?= h($m['member']) ?>
                            </span>
                        <?php endforeach; ?>
                        <?php if (count($group['members']) > 3): ?>
                            <span style="font-size: 11.5px; color: var(--lime); padding: 4px 8px; font-weight: 600; cursor: pointer; background: var(--panel); border: 1px dashed var(--line); border-radius: 6px;" <?= $group['status'] === 'active' ? 'onclick="manageGroupMembers(\''.h($group['group_id']).'\')"' : '' ?>>
                                +<?= count($group['members']) - 3 ?> more
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Card Actions -->
                <div class="assignment-card-actions" style="display: flex; gap: 8px; padding-top: 6px; border-top: 1px solid var(--line);">
                    <?php if ($group['status'] === 'active'): ?>
                        <button type="button" onclick="manageGroupMembers('<?= h($group['group_id']) ?>')" class="btn-sm" style="flex: 1; height: 34px; background: var(--panel-soft); border: 1px solid var(--line); color: var(--ink); font-weight: 600; justify-content: center;">
                            Manage Members
                        </button>
                        <form method="post" style="flex: 1;" onsubmit="event.preventDefault(); Swal.fire({title: 'End Assignment?', html: 'End this assignment for all members?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444', confirmButtonText: 'Yes, end it'}).then((res) => { if (res.isConfirmed) this.submit(); });">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="end_group">
                            <input type="hidden" name="group_id" value="<?= h($group['group_id']) ?>">
                            <button class="btn-sm btn-danger" style="height: 34px; width: 100%; justify-content: center;">End</button>
                        </form>
                    <?php else: ?>
                        <form method="post" style="width: 100%;" onsubmit="event.preventDefault(); Swal.fire({title: 'Delete Assignment?', html: 'Are you sure you want to permanently delete this assignment record? This action cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444', confirmButtonText: 'Yes, delete it'}).then((res) => { if (res.isConfirmed) this.submit(); });">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_group">
                            <input type="hidden" name="group_id" value="<?= h($group['group_id']) ?>">
                            <button class="btn-sm btn-danger" style="height: 34px; width: 100%; justify-content: center; display: inline-flex; align-items: center; gap: 6px;">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                Delete Assignment
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <!-- Pagination Bar -->
        <div id="assignmentPaginationBar" class="assign-pagination-bar" style="display: none;">
            <div id="assignmentPaginationInfo" class="assign-pagination-info"></div>
            <div id="assignmentPaginationControls" class="assign-pagination-controls"></div>
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

    $mainActiveOtherAssignments = query_all("
        SELECT ta.member_user_id, CONCAT(u.first_name, ' ', u.last_name) as trainer_name
        FROM trainer_assignments ta
        JOIN trainer_profiles tp ON tp.trainer_id = ta.trainer_id
        JOIN users u ON u.user_id = tp.user_id
        WHERE ta.status = 'active'
    ");
    $mainOtherAssignMap = [];
    foreach ($mainActiveOtherAssignments as $oa) {
        $mid = (int)$oa['member_user_id'];
        if (!isset($mainOtherAssignMap[$mid])) $mainOtherAssignMap[$mid] = [];
        $mainOtherAssignMap[$mid][] = $oa['trainer_name'];
    }

    $assignMembersList = array_values(array_map(function($m) use ($mainOtherAssignMap) {
        $parts = preg_split('/\s+/', trim($m['name']));
        $initials = '';
        if (!empty($parts[0])) $initials .= strtoupper(substr($parts[0], 0, 1));
        if (count($parts) > 1 && !empty($parts[1])) $initials .= strtoupper(substr($parts[1], 0, 1));
        $hasPlan = str_contains($m['name'], '(Has Plan)');
        $uid = (int) $m['user_id'];
        $activeWith = isset($mainOtherAssignMap[$uid]) ? implode(', ', array_unique($mainOtherAssignMap[$uid])) : null;
        return [
            'id' => $uid,
            'name' => $m['name'],
            'initials' => $initials ?: 'M',
            'has_plan' => $hasPlan,
            'active_trainer' => $activeWith ? "Coach {$activeWith}" : null
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
    const ftCsrfToken = <?= json_encode(csrf_token()) ?>;

    function addAssignment() {
        let selectedTrainerId = null;
        let selectedMemberIds = [];
        let memberDebounceTimer = null;

        Swal.fire({
            title: 'New Assignment',
            width: '520px',
            html: `
                <form id="addAssignmentForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 15px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="trainer_id" id="na_trainer_id" value="">
                    <input type="hidden" name="member_user_ids" id="na_member_user_ids" value="">
                    
                    <!-- Activity / Task Title (Optional) -->
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">
                            Activity / Task Title <span style="font-size: 12px; color: var(--muted);">(Optional)</span>
                        </label>
                        <input type="text" name="activity_title" id="na_activity_title" placeholder="e.g. Group Conditioning, Boot Camp, Squad Strength..." autocomplete="off" style="width: 100%; box-sizing: border-box; background-color: var(--panel); color: var(--ink); border: 1px solid var(--line); padding: 10px 12px; border-radius: 8px; font-size: 13.5px; outline: none;">
                        <span style="font-size: 11px; color: var(--muted); margin-top: 4px; display: block;">Leave blank for default assignment title.</span>
                    </div>

                    <!-- Trainer Searchable Combobox -->
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Trainer *</label>
                        <div style="position: relative; width: 100%;">
                            <div id="naTrainerTrigger" style="width: 100%; box-sizing: border-box; padding: 10px 14px; border-radius: 8px; font-size: 14px; background: var(--panel); color: var(--ink); border: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none;">
                                <span id="naTrainerText" style="color: var(--muted); display: flex; align-items: center; gap: 8px;">Select Trainer...</span>
                                <svg id="naTrainerChevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="transition: transform 0.2s; color: var(--muted);"><polyline points="6 9 12 15 18 9"></polyline></svg>
                            </div>
                            <div id="naTrainerMenu" style="display: none; position: absolute; left: 0; right: 0; margin-top: 6px; background: var(--surface); border: 1px solid var(--line); border-radius: 8px; max-height: 220px; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.25); z-index: 1050;">
                                <div style="padding: 8px; border-bottom: 1px solid var(--line); background: var(--panel); position: sticky; top: 0; z-index: 2;">
                                    <input type="text" id="naTrainerSearch" placeholder="Search trainer..." autocomplete="off" style="width: 100%; box-sizing: border-box; padding: 7px 10px; background: var(--bg); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); font-size: 13px; outline: none;">
                                </div>
                                <div id="naTrainerList" style="padding: 4px;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Member Multi-Select Combobox -->
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <label style="color: var(--muted); font-size: 13.5px; font-weight: 500;">Assign Member(s) *</label>
                            <span id="naMemberCountBadge" style="font-size: 11px; padding: 2px 8px; border-radius: 10px; background: rgba(132, 204, 22, 0.15); color: var(--lime); font-weight: 700; border: 1px solid rgba(132, 204, 22, 0.3);">
                                0 selected
                            </span>
                        </div>

                        <!-- Selected Chips Box -->
                        <div id="naSelectedChips" style="min-height: 42px; max-height: 90px; overflow-y: auto; padding: 6px 8px; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 8px;">
                            <span id="naSelectedChipsEmpty" style="color: var(--muted); font-size: 12px; padding: 2px 4px;">No members selected yet. Search and check members below.</span>
                        </div>

                        <!-- Search and Quick Actions -->
                        <div style="display: flex; gap: 6px; margin-bottom: 6px; align-items: center;">
                            <div style="position: relative; flex: 1;">
                                <input type="text" id="naMemberSearch" placeholder="Search members to select..." autocomplete="off" style="width: 100%; box-sizing: border-box; padding: 7px 10px; background: var(--bg); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); font-size: 13px; outline: none;">
                            </div>
                            <button type="button" id="naSelectAllBtn" class="btn-sm btn-ghost" style="padding: 6px 9px; font-size: 11.5px; border: 1px solid var(--line); font-weight: 600; white-space: nowrap; cursor: pointer;">
                                Select All
                            </button>
                            <button type="button" id="naClearAllBtn" class="btn-sm btn-ghost" style="padding: 6px 9px; font-size: 11.5px; border: 1px solid var(--line); color: var(--muted); font-weight: 600; white-space: nowrap; cursor: pointer;">
                                Clear
                            </button>
                        </div>

                        <!-- Member Checklist -->
                        <div id="naMemberList" style="max-height: 175px; overflow-y: auto; background: var(--surface); border: 1px solid var(--line); border-radius: 8px; padding: 4px;"></div>
                    </div>
                    
                    <div>
                        <label style="display:block; color: var(--muted); font-size: 13.5px; margin-bottom: 6px; font-weight: 500;">Assigned date *</label>
                        <input type="date" name="assigned_date" class="form-control" value="<?= h(date('Y-m-d')) ?>" style="width: 100%; box-sizing: border-box; background-color: var(--panel); color: var(--ink); border: 1px solid var(--line); padding: 10px 12px; border-radius: 8px; font-size: 13.5px; color-scheme: dark light;" required>
                    </div>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Assign Members',
            confirmButtonColor: 'var(--lime-dark)',
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
                                <div style="width: 28px; height: 28px; border-radius: 50%; background: var(--panel-soft); color: var(--lime); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; border: 1px solid var(--line);">
                                    ${c.initials}
                                </div>
                                <span style="color: var(--ink); font-size: 13px; font-weight: 500;">${c.name}</span>
                            </div>
                            ${selectedTrainerId === c.id ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}
                        </div>
                    `).join('');

                    tList.querySelectorAll('.na-trainer-item').forEach(el => {
                        el.addEventListener('mouseenter', () => { if (parseInt(el.getAttribute('data-id'), 10) !== selectedTrainerId) el.style.background = 'var(--panel-soft)'; });
                        el.addEventListener('mouseleave', () => { if (parseInt(el.getAttribute('data-id'), 10) !== selectedTrainerId) el.style.background = 'transparent'; });
                        el.addEventListener('click', () => {
                            selectedTrainerId = parseInt(el.getAttribute('data-id'), 10);
                            tHidden.value = selectedTrainerId;
                            const name = decodeURIComponent(el.getAttribute('data-name'));
                            const ini = el.getAttribute('data-ini');
                            tText.innerHTML = `
                                <span style="width: 22px; height: 22px; border-radius: 50%; background: var(--panel-soft); color: var(--lime); display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; border: 1px solid var(--line);">${ini}</span>
                                <span style="color: var(--ink); font-weight: 600;">${name}</span>
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

                // Member Multi-Select Logic
                const mSearch = document.getElementById('naMemberSearch');
                const mList = document.getElementById('naMemberList');
                const mChipsBox = document.getElementById('naSelectedChips');
                const mChipsEmpty = document.getElementById('naSelectedChipsEmpty');
                const mCountBadge = document.getElementById('naMemberCountBadge');
                const mHidden = document.getElementById('na_member_user_ids');
                const btnSelectAll = document.getElementById('naSelectAllBtn');
                const btnClearAll = document.getElementById('naClearAllBtn');
                const confirmBtn = Swal.getConfirmButton();

                function updateSelectedChips() {
                    mHidden.value = selectedMemberIds.join(',');
                    mCountBadge.textContent = selectedMemberIds.length + ' selected';
                    
                    if (confirmBtn) {
                        confirmBtn.textContent = selectedMemberIds.length > 0 
                            ? `Assign (${selectedMemberIds.length}) Member${selectedMemberIds.length > 1 ? 's' : ''}` 
                            : 'Assign Members';
                    }

                    if (selectedMemberIds.length === 0) {
                        mChipsBox.innerHTML = '<span id="naSelectedChipsEmpty" style="color: var(--muted); font-size: 12px; padding: 2px 4px;">No members selected yet. Search and check members below.</span>';
                        const existingWarnBox = document.getElementById('naDoubleAssignWarning');
                        if (existingWarnBox) existingWarnBox.remove();
                        return;
                    }

                    mChipsBox.innerHTML = selectedMemberIds.map(id => {
                        const m = ftMembersData.find(item => item.id === id) || { name: 'Member #' + id, initials: 'M' };
                        const displayName = m.full_name || m.name.split(' (')[0];
                        return `
                            <span class="na-chip" style="display: inline-flex; align-items: center; gap: 5px; background: rgba(132, 204, 22, 0.15); border: 1px solid rgba(132, 204, 22, 0.3); color: var(--ink); font-size: 12px; font-weight: 500; padding: 2px 8px; border-radius: 14px;">
                                <span style="width: 18px; height: 18px; border-radius: 50%; background: var(--panel-soft); color: var(--lime); display: inline-flex; align-items: center; justify-content: center; font-size: 9px; font-weight: 700;">${m.initials}</span>
                                <span>${displayName}</span>
                                <button type="button" class="na-chip-remove" data-id="${id}" style="background: none; border: none; color: var(--muted); cursor: pointer; padding: 0 2px; font-size: 13px; line-height: 1; display: inline-flex; align-items: center;" title="Remove">✕</button>
                            </span>
                        `;
                    }).join('');

                    // Display gentle warning callout if any selected members are already active with another coach
                    const warningMembers = selectedMemberIds
                        .map(id => ftMembersData.find(item => item.id === id))
                        .filter(m => m && m.active_trainer);

                    const existingWarnBox = document.getElementById('naDoubleAssignWarning');
                    if (warningMembers.length > 0) {
                        const warnNames = warningMembers.map(m => m.full_name || m.name.split(' (')[0]).join(', ');
                        const warnHtml = `
                            <div id="naDoubleAssignWarning" style="margin-bottom: 8px; padding: 7px 11px; background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 8px; font-size: 11.5px; color: #d97706; display: flex; align-items: flex-start; gap: 7px; line-height: 1.4;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink: 0; margin-top: 2px;"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                <div><strong>Multiple Trainers Note:</strong> ${warnNames} already active with another coach. Proceeding will assign them to both trainers.</div>
                            </div>
                        `;
                        if (existingWarnBox) {
                            existingWarnBox.outerHTML = warnHtml;
                        } else {
                            mChipsBox.insertAdjacentHTML('afterend', warnHtml);
                        }
                    } else if (existingWarnBox) {
                        existingWarnBox.remove();
                    }

                    mChipsBox.querySelectorAll('.na-chip-remove').forEach(btn => {
                        btn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            const rid = parseInt(btn.getAttribute('data-id'), 10);
                            selectedMemberIds = selectedMemberIds.filter(id => id !== rid);
                            updateSelectedChips();
                            renderMemberList(mSearch.value);
                        });
                    });
                }

                function renderMemberList(query = '') {
                    const q = query.trim().toLowerCase();
                    const filtered = ftMembersData.filter(m => m.name.toLowerCase().includes(q));

                    if (filtered.length === 0) {
                        mList.innerHTML = `<div style="padding: 12px; text-align: center; color: var(--muted); font-size: 13px;">No members found matching "${q}"</div>`;
                    } else {
                        mList.innerHTML = filtered.map(m => {
                            const isChecked = selectedMemberIds.includes(m.id);
                            return `
                                <div class="na-member-row" data-id="${m.id}"
                                     style="padding: 7px 10px; display: flex; align-items: center; justify-content: space-between; border-radius: 6px; cursor: pointer; margin-bottom: 2px; transition: background 0.15s; background: ${isChecked ? 'rgba(132, 204, 22, 0.12)' : 'transparent'};">
                                    <div style="display: flex; align-items: center; gap: 9px; min-width: 0; flex: 1;">
                                        <input type="checkbox" class="na-member-checkbox" data-id="${m.id}" ${isChecked ? 'checked' : ''} style="cursor: pointer; width: 15px; height: 15px; accent-color: var(--lime); flex-shrink: 0;">
                                        <div style="width: 26px; height: 26px; border-radius: 50%; background: var(--panel-soft); color: var(--lime); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; border: 1px solid var(--line); flex-shrink: 0;">
                                            ${m.initials}
                                        </div>
                                        <div style="min-width: 0; flex: 1;">
                                            <div style="color: var(--ink); font-size: 13px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                ${m.name}
                                            </div>
                                            ${m.active_trainer ? `
                                                <div style="font-size: 10.5px; color: #d97706; display: flex; align-items: center; gap: 4px; margin-top: 1px;">
                                                    <span style="display: inline-block; width: 5px; height: 5px; border-radius: 50%; background: #d97706; flex-shrink: 0;"></span>
                                                    <span>Already with ${m.active_trainer}</span>
                                                </div>
                                            ` : ''}
                                        </div>
                                    </div>
                                    <span style="font-size: 11px; color: ${m.has_plan ? '#22c55e' : 'var(--muted)'}; font-weight: 600; flex-shrink: 0; margin-left: 8px;">
                                        ${m.has_plan ? 'Active Plan' : '1 Day'}
                                    </span>
                                </div>
                            `;
                        }).join('');

                        mList.querySelectorAll('.na-member-row').forEach(row => {
                            const id = parseInt(row.getAttribute('data-id'), 10);
                            row.addEventListener('mouseenter', () => { if (!selectedMemberIds.includes(id)) row.style.background = 'var(--panel-soft)'; });
                            row.addEventListener('mouseleave', () => { if (!selectedMemberIds.includes(id)) row.style.background = 'transparent'; });
                            row.addEventListener('click', (e) => {
                                if (e.target.tagName !== 'INPUT') {
                                    const cb = row.querySelector('.na-member-checkbox');
                                    cb.checked = !cb.checked;
                                }
                                const isChecked = row.querySelector('.na-member-checkbox').checked;
                                if (isChecked && !selectedMemberIds.includes(id)) {
                                    selectedMemberIds.push(id);
                                } else if (!isChecked) {
                                    selectedMemberIds = selectedMemberIds.filter(item => item !== id);
                                }
                                updateSelectedChips();
                                row.style.background = isChecked ? 'rgba(132, 204, 22, 0.12)' : 'transparent';
                            });
                        });
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
                                renderMemberList(q);
                            })
                            .catch(err => console.error('Member search error', err));
                        }, 250);
                    }
                }

                renderMemberList();
                updateSelectedChips();

                mSearch.addEventListener('input', (e) => renderMemberList(e.target.value));

                btnSelectAll.addEventListener('click', () => {
                    const q = mSearch.value.trim().toLowerCase();
                    const filtered = ftMembersData.filter(m => m.name.toLowerCase().includes(q));
                    filtered.forEach(m => {
                        if (!selectedMemberIds.includes(m.id)) selectedMemberIds.push(m.id);
                    });
                    updateSelectedChips();
                    renderMemberList(mSearch.value);
                });

                btnClearAll.addEventListener('click', () => {
                    selectedMemberIds = [];
                    updateSelectedChips();
                    renderMemberList(mSearch.value);
                });

                document.addEventListener('click', function closeMenus(e) {
                    if (tTrigger && tMenu && !tTrigger.contains(e.target) && !tMenu.contains(e.target)) {
                        tMenu.style.display = 'none';
                        tChevron.style.transform = 'rotate(0deg)';
                    }
                });
            },
            preConfirm: () => {
                const form = document.getElementById('addAssignmentForm');
                if (!form.trainer_id.value) {
                    Swal.showValidationMessage('Please select a trainer');
                    return false;
                }
                if (selectedMemberIds.length === 0) {
                    Swal.showValidationMessage('Please select at least one member');
                    return false;
                }
                if (!form.assigned_date.value) {
                    Swal.showValidationMessage('Please select an assigned date');
                    return false;
                }
                form.submit();
            }
        });
    }

    // ── Manage Members in an Existing Assignment ──────────────────────────
    function manageGroupMembers(groupId) {
        Swal.fire({
            title: 'Loading Assignment Details...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
                fetch('index.php?page=trainer_assignments&action=get_group_details&group_id=' + encodeURIComponent(groupId), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        Swal.fire('Error', data.error || 'Failed to load assignment details', 'error');
                        return;
                    }
                    showGroupMembersModal(data);
                })
                .catch(err => {
                    console.error('Group details error', err);
                    Swal.fire('Error', 'Unable to retrieve assignment information.', 'error');
                });
            }
        });
    }

    function showGroupMembersModal(data) {
        let currentMembers = data.members || [];
        let availableMembers = data.available_members || [];
        let selectedAddIds = [];
        let hasModifiedMembers = false;

        Swal.fire({
            title: 'Manage Assignment Members',
            width: 'min(94vw, 580px)',
            html: `
                <style>
                .gmm-member-item {
                    padding: 10px 12px;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    border-radius: 8px;
                    border-bottom: 1px solid var(--line);
                    gap: 12px;
                    transition: background 0.15s;
                }
                .gmm-member-item:last-child {
                    border-bottom: none;
                }
                .gmm-member-item:hover {
                    background: var(--panel-soft);
                }
                .gmm-member-info {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    min-width: 0;
                    flex: 1;
                }
                .gmm-member-controls {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    flex-shrink: 0;
                }
                @media (max-width: 480px) {
                    .gmm-sub-text {
                        display: none !important;
                    }
                    .gmm-member-item {
                        flex-direction: column !important;
                        align-items: stretch !important;
                        gap: 8px !important;
                        padding: 10px 8px !important;
                    }
                    .gmm-member-info {
                        width: 100% !important;
                    }
                    .gmm-member-controls {
                        width: 100% !important;
                        justify-content: space-between !important;
                        padding-top: 6px !important;
                        border-top: 1px dashed color-mix(in srgb, var(--line) 70%, transparent) !important;
                    }
                }
                </style>
                <div style="text-align: left; margin-top: 10px;">
                    <!-- Header info card -->
                    <div style="padding: 10px 14px; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; margin-bottom: 14px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: var(--ink);">${data.activity_title}</h4>
                            <span style="font-size: 11.5px; color: var(--muted);">${data.assigned_date}</span>
                        </div>
                        <div style="font-size: 13px; color: var(--muted);">
                            Trainer: <strong style="color: var(--ink);">${data.trainer_name}</strong> • ${data.specialization}
                        </div>
                    </div>

                    <!-- Inline Notification Alert Banner -->
                    <div id="gmmAlertBox" style="display: none; padding: 9px 12px; border-radius: 8px; font-size: 12.5px; font-weight: 600; margin-bottom: 14px; align-items: center; gap: 8px; transition: all 0.3s ease;"></div>

                    <!-- Assigned Members List -->
                    <div style="margin-bottom: 18px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; gap: 8px;">
                            <div style="font-size: 13.5px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 7px;">
                                <span>Assigned Members</span>
                                <span id="gmmCountBadge" style="font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px; background: rgba(132, 204, 22, 0.15); color: var(--lime); border: 1px solid rgba(132, 204, 22, 0.3); display: inline-flex; align-items: center; justify-content: center; line-height: 1;">${currentMembers.length}</span>
                            </div>
                            <span class="gmm-sub-text" style="font-size: 11.5px; color: var(--muted);">Individual status and actions</span>
                        </div>
                        
                        <div id="gmmCurrentList" style="max-height: 220px; overflow-y: auto; background: var(--surface); border: 1px solid var(--line); border-radius: 8px; padding: 4px;">
                        </div>
                    </div>

                    <!-- Add More Members Section -->
                    <div style="padding-top: 12px; border-top: 1px solid var(--line);">
                        <label style="font-size: 13px; font-weight: 700; color: var(--ink); margin-bottom: 8px; display: block;">
                            Add Members to Assignment
                        </label>

                        <div style="display: flex; gap: 8px; margin-bottom: 8px; flex-wrap: wrap;">
                            <input type="text" id="gmmAvailSearch" placeholder="Search gym members to add..." autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault();event.stopPropagation();}" style="flex: 1; min-width: 160px; box-sizing: border-box; padding: 7px 10px; background: var(--bg); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); font-size: 13px; outline: none;">
                            <button type="button" id="gmmAddSelectedBtn" onclick="event.preventDefault(); event.stopPropagation();" class="btn-sm" style="background: var(--lime); color: var(--bg); font-weight: 700; padding: 0 14px; white-space: nowrap; opacity: 0.5; cursor: not-allowed; border: none; transition: all 0.2s;" disabled>
                                Add Member(s)
                            </button>
                        </div>

                        <div id="gmmAvailList" style="max-height: 140px; overflow-y: auto; background: var(--surface); border: 1px solid var(--line); border-radius: 8px; padding: 4px;">
                        </div>
                    </div>

                    <!-- Bottom Action Controls -->
                    <div style="margin-top: 20px; display: flex; justify-content: center; align-items: center; border-top: 1px solid var(--line); padding-top: 16px;">
                        <button type="button" id="gmmDoneBtn" onclick="event.preventDefault(); event.stopPropagation();" style="background: var(--lime); color: var(--bg); font-weight: 700; padding: 10px 42px; font-size: 14px; border-radius: 8px; border: none; cursor: pointer; transition: opacity 0.2s; min-width: 140px;">
                            Done
                        </button>
                    </div>
                </div>
            `,
            showConfirmButton: false,
            showCancelButton: false,
            showCloseButton: true,
            allowOutsideClick: false,
            allowEscapeKey: false,
            willClose: () => {
                if (hasModifiedMembers) {
                    window.location.reload();
                }
            },
            didOpen: () => {
                const alertBox = document.getElementById('gmmAlertBox');
                const currentList = document.getElementById('gmmCurrentList');
                const countBadge = document.getElementById('gmmCountBadge');
                const availSearch = document.getElementById('gmmAvailSearch');
                const availList = document.getElementById('gmmAvailList');
                const addBtn = document.getElementById('gmmAddSelectedBtn');
                const doneBtn = document.getElementById('gmmDoneBtn');

                if (doneBtn) {
                    doneBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        Swal.close();
                    });
                }

                let alertTimer = null;
                function showAlert(msg, isSuccess = true) {
                    if (!alertBox) return;
                    if (alertTimer) clearTimeout(alertTimer);
                    alertBox.style.display = 'flex';
                    alertBox.style.background = isSuccess ? 'rgba(34, 197, 94, 0.12)' : 'rgba(239, 68, 68, 0.12)';
                    alertBox.style.color = isSuccess ? '#22c55e' : '#ef4444';
                    alertBox.style.border = isSuccess ? '1px solid rgba(34, 197, 94, 0.3)' : '1px solid rgba(239, 68, 68, 0.3)';
                    alertBox.innerHTML = `
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <span>${msg}</span>
                    `;
                    alertTimer = setTimeout(() => {
                        if (alertBox) alertBox.style.display = 'none';
                    }, 4000);
                }

                function renderCurrentList() {
                    if (countBadge) countBadge.textContent = currentMembers.length;
                    if (!currentList) return;

                    if (currentMembers.length === 0) {
                        currentList.innerHTML = '<div style="padding: 16px; text-align: center; color: var(--muted); font-size: 13px;">No members currently in this assignment.</div>';
                        return;
                    }

                    currentList.innerHTML = currentMembers.map(m => {
                        const statusBadge = m.status === 'active' 
                            ? '<span class="badge badge-active" style="font-size: 10px; padding: 2px 7px;">Active</span>' 
                            : `<span class="badge" style="background: var(--panel-soft); color: var(--muted); font-size: 10px; padding: 2px 7px;">${m.status}</span>`;
                        return `
                            <div class="gmm-member-item">
                                <div class="gmm-member-info">
                                    <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--panel-soft); color: var(--lime); display: flex; align-items: center; justify-content: center; font-size: 10.5px; font-weight: 700; border: 1px solid var(--line); flex-shrink: 0;">
                                        ${m.initials}
                                    </div>
                                    <div style="min-width: 0; flex: 1;">
                                        <div style="font-size: 13.5px; font-weight: 600; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            ${m.full_name}
                                        </div>
                                        <div style="font-size: 11.5px; color: var(--muted); display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                            ${m.has_plan ? '<span style="color:#22c55e; font-weight: 600;">Has Plan</span>' : '<span>1 Day</span>'}
                                            <span style="opacity: 0.4;">•</span>
                                            <span>Assigned: ${m.assigned_date}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="gmm-member-controls">
                                    <div style="display: flex; align-items: center;">
                                        ${statusBadge}
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <a href="index.php?page=diet_builder&member_user_id=${m.user_id}&ref=trainer_assignments" class="btn-sm btn-ghost" style="padding: 4px 10px; font-size: 11px; text-decoration: none; border: 1px solid var(--line); color: var(--ink); font-weight: 600; border-radius: 6px;" title="Diet Plan">
                                            Diet
                                        </a>
                                        <button type="button" class="btn-sm btn-danger gmm-remove-btn" onclick="event.preventDefault(); event.stopPropagation();" data-assign-id="${m.assignment_id}" data-name="${encodeURIComponent(m.full_name)}" style="padding: 4px 10px; font-size: 11px; font-weight: 600; border-radius: 6px;" title="Remove from assignment">
                                            Remove
                                        </button>
                                    </div>
                                </div>
                            </div>
                        `;
                    }).join('');

                    // Bind remove buttons
                    currentList.querySelectorAll('.gmm-remove-btn').forEach(btn => {
                        btn.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            const assignId = parseInt(btn.getAttribute('data-assign-id'), 10);
                            const name = decodeURIComponent(btn.getAttribute('data-name'));

                            if (!confirm(`Are you sure you want to remove ${name} from this assignment?`)) {
                                return;
                            }

                            btn.disabled = true;
                            btn.textContent = 'Removing...';

                            const formData = new FormData();
                            formData.append('action', 'remove_group_member');
                            formData.append('assignment_id', assignId);
                            formData.append('csrf_token', ftCsrfToken);
                            formData.append('is_ajax', '1');

                            fetch('index.php?page=trainer_assignments', {
                                method: 'POST',
                                headers: { 
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': ftCsrfToken
                                },
                                body: formData
                            })
                            .then(r => r.json())
                            .then(resp => {
                                if (resp.success) {
                                    hasModifiedMembers = true;
                                    showAlert(`Removed ${name} from assignment.`);
                                    // Re-fetch and update lists in place
                                    fetch('index.php?page=trainer_assignments&action=get_group_details&group_id=' + encodeURIComponent(data.group_id), {
                                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                                    })
                                    .then(r => r.json())
                                    .then(updatedData => {
                                        data = updatedData;
                                        currentMembers = updatedData.members || [];
                                        availableMembers = updatedData.available_members || [];
                                        selectedAddIds = [];
                                        renderCurrentList();
                                        renderAvailList(availSearch ? availSearch.value : '');
                                    });
                                } else {
                                    btn.disabled = false;
                                    btn.textContent = 'Remove';
                                    showAlert(resp.message || resp.error || 'Failed to remove member', false);
                                }
                            })
                            .catch(err => {
                                btn.disabled = false;
                                btn.textContent = 'Remove';
                                console.error('Remove member error', err);
                                showAlert('Failed to remove member', false);
                            });
                        });
                    });
                }

                function renderAvailList(q = '') {
                    const term = q.trim().toLowerCase();
                    const filtered = availableMembers.filter(m => m.name.toLowerCase().includes(term));

                    if (filtered.length === 0) {
                        availList.innerHTML = `<div style="padding: 12px; text-align: center; color: var(--muted); font-size: 12.5px;">${availableMembers.length === 0 ? 'All eligible gym members are already in this assignment.' : 'No matching members found'}</div>`;
                        return;
                    }

                    availList.innerHTML = filtered.map(m => {
                        const isChecked = selectedAddIds.includes(m.id);
                        return `
                            <div class="gmm-avail-row" data-id="${m.id}" style="padding: 6px 10px; display: flex; align-items: center; justify-content: space-between; border-radius: 6px; cursor: pointer; margin-bottom: 2px; background: ${isChecked ? 'rgba(132, 204, 22, 0.12)' : 'transparent'};">
                                <div style="display: flex; align-items: center; gap: 8px; min-width: 0; flex: 1;">
                                    <input type="checkbox" class="gmm-avail-cb" data-id="${m.id}" ${isChecked ? 'checked' : ''} style="cursor: pointer; width: 14px; height: 14px; accent-color: var(--lime); flex-shrink: 0;">
                                    <div style="min-width: 0; flex: 1;">
                                        <div style="font-size: 12.5px; font-weight: 500; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${m.name}</div>
                                        ${m.active_trainer ? `
                                            <div style="font-size: 10.5px; color: #d97706; display: flex; align-items: center; gap: 3px; margin-top: 1px;">
                                                <span style="display: inline-block; width: 5px; height: 5px; border-radius: 50%; background: #d97706; flex-shrink: 0;"></span>
                                                <span>Active with ${m.active_trainer}</span>
                                            </div>
                                        ` : ''}
                                    </div>
                                </div>
                                <span style="font-size: 11px; color: ${m.has_plan ? '#22c55e' : 'var(--muted)'}; flex-shrink: 0; margin-left: 8px;">
                                    ${m.has_plan ? 'Has Plan' : '1 Day'}
                                </span>
                            </div>
                        `;
                    }).join('');

                    availList.querySelectorAll('.gmm-avail-row').forEach(row => {
                        const id = parseInt(row.getAttribute('data-id'), 10);
                        row.addEventListener('click', (e) => {
                            e.stopPropagation();
                            if (e.target.tagName !== 'INPUT') {
                                const cb = row.querySelector('.gmm-avail-cb');
                                cb.checked = !cb.checked;
                            }
                            const isChecked = row.querySelector('.gmm-avail-cb').checked;
                            if (isChecked && !selectedAddIds.includes(id)) {
                                selectedAddIds.push(id);
                            } else if (!isChecked) {
                                selectedAddIds = selectedAddIds.filter(x => x !== id);
                            }
                            addBtn.disabled = selectedAddIds.length === 0;
                            addBtn.style.opacity = selectedAddIds.length > 0 ? '1' : '0.5';
                            addBtn.style.cursor = selectedAddIds.length > 0 ? 'pointer' : 'not-allowed';
                            addBtn.textContent = selectedAddIds.length > 0 ? `Add (${selectedAddIds.length})` : 'Add Member(s)';
                            row.style.background = isChecked ? 'rgba(132, 204, 22, 0.12)' : 'transparent';
                        });
                    });
                }

                renderCurrentList();
                renderAvailList();

                availSearch.addEventListener('input', (e) => renderAvailList(e.target.value));

                addBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (selectedAddIds.length === 0) return;
                    addBtn.disabled = true;
                    addBtn.textContent = 'Adding...';

                    const formData = new FormData();
                    formData.append('action', 'add_members_to_group');
                    formData.append('group_id', data.group_id);
                    formData.append('member_user_ids', selectedAddIds.join(','));
                    formData.append('csrf_token', ftCsrfToken);
                    formData.append('is_ajax', '1');

                    fetch('index.php?page=trainer_assignments', {
                        method: 'POST',
                        headers: { 
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': ftCsrfToken
                        },
                        body: formData
                    })
                    .then(r => r.json())
                    .then(resp => {
                        if (resp.success) {
                            hasModifiedMembers = true;
                            if (resp.group_id) {
                                data.group_id = resp.group_id;
                            }
                            showAlert(resp.message || 'Member(s) added successfully.');
                            addBtn.textContent = 'Added ✓';
                            addBtn.style.background = '#22c55e';
                            addBtn.style.color = '#ffffff';

                            fetch('index.php?page=trainer_assignments&action=get_group_details&group_id=' + encodeURIComponent(data.group_id), {
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            })
                            .then(r => r.json())
                            .then(updatedData => {
                                data = updatedData;
                                currentMembers = updatedData.members || [];
                                availableMembers = updatedData.available_members || [];
                                selectedAddIds = [];
                                setTimeout(() => {
                                    if (addBtn) {
                                        addBtn.disabled = true;
                                        addBtn.style.opacity = '0.5';
                                        addBtn.style.cursor = 'not-allowed';
                                        addBtn.style.background = 'var(--lime)';
                                        addBtn.style.color = 'var(--bg)';
                                        addBtn.textContent = 'Add Member(s)';
                                    }
                                }, 1200);
                                renderCurrentList();
                                renderAvailList(availSearch ? availSearch.value : '');
                            });
                        } else {
                            addBtn.disabled = false;
                            addBtn.textContent = 'Add Member(s)';
                            showAlert(resp.message || resp.error || 'Failed to add members', false);
                        }
                    })
                    .catch(err => {
                        addBtn.disabled = false;
                        addBtn.textContent = 'Add Member(s)';
                        console.error('Add member error', err);
                        showAlert('Failed to add members', false);
                    });
                });
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
                        <div id="ftCustomSelectTrigger" style="width: 100%; box-sizing: border-box; padding: 11px 14px; border-radius: 8px; font-size: 14px; background: var(--panel); color: var(--ink); border: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none;">
                            <span id="ftSelectedMemberText" style="color: var(--muted); display: flex; align-items: center; gap: 8px;">Select Member...</span>
                            <svg id="ftSelectChevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="transition: transform 0.2s; color: var(--muted);"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </div>
                        <div id="ftCustomDropdownMenu" style="display: none; width: 100%; box-sizing: border-box; margin-top: 6px; background: var(--surface); border: 1px solid var(--line); border-radius: 8px; max-height: 230px; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.25); z-index: 1050;">
                            <div style="padding: 8px; border-bottom: 1px solid var(--line); background: var(--panel); position: sticky; top: 0; z-index: 2;">
                                <input type="text" id="ftMemberSearch" placeholder="Search member..." autocomplete="off" style="width: 100%; box-sizing: border-box; padding: 8px 12px; background: var(--bg); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); font-size: 13px; outline: none;">
                            </div>
                            <div id="ftMemberListContainer" style="padding: 4px;"></div>
                        </div>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Open Diet Plan',
            confirmButtonColor: 'var(--lime-dark)',
            didOpen: () => {
                const trigger = document.getElementById('ftCustomSelectTrigger');
                const menu = document.getElementById('ftCustomDropdownMenu');
                const chevron = document.getElementById('ftSelectChevron');
                const listContainer = document.getElementById('ftMemberListContainer');
                const searchInput = document.getElementById('ftMemberSearch');

                function bindDietClicks() {
                    listContainer.querySelectorAll('.ft-member-item').forEach(el => {
                        el.addEventListener('mouseenter', () => {
                            if (parseInt(el.getAttribute('data-id'), 10) !== selectedId) el.style.background = 'var(--panel-soft)';
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
                                    <span style="width: 22px; height: 22px; border-radius: 50%; background: var(--panel-soft); color: var(--lime); display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; border: 1px solid var(--line);">${ini}</span>
                                    <span style="color: var(--ink); font-weight: 600;">${selectedName}</span>
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
                                        <div style="width: 30px; height: 30px; border-radius: 50%; background: var(--panel-soft); color: var(--lime); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; border: 1px solid var(--line);">
                                            ${m.initials}
                                        </div>
                                        <span style="color: var(--ink); font-size: 13.5px; font-weight: 600;">${m.name}</span>
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
                                                    <div style="width: 30px; height: 30px; border-radius: 50%; background: var(--panel-soft); color: var(--lime); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; border: 1px solid var(--line);">
                                                        ${m.initials}
                                                    </div>
                                                    <span style="color: var(--ink); font-size: 13.5px; font-weight: 600;">${m.name}</span>
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

    // ── Pagination & Live Search for Assignments Table & Mobile Cards ────
    let assignCurrentPage = 1;
    let assignPageSize = 10;
    let assignAllRows = [];
    let assignAllCards = [];
    let assignFilteredIndices = [];

    const assignSearchInput = document.getElementById('assignmentSearchInput');
    const assignSearchClear = document.getElementById('assignmentSearchClear');
    const assignPerPageSelect = document.getElementById('assignPerPageSelect');
    const assignCountLabel = document.getElementById('assignmentCountLabel');
    const assignEmptyState = document.getElementById('assignmentEmptyState');
    const assignEmptyStateText = document.getElementById('assignmentEmptyStateText');
    const assignTableWrap = document.querySelector('.assignments-desktop-table');
    const assignCardsWrap = document.getElementById('assignmentMobileCards');
    const assignPaginationBar = document.getElementById('assignmentPaginationBar');
    const assignPaginationInfo = document.getElementById('assignmentPaginationInfo');
    const assignPaginationControls = document.getElementById('assignmentPaginationControls');

    function initAssignPagination() {
        assignAllRows = Array.from(document.querySelectorAll('.assignment-row'));
        assignAllCards = Array.from(document.querySelectorAll('.assignment-card-item'));
        const totalItems = Math.max(assignAllRows.length, assignAllCards.length);
        if (totalItems === 0) return;

        assignFilteredIndices = Array.from({ length: totalItems }, (_, i) => i);

        if (assignSearchInput) {
            assignSearchInput.addEventListener('input', function() {
                const query = this.value.trim().toLowerCase();
                if (assignSearchClear) {
                    assignSearchClear.style.display = query !== '' ? 'block' : 'none';
                }

                assignFilteredIndices = [];
                for (let i = 0; i < totalItems; i++) {
                    const targetEl = assignAllRows[i] || assignAllCards[i];
                    if (!query) {
                        assignFilteredIndices.push(i);
                    } else if (targetEl) {
                        const coach = (targetEl.getAttribute('data-coach') || '');
                        const title = (targetEl.getAttribute('data-title') || '');
                        const type = (targetEl.getAttribute('data-type') || '');
                        const member = (targetEl.getAttribute('data-member') || '');
                        const status = (targetEl.getAttribute('data-status') || '');
                        if (coach.includes(query) || title.includes(query) || type.includes(query) || member.includes(query) || status.includes(query)) {
                            assignFilteredIndices.push(i);
                        }
                    }
                }

                assignCurrentPage = 1;
                renderAssignPage();
            });

            assignSearchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    clearAssignmentSearch();
                }
            });
        }

        if (assignPerPageSelect) {
            assignPerPageSelect.addEventListener('change', function() {
                assignPageSize = parseInt(this.value, 10) || 10;
                assignCurrentPage = 1;
                renderAssignPage();
            });
        }

        renderAssignPage();
    }

    function renderAssignPage() {
        const totalItems = assignFilteredIndices.length;
        const totalPages = Math.max(1, Math.ceil(totalItems / assignPageSize));

        if (assignCurrentPage > totalPages) assignCurrentPage = totalPages;
        if (assignCurrentPage < 1) assignCurrentPage = 1;

        const startIndex = (assignCurrentPage - 1) * assignPageSize;
        const endIndex = Math.min(startIndex + assignPageSize, totalItems);
        const visibleSet = new Set(assignFilteredIndices.slice(startIndex, endIndex));

        // Toggle table rows
        assignAllRows.forEach((r, idx) => {
            r.style.display = visibleSet.has(idx) ? '' : 'none';
        });

        // Toggle mobile cards
        assignAllCards.forEach((c, idx) => {
            c.style.display = visibleSet.has(idx) ? '' : 'none';
        });

        // Count label & search query state
        const query = assignSearchInput ? assignSearchInput.value.trim() : '';
        if (assignCountLabel) {
            assignCountLabel.textContent = (query ? totalItems : assignAllRows.length) + ' assignments' + (query ? ' found' : '');
        }

        if (query && totalItems === 0) {
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

        updateAssignPaginationUI(totalItems, totalPages, startIndex, endIndex);
    }

    function updateAssignPaginationUI(totalItems, totalPages, startIndex, endIndex) {
        if (!assignPaginationBar || !assignPaginationInfo || !assignPaginationControls) return;

        const maxTotal = Math.max(assignAllRows.length, assignAllCards.length);
        if (maxTotal === 0 || totalItems === 0) {
            assignPaginationBar.style.display = 'none';
            return;
        }

        assignPaginationBar.style.display = 'flex';

        const startDisplay = startIndex + 1;
        assignPaginationInfo.innerHTML = `Showing <strong>${startDisplay}</strong> to <strong>${endIndex}</strong> of <strong>${totalItems}</strong> assignment${totalItems === 1 ? '' : 's'}`;

        if (totalPages <= 1) {
            assignPaginationControls.innerHTML = '';
            return;
        }

        let html = '';

        // Prev Button
        const prevDisabled = assignCurrentPage === 1 ? ' disabled' : '';
        html += `<button type="button" class="assign-page-btn${prevDisabled}" onclick="goToAssignPage(${assignCurrentPage - 1})" aria-label="Previous page">← Prev</button>`;

        // Numbered buttons
        const startPage = Math.max(1, assignCurrentPage - 2);
        const endPage = Math.min(totalPages, assignCurrentPage + 2);

        if (startPage > 1) {
            html += `<button type="button" class="assign-page-btn" onclick="goToAssignPage(1)">1</button>`;
            if (startPage > 2) {
                html += `<span class="assign-page-ellipsis">…</span>`;
            }
        }

        for (let p = startPage; p <= endPage; p++) {
            if (p === assignCurrentPage) {
                html += `<button type="button" class="assign-page-btn active">${p}</button>`;
            } else {
                html += `<button type="button" class="assign-page-btn" onclick="goToAssignPage(${p})">${p}</button>`;
            }
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<span class="assign-page-ellipsis">…</span>`;
            }
            html += `<button type="button" class="assign-page-btn" onclick="goToAssignPage(${totalPages})">${totalPages}</button>`;
        }

        // Next Button
        const nextDisabled = assignCurrentPage === totalPages ? ' disabled' : '';
        html += `<button type="button" class="assign-page-btn${nextDisabled}" onclick="goToAssignPage(${assignCurrentPage + 1})" aria-label="Next page">Next →</button>`;

        assignPaginationControls.innerHTML = html;
    }

    function goToAssignPage(page) {
        assignCurrentPage = page;
        renderAssignPage();
        const targetView = window.innerWidth <= 768 ? assignCardsWrap : assignTableWrap;
        if (targetView) {
            const rect = targetView.getBoundingClientRect();
            if (rect.top < 0) {
                targetView.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    function clearAssignmentSearch() {
        if (assignSearchInput) {
            assignSearchInput.value = '';
            assignSearchInput.focus();
        }
        if (assignSearchClear) assignSearchClear.style.display = 'none';

        const totalItems = Math.max(assignAllRows.length, assignAllCards.length);
        assignFilteredIndices = Array.from({ length: totalItems }, (_, i) => i);
        assignCurrentPage = 1;
        renderAssignPage();
    }

    document.addEventListener('DOMContentLoaded', initAssignPagination);
    if (document.readyState === 'interactive' || document.readyState === 'complete') {
        initAssignPagination();
    }
    </script>
    <?php
    render_footer();
}
