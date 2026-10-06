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
    <link rel="stylesheet" href="<?= h(asset_url('css/pages/trainer_assignments.css')) ?>">

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
    <!-- Trainer Assignments Configuration & Script -->
    <script>
    window.TRAINER_ASSIGNMENTS_CONFIG = {
        coaches: <?= json_encode($coachesList) ?>,
        members: <?= json_encode($assignMembersList) ?>,
        dietMembers: <?= json_encode($dietMembersList) ?>,
        csrfToken: <?= json_encode(csrf_token()) ?>,
        currentDate: <?= json_encode(date('Y-m-d')) ?>
    };
    </script>
    <script src="<?= h(asset_url('js/pages/trainer_assignments.js')) ?>"></script>
    <?php
    render_footer();
}
