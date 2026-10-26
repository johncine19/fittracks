<?php
declare(strict_types=1);

function book_classes_page(): void
{
    $user = require_roles(['member']);
    
    $isGymMember = db()->prepare('SELECT 1 FROM gym_members WHERE user_id = ?');
    $isGymMember->execute([$user['user_id']]);
    if (!$isGymMember->fetchColumn()) {
        flash('Please select a gym first to view this page.', 'warning');
        redirect('gym_selection');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $scheduleId = (int) post('schedule_id');
        $action = post('action', 'book');
        
        // Guardrail: Explicitly block booking if the class is not scheduled yet
        if ($scheduleId <= 0) {
            flash('This class cannot be booked because it has not been scheduled yet. Please check back once a session time is published.', 'warning');
            redirect('book_classes');
        }

        $classRows = query_all(
            'SELECT c.class_name, c.capacity, s.start_datetime, s.end_datetime, s.room_location, c.instructor_id
             FROM class_schedules s
             JOIN classes c ON c.class_id = s.class_id
             WHERE s.schedule_id = ?',
            [$scheduleId]
        );
        $class = $classRows[0] ?? null;
        
        if (!$class || empty($class['start_datetime'])) {
            flash('This class session could not be found or has not been scheduled yet.', 'danger');
            redirect('book_classes');
        }

        if (strtotime($class['start_datetime']) < strtotime('today')) {
            flash('This class session is no longer available for booking.', 'warning');
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

        // Check class capacity
        $currentBookings = (int) scalar(
            'SELECT COUNT(*) FROM class_bookings WHERE schedule_id = ? AND booking_status = "booked"',
            [$scheduleId]
        );
        $maxCapacity = (int) ($class['capacity'] ?? 0);
        if ($maxCapacity > 0 && $currentBookings >= $maxCapacity) {
            flash("Sorry, this class is full ($currentBookings / $maxCapacity capacity reached).", 'danger');
            redirect('book_classes');
        }

        // Check for overlaps
        $overlap = scalar('
            SELECT s2.schedule_id
            FROM class_bookings b
            JOIN class_schedules s2 ON b.schedule_id = s2.schedule_id
            WHERE b.user_id = ? AND b.booking_status = "booked"
              AND s2.start_datetime < ?
              AND s2.end_datetime > ?
        ', [
            $user['user_id'],
            $class['end_datetime'],
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
                    grant_retroactive_commission((int)$user['user_id']);
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

    $currentGym = get_user_gym($user);
    $gymId = $currentGym ? (int) $currentGym['gym_id'] : 0;

    $gymClassCount = (int) scalar('SELECT COUNT(*) FROM classes WHERE gym_id = ?', [$gymId]);
    $whereClassGym = ($gymId > 0 && $gymClassCount > 0)
        ? 'c.gym_id = ' . $gymId
        : ($gymId > 0 ? '(c.gym_id = ' . $gymId . ' OR c.gym_id IS NULL)' : '1=1');

    $totalScheduled = (int) scalar("SELECT COUNT(*) FROM class_schedules s JOIN classes c ON c.class_id = s.class_id WHERE s.start_datetime >= CURDATE() AND {$whereClassGym}");
    $totalUnscheduled = (int) scalar("SELECT COUNT(*) FROM classes c WHERE {$whereClassGym} AND NOT EXISTS (SELECT 1 FROM class_schedules s2 WHERE s2.class_id = c.class_id AND s2.start_datetime >= CURDATE())");
    $total = $totalScheduled + $totalUnscheduled;
    $totalPages = (int) ceil($total / $limit);

    $sql = "
    (
        SELECT 
            s.schedule_id,
            s.class_id,
            s.room_location,
            s.start_datetime,
            s.end_datetime,
            c.class_name,
            c.description,
            c.capacity,
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'Open trainer') AS instructor,
            1 AS is_scheduled,
            (SELECT COUNT(*) FROM class_bookings b WHERE b.schedule_id = s.schedule_id AND b.booking_status = 'booked') AS booked,
            (SELECT COUNT(*) FROM class_bookings b2 WHERE b2.schedule_id = s.schedule_id AND b2.user_id = " . (int)$user['user_id'] . " AND b2.booking_status = 'booked') AS is_booked
        FROM class_schedules s
        JOIN classes c ON c.class_id = s.class_id
        LEFT JOIN users u ON u.user_id = c.instructor_id
        WHERE s.start_datetime >= CURDATE()
          AND {$whereClassGym}
    )
    UNION ALL
    (
        SELECT 
            0 AS schedule_id,
            c.class_id,
            NULL AS room_location,
            NULL AS start_datetime,
            NULL AS end_datetime,
            c.class_name,
            c.description,
            c.capacity,
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'Open trainer') AS instructor,
            0 AS is_scheduled,
            0 AS booked,
            0 AS is_booked
        FROM classes c
        LEFT JOIN users u ON u.user_id = c.instructor_id
        WHERE {$whereClassGym}
          AND NOT EXISTS (
              SELECT 1 FROM class_schedules s2 
              WHERE s2.class_id = c.class_id 
                AND s2.start_datetime >= CURDATE()
          )
    )
    ORDER BY is_scheduled DESC, start_datetime ASC, class_name ASC
    LIMIT {$limit} OFFSET {$offset}
    ";
    $rows = db()->query($sql)->fetchAll();
    render_header('Book a Class', $user);
    ?>
    <style>
    .class-search-box {
        position: relative;
        width: 100%;
        max-width: 340px;
        min-width: 260px;
    }
    .class-search-box svg {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--muted);
        pointer-events: none;
        transition: color 0.2s ease;
    }
    #classSearchInput {
        width: 100%;
        box-sizing: border-box;
        padding: 10px 14px 10px 40px;
        border-radius: 10px;
        border: 1px solid var(--line);
        background: var(--bg);
        color: var(--ink);
        font-size: 14px;
        outline: none;
        transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }
    [data-theme="light"] #classSearchInput {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }
    #classSearchInput:focus {
        border-color: var(--lime);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--lime) 22%, transparent);
    }
    .class-search-box:focus-within svg {
        color: var(--lime);
    }
    #classSearchInput::placeholder {
        color: var(--muted);
    }
    [data-theme="light"] #classSearchInput::placeholder {
        color: #94a3b8;
    }
    .class-filter-pills {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
        margin-top: 14px;
    }
    .class-pill-btn {
        background: var(--panel-soft);
        border: 1px solid var(--line);
        color: var(--muted);
        font-size: 12.5px;
        font-weight: 600;
        padding: 6px 14px;
        border-radius: 20px;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .class-pill-btn:hover {
        color: var(--ink);
        border-color: rgba(255, 255, 255, 0.2);
    }
    .class-pill-btn.active {
        background: var(--lime);
        color: #080b0d !important;
        border-color: var(--lime);
        font-weight: 700;
        box-shadow: 0 2px 8px color-mix(in srgb, var(--lime) 30%, transparent);
    }
    [data-theme="light"] .class-pill-btn {
        background: #f1f5f9;
        border-color: #cbd5e1;
        color: #475569;
    }
    [data-theme="light"] .class-pill-btn:hover {
        color: #0f172a;
        background: #e2e8f0;
    }
    [data-theme="light"] .class-pill-btn.active {
        background: #0f172a;
        color: #ffffff !important;
        border-color: #0f172a;
    }
    .cc-badge-status.status-unscheduled {
        background: rgba(148, 163, 184, 0.14);
        color: var(--muted);
        border: 1px solid rgba(148, 163, 184, 0.28);
    }
    .cc-card.cc-unscheduled-card {
        border-style: dashed;
        background: color-mix(in srgb, var(--panel-soft) 80%, transparent);
    }
    .cc-card.cc-unscheduled-card:hover {
        border-color: rgba(255, 255, 255, 0.25);
    }
    @media (max-width: 640px) {
        .class-search-box {
            max-width: 100%;
            min-width: 100%;
        }
    }
    </style>

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
        <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1>Classes</h1>
                <p>Browse gym classes, view upcoming sessions, and reserve your spot.</p>
            </div>
            <div class="class-search-box">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" id="classSearchInput" placeholder="Search class or instructor..." onkeyup="filterClasses()" autocomplete="off">
            </div>
        </div>

        <?php if (!empty($rows)): ?>
            <div class="class-filter-pills">
                <button type="button" class="class-pill-btn active" data-filter="all" onclick="filterBySchedule('all')">
                    All Classes (<?= $total ?>)
                </button>
                <button type="button" class="class-pill-btn" data-filter="scheduled" onclick="filterBySchedule('scheduled')">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Scheduled (<?= $totalScheduled ?>)
                </button>
                <button type="button" class="class-pill-btn" data-filter="unscheduled" onclick="filterBySchedule('unscheduled')">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Not Scheduled Yet (<?= $totalUnscheduled ?>)
                </button>
            </div>
        <?php endif; ?>

        <?php if (!$rows): ?>
            <div class="empty-state" style="margin-top: 24px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <p>No classes found for your home gym.<br>Check back soon or contact your gym.</p>
            </div>
        <?php else: ?>
            <div class="class-card-grid" style="margin-top: 20px;">
                <div id="noFilterResults" style="display:none; grid-column: 1/-1; text-align: center; padding: 48px 20px; color: var(--muted); background: var(--panel-soft); border-radius: 12px; border: 1px dashed var(--line);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 8px; opacity: 0.6;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <p style="margin: 0; font-size: 14px;">No classes match your selected filter or search.</p>
                </div>
                <?php
                $colors = ['#c7ff22', '#42dba5', '#ff9548', '#38bdf8', '#a78bfa', '#f472b6', '#facc15', '#2dd4bf'];
                $ci = 0;
                foreach ($rows as $row):
                    $isScheduled = ((int)($row['is_scheduled'] ?? 0) === 1);
                    $booked   = (int) ($row['booked'] ?? 0);
                    $capacity = (int) ($row['capacity'] ?? 0);
                    $pct      = ($capacity > 0 && $isScheduled) ? min(100, round($booked / $capacity * 100)) : 0;
                    $full     = $isScheduled && ($capacity > 0) && ($booked >= $capacity);
                    $barColor = $full ? '#ef4444' : ($pct >= 75 ? '#ff9548' : $colors[$ci % count($colors)]);
                    $isAlreadyBooked = ($row['is_booked'] ?? 0) > 0;

                    if ($isScheduled && !empty($row['start_datetime'])) {
                        $startDt  = new DateTime($row['start_datetime']);
                        $endDt    = !empty($row['end_datetime']) ? new DateTime($row['end_datetime']) : (clone $startDt)->modify('+45 minutes');
                        $durDiff  = $startDt->diff($endDt);
                        $durStr   = [];
                        if ($durDiff->h > 0) $durStr[] = $durDiff->h . ' hr';
                        if ($durDiff->i > 0) $durStr[] = $durDiff->i . ' min';
                        $durationFormatted = !empty($durStr) ? implode(' ', $durStr) : '45 min';
                        $isToday  = $startDt->format('Y-m-d') === date('Y-m-d');
                        $isTomorrow = $startDt->format('Y-m-d') === date('Y-m-d', strtotime('+1 day'));
                        $dateLabel = $isToday ? 'Today' : ($isTomorrow ? 'Tomorrow' : $startDt->format('M j'));
                        $timeVal = $startDt->format('g:i A');
                        $dateVal = $startDt->format('D, M j');
                        $roomLocation = !empty($row['room_location']) ? $row['room_location'] : 'Main Studio';
                    } else {
                        $durationFormatted = 'Check back soon';
                        $dateLabel = 'Unscheduled';
                        $timeVal = 'TBA';
                        $dateVal = 'Not Scheduled';
                        $roomLocation = 'To be announced';
                    }
                ?>
                    <div class="cc-card <?= $isAlreadyBooked ? 'cc-booked' : '' ?> <?= $full ? 'cc-is-full' : '' ?> <?= !$isScheduled ? 'cc-unscheduled-card' : '' ?>" style="--card-accent: <?= $colors[$ci % count($colors)] ?>;" data-class-name="<?= h(strtolower($row['class_name'])) ?>" data-instructor="<?= h(strtolower($row['instructor'])) ?>" data-scheduled="<?= $isScheduled ? '1' : '0' ?>">
                        <div class="cc-header">
                            <span class="cc-category-badge">
                                <span class="cc-category-dot"></span>
                                <?= h(strtoupper(substr($row['class_name'], 0, 12))) ?>
                            </span>
                            <?php if (!$isScheduled): ?>
                                <span class="cc-badge-status status-unscheduled" title="This class has no upcoming sessions scheduled yet">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    Not Scheduled Yet
                                </span>
                            <?php elseif ($isAlreadyBooked): ?>
                                <span class="cc-badge-status status-booked">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                    Booked
                                </span>
                            <?php elseif ($full): ?>
                                <span class="cc-badge-status status-full">Full</span>
                            <?php elseif ($capacity > 0 && ($capacity - $booked) <= 3): ?>
                                <span class="cc-badge-status status-limited"><?= ($capacity - $booked) ?> spots left</span>
                            <?php endif; ?>
                        </div>

                        <div>
                            <h3 class="cc-name"><?= h($row['class_name']) ?></h3>
                            <?php if (!empty($row['description'])): ?>
                                <p class="cc-desc"><?= h($row['description']) ?></p>
                            <?php else: ?>
                                <p class="cc-desc cc-desc-muted">Instructor-led group training session</p>
                            <?php endif; ?>
                        </div>

                        <div class="cc-meta-grid">
                            <div class="cc-meta-item" title="Time & Duration">
                                <div class="cc-meta-icon">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                </div>
                                <div class="cc-meta-info">
                                    <span class="cc-meta-val"><?= h($timeVal) ?></span>
                                    <span class="cc-meta-sub"><?= h($durationFormatted) ?></span>
                                </div>
                            </div>
                            
                            <div class="cc-meta-item" title="Session Date">
                                <div class="cc-meta-icon">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                </div>
                                <div class="cc-meta-info">
                                    <span class="cc-meta-val"><?= h($dateVal) ?></span>
                                    <span class="cc-meta-sub"><?= h($dateLabel) ?></span>
                                </div>
                            </div>

                            <div class="cc-meta-item" title="Location">
                                <div class="cc-meta-icon">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                </div>
                                <div class="cc-meta-info">
                                    <span class="cc-meta-val"><?= h($roomLocation) ?></span>
                                    <span class="cc-meta-sub">Location</span>
                                </div>
                            </div>

                            <div class="cc-meta-item" title="Instructor">
                                <div class="cc-meta-icon">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                </div>
                                <div class="cc-meta-info">
                                    <span class="cc-meta-val"><?= h($row['instructor']) ?></span>
                                    <span class="cc-meta-sub">Trainer</span>
                                </div>
                            </div>
                        </div>

                        <?php if ($isScheduled): ?>
                            <div class="cc-capacity">
                                <div class="cc-cap-header">
                                    <span>Availability</span>
                                    <span class="cc-cap-nums"><strong><?= $booked ?></strong> / <?= $capacity ?> booked</span>
                                </div>
                                <div class="cc-bar-track">
                                    <div class="cc-bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="cc-capacity">
                                <div class="cc-cap-header">
                                    <span>Class Capacity</span>
                                    <span class="cc-cap-nums">Max <strong><?= $capacity ?></strong> members</span>
                                </div>
                                <div class="cc-bar-track">
                                    <div class="cc-bar-fill" style="width:0%; background:transparent;"></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!$isScheduled): ?>
                            <!-- Guardrail: Class cannot be booked because it is not scheduled yet -->
                            <div class="cc-actions">
                                <button type="button" class="cc-btn cc-btn-disabled" disabled style="cursor: not-allowed; opacity: 0.7;" title="This class cannot be booked yet because it is not currently scheduled.">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                    <span>Not Scheduled Yet</span>
                                </button>
                            </div>
                        <?php else: ?>
                            <form method="post" class="cc-actions">
                                <?= csrf_field() ?>
                                <input type="hidden" name="schedule_id" value="<?= (int) $row['schedule_id'] ?>">
                                <?php if ($isAlreadyBooked): ?>
                                    <button type="submit" name="action" value="cancel" class="cc-btn cc-btn-cancel" data-confirm="Are you sure you want to cancel this booking?" data-confirm-btn="Yes, cancel">
                                        Cancel Booking
                                    </button>
                                <?php else: ?>
                                    <button type="submit" name="action" value="book" class="cc-btn cc-btn-book <?= $full ? 'cc-btn-disabled' : '' ?>" <?= $full ? 'disabled' : 'data-confirm="Are you sure you want to book this class?" data-confirm-btn="Yes, book"' ?>>
                                        <?php if ($full): ?>
                                            Class Full
                                        <?php else: ?>
                                            <span>Reserve Spot</span>
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                                        <?php endif; ?>
                                    </button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php $ci++; endforeach; ?>
            </div>
        <?php endif; ?>
        <?php render_pagination($page, $totalPages, '?page=book_classes'); ?>
    </section>
    <script>
    let currentScheduleFilter = 'all';

    function filterBySchedule(filter) {
        currentScheduleFilter = filter;
        document.querySelectorAll('.class-pill-btn').forEach(btn => {
            if (btn.getAttribute('data-filter') === filter) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
        filterClasses();
    }

    function filterClasses() {
        const q = (document.getElementById('classSearchInput').value || '').toLowerCase().trim();
        let visibleCount = 0;
        document.querySelectorAll('.cc-card').forEach(card => {
            const name = (card.getAttribute('data-class-name') || '');
            const instructor = (card.getAttribute('data-instructor') || '');
            const isScheduled = card.getAttribute('data-scheduled'); // '1' or '0'

            const matchesQuery = !q || name.includes(q) || instructor.includes(q);
            const matchesSchedule = (currentScheduleFilter === 'all')
                || (currentScheduleFilter === 'scheduled' && isScheduled === '1')
                || (currentScheduleFilter === 'unscheduled' && isScheduled === '0');

            if (matchesQuery && matchesSchedule) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        const noResults = document.getElementById('noFilterResults');
        if (noResults) {
            noResults.style.display = (visibleCount === 0) ? 'block' : 'none';
        }
    }
    </script>
    <?php
    render_footer();
}
