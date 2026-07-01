<?php
declare(strict_types=1);

function classes_page(): void
{
    $user = require_roles(['admin']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (post('action') === 'class') {
            db()->prepare('INSERT INTO classes (class_name, description, instructor_id, capacity) VALUES (?, ?, ?, ?)')->execute([post('class_name'), post('description'), post('instructor_id') ?: null, post('capacity')]);
            flash('Class created.');
        } else {
            db()->prepare('INSERT INTO class_schedules (class_id, room_location, start_datetime, end_datetime) VALUES (?, ?, ?, ?)')->execute([post('class_id'), post('room_location'), post('start_datetime'), post('end_datetime')]);
            flash('Schedule created.');
        }
        redirect('classes');
    }
    $coaches   = db()->query('SELECT user_id, CONCAT(first_name, " ", last_name) AS name FROM users WHERE role = "trainer" AND status = "active"')->fetchAll();
    $classes   = db()->query('SELECT c.*, CONCAT(u.first_name, " ", u.last_name) AS instructor FROM classes c LEFT JOIN users u ON u.user_id = c.instructor_id ORDER BY c.created_at DESC')->fetchAll();
    $schedules = db()->query('SELECT s.*, c.class_name, (SELECT COUNT(*) FROM class_bookings b WHERE b.schedule_id = s.schedule_id AND b.booking_status = "booked") AS booked FROM class_schedules s JOIN classes c ON c.class_id = s.class_id ORDER BY s.start_datetime DESC')->fetchAll();
    render_header('Classes', $user);
    ?>
    <div class="page-header" style="padding:0 0 4px">
        <div>
            <h1 style="margin:0 0 4px">Classes & Schedules</h1>
            <p style="color:var(--muted);font-size:13px;margin:0">Create class types and schedule sessions with rooms and instructors.</p>
        </div>
        <button type="button" onclick="document.getElementById('classModal').style.display='flex'">+ Add Class</button>
    </div>

    <!-- Combined Modal -->
    <div id="classModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);align-items:center;justify-content:center;z-index:1000;padding:1rem">
        <div style="background:#0f172a;padding:2rem;border-radius:8px;width:100%;max-width:520px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.5);border:1px solid #334155">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
                <div style="display:flex;gap:0;border-radius:8px;overflow:hidden;border:1px solid #334155">
                    <button type="button" id="tabClassBtn" onclick="switchClassTab('class')" style="border-radius:0;border:none;padding:8px 18px;font-size:13px;font-weight:700">Add Class</button>
                    <button type="button" id="tabSchedBtn" onclick="switchClassTab('schedule')" style="border-radius:0;border:none;padding:8px 18px;font-size:13px;font-weight:700;background:transparent;color:var(--muted)">Schedule Session</button>
                </div>
                <button type="button" onclick="document.getElementById('classModal').style.display='none'" style="background:transparent;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;padding:0">&times;</button>
            </div>

            <!-- Add Class Form -->
            <form method="post" class="form" id="classForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="class">
                <label>Class name <input name="class_name" placeholder="e.g. HIIT Training" required></label>
                <label>Description <input name="description" placeholder="Short description"></label>
                <label>Instructor
                    <select name="instructor_id">
                        <option value="">— None assigned —</option>
                        <?php foreach ($coaches as $trainer): ?>
                            <option value="<?= (int) $trainer['user_id'] ?>"><?= h($trainer['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Capacity <input name="capacity" type="number" min="1" placeholder="e.g. 20" required></label>
                <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:0.5rem">
                    <button type="button" onclick="document.getElementById('classModal').style.display='none'" style="background:transparent;color:#94a3b8;border:1px solid #475569">Cancel</button>
                    <button>Save Class</button>
                </div>
            </form>

            <!-- Schedule Session Form -->
            <form method="post" class="form" id="scheduleForm" style="display:none">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="schedule">
                <label>Class
                    <select name="class_id">
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= (int) $class['class_id'] ?>"><?= h($class['class_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Room / Location <input name="room_location" placeholder="e.g. Studio A"></label>
                <label>Start <input name="start_datetime" type="datetime-local" required></label>
                <label>End <input name="end_datetime" type="datetime-local" required></label>
                <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:0.5rem">
                    <button type="button" onclick="document.getElementById('classModal').style.display='none'" style="background:transparent;color:#94a3b8;border:1px solid #475569">Cancel</button>
                    <button>Save Schedule</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    function switchClassTab(tab) {
        document.getElementById('classForm').style.display = tab === 'class' ? 'grid' : 'none';
        document.getElementById('scheduleForm').style.display = tab === 'schedule' ? 'grid' : 'none';
        document.getElementById('tabClassBtn').style.background = tab === 'class' ? 'var(--lime)' : 'transparent';
        document.getElementById('tabClassBtn').style.color = tab === 'class' ? '#080b0d' : 'var(--muted)';
        document.getElementById('tabSchedBtn').style.background = tab === 'schedule' ? 'var(--lime)' : 'transparent';
        document.getElementById('tabSchedBtn').style.color = tab === 'schedule' ? '#080b0d' : 'var(--muted)';
    }
    </script>

    <section class="panel">
        <p class="section-label">Class list (<?= count($classes) ?>)</p>
        <?php if (!$classes): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <p>No classes created yet.</p>
            </div>
        <?php else: ?>
        <?= render_simple_table($classes, ['class_name', 'instructor', 'capacity', 'description']) ?>
        <?php endif; ?>
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
                    <tr><th>Class</th><th>Room</th><th>Start</th><th>End</th><th>Booked</th></tr>
                </thead>
                <tbody>
                <?php foreach ($schedules as $s): ?>
                    <tr>
                        <td><strong><?= h($s['class_name']) ?></strong></td>
                        <td style="color:var(--muted)"><?= h($s['room_location'] ?: '—') ?></td>
                        <td><?= h(date('M j, Y · g:i A', strtotime($s['start_datetime']))) ?></td>
                        <td><?= h(date('g:i A', strtotime($s['end_datetime']))) ?></td>
                        <td><span style="color:var(--lime);font-weight:700"><?= (int) $s['booked'] ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
    <?php
    render_footer();
}
