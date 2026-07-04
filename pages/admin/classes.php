<?php
declare(strict_types=1);

function classes_page(): void
{
    $user = require_roles(['admin', 'trainer']);
    $isAdmin = $user['role'] === 'admin';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        if ($action === 'class') {
            $instructor_id = $isAdmin ? (post('instructor_id') ?: null) : $user['user_id'];
            db()->prepare('INSERT INTO classes (class_name, description, instructor_id, capacity) VALUES (?, ?, ?, ?)')->execute([post('class_name'), post('description'), $instructor_id, post('capacity')]);
            flash('Class created.');
        } elseif ($action === 'schedule') {
            $class_id = post('class_id');
            if (!$isAdmin) {
                $c = db()->prepare('SELECT instructor_id FROM classes WHERE class_id=?');
                $c->execute([$class_id]);
                if ($c->fetchColumn() != $user['user_id']) redirect('classes');
            }
            db()->prepare('INSERT INTO class_schedules (class_id, room_location, start_datetime, end_datetime) VALUES (?, ?, ?, ?)')->execute([$class_id, post('room_location'), post('start_datetime'), post('end_datetime')]);
            flash('Schedule created.');
        } elseif ($action === 'edit_class') {
            if ($isAdmin) {
                db()->prepare('UPDATE classes SET class_name=?, description=?, instructor_id=?, capacity=? WHERE class_id=?')->execute([post('class_name'), post('description'), post('instructor_id') ?: null, post('capacity'), post('class_id')]);
            } else {
                db()->prepare('UPDATE classes SET class_name=?, description=?, capacity=? WHERE class_id=? AND instructor_id=?')->execute([post('class_name'), post('description'), post('capacity'), post('class_id'), $user['user_id']]);
            }
            flash('Class updated.');
        } elseif ($action === 'delete_class') {
            if ($isAdmin) {
                db()->prepare('DELETE FROM classes WHERE class_id=?')->execute([post('class_id')]);
            } else {
                db()->prepare('DELETE FROM classes WHERE class_id=? AND instructor_id=?')->execute([post('class_id'), $user['user_id']]);
            }
            flash('Class deleted.');
        } elseif ($action === 'edit_schedule') {
            $schedule_id = post('schedule_id');
            $class_id = post('class_id');
            if (!$isAdmin) {
                $c = db()->prepare('SELECT instructor_id FROM classes WHERE class_id=?');
                $c->execute([$class_id]);
                if ($c->fetchColumn() != $user['user_id']) redirect('classes');
            }
            db()->prepare('UPDATE class_schedules SET class_id=?, room_location=?, start_datetime=?, end_datetime=? WHERE schedule_id=?')->execute([$class_id, post('room_location'), post('start_datetime'), post('end_datetime'), $schedule_id]);
            flash('Schedule updated.');
        } elseif ($action === 'delete_schedule') {
            $schedule_id = post('schedule_id');
            if (!$isAdmin) {
                $c = db()->prepare('SELECT c.instructor_id FROM classes c JOIN class_schedules s ON c.class_id = s.class_id WHERE s.schedule_id=?');
                $c->execute([$schedule_id]);
                if ($c->fetchColumn() != $user['user_id']) redirect('classes');
            }
            db()->prepare('DELETE FROM class_schedules WHERE schedule_id=?')->execute([$schedule_id]);
            flash('Schedule deleted.');
        }
        redirect('classes');
    }
    $coaches   = db()->query('SELECT user_id, CONCAT(first_name, " ", last_name) AS name FROM users WHERE role = "trainer" AND status = "active"')->fetchAll();
    if ($isAdmin) {
        $classes   = db()->query('SELECT c.*, CONCAT(u.first_name, " ", u.last_name) AS instructor FROM classes c LEFT JOIN users u ON u.user_id = c.instructor_id ORDER BY c.created_at DESC')->fetchAll();
        $schedules = db()->query('SELECT s.*, c.class_name, (SELECT COUNT(*) FROM class_bookings b WHERE b.schedule_id = s.schedule_id AND b.booking_status = "booked") AS booked FROM class_schedules s JOIN classes c ON c.class_id = s.class_id ORDER BY s.start_datetime DESC')->fetchAll();
    } else {
        $stmt = db()->prepare('SELECT c.*, CONCAT(u.first_name, " ", u.last_name) AS instructor FROM classes c LEFT JOIN users u ON u.user_id = c.instructor_id WHERE c.instructor_id = ? ORDER BY c.created_at DESC');
        $stmt->execute([$user['user_id']]);
        $classes = $stmt->fetchAll();
        
        $stmt2 = db()->prepare('SELECT s.*, c.class_name, (SELECT COUNT(*) FROM class_bookings b WHERE b.schedule_id = s.schedule_id AND b.booking_status = "booked") AS booked FROM class_schedules s JOIN classes c ON c.class_id = s.class_id WHERE c.instructor_id = ? ORDER BY s.start_datetime DESC');
        $stmt2->execute([$user['user_id']]);
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
    <div class="skeleton-wrapper">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;padding-top:12px">
            <div>
                <div class="sk sk-title" style="width:200px;margin-bottom:8px"></div>
                <div class="sk sk-text" style="width:300px;height:12px"></div>
            </div>
            <div style="display:flex;gap:10px;">
                <div class="sk sk-rect" style="width:100px;height:36px;border-radius:18px"></div>
                <div class="sk sk-rect" style="width:140px;height:36px;border-radius:18px"></div>
            </div>
        </div>
        <section class="panel">
            <div class="sk sk-text short" style="margin-bottom:12px;height:14px;width:120px"></div>
            <?php render_skeleton_table(5, 4); ?>
        </section>
        <section class="panel">
            <div class="sk sk-text short" style="margin-bottom:12px;height:14px;width:180px"></div>
            <?php render_skeleton_table(6, 4); ?>
        </section>
    </div>
    <div class="skeleton-content sk-display-block">
    <div class="page-header" style="padding:0 0 4px">
        <div>
            <h1 style="margin:0 0 4px">Classes & Schedules</h1>
            <p style="color:var(--muted);font-size:13px;margin:0">Create class types and schedule sessions with rooms and instructors.</p>
        </div>
        <div style="display:flex;gap:10px;">
            <button type="button" onclick="document.getElementById('classModal').style.display='flex'">+ Add Class</button>
            <button type="button" onclick="document.getElementById('scheduleModal').style.display='flex'" style="background:transparent;border:1px solid #475569;color:var(--ink)">+ Schedule Session</button>
        </div>
    </div>

    <!-- Combined Modal -->
    <!-- Add Class Modal -->
    <div id="classModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);align-items:center;justify-content:center;z-index:1000;padding:1rem">
        <div style="background:#0f172a;padding:2rem;border-radius:8px;width:100%;max-width:520px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.5);border:1px solid #334155">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
                <h2 style="margin:0; font-size:1.5rem; color:var(--ink)">Add Class</h2>
                <button type="button" onclick="document.getElementById('classModal').style.display='none'" style="background:transparent;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;padding:0">&times;</button>
            </div>
            <form method="post" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="class">
                <label>Class name <input name="class_name" placeholder="e.g. HIIT Training" required></label>
                <label>Description <input name="description" placeholder="Short description"></label>
                <?php if ($isAdmin): ?>
                <label>Instructor
                    <select name="instructor_id">
                        <option value="">— None assigned —</option>
                        <?php foreach ($coaches as $trainer): ?>
                            <option value="<?= (int) $trainer['user_id'] ?>"><?= h($trainer['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>
                <label>Capacity <input name="capacity" type="number" min="1" placeholder="e.g. 20" required></label>
                <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:0.5rem">
                    <button type="button" onclick="document.getElementById('classModal').style.display='none'" style="background:transparent;color:#94a3b8;border:1px solid #475569">Cancel</button>
                    <button>Save Class</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Schedule Session Modal -->
    <div id="scheduleModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);align-items:center;justify-content:center;z-index:1000;padding:1rem">
        <div style="background:#0f172a;padding:2rem;border-radius:8px;width:100%;max-width:520px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.5);border:1px solid #334155">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
                <h2 style="margin:0; font-size:1.5rem; color:var(--ink)">Schedule Session</h2>
                <button type="button" onclick="document.getElementById('scheduleModal').style.display='none'" style="background:transparent;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;padding:0">&times;</button>
            </div>
            <form method="post" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="schedule">
                <label>Class
                    <select name="class_id" required>
                        <option value="">-- Select Class --</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= (int) $class['class_id'] ?>"><?= h($class['class_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Room / Location <input name="room_location" placeholder="e.g. Studio A"></label>
                <label>Start <input name="start_datetime" type="datetime-local" required></label>
                <label>End <input name="end_datetime" type="datetime-local" required></label>
                <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:0.5rem">
                    <button type="button" onclick="document.getElementById('scheduleModal').style.display='none'" style="background:transparent;color:#94a3b8;border:1px solid #475569">Cancel</button>
                    <button>Save Schedule</button>
                </div>
            </form>
        </div>
    </div>
    <script>

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
                    <label style="display:block; color: var(--muted); font-size: 14px;">Instructor
                        <select name="instructor_id" id="ec_inst" class="form-control" style="width: 100%; box-sizing: border-box;">
                            <?= $instructorOptions ?>
                        </select>
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Capacity * <input name="capacity" id="ec_cap" type="number" min="1" class="form-control" required style="width: 100%; box-sizing: border-box;"></label>
                </form>
            `,
            didOpen: () => {
                document.getElementById('ec_id').value = c.class_id;
                document.getElementById('ec_name').value = c.class_name;
                document.getElementById('ec_desc').value = c.description || '';
                document.getElementById('ec_inst').value = c.instructor_id || '';
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
                            <?= $classOptions ?>
                        </select>
                    </label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Room / Location <input name="room_location" id="es_room" class="form-control" style="width: 100%; box-sizing: border-box;"></label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">Start * <input name="start_datetime" id="es_start" type="datetime-local" class="form-control" required style="width: 100%; box-sizing: border-box;"></label>
                    <label style="display:block; color: var(--muted); font-size: 14px;">End * <input name="end_datetime" id="es_end" type="datetime-local" class="form-control" required style="width: 100%; box-sizing: border-box;"></label>
                </form>
            `,
            didOpen: () => {
                document.getElementById('es_id').value = s.schedule_id;
                document.getElementById('es_class').value = s.class_id;
                document.getElementById('es_room').value = s.room_location || '';
                // format dates for datetime-local
                document.getElementById('es_start').value = s.start_datetime.substring(0, 16);
                document.getElementById('es_end').value = s.end_datetime.substring(0, 16);
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

    <section class="panel">
        <p class="section-label">Class list (<?= count($classes) ?>)</p>
        <?php if (!$classes): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <p>No classes created yet.</p>
            </div>
        <?php else: 
            $tableRows = array_map(function($c) use ($csrfStr) {
                $safeJson = htmlspecialchars(json_encode($c));
                $c['actions'] = '<button onclick="editClass(' . $safeJson . ')" class="btn btn-secondary" style="padding:4px 8px;font-size:12px;margin-right:4px;">Edit</button>' .
                                '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete this class? All associated schedules will also be deleted.\');">' .
                                $csrfStr . '<input type="hidden" name="action" value="delete_class"><input type="hidden" name="class_id" value="'.$c['class_id'].'">' .
                                '<button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:12px;">Delete</button></form>';
                return $c;
            }, $classes);
            echo render_simple_table($tableRows, ['class_name', 'instructor', 'capacity', 'description', 'actions']);
        endif; ?>
    </section>

    <section class="panel">
        <p class="section-label">Upcoming & recent schedules</p>
        <?php if (!$schedules): ?>
            <div class="empty-state">
                <p>No schedules yet. Create one using the form above.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Class</th><th>Room</th><th>Start</th><th>End</th><th>Booked</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($schedules as $s): ?>
                    <tr>
                        <td><strong><?= h($s['class_name']) ?></strong></td>
                        <td style="color:var(--muted)"><?= h($s['room_location'] ?: '—') ?></td>
                        <td><?= h(date('M j, Y · g:i A', strtotime($s['start_datetime']))) ?></td>
                        <td><?= h(date('M j, Y · g:i A', strtotime($s['end_datetime']))) ?></td>
                        <td><span style="color:var(--lime);font-weight:700"><?= (int) $s['booked'] ?></span></td>
                        <td>
                            <button onclick="editSchedule(<?= htmlspecialchars(json_encode($s)) ?>)" class="btn btn-secondary" style="padding:4px 8px;font-size:12px;">Edit</button>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this schedule?');">
                                <?= $csrfStr ?>
                                <input type="hidden" name="action" value="delete_schedule">
                                <input type="hidden" name="schedule_id" value="<?= $s['schedule_id'] ?>">
                                <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:12px;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
    </div>
    <?php
    render_footer();
}
