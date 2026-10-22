<?php
declare(strict_types=1);

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
            $instructor_id = ($user['role'] === 'trainer') ? $user['user_id'] : (post('instructor_id') ?: null);
            db()->prepare('INSERT INTO classes (class_name, description, instructor_id, capacity, gym_id, created_by) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([post('class_name'), post('description'), $instructor_id, post('capacity'), $gymId, $user['user_id']]);
            audit_log($user['user_id'], 'create', 'class', (string) db()->lastInsertId(), json_encode(['class_name' => post('class_name')]));
            flash('Class created.');
        } elseif ($action === 'schedule') {
            $class_id = post('class_id');
            if (!$isAdmin) {
                $c = db()->prepare('SELECT instructor_id, gym_id FROM classes WHERE class_id=?');
                $c->execute([$class_id]);
                $cls = $c->fetch();
                if (!$cls) redirect('classes');
                
                if ($user['role'] === 'gym_owner' && $cls['gym_id'] != $gymId) redirect('classes');
                if ($user['role'] === 'trainer' && $cls['instructor_id'] != $user['user_id']) redirect('classes');
            }
            db()->prepare('INSERT INTO class_schedules (class_id, room_location, start_datetime, end_datetime) VALUES (?, ?, ?, ?)')
                ->execute([$class_id, post('room_location'), post('start_datetime'), post('end_datetime')]);
            
            $startTime = date('M j, Y g:i A', strtotime(post('start_datetime')));
            Queue::push('broadcast_class_schedule_job', [
                'class_id' => (int) $class_id,
                'start_time' => $startTime,
            ]);
            audit_log($user['user_id'], 'create', 'class_schedule', (string) db()->lastInsertId(), json_encode(['class_id' => $class_id, 'start' => post('start_datetime')]));
            
            flash('Schedule created.');
        } elseif ($action === 'edit_class') {
            if ($isAdmin) {
                db()->prepare('UPDATE classes SET class_name=?, description=?, instructor_id=?, capacity=? WHERE class_id=?')
                    ->execute([post('class_name'), post('description'), post('instructor_id') ?: null, post('capacity'), post('class_id')]);
            } elseif ($user['role'] === 'gym_owner') {
                db()->prepare('UPDATE classes SET class_name=?, description=?, instructor_id=?, capacity=? WHERE class_id=? AND gym_id=?')
                    ->execute([post('class_name'), post('description'), post('instructor_id') ?: null, post('capacity'), post('class_id'), $gymId]);
            } else {
                db()->prepare('UPDATE classes SET class_name=?, description=?, capacity=? WHERE class_id=? AND instructor_id=?')
                    ->execute([post('class_name'), post('description'), post('capacity'), post('class_id'), $user['user_id']]);
            }
            flash('Class updated.');
            audit_log($user['user_id'], 'edit', 'class', (string) post('class_id'), json_encode(['class_name' => post('class_name')]));
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
        } elseif ($action === 'edit_schedule') {
            $schedule_id = post('schedule_id');
            $class_id = post('class_id');
            if (!$isAdmin) {
                $c = db()->prepare('SELECT instructor_id, gym_id FROM classes WHERE class_id=?');
                $c->execute([$class_id]);
                $cls = $c->fetch();
                if (!$cls) redirect('classes');
                
                if ($user['role'] === 'gym_owner' && $cls['gym_id'] != $gymId) redirect('classes');
                if ($user['role'] === 'trainer' && $cls['instructor_id'] != $user['user_id']) redirect('classes');
            }
            db()->prepare('UPDATE class_schedules SET class_id=?, room_location=?, start_datetime=?, end_datetime=? WHERE schedule_id=?')
                ->execute([$class_id, post('room_location'), post('start_datetime'), post('end_datetime'), $schedule_id]);
            audit_log($user['user_id'], 'edit', 'class_schedule', (string) $schedule_id, json_encode(['class_id' => $class_id]));
            flash('Schedule updated.');
        } elseif ($action === 'delete_schedule') {
            $schedule_id = post('schedule_id');
            if (!$isAdmin) {
                $c = db()->prepare('SELECT c.instructor_id, c.gym_id FROM classes c JOIN class_schedules s ON c.class_id = s.class_id WHERE s.schedule_id=?');
                $c->execute([$schedule_id]);
                $cls = $c->fetch();
                if (!$cls) redirect('classes');
                
                if ($user['role'] === 'gym_owner' && $cls['gym_id'] != $gymId) redirect('classes');
                if ($user['role'] === 'trainer' && $cls['instructor_id'] != $user['user_id']) redirect('classes');
            }
            db()->prepare('DELETE FROM class_schedules WHERE schedule_id=?')->execute([$schedule_id]);
            audit_log($user['user_id'], 'delete', 'class_schedule', (string) $schedule_id);
            flash('Schedule deleted.');
        }
        redirect('classes');
    }

    if ($isAdmin) {
        $coaches = db()->query('SELECT user_id, CONCAT(first_name, " ", last_name) AS name FROM users WHERE role = "trainer" AND status = "active"')->fetchAll();
        $classes = db()->query('SELECT c.*, CONCAT(u.first_name, " ", u.last_name) AS instructor FROM classes c LEFT JOIN users u ON u.user_id = c.instructor_id ORDER BY c.created_at DESC')->fetchAll();
        $schedules = db()->query('SELECT s.*, c.class_name, (SELECT COUNT(*) FROM class_bookings b WHERE b.schedule_id = s.schedule_id AND b.booking_status = "booked") AS booked FROM class_schedules s JOIN classes c ON c.class_id = s.class_id ORDER BY s.start_datetime DESC')->fetchAll();
    } else {
        $coaches = db()->query('SELECT u.user_id, CONCAT(u.first_name, " ", u.last_name) AS name FROM users u JOIN trainer_profiles tp ON u.user_id = tp.user_id WHERE u.role = "trainer" AND u.status = "active" AND tp.gym_id = ' . (int)$gymId)->fetchAll();
        
        $stmt = db()->prepare('SELECT c.*, CONCAT(u.first_name, " ", u.last_name) AS instructor FROM classes c LEFT JOIN users u ON u.user_id = c.instructor_id WHERE c.gym_id = ? ORDER BY c.created_at DESC');
        $stmt->execute([$gymId]);
        $classes = $stmt->fetchAll();
        
        $stmt2 = db()->prepare('SELECT s.*, c.class_name, (SELECT COUNT(*) FROM class_bookings b WHERE b.schedule_id = s.schedule_id AND b.booking_status = "booked") AS booked FROM class_schedules s JOIN classes c ON c.class_id = s.class_id WHERE c.gym_id = ? ORDER BY s.start_datetime DESC');
        $stmt2->execute([$gymId]);
        $schedules = $stmt2->fetchAll();
    }
    
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
        .class-card { background: var(--panel-soft); border: 1px solid var(--line); border-radius: 16px; padding: 20px; transition: all 0.2s ease; display: flex; flex-direction: column; gap: 12px; position: relative; overflow: hidden; }
        .class-card:hover { border-color: rgba(255,255,255,0.15); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.2); }
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
                <button type="button" class="classes-banner-btn-primary" onclick="document.getElementById('scheduleModal').showModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Schedule Session
                </button>
            </div>
        </div>

        <div class="class-tabs">
            <button class="class-tab-btn active" onclick="switchClassTab('types')">Class Types</button>
            <button class="class-tab-btn" onclick="switchClassTab('schedules')">Schedules</button>
        </div>

        <div id="tab-types" class="tab-content active animate-fade-in">
            <?php if (!$classes): ?>
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <p>No classes created yet.</p>
                </div>
            <?php else: ?>
                <div class="cc-grid">
                    <?php foreach ($classes as $c): $safeJson = htmlspecialchars(json_encode($c)); ?>
                        <div class="class-card">
                            <div style="display:flex; justify-content: space-between; align-items: flex-start;">
                                <h3 style="margin:0; font-size:18px; color:var(--ink)"><?= h($c['class_name']) ?></h3>
                                <div style="display:flex; gap:6px;">
                                    <button onclick="editClass(<?= $safeJson ?>)" style="background:transparent; border:none; color:var(--muted); cursor:pointer; padding:4px;" title="Edit"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>
                                    <form method="post" onsubmit="return confirm('Delete this class? All associated schedules will also be deleted.');" style="margin:0;">
                                        <?= $csrfStr ?><input type="hidden" name="action" value="delete_class"><input type="hidden" name="class_id" value="<?= $c['class_id'] ?>">
                                        <button type="submit" style="background:transparent; border:none; color:var(--danger); cursor:pointer; padding:4px;" title="Delete"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
                                    </form>
                                </div>
                            </div>
                            <p style="margin:0; font-size:14px; color:var(--muted); flex-grow:1;"><?= h($c['description'] ?: 'No description provided.') ?></p>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; padding-top:12px; border-top:1px solid rgba(255,255,255,0.05);">
                                <div style="display:flex; align-items:center; gap:6px; font-size:13px; color:var(--muted);">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <?= h($c['instructor'] ?: 'No instructor') ?>
                                </div>
                                <span style="background:rgba(255,255,255,0.05); padding:4px 8px; border-radius:12px; font-size:12px; color:var(--ink);">Capacity: <?= (int)$c['capacity'] ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div id="tab-schedules" class="tab-content animate-fade-in">
            <?php if (!$schedules): ?>
                <div class="empty-state">
                    <p>No schedules yet. Create one using the button above.</p>
                </div>
            <?php else: ?>
                <div class="panel schedule-table-panel" style="padding:0; overflow:hidden;">
                    <div class="table-wrap">
                        <table style="margin:0; width:100%;">
                            <thead>
                                <tr><th>Class Session</th><th>Location</th><th>Date & Time</th><th>Booked</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($schedules as $s): ?>
                                <tr>
                                    <td><strong><?= h($s['class_name']) ?></strong></td>
                                    <td><span style="display:inline-flex; align-items:center; gap:6px; color:var(--muted); font-size:13px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg><?= h($s['room_location'] ?: '—') ?></span></td>
                                    <td>
                                        <div style="font-size:14px; color:var(--ink);"><?= h(date('M j, Y', strtotime($s['start_datetime']))) ?></div>
                                        <div style="font-size:12px; color:var(--muted);"><?= h(date('g:i A', strtotime($s['start_datetime']))) ?> - <?= h(date('g:i A', strtotime($s['end_datetime']))) ?></div>
                                    </td>
                                    <td><span style="background:rgba(234,88,12,0.12); color:var(--orange, #ea580c); padding:4px 10px; border-radius:12px; font-weight:700; font-size:13px;"><?= (int) $s['booked'] ?></span></td>
                                    <td>
                                        <button onclick="editSchedule(<?= htmlspecialchars(json_encode($s)) ?>)" class="btn btn-secondary" style="padding:6px 10px; font-size:12px; border:none; background:rgba(255,255,255,0.05);">Edit</button>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this schedule?');">
                                            <?= $csrfStr ?><input type="hidden" name="action" value="delete_schedule"><input type="hidden" name="schedule_id" value="<?= $s['schedule_id'] ?>">
                                            <button type="submit" class="btn btn-danger" style="padding:6px 10px; font-size:12px; border:none; background:rgba(239,68,68,0.1); color:var(--danger);">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="schedule-mobile-list">
                    <?php foreach ($schedules as $s): ?>
                        <article class="schedule-mobile-card">
                            <h3 class="schedule-mobile-card-title"><?= h($s['class_name']) ?></h3>
                            <div class="schedule-mobile-details">
                                <div>
                                    <span class="schedule-mobile-detail-label">Location</span>
                                    <div class="schedule-mobile-detail-value"><?= h($s['room_location'] ?: 'â€”') ?></div>
                                </div>
                                <div>
                                    <span class="schedule-mobile-detail-label">Date &amp; time</span>
                                    <div class="schedule-mobile-detail-value">
                                        <div><?= h(date('M j, Y', strtotime($s['start_datetime']))) ?></div>
                                        <div style="color:var(--muted); font-size:12px;"><?= h(date('g:i A', strtotime($s['start_datetime']))) ?> - <?= h(date('g:i A', strtotime($s['end_datetime']))) ?></div>
                                    </div>
                                </div>
                                <div>
                                    <span class="schedule-mobile-detail-label">Booked</span>
                                    <div class="schedule-mobile-detail-value"><?= (int) $s['booked'] ?> member<?= (int) $s['booked'] === 1 ? '' : 's' ?></div>
                                </div>
                            </div>
                            <div class="schedule-mobile-actions">
                                <button onclick="editSchedule(<?= htmlspecialchars(json_encode($s)) ?>)" class="btn btn-secondary" style="padding:8px 10px; font-size:12px;">Edit</button>
                                <form method="post" style="display:contents;" onsubmit="return confirm('Delete this schedule?');">
                                    <?= $csrfStr ?><input type="hidden" name="action" value="delete_schedule"><input type="hidden" name="schedule_id" value="<?= $s['schedule_id'] ?>">
                                    <button type="submit" class="btn btn-danger" style="padding:8px 10px; font-size:12px;">Delete</button>
                                </form>
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
            <form method="post" class="classes-modal-form">
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
                    <label><span class="label-title">Start Date/Time <span class="req-star">*</span></span> <input type="datetime-local" lang="en-US" name="start_datetime" class="form-control" required></label>
                    <label><span class="label-title">End Date/Time <span class="req-star">*</span></span> <input type="datetime-local" lang="en-US" name="end_datetime" class="form-control" required></label>
                </div>
                <button type="submit" class="btn btn-primary classes-modal-submit">Save Schedule</button>
            </form>
        </div>
    </dialog>

    <script>
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

    function switchClassTab(tab) {
        document.querySelectorAll('.class-tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        document.querySelector(`.class-tab-btn[onclick="switchClassTab('${tab}')"]`).classList.add('active');
        document.getElementById(`tab-${tab}`).classList.add('active');
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
                document.getElementById('es_id').value = s.schedule_id;
                document.getElementById('es_class').value = s.class_id;
                enhanceScheduleSelect(document.getElementById('es_class'));
                document.getElementById('es_loc').value = s.room_location || '';
                // format datetime-local
                document.getElementById('es_start').value = s.start_datetime.substring(0,16);
                document.getElementById('es_end').value = s.end_datetime.substring(0,16);
            },
            showCancelButton: true,
            confirmButtonText: 'Save Changes',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('editSchedForm');
                if (!form.start_datetime.value || !form.end_datetime.value) {
                    Swal.showValidationMessage('Start and end times are required');
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
