<?php
declare(strict_types=1);

function check_schedule_conflicts(PDO $pdo, int $classId, string $startDatetime, string $endDatetime, ?string $roomLocation, ?int $ignoreScheduleId, ?int $facilityGymId): ?string
{
    // 1. Same-Day Constraint & Duration Guardrails
    $startTs = strtotime($startDatetime);
    $endTs = strtotime($endDatetime);
    if (date('Y-m-d', $startTs) !== date('Y-m-d', $endTs)) {
        return 'Class sessions must start and end on the same calendar day. Overnight multi-day sessions are not allowed.';
    }

    $durationMinutes = ($endTs - $startTs) / 60;
    if ($durationMinutes < 15) {
        return 'Class duration must be at least 15 minutes.';
    }
    if ($durationMinutes > 240) {
        return 'Class session exceeds the maximum allowed duration of 4 hours.';
    }

    // Get class info
    $classStmt = $pdo->prepare('SELECT class_name, instructor_id, gym_id FROM classes WHERE class_id = ?');
    $classStmt->execute([$classId]);
    $classInfo = $classStmt->fetch(PDO::FETCH_ASSOC);
    if (!$classInfo) {
        return 'Selected class type could not be found.';
    }

    $className = $classInfo['class_name'];
    $instructorId = $classInfo['instructor_id'] ? (int) $classInfo['instructor_id'] : null;
    $gymId = $classInfo['gym_id'] ? (int) $classInfo['gym_id'] : $facilityGymId;

    // 2. Room / Location Conflict
    $cleanRoom = trim((string) $roomLocation);
    if ($cleanRoom !== '' && $cleanRoom !== '—') {
        $roomSql = "
            SELECT s.schedule_id, c.class_name, s.start_datetime, s.end_datetime, s.room_location
            FROM class_schedules s
            JOIN classes c ON c.class_id = s.class_id
            WHERE s.start_datetime < ? AND s.end_datetime > ?
              AND LOWER(TRIM(s.room_location)) = LOWER(TRIM(?))
              AND (c.gym_id <=> ? OR ? IS NULL)
        ";
        $params = [$endDatetime, $startDatetime, $cleanRoom, $gymId, $gymId];
        if ($ignoreScheduleId) {
            $roomSql .= " AND s.schedule_id != ?";
            $params[] = $ignoreScheduleId;
        }
        $roomSql .= " LIMIT 1";

        $stmt = $pdo->prepare($roomSql);
        $stmt->execute($params);
        $roomConflict = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($roomConflict) {
            $cStart = date('g:i A', strtotime($roomConflict['start_datetime']));
            $cEnd = date('g:i A', strtotime($roomConflict['end_datetime']));
            return "Room Conflict: Studio \"{$cleanRoom}\" is already booked for \"{$roomConflict['class_name']}\" ({$cStart} – {$cEnd}). Please select a different room or time.";
        }
    }

    // 3. Instructor Conflict
    if ($instructorId !== null && $instructorId > 0) {
        $instSql = "
            SELECT s.schedule_id, c.class_name, s.start_datetime, s.end_datetime
            FROM class_schedules s
            JOIN classes c ON c.class_id = s.class_id
            WHERE s.start_datetime < ? AND s.end_datetime > ?
              AND c.instructor_id = ?
        ";
        $params = [$endDatetime, $startDatetime, $instructorId];
        if ($ignoreScheduleId) {
            $instSql .= " AND s.schedule_id != ?";
            $params[] = $ignoreScheduleId;
        }
        $instSql .= " LIMIT 1";

        $stmt = $pdo->prepare($instSql);
        $stmt->execute($params);
        $instConflict = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($instConflict) {
            $trainerName = scalar('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE user_id = ?', [$instructorId]) ?: 'The assigned instructor';
            $cStart = date('g:i A', strtotime($instConflict['start_datetime']));
            $cEnd = date('g:i A', strtotime($instConflict['end_datetime']));
            return "Instructor Conflict: {$trainerName} is already scheduled to instruct \"{$instConflict['class_name']}\" ({$cStart} – {$cEnd}). A trainer cannot instruct two sessions simultaneously.";
        }
    }

    // 4. Same Class Type Overlap
    $sameClassSql = "
        SELECT s.schedule_id, c.class_name, s.start_datetime, s.end_datetime
        FROM class_schedules s
        JOIN classes c ON c.class_id = s.class_id
        WHERE s.start_datetime < ? AND s.end_datetime > ?
          AND s.class_id = ?
    ";
    $params = [$endDatetime, $startDatetime, $classId];
    if ($ignoreScheduleId) {
        $sameClassSql .= " AND s.schedule_id != ?";
        $params[] = $ignoreScheduleId;
    }
    $sameClassSql .= " LIMIT 1";

    $stmt = $pdo->prepare($sameClassSql);
    $stmt->execute($params);
    $sameConflict = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($sameConflict) {
        $cStart = date('g:i A', strtotime($sameConflict['start_datetime']));
        $cEnd = date('g:i A', strtotime($sameConflict['end_datetime']));
        return "Schedule Overlap: A session of \"{$className}\" is already scheduled from {$cStart} to {$cEnd}. Sessions of the same class cannot overlap.";
    }

    return null;
}

function classes_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner', 'trainer']);
    if ($user['role'] === 'gym_owner') {
        require_gym_feature('classes');
    }
    $isAdmin = $user['role'] === 'platform_admin';
    $gymId = null;
    if ($user['role'] === 'gym_owner') {
        $gymId = (int) scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
    } elseif ($user['role'] === 'trainer') {
        $gymId = (int) scalar('SELECT gym_id FROM trainer_profiles WHERE user_id = ?', [$user['user_id']]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        if ($action === 'class') {
            $instructor_id = ($user['role'] === 'trainer') ? (int) $user['user_id'] : (post('instructor_id') ? (int) post('instructor_id') : null);
            $className = trim((string) post('class_name'));
            db()->prepare('INSERT INTO classes (class_name, description, instructor_id, capacity, gym_id, created_by) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$className, post('description'), $instructor_id, post('capacity'), $gymId, $user['user_id']]);
            $newClassId = (int) db()->lastInsertId();
            audit_log($user['user_id'], 'create', 'class', (string) $newClassId, json_encode(['class_name' => $className]));

            // In-app notification to the trainer when assigned to this class
            if (!empty($instructor_id) && $instructor_id !== (int) $user['user_id']) {
                notify_user(
                    $instructor_id,
                    'class_reminder',
                    'Class Assignment',
                    'You have been assigned as the instructor for class "' . $className . '".',
                    $newClassId
                );
            }

            flash('Class created.');
            redirect('classes&tab=types');
        } elseif ($action === 'schedule') {
            $class_id = post('class_id');
            $startDatetime = post('start_datetime');
            $endDatetime = post('end_datetime');

            if (!$class_id || !$startDatetime || !$endDatetime) {
                flash('Please select a class and provide both start and end times.', 'danger');
                redirect('classes&tab=schedules');
            }

            $startTimestamp = strtotime($startDatetime);
            $endTimestamp = strtotime($endDatetime);
            if ($startTimestamp === false || $endTimestamp === false) {
                flash('Invalid date or time format provided.', 'danger');
                redirect('classes&tab=schedules');
            }

            // Disallow scheduling on past days or past times (60s tolerance for latency)
            if ($startTimestamp < (time() - 60)) {
                flash('Cannot schedule a session on past days or times. Please select an upcoming date and time.', 'danger');
                redirect('classes&tab=schedules');
            }

            if ($endTimestamp <= $startTimestamp) {
                flash('End Date/Time must be after Start Date/Time.', 'danger');
                redirect('classes&tab=schedules');
            }

            if (!$isAdmin) {
                $c = db()->prepare('SELECT instructor_id, gym_id FROM classes WHERE class_id=?');
                $c->execute([$class_id]);
                $cls = $c->fetch();
                if (!$cls) redirect('classes&tab=schedules');
                
                if ($user['role'] === 'gym_owner' && $cls['gym_id'] != $gymId) redirect('classes&tab=schedules');
                if ($user['role'] === 'trainer' && $cls['instructor_id'] != $user['user_id']) redirect('classes&tab=schedules');
            }

            $conflictErr = check_schedule_conflicts(db(), (int) $class_id, $startDatetime, $endDatetime, post('room_location'), null, $gymId);
            if ($conflictErr !== null) {
                flash($conflictErr, 'danger');
                redirect('classes&tab=schedules');
            }

            db()->prepare('INSERT INTO class_schedules (class_id, room_location, start_datetime, end_datetime) VALUES (?, ?, ?, ?)')
                ->execute([$class_id, post('room_location'), $startDatetime, $endDatetime]);
            
            $startTime = date('M j, Y g:i A', $startTimestamp);
            Queue::push('broadcast_class_schedule_job', [
                'class_id' => (int) $class_id,
                'start_time' => $startTime,
            ]);
            audit_log($user['user_id'], 'create', 'class_schedule', (string) db()->lastInsertId(), json_encode(['class_id' => $class_id, 'start' => post('start_datetime')]));
            
            flash('Schedule created.');
            redirect('classes&tab=schedules');
        } elseif ($action === 'edit_class') {
            $classId = (int) post('class_id');
            $prevInstructorId = (int) scalar('SELECT instructor_id FROM classes WHERE class_id = ?', [$classId]);
            $className = trim((string) post('class_name'));
            $newInstructorId = ($user['role'] === 'trainer') ? (int) $user['user_id'] : (post('instructor_id') ? (int) post('instructor_id') : null);

            if ($isAdmin) {
                db()->prepare('UPDATE classes SET class_name=?, description=?, instructor_id=?, capacity=? WHERE class_id=?')
                    ->execute([$className, post('description'), $newInstructorId, post('capacity'), $classId]);
            } elseif ($user['role'] === 'gym_owner') {
                db()->prepare('UPDATE classes SET class_name=?, description=?, instructor_id=?, capacity=? WHERE class_id=? AND gym_id=?')
                    ->execute([$className, post('description'), $newInstructorId, post('capacity'), $classId, $gymId]);
            } else {
                db()->prepare('UPDATE classes SET class_name=?, description=?, capacity=? WHERE class_id=? AND instructor_id=?')
                    ->execute([$className, post('description'), post('capacity'), $classId, $user['user_id']]);
            }

            // In-app notification to the trainer if newly assigned or changed
            if (!empty($newInstructorId) && $newInstructorId !== $prevInstructorId && $newInstructorId !== (int) $user['user_id']) {
                notify_user(
                    $newInstructorId,
                    'class_reminder',
                    'Class Assignment',
                    'You have been assigned as the instructor for class "' . $className . '".',
                    $classId
                );
            }

            flash('Class updated.');
            audit_log($user['user_id'], 'edit', 'class', (string) $classId, json_encode(['class_name' => $className]));
            redirect('classes&tab=types');
        } elseif ($action === 'delete_class') {
            if ($isAdmin) {
                db()->prepare('DELETE FROM classes WHERE class_id=?')->execute([post('class_id')]);
            } elseif ($user['role'] === 'gym_owner') {
                db()->prepare('DELETE FROM classes WHERE class_id=? AND gym_id=?')->execute([post('class_id'), $gymId]);
            } else {
                db()->prepare('DELETE FROM classes WHERE class_id=? AND instructor_id=?')->execute([post('class_id'), $user['user_id']]);
            }
            flash('Class deleted.');
            audit_log($user['user_id'], 'delete', 'class', (string) post('class_id'));
            redirect('classes&tab=types');
        } elseif ($action === 'edit_schedule') {
            $schedule_id = post('schedule_id');
            $class_id = post('class_id');
            $startDatetime = post('start_datetime');
            $endDatetime = post('end_datetime');

            if (!$schedule_id || !$class_id || !$startDatetime || !$endDatetime) {
                flash('Please complete all required fields.', 'danger');
                redirect('classes&tab=schedules');
            }

            $startTimestamp = strtotime($startDatetime);
            $endTimestamp = strtotime($endDatetime);
            if ($startTimestamp === false || $endTimestamp === false) {
                flash('Invalid date or time format provided.', 'danger');
                redirect('classes&tab=schedules');
            }

            // Disallow scheduling on past days or past times
            if ($startTimestamp < (time() - 60)) {
                flash('Cannot schedule a session on past days or times. Please select an upcoming date and time.', 'danger');
                redirect('classes&tab=schedules');
            }

            if ($endTimestamp <= $startTimestamp) {
                flash('End Date/Time must be after Start Date/Time.', 'danger');
                redirect('classes&tab=schedules');
            }

            if (!$isAdmin) {
                $c = db()->prepare('SELECT c.instructor_id, c.gym_id FROM classes c JOIN class_schedules s ON c.class_id = s.class_id WHERE s.schedule_id=?');
                $c->execute([$schedule_id]);
                $cls = $c->fetch();
                if (!$cls) redirect('classes&tab=schedules');
                
                if ($user['role'] === 'gym_owner' && $cls['gym_id'] != $gymId) redirect('classes&tab=schedules');
                if ($user['role'] === 'trainer' && $cls['instructor_id'] != $user['user_id']) redirect('classes&tab=schedules');
            }

            $conflictErr = check_schedule_conflicts(db(), (int) $class_id, $startDatetime, $endDatetime, post('room_location'), (int) $schedule_id, $gymId);
            if ($conflictErr !== null) {
                flash($conflictErr, 'danger');
                redirect('classes&tab=schedules');
            }

            db()->prepare('UPDATE class_schedules SET class_id=?, room_location=?, start_datetime=?, end_datetime=? WHERE schedule_id=?')
                ->execute([$class_id, post('room_location'), $startDatetime, $endDatetime, $schedule_id]);
            audit_log($user['user_id'], 'edit', 'class_schedule', (string) $schedule_id, json_encode(['class_id' => $class_id]));
            flash('Schedule updated.');
            redirect('classes&tab=schedules');
        } elseif ($action === 'delete_schedule') {
            $schedule_id = post('schedule_id');
            if (!$isAdmin) {
                $c = db()->prepare('SELECT c.instructor_id, c.gym_id FROM classes c JOIN class_schedules s ON c.class_id = s.class_id WHERE s.schedule_id=?');
                $c->execute([$schedule_id]);
                $cls = $c->fetch();
                if (!$cls) redirect('classes&tab=schedules');
                
                if ($user['role'] === 'gym_owner' && $cls['gym_id'] != $gymId) redirect('classes&tab=schedules');
                if ($user['role'] === 'trainer' && $cls['instructor_id'] != $user['user_id']) redirect('classes&tab=schedules');
            }
            db()->prepare('DELETE FROM class_schedules WHERE schedule_id=?')->execute([$schedule_id]);
            audit_log($user['user_id'], 'delete', 'class_schedule', (string) $schedule_id);
            flash('Schedule deleted.');
            redirect('classes&tab=schedules');
        }
        $tabFallback = in_array($action, ['schedule', 'edit_schedule', 'delete_schedule'], true) ? 'schedules' : 'types';
        redirect('classes&tab=' . $tabFallback);
    }

    if ($isAdmin) {
        $coaches = db()->query('SELECT user_id, CONCAT(first_name, " ", last_name) AS name FROM users WHERE role = "trainer" AND status = "active"')->fetchAll();
        $classes = db()->query('SELECT c.*, CONCAT(u.first_name, " ", u.last_name) AS instructor FROM classes c LEFT JOIN users u ON u.user_id = c.instructor_id ORDER BY c.created_at DESC')->fetchAll();
        $schedules = db()->query('SELECT s.*, c.class_name, c.capacity, (SELECT COUNT(*) FROM class_bookings b WHERE b.schedule_id = s.schedule_id AND b.booking_status = "booked") AS booked FROM class_schedules s JOIN classes c ON c.class_id = s.class_id ORDER BY s.start_datetime DESC')->fetchAll();
    } else {
        $coaches = db()->query('SELECT u.user_id, CONCAT(u.first_name, " ", u.last_name) AS name FROM users u JOIN trainer_profiles tp ON u.user_id = tp.user_id WHERE u.role = "trainer" AND u.status = "active" AND tp.gym_id = ' . (int)$gymId)->fetchAll();
        
        $stmt = db()->prepare('SELECT c.*, CONCAT(u.first_name, " ", u.last_name) AS instructor FROM classes c LEFT JOIN users u ON u.user_id = c.instructor_id WHERE c.gym_id = ? ORDER BY c.created_at DESC');
        $stmt->execute([$gymId]);
        $classes = $stmt->fetchAll();
        
        $stmt2 = db()->prepare('SELECT s.*, c.class_name, c.capacity, (SELECT COUNT(*) FROM class_bookings b WHERE b.schedule_id = s.schedule_id AND b.booking_status = "booked") AS booked FROM class_schedules s JOIN classes c ON c.class_id = s.class_id WHERE c.gym_id = ? ORDER BY s.start_datetime DESC');
        $stmt2->execute([$gymId]);
        $schedules = $stmt2->fetchAll();
    }

    // Pre-fetch all enrolled/booked members for each displayed schedule
    $scheduleIds = array_column($schedules, 'schedule_id');
    $bookedMembersBySchedule = [];
    if (!empty($scheduleIds)) {
        $placeholders = implode(',', array_fill(0, count($scheduleIds), '?'));
        $stmtBooked = db()->prepare("
            SELECT b.schedule_id, b.booking_id, b.user_id, b.booking_status, b.booked_at,
                   u.first_name, u.last_name, u.email, u.phone, u.profile_picture
            FROM class_bookings b
            JOIN users u ON u.user_id = b.user_id
            WHERE b.schedule_id IN ($placeholders) AND b.booking_status = 'booked'
            ORDER BY b.booked_at ASC
        ");
        $stmtBooked->execute($scheduleIds);
        $allBookedRows = $stmtBooked->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allBookedRows as $bRow) {
            $bookedMembersBySchedule[(int)$bRow['schedule_id']][] = $bRow;
        }
    }
    
    $activeTab = ($_GET['tab'] ?? '') === 'schedules' ? 'schedules' : 'types';
    $csrfStr = csrf_field();
    $instructorOptions = '<option value="">— None assigned —</option>';
    foreach ($coaches as $trainer) {
        $instructorOptions .= '<option value="' . (int)$trainer['user_id'] . '">' . h($trainer['name']) . '</option>';
    }
    
    $classOptions = '';
    foreach ($classes as $class) {
        $classOptions .= '<option value="' . (int)$class['class_id'] . '">' . h($class['class_name']) . '</option>';
    }

    render_header('Classes', $user);
    ?>
    <style>
        .class-tabs { display: flex; gap: 8px; margin-bottom: 24px; padding: 4px; background: rgba(0,0,0,0.2); border-radius: 12px; width: fit-content; }
        .class-tab-btn { background: transparent; color: var(--muted); border: none; padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; transition: all 0.2s; }
        .class-tab-btn.active { background: var(--surface); color: var(--ink); box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .class-card {
            background: radial-gradient(circle at 95% 0%, color-mix(in srgb, var(--card-accent, #c7ff22) 14%, transparent) 0%, transparent 60%),
                        linear-gradient(145deg, rgba(255, 255, 255, 0.03) 0%, rgba(0, 0, 0, 0.22) 100%),
                        var(--panel-soft);
            border: 1px solid color-mix(in srgb, var(--card-accent, #c7ff22) 22%, var(--line));
            border-radius: 16px;
            padding: 22px;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            flex-direction: column;
            gap: 14px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.12);
        }
        .class-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--card-accent, var(--lime));
            opacity: 0.9;
            transition: opacity 0.2s ease, height 0.2s ease;
        }
        .class-card:hover {
            border-color: color-mix(in srgb, var(--card-accent, var(--lime)) 45%, rgba(255, 255, 255, 0.2));
            transform: translateY(-3px);
            box-shadow: 0 10px 28px rgba(0,0,0,0.25), 0 0 24px color-mix(in srgb, var(--card-accent, var(--lime)) 15%, transparent);
        }
        .class-card:hover::before {
            height: 4px;
            opacity: 1;
        }
        .class-card-watermark {
            position: absolute;
            right: -12px;
            bottom: -12px;
            width: 115px;
            height: 115px;
            color: var(--card-accent, var(--ink));
            opacity: 0.05;
            pointer-events: none;
            user-select: none;
            transition: opacity 0.25s ease, transform 0.25s ease;
        }
        .class-card:hover .class-card-watermark {
            opacity: 0.11;
            transform: scale(1.08) rotate(4deg);
        }
        .class-card-icon-pill {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: color-mix(in srgb, var(--card-accent, var(--lime)) 14%, var(--panel));
            border: 1px solid color-mix(in srgb, var(--card-accent, var(--lime)) 30%, transparent);
            color: var(--card-accent, var(--lime));
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 2px 8px color-mix(in srgb, var(--card-accent, var(--lime)) 15%, transparent);
        }
        .class-card-tag {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--card-accent, var(--lime));
            opacity: 0.88;
            display: block;
            margin-bottom: 2px;
        }
        .class-card-btn-action {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            color: var(--muted);
            cursor: pointer;
            padding: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.18s ease;
        }
        .class-card-btn-action:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--ink);
            border-color: rgba(255, 255, 255, 0.2);
        }
        .class-card-btn-action.btn-del:hover {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border-color: rgba(239, 68, 68, 0.35);
        }
        [data-theme="light"] .class-card {
            background: radial-gradient(circle at 95% 0%, color-mix(in srgb, var(--card-accent, #16a34a) 12%, transparent) 0%, transparent 60%),
                        linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
            border-color: color-mix(in srgb, var(--card-accent, #16a34a) 25%, #e2e8f0);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
        }
        [data-theme="light"] .class-card-btn-action {
            background: #f1f5f9;
            border-color: #e2e8f0;
        }
        .member-pill-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 3px 8px 3px 4px;
            font-size: 12px;
            color: var(--ink);
            max-width: 150px;
            box-sizing: border-box;
        }
        .member-pill-avatar {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: color-mix(in srgb, var(--orange, #ea580c) 25%, var(--panel));
            color: var(--orange, #ea580c);
            font-weight: 700;
            font-size: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .member-pill-name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .btn-see-more-members {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(234, 88, 12, 0.12);
            border: 1px solid rgba(234, 88, 12, 0.28);
            color: var(--orange, #ea580c);
            border-radius: 14px;
            padding: 3px 9px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            width: fit-content;
        }
        .btn-see-more-members:hover {
            background: rgba(234, 88, 12, 0.22);
            border-color: rgba(234, 88, 12, 0.5);
            transform: translateY(-1px);
        }
        .btn-view-all-members-link {
            background: transparent;
            border: none;
            color: var(--muted);
            font-size: 11px;
            font-weight: 500;
            cursor: pointer;
            padding: 0;
            text-align: left;
            text-decoration: underline;
            text-underline-offset: 2px;
            transition: color 0.15s ease;
            width: fit-content;
        }
        .btn-view-all-members-link:hover {
            color: var(--ink);
        }
        [data-theme="light"] .member-pill-tag {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }
        [data-theme="light"] .member-pill-avatar {
            background: #ffedd5;
            color: #c2410c;
        }
        .cc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr)); gap: 16px; }
        .classes-banner {
            background: linear-gradient(135deg, rgba(234, 88, 12, 0.08) 0%, rgba(255, 255, 255, 0.02) 100%);
            border: 1px solid rgba(234, 88, 12, 0.22);
            border-left: 4px solid var(--orange, #ea580c);
            border-radius: 16px;
            padding: 24px 28px;
            margin-bottom: 24px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            backdrop-filter: blur(16px);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
        }
        [data-theme="light"] .classes-banner {
            background: linear-gradient(135deg, #ffffff 0%, #fff7ed 100%);
            border: 1px solid #fed7aa;
            border-left: 4px solid var(--orange, #ea580c);
            box-shadow: 0 4px 18px rgba(15, 23, 42, 0.05);
        }
        [data-theme="dark"] .classes-banner {
            background: linear-gradient(135deg, rgba(26, 30, 40, 0.95) 0%, rgba(16, 19, 27, 0.95) 100%);
            border: 1px solid var(--line);
            border-left: 4px solid var(--orange, #ea580c);
        }
        .classes-banner-title {
            margin: 0;
            font-size: 24px;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 800;
        }
        .classes-banner-icon {
            width: 28px;
            height: 28px;
            flex-shrink: 0;
            color: var(--orange, #ea580c);
        }
        .classes-banner-desc {
            margin: 8px 0 0 0;
            color: var(--muted);
            font-size: 14.5px;
            max-width: 600px;
        }
        .classes-banner-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .classes-banner-btn-secondary {
            background: var(--surface);
            border: 1px solid var(--line);
            color: var(--ink);
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            white-space: nowrap;
            transition: all 0.2s;
        }
        .classes-banner-btn-secondary:hover {
            border-color: var(--orange, #ea580c);
            color: var(--orange, #ea580c);
        }
        [data-theme="light"] .classes-banner-btn-secondary {
            background: #ffffff;
            border-color: #cbd5e1;
        }
        .classes-banner-btn-primary {
            background: var(--orange, #ea580c);
            border: none;
            color: #ffffff;
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            white-space: nowrap;
            transition: opacity 0.2s;
        }
        .classes-banner-btn-primary:hover {
            opacity: 0.9;
        }
        /* Modals in Classes & Schedules */
        dialog#scheduleModal.modal,
        dialog#classModal.modal {
            border: 1px solid var(--line);
            border-radius: 14px;
            background: var(--panel);
            color: var(--ink);
            width: min(440px, calc(100% - 24px));
            max-width: 440px;
            box-shadow: 0 16px 36px rgba(0,0,0,0.45);
        }
        .classes-modal-title {
            margin: 0;
            font-size: 18px;
            color: var(--ink);
            font-weight: 700;
        }
        .classes-modal-form {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .schedule-datetime-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        .schedule-datetime-grid > label {
            min-width: 0;
            width: 100%;
        }
        #scheduleModal .form-control,
        #classModal .form-control {
            width: 100%;
            box-sizing: border-box;
            background: var(--panel) !important;
            border: 1px solid var(--line);
            border-radius: 8px;
            color: var(--ink);
            padding: 8px 12px;
            font-size: 13.5px;
            font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        #scheduleModal select.form-control,
        #classModal select.form-control,
        #scheduleModal input.form-control,
        #classModal input.form-control {
            height: 38px;
        }
        .schedule-select-wrap {
            position: relative;
            width: 100%;
            min-width: 0;
        }
        .schedule-native-select {
            display: none !important;
        }
        .schedule-select-trigger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            width: 100%;
            min-width: 0;
            height: 38px;
            padding: 8px 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel);
            color: var(--ink);
            font: inherit;
            font-size: 13.5px;
            text-align: left;
            cursor: pointer;
        }
        .schedule-select-trigger:hover,
        .schedule-select-trigger:focus,
        .schedule-select-trigger:active {
            background: var(--panel) !important;
            outline: none;
        }
        .schedule-select-trigger:focus-visible {
            box-shadow: 0 0 0 2px color-mix(in srgb, var(--primary, #ea580c) 22%, transparent);
        }
        .schedule-select-trigger[aria-invalid="true"] {
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.25) !important;
        }
        .schedule-select-value {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .schedule-select-menu {
            display: none;
            position: absolute;
            z-index: 50;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            max-height: min(180px, 32vh);
            overflow-y: auto;
            overscroll-behavior: contain;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel);
            box-shadow: 0 8px 22px rgba(0,0,0,.25);
        }
        .schedule-select-wrap.is-open .schedule-select-menu { display: block; }
        .schedule-select-wrap.opens-up .schedule-select-menu {
            top: auto;
            bottom: calc(100% + 4px);
        }
        .schedule-select-option {
            display: block;
            width: 100%;
            padding: 9px 12px;
            border: 0;
            background: transparent;
            color: var(--ink);
            font: inherit;
            font-size: 13px;
            text-align: left;
            cursor: pointer;
        }
        .schedule-select-option:hover,
        .schedule-select-option[aria-selected="true"] {
            background: color-mix(in srgb, var(--primary, #ea580c) 7%, var(--panel));
        }
        #classModal textarea.form-control {
            height: 60px;
            min-height: 60px;
            resize: vertical;
        }
        #scheduleModal .form-control:focus,
        #classModal .form-control:focus {
            outline: none;
            border-color: var(--lime);
            box-shadow: 0 0 0 2px color-mix(in srgb, var(--lime) 25%, transparent);
        }
        #scheduleModal label,
        #classModal label {
            display: flex;
            flex-direction: column;
            gap: 5px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--muted);
        }
        .classes-modal-submit {
            margin-top: 4px;
            min-height: 40px;
            font-size: 14px;
            font-weight: 700;
            border-radius: 8px;
            width: 100%;
            justify-content: center;
        }
        [data-theme="light"] #scheduleModal .form-control,
        [data-theme="light"] #classModal .form-control {
            background: #ffffff;
            border-color: #cbd5e1;
            color: #1e293b;
        }
        .schedule-mobile-list { display: none; }

        @media (max-width: 640px) {
            .classes-banner {
                padding: 14px 16px !important;
                border-radius: 12px !important;
                margin-bottom: 16px !important;
                gap: 12px !important;
            }
            .classes-banner-title {
                font-size: 18px !important;
                gap: 8px !important;
            }
            .classes-banner-icon {
                width: 20px !important;
                height: 20px !important;
            }
            .classes-banner-desc {
                font-size: 12.5px !important;
                margin-top: 4px !important;
                line-height: 1.35 !important;
            }
            .classes-banner-actions {
                width: 100% !important;
                display: flex !important;
                gap: 8px !important;
            }
            .classes-banner-btn-secondary,
            .classes-banner-btn-primary {
                flex: 1 1 0 !important;
                padding: 8px 6px !important;
                font-size: 12px !important;
                min-height: 38px !important;
                justify-content: center !important;
                gap: 5px !important;
                white-space: nowrap !important;
            }
            .classes-banner-btn-secondary svg,
            .classes-banner-btn-primary svg {
                width: 14px !important;
                height: 14px !important;
                flex-shrink: 0 !important;
            }
            .class-card {
                padding: 14px 16px !important;
                border-radius: 12px !important;
                gap: 10px !important;
            }
            .schedule-table-panel { display: none; }
            .schedule-mobile-list {
                display: grid;
                gap: 12px;
            }
            .schedule-mobile-card {
                padding: 16px;
                border: 1px solid var(--line);
                border-radius: 12px;
                background: var(--panel);
            }
            .schedule-mobile-card-title {
                margin: 0 0 14px;
                font-size: 16px;
                color: var(--ink);
            }
            .schedule-mobile-details {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
                padding-top: 12px;
                border-top: 1px solid var(--line);
            }
            .schedule-mobile-detail-label {
                display: block;
                margin-bottom: 4px;
                color: var(--muted);
                font-size: 10px;
                font-weight: 700;
                letter-spacing: .06em;
                text-transform: uppercase;
            }
            .schedule-mobile-detail-value {
                color: var(--ink);
                font-size: 13px;
                line-height: 1.4;
            }
            .schedule-mobile-actions {
                display: flex;
                gap: 8px;
                margin-top: 14px;
            }
            .schedule-mobile-actions .btn { flex: 1; }

            /* Compact Mobile Modals */
            dialog#scheduleModal.modal,
            dialog#classModal.modal {
                width: min(350px, calc(100% - 24px)) !important;
                max-width: 350px !important;
                margin: auto !important;
                border-radius: 14px !important;
                max-height: calc(100vh - 36px) !important;
                max-height: calc(100dvh - 36px) !important;
                overflow-y: auto !important;
            }
            dialog#scheduleModal.modal .modal-header,
            dialog#classModal.modal .modal-header {
                padding: 10px 14px !important;
            }
            .classes-modal-title {
                font-size: 15px !important;
                font-weight: 700 !important;
            }
            dialog#scheduleModal.modal .modal-close,
            dialog#classModal.modal .modal-close {
                padding: 3px !important;
            }
            dialog#scheduleModal.modal .modal-close svg,
            dialog#classModal.modal .modal-close svg {
                width: 16px !important;
                height: 16px !important;
            }
            dialog#scheduleModal.modal .modal-body,
            dialog#classModal.modal .modal-body {
                padding: 12px 14px 14px !important;
            }
            .classes-modal-form {
                gap: 8px !important;
            }
            #scheduleModal label,
            #classModal label {
                gap: 3px !important;
                font-size: 11.5px !important;
                font-weight: 600 !important;
            }
            #scheduleModal .form-control,
            #classModal .form-control {
                font-size: 12.5px !important;
                padding: 6px 9px !important;
                border-radius: 7px !important;
            }
            #scheduleModal select.form-control,
            #classModal select.form-control,
            #scheduleModal input.form-control,
            #classModal input.form-control {
                height: 35px !important;
                min-height: 35px !important;
            }
            #classModal textarea.form-control {
                height: 48px !important;
                min-height: 48px !important;
                resize: none !important;
            }
            .schedule-datetime-grid {
                grid-template-columns: 1fr !important;
                gap: 8px !important;
            }
            .schedule-datetime-grid input[type="datetime-local"] {
                width: 100% !important;
                box-sizing: border-box !important;
                min-width: 0 !important;
                height: 35px !important;
                min-height: 35px !important;
                font-size: 12px !important;
            }
            .classes-modal-submit {
                min-height: 36px !important;
                height: 36px !important;
                font-size: 13px !important;
                font-weight: 700 !important;
                border-radius: 7px !important;
                margin-top: 4px !important;
            }
        }
    </style>

    <div>
        <!-- Glassmorphic Banner -->
        <div class="classes-banner animate-fade-in">
            <div>
                <h1 class="classes-banner-title">
                    <svg class="classes-banner-icon" xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Classes & Schedules
                </h1>
                <p class="classes-banner-desc">
                    Create class types and schedule sessions with rooms and instructors.
                </p>
            </div>
            <div class="classes-banner-actions">
                <button type="button" class="classes-banner-btn-secondary" onclick="document.getElementById('classModal').showModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    New Class Type
                </button>
                <button type="button" class="classes-banner-btn-primary" onclick="openScheduleModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Schedule Session
                </button>
            </div>
        </div>

        <div class="class-tabs">
            <button class="class-tab-btn <?= $activeTab === 'types' ? 'active' : '' ?>" data-tab="types" onclick="switchClassTab('types')">Class Types</button>
            <button class="class-tab-btn <?= $activeTab === 'schedules' ? 'active' : '' ?>" data-tab="schedules" onclick="switchClassTab('schedules')">Schedules</button>
        </div>

        <div id="tab-types" class="tab-content <?= $activeTab === 'types' ? 'active' : '' ?> animate-fade-in">
            <?php if (!$classes): ?>
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <p>No classes created yet.</p>
                </div>
            <?php else: ?>
                <div class="cc-grid">
                    <?php 
                    $accentPalette = ['#c7ff22', '#38bdf8', '#ff9548', '#a78bfa', '#f472b6', '#34d399', '#facc15', '#fb7185'];
                    $ci = 0;
                    foreach ($classes as $c): 
                        $safeJson = htmlspecialchars(json_encode($c));
                        $cardAccent = $accentPalette[$ci % count($accentPalette)];
                        $nameLower = strtolower((string)($c['class_name'] ?? ''));

                        if (str_contains($nameLower, 'hiit') || str_contains($nameLower, 'tabata') || str_contains($nameLower, 'crossfit') || str_contains($nameLower, 'circuit')) {
                            $categoryLabel = 'HIIT & Circuit';
                            $iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>';
                            $watermarkSvg = '<svg viewBox="0 0 24 24" fill="currentColor"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>';
                        } elseif (str_contains($nameLower, 'yoga') || str_contains($nameLower, 'stretch') || str_contains($nameLower, 'pilates') || str_contains($nameLower, 'flex')) {
                            $categoryLabel = 'Mind & Body';
                            $iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="4" r="2"/><path d="M4 17l4-2 4 4 4-4 4 2"/><path d="M12 6v8"/></svg>';
                            $watermarkSvg = '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="4" r="2"/><path d="M4 17l4-2 4 4 4-4 4 2"/><path d="M12 6v8"/></svg>';
                        } elseif (str_contains($nameLower, 'spin') || str_contains($nameLower, 'cycle') || str_contains($nameLower, 'bike') || str_contains($nameLower, 'ride')) {
                            $categoryLabel = 'Indoor Cycling';
                            $iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="M15 6a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm-3 11.5L9 11l3-3 3 3-1.5 3.5"/><path d="M9 11h6"/></svg>';
                            $watermarkSvg = '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="M12 17.5L9 11l3-3 3 3-1.5 3.5"/><path d="M9 11h6"/></svg>';
                        } elseif (str_contains($nameLower, 'box') || str_contains($nameLower, 'combat') || str_contains($nameLower, 'muay') || str_contains($nameLower, 'mma') || str_contains($nameLower, 'kick')) {
                            $categoryLabel = 'Combat Sports';
                            $iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9h12a2 2 0 0 1 2 2v6a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4v-6a2 2 0 0 1 2-2z"/><path d="M9 9V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v4"/></svg>';
                            $watermarkSvg = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 9h12a2 2 0 0 1 2 2v6a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4v-6a2 2 0 0 1 2-2z"/><path d="M9 9V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v4"/></svg>';
                        } elseif (str_contains($nameLower, 'strength') || str_contains($nameLower, 'lift') || str_contains($nameLower, 'iron') || str_contains($nameLower, 'pump') || str_contains($nameLower, 'weight')) {
                            $categoryLabel = 'Strength & Conditioning';
                            $iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M6 5v14M18 5v14M2 9v6M22 9v6M6 12h12"/></svg>';
                            $watermarkSvg = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 5v14M18 5v14M2 9v6M22 9v6M6 12h12"/></svg>';
                        } elseif (str_contains($nameLower, 'cardio') || str_contains($nameLower, 'aerobic') || str_contains($nameLower, 'step') || str_contains($nameLower, 'burn')) {
                            $categoryLabel = 'Cardio Endurance';
                            $iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>';
                            $watermarkSvg = '<svg viewBox="0 0 24 24" fill="currentColor"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>';
                        } else {
                            $categoryLabel = 'Studio Class';
                            $iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
                            $watermarkSvg = '<svg viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
                        }
                        $ci++;
                    ?>
                        <div class="class-card" style="--card-accent: <?= $cardAccent ?>;">
                            <div class="class-card-watermark" aria-hidden="true">
                                <?= $watermarkSvg ?>
                            </div>
                            <div style="display:flex; justify-content: space-between; align-items: flex-start; gap: 12px; position: relative; z-index: 1;">
                                <div style="display:flex; align-items:center; gap: 10px; min-width: 0;">
                                    <div class="class-card-icon-pill">
                                        <?= $iconSvg ?>
                                    </div>
                                    <div style="min-width: 0;">
                                        <span class="class-card-tag"><?= h($categoryLabel) ?></span>
                                        <h3 style="margin:0; font-size:17px; font-weight:700; color:var(--ink); line-height: 1.25; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= h($c['class_name']) ?></h3>
                                    </div>
                                </div>
                                <div style="display:flex; gap:6px; flex-shrink:0;">
                                    <button type="button" class="class-card-btn-action" onclick="editClass(<?= $safeJson ?>)" title="Edit Class">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </button>
                                    <button type="button" class="class-card-btn-action btn-del" onclick="confirmDeleteClass(<?= (int)$c['class_id'] ?>, <?= htmlspecialchars(json_encode($c['class_name'])) ?>)" title="Delete Class">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    </button>
                                </div>
                            </div>
                            <p style="margin:0; font-size:13.5px; color:var(--muted); line-height:1.5; flex-grow:1; position: relative; z-index: 1;">
                                <?= h($c['description'] ?: 'No description provided.') ?>
                            </p>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:6px; padding-top:12px; border-top:1px solid rgba(255,255,255,0.06); position: relative; z-index: 1;">
                                <div style="display:flex; align-items:center; gap:6px; font-size:13px; color:var(--muted);">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <span style="font-weight: 500;"><?= h($c['instructor'] ?: 'No instructor') ?></span>
                                </div>
                                <span style="background:color-mix(in srgb, var(--card-accent) 12%, rgba(255,255,255,0.04)); border: 1px solid color-mix(in srgb, var(--card-accent) 24%, rgba(255,255,255,0.08)); padding:4px 10px; border-radius:12px; font-size:12px; font-weight:600; color:var(--ink);">
                                    Capacity: <?= (int)$c['capacity'] ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div id="tab-schedules" class="tab-content <?= $activeTab === 'schedules' ? 'active' : '' ?> animate-fade-in">
            <?php if (!$schedules): ?>
                <div class="empty-state">
                    <p>No schedules yet. Create one using the button above.</p>
                </div>
            <?php else: ?>
                <div class="panel schedule-table-panel" style="padding:0; overflow:hidden;">
                    <div class="table-wrap">
                        <table style="margin:0; width:100%;">
                            <thead>
                                <tr>
                                    <th>Class Session</th>
                                    <th>Location</th>
                                    <th>Date &amp; Time</th>
                                    <th>Booked</th>
                                    <th>Enrolled Members</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($schedules as $s): 
                                $startTs = strtotime($s['start_datetime']);
                                $endTs = strtotime($s['end_datetime']);
                                $startDate = date('M j, Y', $startTs);
                                $endDate = date('M j, Y', $endTs);
                                $startTime = date('g:i A', $startTs);
                                $endTime = date('g:i A', $endTs);
                                $scheduleTimeStr = $startDate . ' ' . $startTime . ' – ' . ($startDate !== $endDate ? $endDate . ' ' : '') . $endTime;
                                $sMembers = $bookedMembersBySchedule[(int)$s['schedule_id']] ?? [];
                                $sMemCount = count($sMembers);
                                $shownMembers = array_slice($sMembers, 0, 2);
                                $remainingCount = $sMemCount - count($shownMembers);
                                $modalPayload = htmlspecialchars(json_encode([
                                    'class_name' => $s['class_name'],
                                    'time' => $scheduleTimeStr,
                                    'location' => $s['room_location'] ?: 'Main Studio',
                                    'capacity' => (int)($s['capacity'] ?? 0),
                                    'members' => $sMembers
                                ]), ENT_QUOTES, 'UTF-8');
                            ?>
                                <tr>
                                    <td><strong><?= h($s['class_name']) ?></strong></td>
                                    <td><span style="display:inline-flex; align-items:center; gap:6px; color:var(--muted); font-size:13px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg><?= h($s['room_location'] ?: '—') ?></span></td>
                                    <td>
                                        <div style="display:flex; flex-direction:column; gap:4px; font-size:13px;">
                                            <div style="display:flex; align-items:center; gap:6px;">
                                                <span style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; padding:1px 6px; border-radius:4px; background:rgba(34,197,94,0.15); color:var(--success, #22c55e);">Start</span>
                                                <span style="font-weight:600; color:var(--ink);"><?= h($startDate) ?></span>
                                                <span style="color:var(--muted); font-size:12px;"><?= h($startTime) ?></span>
                                            </div>
                                            <div style="display:flex; align-items:center; gap:6px;">
                                                <span style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; padding:1px 6px; border-radius:4px; background:rgba(234,88,12,0.15); color:var(--orange, #ea580c);">End</span>
                                                <span style="font-weight:600; color:var(--ink);"><?= h($endDate) ?></span>
                                                <span style="color:var(--muted); font-size:12px;"><?= h($endTime) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="background:rgba(234,88,12,0.12); color:var(--orange, #ea580c); padding:4px 10px; border-radius:12px; font-weight:700; font-size:13px;">
                                            <?= (int) $s['booked'] ?><?= !empty($s['capacity']) ? ' / ' . (int)$s['capacity'] : '' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($sMemCount === 0): ?>
                                            <span style="color:var(--muted); font-size:12px; font-style:italic;">No members joined yet</span>
                                        <?php else: ?>
                                            <div style="display:flex; flex-direction:column; gap:4px; max-width:240px;">
                                                <div style="display:flex; flex-wrap:wrap; gap:4px;">
                                                    <?php foreach ($shownMembers as $m): 
                                                        $initials = strtoupper(substr($m['first_name'] ?? 'M', 0, 1) . substr($m['last_name'] ?? '', 0, 1));
                                                        $fullName = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
                                                    ?>
                                                        <span class="member-pill-tag" title="<?= h($fullName) . (!empty($m['email']) ? ' (' . h($m['email']) . ')' : '') ?>">
                                                            <span class="member-pill-avatar"><?= h($initials) ?></span>
                                                            <span class="member-pill-name"><?= h($fullName) ?></span>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                                <?php if ($remainingCount > 0): ?>
                                                    <button type="button" class="btn-see-more-members" onclick="openClassMembersModal(<?= $modalPayload ?>)">
                                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                                        <span>+<?= $remainingCount ?> more</span>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn-view-all-members-link" onclick="openClassMembersModal(<?= $modalPayload ?>)">
                                                        View details
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button onclick="editSchedule(<?= htmlspecialchars(json_encode($s)) ?>)" class="btn btn-secondary" style="padding:6px 10px; font-size:12px; border:none; background:rgba(255,255,255,0.05); cursor:pointer;">Edit</button>
                                        <button type="button" class="btn btn-danger" style="padding:6px 10px; font-size:12px; border:none; background:rgba(239,68,68,0.1); color:var(--danger); cursor:pointer;" onclick="confirmDeleteSchedule(<?= (int)$s['schedule_id'] ?>, <?= htmlspecialchars(json_encode($s['class_name'])) ?>, <?= htmlspecialchars(json_encode($scheduleTimeStr)) ?>)">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="schedule-mobile-list">
                    <?php foreach ($schedules as $s): 
                        $startTs = strtotime($s['start_datetime']);
                        $endTs = strtotime($s['end_datetime']);
                        $startDate = date('M j, Y', $startTs);
                        $endDate = date('M j, Y', $endTs);
                        $startTime = date('g:i A', $startTs);
                        $endTime = date('g:i A', $endTs);
                        $scheduleTimeStr = $startDate . ' ' . $startTime . ' – ' . ($startDate !== $endDate ? $endDate . ' ' : '') . $endTime;
                        $sMembers = $bookedMembersBySchedule[(int)$s['schedule_id']] ?? [];
                        $sMemCount = count($sMembers);
                        $shownMembers = array_slice($sMembers, 0, 2);
                        $remainingCount = $sMemCount - count($shownMembers);
                        $modalPayload = htmlspecialchars(json_encode([
                            'class_name' => $s['class_name'],
                            'time' => $scheduleTimeStr,
                            'location' => $s['room_location'] ?: 'Main Studio',
                            'capacity' => (int)($s['capacity'] ?? 0),
                            'members' => $sMembers
                        ]), ENT_QUOTES, 'UTF-8');
                    ?>
                        <article class="schedule-mobile-card">
                            <h3 class="schedule-mobile-card-title"><?= h($s['class_name']) ?></h3>
                            <div class="schedule-mobile-details">
                                <div>
                                    <span class="schedule-mobile-detail-label">Location</span>
                                    <div class="schedule-mobile-detail-value"><?= h($s['room_location'] ?: '—') ?></div>
                                </div>
                                <div>
                                    <span class="schedule-mobile-detail-label">Date &amp; time</span>
                                    <div class="schedule-mobile-detail-value" style="display:flex; flex-direction:column; gap:4px; margin-top:2px;">
                                        <div style="display:flex; align-items:center; gap:6px;">
                                            <span style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; padding:1px 5px; border-radius:4px; background:rgba(34,197,94,0.15); color:var(--success, #22c55e);">Start</span>
                                            <span style="font-weight:600; color:var(--ink); font-size:13px;"><?= h($startDate) ?></span>
                                            <span style="color:var(--muted); font-size:12px;"><?= h($startTime) ?></span>
                                        </div>
                                        <div style="display:flex; align-items:center; gap:6px;">
                                            <span style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; padding:1px 5px; border-radius:4px; background:rgba(234,88,12,0.15); color:var(--orange, #ea580c);">End</span>
                                            <span style="font-weight:600; color:var(--ink); font-size:13px;"><?= h($endDate) ?></span>
                                            <span style="color:var(--muted); font-size:12px;"><?= h($endTime) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <span class="schedule-mobile-detail-label">Booked</span>
                                    <div class="schedule-mobile-detail-value"><?= (int) $s['booked'] ?><?= !empty($s['capacity']) ? ' / ' . (int)$s['capacity'] : '' ?> member<?= (int) $s['booked'] === 1 ? '' : 's' ?></div>
                                </div>
                                <div style="grid-column: 1 / -1;">
                                    <span class="schedule-mobile-detail-label">Enrolled Members (<?= $sMemCount ?>)</span>
                                    <div class="schedule-mobile-detail-value" style="margin-top:4px;">
                                        <?php if ($sMemCount === 0): ?>
                                            <span style="color:var(--muted); font-size:12px; font-style:italic;">No members joined yet</span>
                                        <?php else: ?>
                                            <div style="display:flex; flex-wrap:wrap; gap:5px; align-items:center;">
                                                <?php foreach ($shownMembers as $m): 
                                                    $initials = strtoupper(substr($m['first_name'] ?? 'M', 0, 1) . substr($m['last_name'] ?? '', 0, 1));
                                                    $fullName = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
                                                ?>
                                                    <span class="member-pill-tag">
                                                        <span class="member-pill-avatar"><?= h($initials) ?></span>
                                                        <span class="member-pill-name"><?= h($fullName) ?></span>
                                                    </span>
                                                <?php endforeach; ?>
                                                <button type="button" class="btn-see-more-members" onclick="openClassMembersModal(<?= $modalPayload ?>)">
                                                    <?= $remainingCount > 0 ? "+{$remainingCount} more" : "View roster" ?>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="schedule-mobile-actions">
                                <button onclick="editSchedule(<?= htmlspecialchars(json_encode($s)) ?>)" class="btn btn-secondary" style="padding:8px 10px; font-size:12px; cursor:pointer;">Edit</button>
                                <button type="button" class="btn btn-danger" style="padding:8px 10px; font-size:12px; cursor:pointer;" onclick="confirmDeleteSchedule(<?= (int)$s['schedule_id'] ?>, <?= htmlspecialchars(json_encode($s['class_name'])) ?>, <?= htmlspecialchars(json_encode($scheduleTimeStr)) ?>)">Delete</button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modals -->
    <dialog id="classModal" class="modal">
        <div class="modal-header">
            <h3 class="classes-modal-title">Add New Class Type</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('classModal').close()">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="modal-body">
            <form method="post" class="classes-modal-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="class">
                <label><span class="label-title">Class Name <span class="req-star">*</span></span> <input type="text" name="class_name" class="form-control" placeholder="e.g. HIIT Training" required></label>
                <label>Description <textarea name="description" class="form-control" placeholder="Short description" rows="2"></textarea></label>
                <?php if ($user['role'] !== 'trainer'): ?>
                <label>Instructor
                    <select name="instructor_id" class="form-control">
                        <option value="">— None assigned —</option>
                        <?php foreach ($coaches as $trainer): ?>
                            <option value="<?= (int) $trainer['user_id'] ?>"><?= h($trainer['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>
                <label><span class="label-title">Capacity <span class="req-star">*</span></span> <input type="number" name="capacity" class="form-control" min="1" placeholder="e.g. 20" required></label>
                <button type="submit" class="btn btn-primary classes-modal-submit">Save Class</button>
            </form>
        </div>
    </dialog>

    <dialog id="scheduleModal" class="modal">
        <div class="modal-header">
            <h3 class="classes-modal-title">Schedule Session</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('scheduleModal').close()">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="modal-body">
            <form method="post" id="createScheduleForm" class="classes-modal-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="schedule">
                <label><span class="label-title">Class Type <span class="req-star">*</span></span>
                    <select name="class_id" class="form-control schedule-class-select" required>
                        <option value="">-- Select Class --</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= (int) $class['class_id'] ?>"><?= h($class['class_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Room / Location <input type="text" name="room_location" class="form-control" placeholder="e.g. Studio A"></label>
                <div class="schedule-datetime-grid">
                    <label><span class="label-title">Start Date/Time <span class="req-star">*</span></span> <input type="datetime-local" lang="en-US" name="start_datetime" id="sched_start_datetime" class="form-control" required></label>
                    <label><span class="label-title">End Date/Time <span class="req-star">*</span></span> <input type="datetime-local" lang="en-US" name="end_datetime" id="sched_end_datetime" class="form-control" required></label>
                </div>
                <div id="schedDateWarning" style="display: none; padding: 7px 10px; background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 8px; font-size: 12px; color: #ef4444; margin-top: -4px;"></div>
                <button type="submit" class="btn btn-primary classes-modal-submit">Save Schedule</button>
            </form>
        </div>
    </dialog>

    <!-- Modal: View Enrolled Members for a Class Session -->
    <dialog id="classMembersModal" class="modal" style="max-width: 580px; width: 95%;">
        <div class="modal-header">
            <div>
                <h3 class="classes-modal-title" id="cmm_class_name">Enrolled Members</h3>
                <p id="cmm_subtitle" style="margin: 4px 0 0; font-size: 13px; color: var(--muted);"></p>
            </div>
            <button type="button" class="modal-close" onclick="document.getElementById('classMembersModal').close()">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="modal-body" style="padding: 16px 20px;">
            <div style="margin-bottom: 14px;">
                <input type="text" id="cmm_search_input" class="form-control" placeholder="Search enrolled member by name, email, or phone..." oninput="filterClassMembersModal(this.value)" style="width: 100%; box-sizing: border-box; font-size: 13px; padding: 9px 12px;">
            </div>
            <div id="cmm_members_container" style="max-height: 380px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; padding-right: 4px;">
                <!-- Populated via JavaScript -->
            </div>
        </div>
        <div style="padding: 12px 20px; border-top: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.06);">
            <span id="cmm_count_summary" style="font-size: 13px; color: var(--muted); font-weight: 500;"></span>
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('classMembersModal').close()" style="padding: 8px 18px; font-size: 13px; cursor: pointer;">Close</button>
        </div>
    </dialog>

    <script>
    let currentClassMembersData = [];

    function openClassMembersModal(data) {
        const modal = document.getElementById('classMembersModal');
        if (!modal) return;
        
        document.getElementById('cmm_class_name').textContent = data.class_name ? `Enrolled Members: ${data.class_name}` : 'Enrolled Members';
        const capText = data.capacity > 0 ? ` • Capacity: ${data.capacity}` : '';
        document.getElementById('cmm_subtitle').textContent = `${data.time} • ${data.location}${capText}`;
        
        currentClassMembersData = data.members || [];
        const searchInput = document.getElementById('cmm_search_input');
        if (searchInput) searchInput.value = '';
        
        renderClassMembersList(currentClassMembersData);
        modal.showModal();
    }

    function renderClassMembersList(members) {
        const container = document.getElementById('cmm_members_container');
        const summary = document.getElementById('cmm_count_summary');
        if (!container) return;
        
        if (summary) {
            summary.textContent = `Total Enrolled: ${members.length}`;
        }
        
        if (members.length === 0) {
            container.innerHTML = `
                <div style="text-align: center; padding: 32px 16px; color: var(--muted); font-size: 14px;">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 8px; opacity: 0.5;"><circle cx="12" cy="12" r="10"/><path d="M16 16s-1.5-2-4-2-4 2-4 2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
                    <p style="margin: 0;">No members found matching your search.</p>
                </div>
            `;
            return;
        }
        
        container.innerHTML = members.map((m, idx) => {
            const fName = (m.first_name || '').trim();
            const lName = (m.last_name || '').trim();
            const initials = ((fName[0] || 'M') + (lName[0] || '')).toUpperCase();
            const fullName = `${fName} ${lName}`.trim() || 'Member';
            const email = m.email || '—';
            const phone = m.phone ? ` • ${m.phone}` : '';
            const bookedDate = m.booked_at ? new Date(m.booked_at.replace(/-/g, '/')) : null;
            const bookedAt = bookedDate && !isNaN(bookedDate.getTime()) 
                ? bookedDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) 
                : '';
            
            return `
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; gap: 12px;">
                    <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
                        <div style="width: 36px; height: 36px; border-radius: 50%; background: color-mix(in srgb, var(--orange, #ea580c) 20%, var(--panel)); color: var(--orange, #ea580c); font-weight: 700; font-size: 13px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; border: 1px solid color-mix(in srgb, var(--orange, #ea580c) 35%, transparent);">
                            ${initials}
                        </div>
                        <div style="min-width: 0;">
                            <strong style="display: block; font-size: 14px; color: var(--ink); line-height: 1.25; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${fullName}</strong>
                            <span style="font-size: 12px; color: var(--muted); display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${email}${phone}</span>
                        </div>
                    </div>
                    <div style="text-align: right; flex-shrink: 0;">
                        <span style="display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; background: rgba(34,197,94,0.15); color: #22c55e;">Booked</span>
                        ${bookedAt ? `<span style="display: block; font-size: 11px; color: var(--muted); margin-top: 2px;">${bookedAt}</span>` : ''}
                    </div>
                </div>
            `;
        }).join('');
    }

    function filterClassMembersModal(query) {
        const q = (query || '').toLowerCase().trim();
        if (!q) {
            renderClassMembersList(currentClassMembersData);
            return;
        }
        const filtered = currentClassMembersData.filter(m => {
            const name = `${m.first_name || ''} ${m.last_name || ''}`.toLowerCase();
            const email = (m.email || '').toLowerCase();
            const phone = (m.phone || '').toLowerCase();
            return name.includes(q) || email.includes(q) || phone.includes(q);
        });
        renderClassMembersList(filtered);
    }
    function enhanceScheduleSelect(select) {
        if (!select || select.dataset.enhanced === 'true') return;
        select.dataset.enhanced = 'true';
        const wasRequired = select.required;
        select.required = false;
        const wrapper = document.createElement('div');
        wrapper.className = 'schedule-select-wrap';
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);
        select.classList.add('schedule-native-select');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'schedule-select-trigger';
        trigger.setAttribute('role', 'combobox');
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        if (wasRequired) trigger.setAttribute('aria-required', 'true');
        trigger.innerHTML = '<span class="schedule-select-value"></span><span aria-hidden="true">⌄</span>';
        wrapper.appendChild(trigger);

        const menu = document.createElement('div');
        menu.className = 'schedule-select-menu';
        menu.setAttribute('role', 'listbox');
        Array.from(select.options).forEach((option) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'schedule-select-option';
            item.textContent = option.textContent;
            item.dataset.value = option.value;
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', option.selected ? 'true' : 'false');
            item.addEventListener('click', () => {
                select.value = option.value;
                trigger.querySelector('.schedule-select-value').textContent = option.textContent;
                trigger.removeAttribute('aria-invalid');
                const warning = select.closest('form')?.querySelector('#schedDateWarning');
                if (warning && warning.textContent.includes('Class Type')) {
                    warning.textContent = '';
                    warning.style.display = 'none';
                }
                menu.querySelectorAll('.schedule-select-option').forEach((entry) => {
                    entry.setAttribute('aria-selected', entry.dataset.value === option.value ? 'true' : 'false');
                });
                select.dispatchEvent(new Event('change', { bubbles: true }));
                wrapper.classList.remove('is-open', 'opens-up');
                trigger.setAttribute('aria-expanded', 'false');
            });
            menu.appendChild(item);
        });
        wrapper.appendChild(menu);
        trigger.querySelector('.schedule-select-value').textContent = select.options[select.selectedIndex]?.textContent || '';

        trigger.addEventListener('click', () => {
            const shouldOpen = !wrapper.classList.contains('is-open');
            const scope = wrapper.closest('dialog, .swal2-popup') || document;
            scope.querySelectorAll('.schedule-select-wrap.is-open').forEach((open) => {
                open.classList.remove('is-open', 'opens-up');
                open.querySelector('.schedule-select-trigger').setAttribute('aria-expanded', 'false');
            });
            if (!shouldOpen) return;
            const clippingBox = wrapper.closest('dialog') || wrapper.closest('.swal2-html-container') || scope;
            const box = clippingBox.getBoundingClientRect();
            const triggerBox = trigger.getBoundingClientRect();
            const below = box.bottom - triggerBox.bottom;
            const above = triggerBox.top - box.top;
            const opensUp = below < 180 && above > below;
            wrapper.classList.toggle('opens-up', opensUp);
            menu.style.maxHeight = String(Math.max(96, Math.min(180, (opensUp ? above : below) - 8))) + 'px';
            wrapper.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
        });

        const form = select.closest('form');
        if (wasRequired && form && !form.dataset.scheduleSelectValidation) {
            form.dataset.scheduleSelectValidation = 'true';
            form.addEventListener('submit', (event) => {
                const requiredSelect = form.querySelector('select.schedule-native-select[aria-hidden="true"]');
                if (requiredSelect && !requiredSelect.value) {
                    event.preventDefault();
                    const requiredTrigger = requiredSelect.parentElement.querySelector('.schedule-select-trigger');
                    requiredTrigger.setAttribute('aria-invalid', 'true');
                    const warning = form.querySelector('#schedDateWarning');
                    if (warning) {
                        warning.textContent = 'Please select a Class Type to save the schedule.';
                        warning.style.display = 'block';
                    }
                    requiredTrigger.focus();
                }
            });
        }

        const closeOnOutsideClick = (event) => {
            if (event.target.closest('.schedule-select-wrap')) return;
            wrapper.classList.remove('is-open', 'opens-up');
            trigger.setAttribute('aria-expanded', 'false');
        };
        const scope = wrapper.closest('dialog, .swal2-popup');
        if (scope) scope.addEventListener('click', closeOnOutsideClick);
    }

    document.querySelectorAll('#scheduleModal select.schedule-class-select').forEach(enhanceScheduleSelect);

    function switchClassTab(tab, updateHistory = true) {
        document.querySelectorAll('.class-tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        const activeBtn = document.querySelector(`.class-tab-btn[data-tab="${tab}"]`) || document.querySelector(`.class-tab-btn[onclick*="'${tab}'"]`);
        if (activeBtn) activeBtn.classList.add('active');
        const activeTabContent = document.getElementById(`tab-${tab}`);
        if (activeTabContent) activeTabContent.classList.add('active');

        if (updateHistory) {
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('tab', tab);
                window.history.replaceState(null, '', url.toString());
            } catch (e) {}
        }
    }

    window.addEventListener('popstate', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const tab = urlParams.get('tab') || 'types';
        switchClassTab(tab, false);
    });

    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const tab = urlParams.get('tab');
        if (tab === 'schedules' || tab === 'types') {
            switchClassTab(tab, false);
        }
    });

    function confirmDeleteSchedule(scheduleId, className, dateTimeStr) {
        Swal.fire({
            title: 'Delete Class Schedule?',
            html: `
                <div style="text-align:center; padding:6px 0;">
                    <p style="margin:0 0 10px 0; color:var(--ink); font-size:15px;">
                        Are you sure you want to delete the schedule for <strong>${className}</strong>?
                    </p>
                    <div style="background:rgba(255,255,255,0.05); border:1px solid var(--line); border-radius:8px; padding:10px; font-size:13px; color:var(--muted); margin-bottom:12px;">
                        ${dateTimeStr}
                    </div>
                    <p style="margin:0; font-size:12px; color:var(--danger);">
                        This will cancel this session and remove associated member bookings.
                    </p>
                </div>
            `,
            icon: 'warning',
            iconColor: '#ef4444',
            showCancelButton: true,
            confirmButtonText: 'Yes, Delete Schedule',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#ef4444',
            cancelButtonColor: 'var(--line, #64748b)',
            background: 'var(--panel, #1e293b)',
            color: 'var(--ink)'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'index.php?page=classes&tab=schedules';

                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = '<?= csrf_token() ?>';
                form.appendChild(csrfInput);

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_schedule';
                form.appendChild(actionInput);

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'schedule_id';
                idInput.value = scheduleId;
                form.appendChild(idInput);

                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    function confirmDeleteClass(classId, className) {
        Swal.fire({
            title: 'Delete Class Type?',
            html: `
                <div style="text-align:center; padding:6px 0;">
                    <p style="margin:0 0 10px 0; color:var(--ink); font-size:15px;">
                        Are you sure you want to delete <strong>${className}</strong>?
                    </p>
                    <p style="margin:0; font-size:12px; color:var(--danger);">
                        All associated schedules and member bookings will also be deleted.
                    </p>
                </div>
            `,
            icon: 'warning',
            iconColor: '#ef4444',
            showCancelButton: true,
            confirmButtonText: 'Yes, Delete Class',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#ef4444',
            cancelButtonColor: 'var(--line, #64748b)',
            background: 'var(--panel, #1e293b)',
            color: 'var(--ink)'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'index.php?page=classes&tab=types';

                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = '<?= csrf_token() ?>';
                form.appendChild(csrfInput);

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_class';
                form.appendChild(actionInput);

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'class_id';
                idInput.value = classId;
                form.appendChild(idInput);

                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    function editClass(c) {
        Swal.fire({
            title: 'Edit Class',
            html: `
                <form id="editClassForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <?= $csrfStr ?>
                    <input type="hidden" name="action" value="edit_class">
                    <input type="hidden" name="class_id" id="ec_id">
                    <label style="display:block; color: var(--muted); font-size: 14px;">Class name * <input name="class_name" id="ec_name" class="form-control" required style="width: 100%; box-sizing: border-box;"></label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Description <input name="description" id="ec_desc" class="form-control" style="width: 100%; box-sizing: border-box;"></label>
                    <?php if ($user['role'] !== 'trainer'): ?>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Instructor
                        <select name="instructor_id" id="ec_inst" class="form-control" style="width: 100%; box-sizing: border-box;">
                            <?= $instructorOptions ?>
                        </select>
                    </label>
                    <?php endif; ?>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Capacity * <input name="capacity" id="ec_cap" type="number" min="1" class="form-control" required style="width: 100%; box-sizing: border-box;"></label>
                </form>
            `,
            didOpen: () => {
                document.getElementById('ec_id').value = c.class_id;
                document.getElementById('ec_name').value = c.class_name;
                document.getElementById('ec_desc').value = c.description || '';
                if (document.getElementById('ec_inst')) {
                    document.getElementById('ec_inst').value = c.instructor_id || '';
                }
                document.getElementById('ec_cap').value = c.capacity;
            },
            showCancelButton: true,
            confirmButtonText: 'Save Changes',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('editClassForm');
                if (!form.class_name.value || !form.capacity.value) {
                    Swal.showValidationMessage('Class name and capacity are required');
                    return false;
                }
                form.submit();
            }
        });
    }

    function getNowLocalIsoMinutes() {
        const d = new Date();
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const h = String(d.getHours()).padStart(2, '0');
        const min = String(d.getMinutes()).padStart(2, '0');
        return `${y}-${m}-${day}T${h}:${min}`;
    }

    function initScheduleModalDateTime() {
        const startInput = document.getElementById('sched_start_datetime');
        const endInput = document.getElementById('sched_end_datetime');
        const warning = document.getElementById('schedDateWarning');
        const form = document.getElementById('createScheduleForm');
        if (!startInput || !endInput) return;

        const nowIso = getNowLocalIsoMinutes();
        startInput.setAttribute('min', nowIso);
        endInput.setAttribute('min', nowIso);

        function showWarning(msg) {
            if (!warning) return;
            warning.textContent = msg;
            warning.style.display = 'block';
        }
        function clearWarning() {
            if (!warning) return;
            warning.textContent = '';
            warning.style.display = 'none';
        }

        // Keep track of previous start date to auto-sync end date when start date changes
        let prevStartDate = startInput.value ? startInput.value.split('T')[0] : '';

        startInput.addEventListener('input', () => {
            const currentNow = getNowLocalIsoMinutes();
            if (startInput.value && startInput.value < currentNow) {
                startInput.value = currentNow;
                showWarning('Cannot schedule in the past. Start time reset to current time.');
                setTimeout(clearWarning, 4000);
            } else {
                clearWarning();
            }

            if (startInput.value) {
                const newStartDate = startInput.value.split('T')[0];
                endInput.setAttribute('min', startInput.value);
                endInput.setAttribute('max', `${newStartDate}T23:59`); // Restrict end to the same calendar day

                // If end is blank, or earlier than start, or start date just changed:
                // Auto-sync end date to match start date with +1 hour default (capped at 23:59)
                if (!endInput.value || endInput.value <= startInput.value || (prevStartDate && prevStartDate !== newStartDate)) {
                    const startDateObj = new Date(startInput.value);
                    if (!isNaN(startDateObj.getTime())) {
                        startDateObj.setHours(startDateObj.getHours() + 1);
                        const sy = startDateObj.getFullYear();
                        const sm = String(startDateObj.getMonth() + 1).padStart(2, '0');
                        const sd = String(startDateObj.getDate()).padStart(2, '0');
                        const sh = String(startDateObj.getHours()).padStart(2, '0');
                        const smin = String(startDateObj.getMinutes()).padStart(2, '0');
                        let calculatedEnd = `${sy}-${sm}-${sd}T${sh}:${smin}`;
                        if (calculatedEnd.split('T')[0] !== newStartDate) {
                            calculatedEnd = `${newStartDate}T23:59`;
                        }
                        endInput.value = calculatedEnd;
                    }
                }
                prevStartDate = newStartDate;
            }
        });

        endInput.addEventListener('input', () => {
            if (!startInput.value || !endInput.value) return;
            if (endInput.value <= startInput.value) {
                showWarning('End Date/Time must be after the Start Date/Time.');
            } else if (startInput.value.split('T')[0] !== endInput.value.split('T')[0]) {
                showWarning('Sessions must start and end on the same calendar day.');
            } else {
                clearWarning();
            }
        });

        if (form && !form.dataset.dateValidationBound) {
            form.dataset.dateValidationBound = 'true';
            form.addEventListener('submit', (e) => {
                const currentNow = getNowLocalIsoMinutes();
                if (startInput.value && startInput.value < currentNow) {
                    e.preventDefault();
                    showWarning('Cannot schedule a session in the past. Please choose an upcoming date and time.');
                    startInput.focus();
                    return false;
                }
                if (endInput.value && startInput.value && endInput.value <= startInput.value) {
                    e.preventDefault();
                    showWarning('End Date/Time must be after Start Date/Time.');
                    endInput.focus();
                    return false;
                }
                if (startInput.value && endInput.value && startInput.value.split('T')[0] !== endInput.value.split('T')[0]) {
                    e.preventDefault();
                    showWarning('Sessions must start and end on the same calendar day.');
                    endInput.focus();
                    return false;
                }
                const diffMin = (new Date(endInput.value) - new Date(startInput.value)) / 60000;
                if (diffMin < 15) {
                    e.preventDefault();
                    showWarning('Class duration must be at least 15 minutes.');
                    endInput.focus();
                    return false;
                }
                if (diffMin > 240) {
                    e.preventDefault();
                    showWarning('Class duration cannot exceed 4 hours.');
                    endInput.focus();
                    return false;
                }
            });
        }
    }

    function openScheduleModal() {
        const modal = document.getElementById('scheduleModal');
        if (!modal) return;
        const startInput = document.getElementById('sched_start_datetime');
        const endInput = document.getElementById('sched_end_datetime');
        const nowIso = getNowLocalIsoMinutes();
        if (startInput) {
            startInput.setAttribute('min', nowIso);
            if (!startInput.value || startInput.value < nowIso) {
                startInput.value = nowIso;
            }
        }
        if (endInput) {
            const startVal = (startInput && startInput.value) ? startInput.value : nowIso;
            const startDate = startVal.split('T')[0];
            endInput.setAttribute('min', startVal);
            endInput.setAttribute('max', `${startDate}T23:59`);

            // Default end to 1 hour after start (clamped to same day)
            const d = new Date(startVal);
            d.setHours(d.getHours() + 1);
            const ey = d.getFullYear();
            const em = String(d.getMonth() + 1).padStart(2, '0');
            const ed = String(d.getDate()).padStart(2, '0');
            const eh = String(d.getHours()).padStart(2, '0');
            const emin = String(d.getMinutes()).padStart(2, '0');
            let defaultEnd = `${ey}-${em}-${ed}T${eh}:${emin}`;
            if (defaultEnd.split('T')[0] !== startDate) {
                defaultEnd = `${startDate}T23:59`;
            }
            endInput.value = defaultEnd;
        }
        modal.showModal();
    }

    initScheduleModalDateTime();

    function editSchedule(s) {
        Swal.fire({
            title: 'Edit Schedule',
            html: `
                <form id="editSchedForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <?= $csrfStr ?>
                    <input type="hidden" name="action" value="edit_schedule">
                    <input type="hidden" name="schedule_id" id="es_id">
                    <label style="display:block; color: var(--muted); font-size: 14px;">Class *
                        <select name="class_id" id="es_class" class="form-control" required style="width: 100%; box-sizing: border-box;">
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= (int) $class['class_id'] ?>"><?= h($class['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Room / Location <input name="room_location" id="es_loc" class="form-control" style="width: 100%; box-sizing: border-box;"></label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Start * <input name="start_datetime" id="es_start" type="datetime-local" lang="en-US" class="form-control" required style="width: 100%; box-sizing: border-box;"></label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">End * <input name="end_datetime" id="es_end" type="datetime-local" lang="en-US" class="form-control" required style="width: 100%; box-sizing: border-box;"></label>
                </form>
            `,
            didOpen: () => {
                const nowIso = getNowLocalIsoMinutes();
                const esStart = document.getElementById('es_start');
                const esEnd = document.getElementById('es_end');
                document.getElementById('es_id').value = s.schedule_id;
                document.getElementById('es_class').value = s.class_id;
                enhanceScheduleSelect(document.getElementById('es_class'));
                document.getElementById('es_loc').value = s.room_location || '';
                // format datetime-local
                esStart.value = s.start_datetime.substring(0,16);
                esEnd.value = s.end_datetime.substring(0,16);
                esStart.setAttribute('min', nowIso);
                esEnd.setAttribute('min', esStart.value || nowIso);
                const initDate = esStart.value ? esStart.value.split('T')[0] : '';
                if (initDate) {
                    esEnd.setAttribute('max', `${initDate}T23:59`);
                }

                let prevEsStart = initDate;
                esStart.addEventListener('input', () => {
                    if (esStart.value) {
                        esEnd.setAttribute('min', esStart.value);
                        const newDate = esStart.value.split('T')[0];
                        esEnd.setAttribute('max', `${newDate}T23:59`);
                        if (prevEsStart && prevEsStart !== newDate) {
                            // Automatically sync the date portion to match new start date
                            const eTime = esEnd.value ? esEnd.value.split('T')[1] : '12:00';
                            esEnd.value = `${newDate}T${eTime}`;
                        }
                        prevEsStart = newDate;
                    }
                });
            },
            showCancelButton: true,
            confirmButtonText: 'Save Changes',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('editSchedForm');
                const nowIso = getNowLocalIsoMinutes();
                if (!form.start_datetime.value || !form.end_datetime.value) {
                    Swal.showValidationMessage('Start and end times are required');
                    return false;
                }
                if (form.start_datetime.value < nowIso) {
                    Swal.showValidationMessage('Cannot schedule a session in the past. Please select an upcoming date and time.');
                    return false;
                }
                if (form.end_datetime.value <= form.start_datetime.value) {
                    Swal.showValidationMessage('End Date/Time must be after Start Date/Time');
                    return false;
                }
                if (form.start_datetime.value.split('T')[0] !== form.end_datetime.value.split('T')[0]) {
                    Swal.showValidationMessage('Class sessions must start and end on the same calendar day.');
                    return false;
                }
                const diffMin = (new Date(form.end_datetime.value) - new Date(form.start_datetime.value)) / 60000;
                if (diffMin < 15) {
                    Swal.showValidationMessage('Class duration must be at least 15 minutes.');
                    return false;
                }
                if (diffMin > 240) {
                    Swal.showValidationMessage('Class duration cannot exceed 4 hours.');
                    return false;
                }
                form.submit();
            }
        });
    }
    </script>
    </div>
    <?php
    render_footer();
}
