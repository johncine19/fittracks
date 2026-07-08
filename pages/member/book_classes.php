<?php
declare(strict_types=1);

function book_classes_page(): void
{
    $user = require_roles(['member']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $scheduleId = (int) post('schedule_id');
        $action = post('action', 'book');
        
        $classRows = query_all(
            'SELECT c.class_name, s.start_datetime, s.room_location, c.instructor_id, s.duration_minutes
             FROM class_schedules s
             JOIN classes c ON c.class_id = s.class_id
             WHERE s.schedule_id = ?',
            [$scheduleId]
        );
        $class = $classRows[0] ?? null;
        
        if (!$class) {
            flash('Class not found.', 'danger');
            redirect('book_classes');
        }

        if ($action === 'cancel') {
            $start = strtotime($class['start_datetime']);
            if ($start - time() < 3600) {
                flash('You cannot cancel a class less than 1 hour before it starts.', 'danger');
            } else {
                db()->prepare('UPDATE class_bookings SET booking_status = "cancelled" WHERE schedule_id = ? AND user_id = ? AND booking_status = "booked"')->execute([$scheduleId, $user['user_id']]);
                if ($class['instructor_id']) {
                    notify_user((int) $class['instructor_id'], 'system', 'Class cancellation', $user['first_name'] . ' ' . $user['last_name'] . ' has cancelled their booking for ' . $class['class_name'] . '.');
                }
                flash('Your booking has been cancelled.', 'success');
            }
            redirect('book_classes');
        }
        
        $existing = db()->prepare('SELECT booking_status FROM class_bookings WHERE schedule_id = ? AND user_id = ?');
        $existing->execute([$scheduleId, $user['user_id']]);
        if ($existing->fetchColumn() === 'booked') {
            flash('You have already booked this class!', 'danger');
            redirect('book_classes');
        }

        // Check for overlaps
        $overlap = scalar('
            SELECT s2.schedule_id
            FROM class_bookings b
            JOIN class_schedules s2 ON b.schedule_id = s2.schedule_id
            WHERE b.user_id = ? AND b.booking_status = "booked"
              AND s2.start_datetime < DATE_ADD(?, INTERVAL ? MINUTE)
              AND DATE_ADD(s2.start_datetime, INTERVAL s2.duration_minutes MINUTE) > ?
        ', [
            $user['user_id'],
            $class['start_datetime'], $class['duration_minutes'],
            $class['start_datetime']
        ]);

        if ($overlap) {
            flash('You already have another class booked that overlaps with this time.', 'danger');
            redirect('book_classes');
        }

        db()->prepare('INSERT INTO class_bookings (schedule_id, user_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE booking_status = "booked"')->execute([$scheduleId, $user['user_id']]);

        // Auto-assign member to the trainer if an instructor exists
        if ($class['instructor_id']) {
            $trainerProfile = db()->prepare('SELECT trainer_id FROM trainer_profiles WHERE user_id = ?');
            $trainerProfile->execute([$class['instructor_id']]);
            $trainerId = $trainerProfile->fetchColumn();
            
            if ($trainerId) {
                $existingAssignment = db()->prepare('SELECT assignment_id FROM trainer_assignments WHERE trainer_id = ? AND member_user_id = ? AND status = "active"');
                $existingAssignment->execute([$trainerId, $user['user_id']]);
                if (!$existingAssignment->fetch()) {
                    db()->prepare('INSERT INTO trainer_assignments (trainer_id, member_user_id, assigned_date, status, assigned_by) VALUES (?, ?, CURDATE(), "active", ?)')
                        ->execute([$trainerId, $user['user_id'], $user['user_id']]);
                }
            }
        }
        
        $when = date('D, M j \a\t g:i A', strtotime($class['start_datetime']));
        $location = $class['room_location'] ? ' in ' . $class['room_location'] : '';
        notify_user(
            (int) $user['user_id'],
            'class_reminder',
            'Class booked',
            $class['class_name'] . ' on ' . $when . $location . '.'
        );

        flash('Class booked successfully!', 'success');
        redirect('book_classes');
    }
    $page = max(1, (int)($_GET['p'] ?? 1));
    $limit = 12;
    $offset = ($page - 1) * $limit;

    $totalSql = 'SELECT COUNT(*) FROM class_schedules s WHERE DATE(s.start_datetime) >= CURDATE()';
    $total = (int) scalar($totalSql);
    $totalPages = (int) ceil($total / $limit);

    $sql = 'SELECT s.*, c.class_name, c.description, c.capacity, COALESCE(CONCAT(u.first_name, " ", u.last_name), "Open trainer") AS instructor, (SELECT COUNT(*) FROM class_bookings b WHERE b.schedule_id = s.schedule_id AND b.booking_status = "booked") AS booked, (SELECT COUNT(*) FROM class_bookings b2 WHERE b2.schedule_id = s.schedule_id AND b2.user_id = ' . (int)$user['user_id'] . ' AND b2.booking_status = "booked") AS is_booked FROM class_schedules s JOIN classes c ON c.class_id = s.class_id LEFT JOIN users u ON u.user_id = c.instructor_id WHERE DATE(s.start_datetime) >= CURDATE() ORDER BY s.start_datetime LIMIT ' . $limit . ' OFFSET ' . $offset;
    $rows = db()->query($sql)->fetchAll();
    render_header('Book a Class', $user);
    ?>
    <div class="skeleton-wrapper">
        <section class="panel">
            <div style="margin-bottom:24px">
                <div class="sk sk-title" style="width:140px;margin-bottom:8px"></div>
                <div class="sk sk-text" style="width:280px;height:12px"></div>
            </div>
            <?php render_skeleton_cards(6); ?>
        </section>
    </div>
    <section class="panel skeleton-content sk-display-block">
        <div class="page-header">
            <div>
                <h1>Classes</h1>
                <p>Browse upcoming sessions and reserve your spot.</p>
            </div>
        </div>

        <?php if (!$rows): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <p>No upcoming classes scheduled yet.<br>Check back soon or contact your gym.</p>
            </div>
        <?php else: ?>
            <div class="class-card-grid">
                <?php
                $colors = ['#c7ff22', '#42dba5', '#ff9548', '#ff4d5d', '#a78bfa', '#38bdf8', '#f472b6', '#facc15'];
                $ci = 0;
                foreach ($rows as $row):
                    $booked   = (int) $row['booked'];
                    $capacity = (int) $row['capacity'];
                    $pct      = $capacity > 0 ? min(100, round($booked / $capacity * 100)) : 0;
                    $full     = $booked >= $capacity;
                    $barColor = $full ? '#ff4d5d' : ($pct >= 75 ? '#ff9548' : $colors[$ci % count($colors)]);
                    $startDt  = new DateTime($row['start_datetime']);
                    $endDt    = new DateTime($row['end_datetime']);
                ?>
                    <div class="cc-card">
                        <div class="cc-header">
                            <span class="cc-category" style="color:<?= $colors[$ci % count($colors)] ?>;border-color:<?= $colors[$ci % count($colors)] ?>"><?= h(strtoupper(substr($row['class_name'], 0, 8))) ?></span>
                            <?php if ($full): ?>
                                <span class="cc-full">Full</span>
                            <?php endif; ?>
                        </div>
                        <h3 class="cc-name"><?= h($row['class_name']) ?></h3>
                        <div class="cc-meta">
                            <div class="cc-meta-row">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                <?= h($startDt->format('g:i A')) ?> · <?= h($startDt->format('D, M j')) ?>
                            </div>
                            <div class="cc-meta-row">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <?= h($row['instructor']) ?>
                            </div>
                        </div>
                        <div class="cc-capacity">
                            <div class="cc-cap-header">
                                <span>Capacity</span>
                                <span class="cc-cap-nums"><?= $booked ?>/<?= $capacity ?></span>
                            </div>
                            <div class="cc-bar-track">
                                <div class="cc-bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div>
                            </div>
                        </div>
                        <form method="post" style="display: flex; gap: 8px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="schedule_id" value="<?= (int) $row['schedule_id'] ?>">
                            <?php $isAlreadyBooked = $row['is_booked'] > 0; ?>
                            <?php if ($isAlreadyBooked): ?>
                                <button type="submit" name="action" value="cancel" class="cc-book-btn" style="background:transparent;color:var(--danger);border:1px solid var(--danger);" data-confirm="Are you sure you want to cancel this booking?" data-confirm-btn="Yes, cancel">Cancel Booking</button>
                                <button type="button" disabled class="cc-book-btn" style="background:transparent;color:var(--lime);border:1px solid var(--lime);">Already Booked</button>
                            <?php else: ?>
                                <button type="submit" name="action" value="book" class="cc-book-btn" <?= $full ? 'disabled' : 'data-confirm="Are you sure you want to book this class?" data-confirm-btn="Yes, book"' ?>>
                                    <?= $full ? 'Class Full' : 'Book Slot' ?>
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>
                <?php $ci++; endforeach; ?>
            </div>
        <?php endif; ?>
        <?php render_pagination($page, $totalPages, '?page=book_classes'); ?>
    </section>
    <?php
    render_footer();
}
