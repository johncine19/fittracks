<?php
declare(strict_types=1);

function classes_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner', 'trainer']);
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
            
            $className = db()->query('SELECT class_name FROM classes WHERE class_id = ' . (int)$class_id)->fetchColumn();
            $startTime = date('M j, Y g:i A', strtotime(post('start_datetime')));
            $activeMembers = db()->query('SELECT user_id FROM users WHERE role="member" AND status="active"')->fetchAll();
            foreach ($activeMembers as $member) {
                notify_user((int) $member['user_id'], 'class_reminder', 'New Class Session', 'A new session for ' . $className . ' has been scheduled on ' . $startTime . '.');
            }
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
    </style>

    <div>
        <!-- Glassmorphic Banner -->
        <div class="animate-fade-in" style="background: linear-gradient(135deg, rgba(199,255,34,0.1) 0%, rgba(66,219,165,0.05) 100%); border: 1px solid rgba(199,255,34,0.2); border-radius: 16px; padding: 28px 32px; margin-bottom: 24px; box-shadow: 0 4px 24px rgba(0,0,0,0.1); backdrop-filter: blur(16px); display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1 style="margin: 0; font-size: 26px; color: var(--ink); display: flex; align-items: center; gap: 12px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Classes & Schedules
                </h1>
                <p style="margin: 8px 0 0 0; color: var(--muted); font-size: 15px; max-width: 600px;">
                    Create class types and schedule sessions with rooms and instructors.
                </p>
            </div>
            <div style="display:flex; gap:12px;">
                <button type="button" onclick="document.getElementById('classModal').showModal()" style="background: var(--surface); border: 1px solid var(--line); color: var(--ink); padding: 10px 16px; border-radius: 8px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: background 0.2s;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    New Class Type
                </button>
                <button type="button" onclick="document.getElementById('scheduleModal').showModal()" style="background: var(--lime); border: none; color: var(--bg); padding: 10px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: opacity 0.2s;">
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
                <div class="panel" style="padding:0; overflow:hidden;">
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
                                    <td><span style="background:rgba(199,255,34,0.1); color:var(--lime); padding:4px 10px; border-radius:12px; font-weight:700; font-size:13px;"><?= (int) $s['booked'] ?></span></td>
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
            <?php endif; ?>
        </div>
    </div>

    <!-- Modals -->
    <dialog id="classModal" class="modal">
        <div class="modal-header">
            <h3 style="margin:0; font-size:20px;">Add New Class Type</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('classModal').close()">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="modal-body">
            <form method="post" style="display:flex; flex-direction:column; gap:16px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="class">
                <label>Class Name <input type="text" name="class_name" class="form-control" placeholder="e.g. HIIT Training" required></label>
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
                <label>Capacity <input type="number" name="capacity" class="form-control" min="1" placeholder="e.g. 20" required></label>
                <button type="submit" class="btn btn-primary" style="margin-top:8px;">Save Class</button>
            </form>
        </div>
    </dialog>

    <dialog id="scheduleModal" class="modal">
        <div class="modal-header">
            <h3 style="margin:0; font-size:20px;">Schedule Session</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('scheduleModal').close()">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="modal-body">
            <form method="post" style="display:flex; flex-direction:column; gap:16px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="schedule">
                <label>Class Type
                    <select name="class_id" class="form-control" required>
                        <option value="">-- Select Class --</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= (int) $class['class_id'] ?>"><?= h($class['class_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Room / Location <input type="text" name="room_location" class="form-control" placeholder="e.g. Studio A"></label>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <label>Start Date/Time <input type="datetime-local" name="start_datetime" class="form-control" required></label>
                    <label>End Date/Time <input type="datetime-local" name="end_datetime" class="form-control" required></label>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:8px;">Save Schedule</button>
            </form>
        </div>
    </dialog>

    <script>
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
                    <label style="display:block; color: var(--muted); font-size: 14px;">Start * <input name="start_datetime" id="es_start" type="datetime-local" class="form-control" required style="width: 100%; box-sizing: border-box;"></label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">End * <input name="end_datetime" id="es_end" type="datetime-local" class="form-control" required style="width: 100%; box-sizing: border-box;"></label>
                </form>
            `,
            didOpen: () => {
                document.getElementById('es_id').value = s.schedule_id;
                document.getElementById('es_class').value = s.class_id;
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
