<?php
declare(strict_types=1);

function qr_attendance_page(): void
{
    $user = require_roles(['member', 'trainer']);
    auto_checkout_past_attendance((int) $user['user_id']);
    $initialToken = null;
    $initialSecondsRemaining = 0;

    if (!empty($user['qr_token']) && !empty($user['qr_expires_at'])) {
        $expiresAt = new DateTimeImmutable((string) $user['qr_expires_at']);
        $initialSecondsRemaining = max(0, $expiresAt->getTimestamp() - time());
        if ($initialSecondsRemaining > 0) {
            $initialToken = (string) $user['qr_token'];
        }
    }
    
    // Handle AJAX actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        if ($action === 'refresh_token') {
            $token = bin2hex(random_bytes(16));
            $expiresAt = (new DateTimeImmutable())->modify('+5 minutes');
            $expires = $expiresAt->format('Y-m-d H:i:s');
            db()->prepare('UPDATE users SET qr_token = ?, qr_expires_at = ? WHERE user_id = ?')->execute([$token, $expires, $user['user_id']]);
            header('Content-Type: application/json');
            echo json_encode([
                'token' => $token,
                'expires' => $expires,
                'seconds_remaining' => max(0, $expiresAt->getTimestamp() - time()),
            ]);
            exit;
        } elseif ($action === 'poll_status') {
            $tokenRaw = scalar('SELECT qr_token FROM users WHERE user_id = ?', [$user['user_id']]);
            // If token is null, it means the scanner just invalidated it
            if ($tokenRaw === null) {
                // Find latest attendance
                $row = db()->query('SELECT attendance_id, gym_id, check_in_time, check_out_time FROM attendance WHERE user_id = ' . (int)$user['user_id'] . ' ORDER BY attendance_id DESC LIMIT 1')->fetch();
                if ($row) {
                    $isCheckout = ($row['check_out_time'] !== null && strtotime($row['check_out_time']) >= strtotime($row['check_in_time']));
                    $gymId = (int)($row['gym_id'] ?? 0);
                    if (!$gymId) {
                        $gymId = (int) scalar('SELECT gym_id FROM gym_members WHERE user_id = ? LIMIT 1', [$user['user_id']]);
                    }
                    if (!$gymId && $user['role'] === 'trainer') {
                        $gymId = (int) scalar('SELECT gym_id FROM trainer_profiles WHERE user_id = ? LIMIT 1', [$user['user_id']]);
                    }

                    $equipmentCats = [];
                    $equipmentList = [];
                    if ($gymId > 0) {
                        $catStmt = db()->prepare('SELECT category, COUNT(*) as cnt FROM gym_equipment WHERE gym_id = ? AND status = "available" GROUP BY category');
                        $catStmt->execute([$gymId]);
                        $equipmentCats = $catStmt->fetchAll(PDO::FETCH_KEY_PAIR);

                        $eqStmt = db()->prepare('SELECT equipment_id, name, unit_number, category, location_area FROM gym_equipment WHERE gym_id = ? AND status = "available" ORDER BY category ASC, name ASC LIMIT 150');
                        $eqStmt->execute([$gymId]);
                        $equipmentList = $eqStmt->fetchAll(PDO::FETCH_ASSOC);
                    }

                    header('Content-Type: application/json');
                    echo json_encode([
                        'scanned' => true,
                        'type' => $isCheckout ? 'checkout' : 'checkin',
                        'attendance_id' => $row['attendance_id'],
                        'role' => $user['role'],
                        'gym_id' => $gymId,
                        'equipment_categories' => $equipmentCats,
                        'equipment_list' => $equipmentList
                    ]);
                    exit;
                }
            }
            header('Content-Type: application/json');
            echo json_encode(['scanned' => false]);
            exit;
        } elseif ($action === 'submit_rating') {
            $attendanceId = (int) post('attendance_id');
            $rating = (int) post('rating');
            $comment = mb_substr(trim((string) post('comment')), 0, 1000) ?: null;
            if ($rating >= 1 && $rating <= 5) {
                try {
                    db()->prepare('INSERT INTO checkout_ratings (attendance_id, user_id, rating, comment) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment)')
                       ->execute([$attendanceId, $user['user_id'], $rating, $comment]);
                } catch (Throwable) {}

                // Save to gym_ratings so QR checkout ratings contribute to the gym's overall score
                $gymId = (int) scalar('SELECT gym_id FROM attendance WHERE attendance_id = ?', [$attendanceId]);
                if (!$gymId) {
                    $gymId = (int) ($user['gym_id'] ?? 0);
                }
                if (!$gymId) {
                    $gymId = (int) scalar('SELECT gym_id FROM gym_members WHERE user_id = ? LIMIT 1', [$user['user_id']]);
                }
                if (!$gymId) {
                    $gymId = (int) scalar('SELECT mp.gym_id FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = ? AND m.status = "active" LIMIT 1', [$user['user_id']]);
                }
                if ($gymId > 0) {
                    save_gym_rating((int)$user['user_id'], $gymId, $rating, $comment);
                }
            }
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
    }

    // Check if user currently has an active, unclosed attendance today
    $activeAttendanceStmt = db()->prepare("
        SELECT a.attendance_id, a.gym_id, a.check_in_time, g.name as gym_name
        FROM attendance a
        LEFT JOIN gyms g ON g.gym_id = a.gym_id
        WHERE a.user_id = ?
          AND a.check_out_time IS NULL
          AND DATE(a.check_in_time) = CURDATE()
        ORDER BY a.attendance_id DESC
        LIMIT 1
    ");
    $activeAttendanceStmt->execute([(int)$user['user_id']]);
    $activeAttendance = $activeAttendanceStmt->fetch(PDO::FETCH_ASSOC);

    $activeEquipSession = null;
    $checkedInEquipmentCats = [];
    $checkedInEquipmentList = [];
    $shouldAutoRestoreModal = false;

    if ($activeAttendance) {
        $activeEquipStmt = db()->prepare("
            SELECT s.session_id, s.equipment_id, s.start_time, e.name as equipment_name, e.unit_number, e.category
            FROM equipment_sessions s
            JOIN gym_equipment e ON e.equipment_id = s.equipment_id
            WHERE s.user_id = ?
              AND s.session_status = 'active'
            LIMIT 1
        ");
        $activeEquipStmt->execute([(int)$user['user_id']]);
        $activeEquipSession = $activeEquipStmt->fetch(PDO::FETCH_ASSOC);

        $gymId = (int)$activeAttendance['gym_id'];
        if ($gymId > 0) {
            $catStmt = db()->prepare('SELECT category, COUNT(*) as cnt FROM gym_equipment WHERE gym_id = ? AND status = "available" GROUP BY category');
            $catStmt->execute([$gymId]);
            $checkedInEquipmentCats = $catStmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $eqStmt = db()->prepare('SELECT equipment_id, name, unit_number, category, location_area FROM gym_equipment WHERE gym_id = ? AND status = "available" ORDER BY category ASC, name ASC LIMIT 150');
            $eqStmt->execute([$gymId]);
            $checkedInEquipmentList = $eqStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Auto-restore modal if checked in within the last 5 minutes (300s) and hasn't started an equipment session yet
        $checkinTs = strtotime((string)$activeAttendance['check_in_time']);
        $elapsedSecs = time() - $checkinTs;
        if ($elapsedSecs >= 0 && $elapsedSecs < 300 && !$activeEquipSession) {
            $shouldAutoRestoreModal = true;
        }
    }

    // Fetch member's recent check-in/check-out history (last 10 visits)
    $historyStmt = db()->prepare("
        SELECT a.attendance_id, a.gym_id, a.check_in_time, a.check_out_time, g.name AS gym_name
        FROM attendance a
        LEFT JOIN gyms g ON g.gym_id = a.gym_id
        WHERE a.user_id = ?
        ORDER BY a.check_in_time DESC
        LIMIT 80
    ");
    $historyStmt->execute([(int)$user['user_id']]);
    $attendanceHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
    $totalVisits = (int) scalar('SELECT COUNT(*) FROM attendance WHERE user_id = ?', [(int)$user['user_id']]);

    render_header('My QR Code', $user);
    ?>
    <style>
        @keyframes pulseDot {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.6); opacity: 0.4; }
        }
        .hist-page-btn {
            background: var(--panel-soft);
            border: 1px solid var(--line);
            color: var(--ink);
            border-radius: 6px;
            min-width: 26px;
            height: 24px;
            padding: 0 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease;
        }
        .hist-page-btn:hover:not(:disabled) {
            border-color: var(--lime);
            color: var(--lime);
        }
        .hist-page-btn.active {
            background: var(--lime);
            border-color: var(--lime);
            color: #000;
            font-weight: 800;
        }
    </style>
    <div class="skeleton-wrapper">
        <section class="panel" style="text-align: center; display:flex; flex-direction:column; align-items:center; padding: 40px 20px;">
            <div class="sk sk-title" style="width:200px;margin-bottom:12px"></div>
            <div class="sk sk-text" style="width:240px;height:12px;margin-bottom:40px"></div>
            
            <div class="sk-qr" style="margin-bottom:24px"></div>
            
            <div class="sk sk-rect" style="width:200px;height:44px;border-radius:6px"></div>
        </section>
        <section class="panel" style="margin-top: 14px; padding: 14px 16px;">
            <div class="sk sk-title" style="width:180px; height:14px; margin-bottom:12px"></div>
            <div class="sk sk-rect" style="width:100%; height:36px; border-radius:8px; margin-bottom:6px"></div>
            <div class="sk sk-rect" style="width:100%; height:36px; border-radius:8px; margin-bottom:6px"></div>
            <div class="sk sk-rect" style="width:100%; height:36px; border-radius:8px"></div>
        </section>
    </div>

    <?php if ($activeAttendance): ?>
        <!-- ACTIVE GYM VISIT BANNER (COMPACT THEME-ADAPTIVE) -->
        <div class="panel skeleton-content sk-display-block" style="background: var(--panel); border: 1px solid color-mix(in srgb, var(--lime) 30%, var(--line)); border-radius: 12px; padding: 10px 13px; margin-bottom: 12px; text-align: left; box-shadow: 0 2px 10px rgba(0,0,0,0.04); position: relative; overflow: hidden;">
            <div style="position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--lime), #22c55e);"></div>
            <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:8px;">
                <div style="display:flex; align-items:center; gap:7px; min-width:0;">
                    <div style="position:relative; width:10px; height:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center;">
                        <span style="position:absolute; width:12px; height:12px; border-radius:50%; background:#22c55e; animation: pulseDot 2s cubic-bezier(0, 0, 0.2, 1) infinite;"></span>
                        <span style="position:relative; width:6px; height:6px; border-radius:50%; background:#22c55e;"></span>
                    </div>
                    <div style="min-width:0;">
                        <div style="font-size:9.5px; font-weight:800; text-transform:uppercase; letter-spacing:0.04em; color:var(--lime); line-height:1.2;">Currently Checked In</div>
                        <h2 style="font-size:15px; margin:0; color:var(--ink); font-weight:800; letter-spacing:-0.01em; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; line-height:1.2;"><?= h($activeAttendance['gym_name'] ?: 'Fitness Gym') ?></h2>
                    </div>
                </div>
                <div style="font-size:11px; font-weight:600; color:var(--muted); background:var(--panel-soft); padding:3px 8px; border-radius:12px; border:1px solid var(--line); display:inline-flex; align-items:center; gap:4px; white-space:nowrap; flex-shrink:0;">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span><?= date('g:i A', strtotime((string)$activeAttendance['check_in_time'])) ?> (<?= max(1, (int)round((time() - strtotime((string)$activeAttendance['check_in_time'])) / 60)) ?>m)</span>
                </div>
            </div>

            <?php if ($activeEquipSession): ?>
                <div style="background:var(--panel-soft); border:1px solid var(--line); border-radius:8px; padding:6px 10px; margin-bottom:8px; display:flex; align-items:center; justify-content:space-between; gap:8px;">
                    <div style="display:flex; align-items:center; gap:7px; min-width:0;">
                        <div style="width:24px; height:24px; border-radius:6px; background:color-mix(in srgb, var(--lime) 18%, transparent); color:var(--lime); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        </div>
                        <div style="min-width:0; overflow:hidden;">
                            <div style="font-size:9px; text-transform:uppercase; color:var(--muted); font-weight:700; line-height:1.1;">Active Session</div>
                            <strong style="font-size:12px; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block;"><?= h($activeEquipSession['equipment_name']) ?> <?= h($activeEquipSession['unit_number'] ? '#' . $activeEquipSession['unit_number'] : '') ?></strong>
                        </div>
                    </div>
                    <a href="index.php?page=equipment" class="btn" style="background:var(--lime); color:#000; font-size:11px; font-weight:800; padding:4px 9px; border-radius:6px; text-decoration:none; white-space:nowrap; flex-shrink:0;">
                        Manage &rarr;
                    </a>
                </div>
            <?php endif; ?>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap:6px;">
                <?php if (!$activeEquipSession && !empty($checkedInEquipmentList)): ?>
                    <button type="button" id="btn-reopen-equipment-modal" class="btn btn-primary" style="font-weight:700; font-size:11.5px; padding:6px 8px; display:inline-flex; align-items:center; justify-content:center; gap:5px; border-radius:7px; min-height:32px; line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        <span>Choose Equipment</span>
                    </button>
                <?php endif; ?>
                <a href="index.php?page=equipment" class="btn" style="background:var(--panel-soft); border:1px solid var(--line); color:var(--ink); font-weight:600; font-size:11.5px; padding:6px 8px; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:5px; border-radius:7px; min-height:32px; line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><path d="M6 4v16M18 4v16M2 8h4M2 16h4M18 8h4M18 16h4M6 12h12"/></svg>
                    <span>Floor &amp; Queue</span>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <section class="panel skeleton-content sk-display-block" style="text-align: center;">
        <div class="page-header" style="justify-content: center;">
            <div>
                <h1><?= $activeAttendance ? 'Check-Out QR Code' : 'Dynamic QR Code' ?></h1>
                <p><?= $activeAttendance ? 'Scan this at the front desk or turnstile when leaving to check out.' : 'Scan this at the front desk to check in.' ?></p>
            </div>
        </div>

        <!-- Initial prompt shown before any QR is generated -->
        <div id="qr-prompt" style="margin: 30px auto;">
            <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.5" style="margin-bottom: 16px;"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h.01M18 14h.01M14 18h.01M18 18h.01M14 21h.01M21 14h.01M21 18h.01M21 21h.01"/></svg>
            <p class="muted" style="margin-bottom: 20px;">
                <?= $activeAttendance 
                    ? 'Your check-out pass is ready to generate.<br>Click the button below when you are ready to scan out at the front desk.' 
                    : 'Your QR code is ready to be generated.<br>Click the button below when you arrive at the front desk.' ?>
            </p>
            <button type="button" class="btn btn-primary" onclick="generateAndShow()" id="generate-btn" style="font-size: 15px; padding: 12px 28px;">
                 <?= $activeAttendance ? 'Generate Check-Out QR Code' : 'Generate My QR Code' ?>
            </button>
        </div>

        <!-- QR display - hidden until generated or restored -->
        <div id="qr-display" style="display: none;">
            <div id="qr-container" style="margin: 20px auto; padding: 20px; background: white; display: inline-block; border-radius: 8px; position: relative;"></div>
            <p class="muted" id="qr-timer"></p>
            
            <div id="qr-manual-refresh" style="display: none; margin-top: 15px;">
                <button type="button" class="btn" style="background: var(--panel-soft); border: 1px solid var(--line); color: var(--muted); font-size: 13px; padding: 6px 16px;" onclick="manualRefresh()">
                    Regenerate (<span id="qr-refreshes-left">2</span> left)
                </button>
            </div>
            
            <div id="qr-expired" style="display: none; margin-top: 12px;">
                <p style="color: var(--danger); font-weight: 700; margin-bottom: 12px;">This QR code has expired</p>
                <button type="button" class="btn btn-primary" onclick="refreshQR()">Regenerate QR Code</button>
            </div>
        </div>
    </section>

    <!-- ATTENDANCE HISTORY SECTION -->
    <section class="panel skeleton-content sk-display-block" style="margin-top: 14px; text-align: left; padding: 14px 16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:10px; padding-bottom:8px; border-bottom:1px solid var(--line);">
            <div>
                <h3 style="font-size:14px; font-weight:800; color:var(--ink); margin:0; display:flex; align-items:center; gap:6px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color:var(--lime);"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Check-in &amp; Check-out History
                </h3>
                <div style="font-size:11px; color:var(--muted); margin-top:2px;">Recent visits, floor durations, and check-out logs</div>
            </div>
            <div style="font-size:11px; font-weight:700; color:var(--muted); background:var(--panel-soft); padding:3px 9px; border-radius:12px; border:1px solid var(--line);">
                <?= $totalVisits ?> <?= $totalVisits === 1 ? 'total visit' : 'total visits' ?>
            </div>
        </div>

        <?php if (empty($attendanceHistory)): ?>
            <div style="text-align:center; padding: 22px 12px; color:var(--muted);">
                <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom:6px; opacity:0.6;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <div style="font-size:13px; font-weight:700; color:var(--ink); margin-bottom:2px;">No Attendance History Yet</div>
                <div style="font-size:11.5px; max-width:280px; margin:0 auto; line-height:1.4;">Your visits, floor durations, and check-out times will be recorded here when you scan your QR pass at the gym.</div>
            </div>
        <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:6px;">
                <?php foreach ($attendanceHistory as $hist): 
                    $checkInTs = !empty($hist['check_in_time']) ? strtotime((string)$hist['check_in_time']) : null;
                    $checkOutTs = !empty($hist['check_out_time']) ? strtotime((string)$hist['check_out_time']) : null;
                    $isToday = $checkInTs && date('Y-m-d', $checkInTs) === date('Y-m-d');
                    $isYesterday = $checkInTs && date('Y-m-d', $checkInTs) === date('Y-m-d', strtotime('-1 day'));

                    if ($isToday) {
                        $dateStr = 'Today';
                    } elseif ($isYesterday) {
                        $dateStr = 'Yesterday';
                    } else {
                        $dateStr = $checkInTs ? date('M j, Y', $checkInTs) : '—';
                    }

                    $inTime = $checkInTs ? date('g:i A', $checkInTs) : '—';
                    $outTime = $checkOutTs ? date('g:i A', $checkOutTs) : null;

                    if ($checkOutTs && $checkInTs && $checkOutTs >= $checkInTs) {
                        $diff = $checkOutTs - $checkInTs;
                        $hrs = (int) floor($diff / 3600);
                        $mins = (int) round(($diff % 3600) / 60);
                        $duration = $hrs > 0 ? "{$hrs}h {$mins}m" : max(1, $mins) . "m";
                        $isLive = false;
                    } elseif (!$checkOutTs && $isToday) {
                        $diff = time() - $checkInTs;
                        $mins = max(1, (int) round($diff / 60));
                        $hrs = (int) floor($mins / 60);
                        $rem = $mins % 60;
                        $duration = ($hrs > 0 ? "{$hrs}h {$rem}m" : "{$mins}m");
                        $isLive = true;
                    } else {
                        $duration = 'Auto-closed';
                        $isLive = false;
                    }
                ?>
                    <div class="hist-row" style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:7px 10px; background:var(--panel-soft); border:1px solid var(--line); border-radius:8px; font-size:12px;">
                        <div style="min-width:0; flex:1;">
                            <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                <strong style="font-size:12px; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= h($hist['gym_name'] ?: 'Fitness Gym') ?></strong>
                                <span style="font-size:11px; color:var(--muted); font-weight:600;">• <?= $dateStr ?></span>
                            </div>
                            <div style="display:flex; align-items:center; gap:5px; font-size:11px; color:var(--muted); margin-top:2px;">
                                <span>In: <b style="color:var(--ink);"><?= $inTime ?></b></span>
                                <span>&rarr;</span>
                                <?php if ($outTime): ?>
                                    <span>Out: <b style="color:var(--ink);"><?= $outTime ?></b></span>
                                <?php elseif ($isLive): ?>
                                    <span style="color:#22c55e; font-weight:700;">On floor</span>
                                <?php else: ?>
                                    <span style="color:var(--muted);">Closed</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="text-align:right; flex-shrink:0;">
                            <?php if ($isLive): ?>
                                <span style="display:inline-flex; align-items:center; gap:4px; font-size:10.5px; font-weight:800; color:#22c55e; background:rgba(34,197,94,0.12); padding:2px 7px; border-radius:6px; border:1px solid rgba(34,197,94,0.3);">
                                    <span style="width:5px; height:5px; border-radius:50%; background:#22c55e; animation:pulseDot 2s infinite;"></span>
                                    <?= $duration ?>
                                </span>
                            <?php else: ?>
                                <span style="font-size:11px; font-weight:700; color:var(--lime); background:color-mix(in srgb, var(--lime) 15%, transparent); padding:2px 7px; border-radius:6px; display:inline-block;">
                                    <?= $duration ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- HISTORY PAGINATION BAR (4 items per page) -->
            <div id="hist-pagination-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-top:8px; padding-top:8px; border-top:1px solid var(--line); font-size:11px;">
                <div id="hist-pagination-info" style="color:var(--muted); font-weight:600;">
                    Showing <strong style="color:var(--ink);">1–<?= min(4, count($attendanceHistory)) ?></strong> of <strong style="color:var(--ink);"><?= count($attendanceHistory) ?></strong>
                </div>
                <div id="hist-pagination-nav" style="display:flex; align-items:center; gap:4px;"></div>
            </div>
        <?php endif; ?>
    </section>

    <!-- Load a browser-compatible QR code library -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
    <script>
    const userId = <?= json_encode((string) $user['user_id']) ?>;
    const initialToken = <?= json_encode($initialToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const initialSecondsRemaining = <?= (int) $initialSecondsRemaining ?>;
    const csrfToken = <?= json_encode(csrf_token()) ?>;
    const activeCheckinData = <?= json_encode($activeAttendance ? [
        'role' => $user['role'],
        'gym_id' => (int)($activeAttendance['gym_id'] ?? 0),
        'attendance_id' => (int)($activeAttendance['attendance_id'] ?? 0),
        'equipment_categories' => $checkedInEquipmentCats,
        'equipment_list' => $checkedInEquipmentList
    ] : null) ?>;
    const shouldAutoRestore = <?= $shouldAutoRestoreModal ? 'true' : 'false' ?>;

    function generateQR(text) {
        const container = document.getElementById('qr-container');
        container.innerHTML = '';
        container.style.opacity = '1';

        const qr = qrcode(0, 'M'); // Error correction level M (15%) - produces cleaner, less dense QR codes
        qr.addData(text);
        qr.make();

        // Use built-in function to create a highly compatible img element (cellSize=8, margin=4)
        container.innerHTML = qr.createImgTag(8, 4);
        
        // Ensure the generated image is responsive
        const img = container.querySelector('img');
        if (img) {
            img.style.maxWidth = '100%';
            img.style.height = 'auto';
            img.style.display = 'block';
            img.style.margin = '0 auto';
        }
    }

    let manualRegeneratesLeft = 2;

    function showQR(token, secondsRemaining) {
        document.getElementById('qr-prompt').style.display = 'none';
        document.getElementById('qr-display').style.display = 'block';
        document.getElementById('qr-expired').style.display = 'none';
        document.getElementById('qr-timer').style.display = 'block';
        
        if (manualRegeneratesLeft > 0) {
            document.getElementById('qr-manual-refresh').style.display = 'block';
            document.getElementById('qr-refreshes-left').textContent = manualRegeneratesLeft;
        } else {
            document.getElementById('qr-manual-refresh').style.display = 'none';
        }

        generateQR(userId + ':' + token);
        startTimer(secondsRemaining);
    }

    function generateAndShow() {
        refreshQR();
    }

    function startTimer(secondsRemaining) {
        const timerEl = document.getElementById('qr-timer');
        const expiredEl = document.getElementById('qr-expired');
        let timeLeft = Math.max(0, Number(secondsRemaining) || 0);

        clearInterval(window.qrInterval);
        clearInterval(window.qrPollInterval);

        function renderTimer() {
            if (timeLeft <= 0) {
                clearInterval(window.qrInterval);
                clearInterval(window.qrPollInterval);
                document.getElementById('qr-container').style.opacity = '0.25';
                timerEl.style.display = 'none';
                document.getElementById('qr-manual-refresh').style.display = 'none';
                expiredEl.style.display = 'block';
                return;
            }

            const mins = Math.floor(timeLeft / 60);
            const secs = timeLeft % 60;
            timerEl.textContent = 'Expires in ' + mins + ':' + String(secs).padStart(2, '0') + '...';
        }

        renderTimer();
        window.qrInterval = setInterval(() => {
            timeLeft--;
            renderTimer();
        }, 1000);

        // Poll server to check if admin scanned the QR
        window.qrPollInterval = setInterval(pollScanStatus, 3000);
    }
    
    function pollScanStatus() {
        fetch('index.php?page=qr_attendance', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=poll_status&csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(r => r.json())
        .then(data => {
            if (data.scanned) {
                clearInterval(window.qrInterval);
                clearInterval(window.qrPollInterval);
                document.getElementById('qr-display').style.display = 'none';
                
                if (data.type === 'checkout') {
                    if (data.attendance_id) {
                        sessionStorage.removeItem('quick_claim_dismissed_' + data.attendance_id);
                    }
                    promptRating(data.attendance_id);
                } else {
                    promptEquipmentUsage(data);
                }
            }
        }).catch(() => {});
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }

    function promptEquipmentUsage(data) {
        const isTrainer = (data.role === 'trainer');
        const categories = data.equipment_categories || {};
        const catKeys = Object.keys(categories);
        const equipList = Array.isArray(data.equipment_list) ? data.equipment_list : [];

        let catChipsHtml = '';
        if (catKeys.length > 0) {
            if (isTrainer) {
                catChipsHtml = '<div style="display:flex; gap:6px; overflow-x:auto; -webkit-overflow-scrolling:touch; padding:2px 2px 6px; margin:4px 0 8px; scrollbar-width:none;">' +
                    catKeys.map(cat => {
                        const count = categories[cat];
                        return `<a href="index.php?page=equipment&category=${encodeURIComponent(cat)}" style="background:var(--panel-soft); border:1px solid var(--line); color:var(--lime); padding:4px 9px; border-radius:14px; font-size:11px; text-decoration:none; font-weight:700; display:inline-flex; align-items:center; gap:4px; white-space:nowrap; flex-shrink:0;">
                            <span>${escapeHtml(cat)}</span>
                            <span style="background:var(--lime); color:#000; border-radius:10px; padding:1px 5px; font-size:9.5px; font-weight:800;">${count}</span>
                        </a>`;
                    }).join('') +
                '</div>';
            } else {
                catChipsHtml = '<div class="swal-cat-chips-scroll" style="display:flex; gap:6px; overflow-x:auto; -webkit-overflow-scrolling:touch; padding:2px 2px 6px; margin:4px 0 8px; scrollbar-width:none;">' +
                    `<button type="button" class="quick-cat-filter active" data-cat="" style="background:var(--lime); border:1px solid var(--lime); color:#000; padding:4px 10px; border-radius:14px; font-size:11px; font-weight:800; display:inline-flex; align-items:center; gap:5px; cursor:pointer; white-space:nowrap; flex-shrink:0;">
                        <span>All</span>
                        <span class="cat-badge" style="background:#000; color:var(--lime); border-radius:10px; padding:1px 5px; font-size:9.5px; font-weight:800;">${equipList.length}</span>
                    </button>` +
                    catKeys.map(cat => {
                        const count = categories[cat];
                        return `<button type="button" class="quick-cat-filter" data-cat="${escapeHtml(cat)}" style="background:var(--panel-soft); border:1px solid var(--line); color:var(--ink); padding:4px 10px; border-radius:14px; font-size:11px; font-weight:600; display:inline-flex; align-items:center; gap:5px; cursor:pointer; white-space:nowrap; flex-shrink:0;">
                            <span>${escapeHtml(cat)}</span>
                            <span class="cat-badge" style="background:color-mix(in srgb, var(--lime) 20%, transparent); color:var(--lime); border-radius:10px; padding:1px 5px; font-size:9.5px; font-weight:800;">${count}</span>
                        </button>`;
                    }).join('') +
                '</div>';
            }
        } else {
            catChipsHtml = '<p style="color:var(--muted); font-size:12px; margin:6px 0;">All gym stations and free zones are ready for your session.</p>';
        }

        let quickSelectHtml = '';
        if (equipList.length > 0 && !isTrainer) {
            quickSelectHtml = `
                <div style="background: color-mix(in srgb, var(--panel-soft) 40%, var(--panel)); border: 1px solid var(--line); border-radius: 10px; padding: 9px 11px; margin: 6px 0; text-align: left;">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom: 5px;">
                        <span style="font-size: 10.5px; font-weight: 800; color: var(--lime); text-transform: uppercase; letter-spacing: 0.04em;">Quick-Claim Machine</span>
                        <span id="quick-equip-live-count" style="font-size: 10.5px; color: var(--muted); font-weight: 600;">${equipList.length} available</span>
                    </div>

                    <div style="position:relative; margin-bottom: 6px;">
                        <input type="text" id="quick-search-equip" placeholder="Type to filter (e.g. Bench, Treadmill)..." style="width:100%; box-sizing:border-box; padding:6px 26px 6px 26px; border-radius:7px; background:var(--panel); border:1px solid var(--line); color:var(--ink); font-size:11.5px; outline:none;" autocomplete="off">
                        <svg style="position:absolute; left:8px; top:50%; transform:translateY(-50%); color:var(--muted); pointer-events:none;" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <button type="button" id="quick-search-clear" style="display:none; position:absolute; right:7px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--muted); cursor:pointer; font-size:11px; line-height:1; padding:2px;">✕</button>
                    </div>

                    <div style="display: flex; gap: 6px;">
                        <select id="quick-checkin-equip-id" style="flex: 1; min-width: 0; padding: 6px 8px; border-radius: 7px; background: var(--panel); border: 1px solid var(--line); color: var(--ink); font-size: 11.5px; outline: none; cursor: pointer;">
                        </select>
                        <button type="button" id="btn-quick-claim" style="padding: 6px 12px; border-radius: 7px; background: var(--lime); color: #000; font-weight: 800; font-size: 11.5px; border: none; cursor: pointer; white-space: nowrap;">
                            Claim &amp; Start
                        </button>
                    </div>
                </div>
            `;
        }

        const titleHtml = `
            <div style="display:flex; align-items:center; justify-content:center; gap:7px; font-size:16px; font-weight:800; color:var(--ink); margin-bottom:2px;">
                <span style="display:inline-flex; width:22px; height:22px; border-radius:50%; background:color-mix(in srgb, var(--lime) 20%, transparent); color:var(--lime); align-items:center; justify-content:center;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                </span>
                <span>Checked In!</span>
            </div>
        `;

        const descText = isTrainer
            ? "Select your training equipment and circuit areas for today's clients:"
            : "Floor access unlocked. Quick-claim your machine or browse zones:";

        const primaryBtnText = isTrainer ? "Floor Availability" : "Floor Equipment";
        const primaryBtnUrl = "index.php?page=equipment";
        const secondaryBtnText = isTrainer ? "Training Plans" : "My Workout";
        const secondaryBtnUrl = isTrainer ? "index.php?page=training" : "index.php?page=my_workout";

        Swal.fire({
            title: titleHtml,
            html: `
                <p style="color:var(--muted); font-size:12px; line-height:1.35; margin:2px 0 6px;">${descText}</p>
                ${quickSelectHtml}
                <div style="font-size:10.5px; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em; font-weight:800; margin:6px 0 3px; text-align:left;">Filter by Zone</div>
                ${catChipsHtml}
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:7px; margin-top:8px;">
                    <a href="${primaryBtnUrl}" style="display:flex; align-items:center; justify-content:center; gap:5px; text-decoration:none; padding:8px 10px; border-radius:8px; background:var(--lime); color:#000; font-weight:800; font-size:11.5px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        ${primaryBtnText}
                    </a>
                    <a href="${secondaryBtnUrl}" style="display:flex; align-items:center; justify-content:center; gap:5px; text-decoration:none; padding:8px 10px; border-radius:8px; background:var(--panel-soft); border:1px solid var(--line); color:var(--ink); font-weight:700; font-size:11.5px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                        ${secondaryBtnText}
                    </a>
                </div>
            `,
            showConfirmButton: false,
            showCancelButton: true,
            cancelButtonText: 'Dismiss',
            allowOutsideClick: false,
            allowEscapeKey: false,
            background: 'var(--panel)',
            color: 'var(--ink)',
            width: '92%',
            maxWidth: '400px',
            padding: '14px 12px',
            didOpen: () => {
                const rawEquipList = Array.isArray(data.equipment_list) ? data.equipment_list : [];
                const searchInput = document.getElementById('quick-search-equip');
                const searchClear = document.getElementById('quick-search-clear');
                const equipSel = document.getElementById('quick-checkin-equip-id');
                const liveCount = document.getElementById('quick-equip-live-count');
                const catChips = document.querySelectorAll('.quick-cat-filter');
                const claimBtn = document.getElementById('btn-quick-claim');

                let activeCategory = '';

                function updateOptions() {
                    if (!equipSel) return;
                    const q = (searchInput ? searchInput.value : '').toLowerCase().trim();

                    const filtered = rawEquipList.filter(eq => {
                        const matchesCat = !activeCategory || eq.category === activeCategory;
                        const matchesSearch = !q ||
                            (eq.name && eq.name.toLowerCase().includes(q)) ||
                            (eq.unit_number && String(eq.unit_number).toLowerCase().includes(q)) ||
                            (eq.category && eq.category.toLowerCase().includes(q)) ||
                            (eq.location_area && eq.location_area.toLowerCase().includes(q));
                        return matchesCat && matchesSearch;
                    });

                    // Group filtered items by category
                    const byCat = {};
                    filtered.forEach(eq => {
                        const cat = eq.category || 'General';
                        if (!byCat[cat]) byCat[cat] = [];
                        byCat[cat].push(eq);
                    });

                    let optsHtml = '';
                    if (filtered.length === 0) {
                        optsHtml = `<option value="" disabled selected>No available machines matching ${q ? '"' + escapeHtml(q) + '"' : 'filter'}</option>`;
                    } else {
                        const defaultPrompt = filtered.length === 1
                            ? '-- Ready to claim (1 match) --'
                            : `-- Choose an available machine (${filtered.length}) --`;
                        optsHtml = `<option value="">${defaultPrompt}</option>`;

                        for (const [catName, items] of Object.entries(byCat)) {
                            optsHtml += `<optgroup label="${escapeHtml(catName)} (${items.length})" style="background:var(--panel); color:var(--lime); font-weight:700;">`;
                            items.forEach(eq => {
                                const unitStr = eq.unit_number ? ` #${escapeHtml(eq.unit_number)}` : '';
                                optsHtml += `<option value="${eq.equipment_id}" style="background:var(--panel); color:var(--ink); font-weight:400;">${escapeHtml(eq.name)}${unitStr} • ${escapeHtml(eq.category)}</option>`;
                            });
                            optsHtml += `</optgroup>`;
                        }
                    }

                    equipSel.innerHTML = optsHtml;

                    if (filtered.length === 1) {
                        equipSel.value = filtered[0].equipment_id;
                    }

                    if (liveCount) {
                        if (q || activeCategory) {
                            liveCount.textContent = `${filtered.length} of ${rawEquipList.length} available`;
                        } else {
                            liveCount.textContent = `${rawEquipList.length} available`;
                        }
                    }

                    if (searchClear) {
                        searchClear.style.display = q ? 'block' : 'none';
                    }
                }

                // Attach category filter click events
                if (catChips.length > 0) {
                    catChips.forEach(chip => {
                        chip.addEventListener('click', () => {
                            activeCategory = chip.getAttribute('data-cat') || '';
                            catChips.forEach(c => {
                                const isActive = (c.getAttribute('data-cat') || '') === activeCategory;
                                c.style.background = isActive ? 'var(--lime)' : 'var(--panel-soft)';
                                c.style.borderColor = isActive ? 'var(--lime)' : 'var(--line)';
                                c.style.color = isActive ? '#000' : 'var(--ink)';
                                c.style.fontWeight = isActive ? '700' : '600';
                                const badge = c.querySelector('.cat-badge');
                                if (badge) {
                                    badge.style.background = isActive ? '#000' : 'color-mix(in srgb, var(--lime) 20%, transparent)';
                                    badge.style.color = isActive ? 'var(--lime)' : 'var(--lime)';
                                }
                            });
                            updateOptions();
                        });
                    });
                }

                // Attach search listeners
                if (searchInput) {
                    searchInput.addEventListener('input', updateOptions);
                    searchInput.addEventListener('focus', () => {
                        searchInput.style.borderColor = 'var(--lime)';
                    });
                    searchInput.addEventListener('blur', () => {
                        searchInput.style.borderColor = 'var(--line)';
                    });
                }

                if (searchClear) {
                    searchClear.addEventListener('click', () => {
                        if (searchInput) {
                            searchInput.value = '';
                            searchInput.focus();
                        }
                        updateOptions();
                    });
                }

                // Compact Dismiss button styling
                const actions = Swal.getActions();
                if (actions) {
                    actions.style.marginTop = '8px';
                    actions.style.gap = '8px';
                }
                const cancelBtn = Swal.getCancelButton();
                if (cancelBtn) {
                    cancelBtn.style.padding = '6px 16px';
                    cancelBtn.style.fontSize = '12px';
                    cancelBtn.style.fontWeight = '600';
                    cancelBtn.style.borderRadius = '8px';
                    cancelBtn.style.background = 'var(--panel-soft)';
                    cancelBtn.style.border = '1px solid var(--line)';
                    cancelBtn.style.color = 'var(--muted)';
                    cancelBtn.style.boxShadow = 'none';
                }

                // Initial populate
                updateOptions();

                if (claimBtn) {
                    claimBtn.addEventListener('click', async () => {
                        const equipId = equipSel ? equipSel.value : null;
                        if (!equipId) {
                            Swal.fire({
                                icon: 'info',
                                title: 'Select a Machine',
                                text: 'Please choose an available machine from the dropdown or search for one.',
                                background: 'var(--panel)',
                                color: 'var(--ink)',
                                confirmButtonColor: 'var(--lime)'
                            });
                            return;
                        }
                        claimBtn.disabled = true;
                        claimBtn.textContent = 'Claiming...';
                        try {
                            const fd = new FormData();
                            fd.append('equipment_id', equipId);
                            fd.append('csrf_token', csrfToken);
                            fd.append('gym_id', data.gym_id);
                            const res = await fetch(`index.php?page=equipment_api&action=start_session&gym_id=${data.gym_id}`, {
                                method: 'POST',
                                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                                body: fd
                            });
                            const claimData = await res.json();
                            if (claimData.success) {
                                window.location.href = 'index.php?page=equipment';
                            } else {
                                claimBtn.disabled = false;
                                claimBtn.textContent = 'Claim & Start';
                                Swal.fire({
                                    icon: 'warning',
                                    title: 'Cannot Claim Machine',
                                    text: claimData.message || 'Equipment could not be started.',
                                    background: 'var(--panel)',
                                    color: 'var(--ink)',
                                    confirmButtonColor: 'var(--lime)'
                                });
                            }
                        } catch (e) {
                            console.error(e);
                            window.location.href = 'index.php?page=equipment';
                        }
                    });
                }
            }
        }).then((result) => {
            if (result.dismiss === Swal.DismissReason.cancel || result.dismiss === Swal.DismissReason.backdrop || result.dismiss === Swal.DismissReason.close) {
                if (data && data.attendance_id) {
                    sessionStorage.setItem('quick_claim_dismissed_' + data.attendance_id, '1');
                }
            }
        });
    }

    function promptRating(attendanceId) {
        let selectedRating = 0;
        const isLight = document.documentElement.getAttribute('data-theme') === 'light' || document.body.getAttribute('data-theme') === 'light';
        const emptyColor = isLight ? '#cbd5e1' : '#475569';
        const activeColor = '#f59e0b';

        let starHtml = '<div id="swal-star-row">'
            + [1,2,3,4,5].map(v => '<span class="co-star" data-val="' + v + '">★</span>').join('')
            + '</div>';

        Swal.fire({
            title: 'Rate Your Gym Experience',
            html: '<p class="checkout-modal-desc">How was your workout session? Leave a 1–5 star rating and optional review for your gym on check-out.</p>'
                + starHtml
                + '<textarea id="swal-comment" placeholder="Write an optional review (equipment, cleanliness, trainers, overall experience)..." rows="2"></textarea>',
            showCancelButton: true,
            showDenyButton: false,
            allowOutsideClick: false,
            allowEscapeKey: false,
            confirmButtonText: 'Submit Review',
            cancelButtonText: 'Skip',
            customClass: {
                popup: 'swal-checkout-modal',
                confirmButton: 'swal-checkout-confirm-btn',
                cancelButton: 'swal-skip-btn'
            },
            didOpen: () => {
                const stars = document.querySelectorAll('.co-star');
                const updateStars = (val) => {
                    stars.forEach((s, i) => {
                        const isFilled = i < val;
                        s.style.color = isFilled ? activeColor : emptyColor;
                        s.style.transform = isFilled ? 'scale(1.15)' : 'scale(1)';
                        s.style.textShadow = isFilled ? '0 0 12px rgba(245, 158, 11, 0.45)' : 'none';
                    });
                };
                stars.forEach(star => {
                    star.addEventListener('mouseover', () => {
                        let val = parseInt(star.dataset.val, 10);
                        updateStars(val);
                    });
                    star.addEventListener('mouseout', () => {
                        updateStars(selectedRating);
                    });
                    star.addEventListener('click', () => {
                        selectedRating = parseInt(star.dataset.val, 10);
                        updateStars(selectedRating);
                    });
                });
            },
            preConfirm: () => {
                if (selectedRating > 0) {
                    let comment = document.getElementById('swal-comment').value || '';
                    return fetch('index.php?page=qr_attendance', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=submit_rating&attendance_id=${attendanceId}&rating=${selectedRating}&comment=${encodeURIComponent(comment)}&csrf_token=${encodeURIComponent(csrfToken)}`
                    });
                }
            }
        }).then(() => {
            Swal.fire({
                icon: 'success',
                title: selectedRating > 0 ? 'Review Submitted & Checked Out!' : 'Checked Out!',
                text: selectedRating > 0 ? 'Thank you for rating your gym. See you next time!' : 'See you next time.',
                background: 'var(--surface-color, #090b10)',
                color: 'var(--ink, #ffffff)',
                confirmButtonColor: 'var(--lime, #c7ff22)'
            });
        });
    }
    
    function manualRefresh() {
        if (manualRegeneratesLeft <= 0) return;
        manualRegeneratesLeft--;
        refreshQR();
    }

    function refreshQR() {
        const timerEl = document.getElementById('qr-timer');
        const expiredEl = document.getElementById('qr-expired');
        document.getElementById('qr-prompt').style.display = 'none';
        document.getElementById('qr-display').style.display = 'block';
        timerEl.style.display = 'block';
        timerEl.textContent = 'Generating...';
        expiredEl.style.display = 'none';

        fetch('index.php?page=qr_attendance', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=refresh_token&csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(r => r.json())
        .then(data => {
            showQR(data.token, data.seconds_remaining || 300);
        })
        .catch(err => console.error('QR refresh error:', err));
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (initialToken && initialSecondsRemaining > 0) {
            showQR(initialToken, initialSecondsRemaining);
        }

        // Auto-restore equipment claim modal if refreshed within 5 minutes of checkin and not dismissed
        if (shouldAutoRestore && activeCheckinData && activeCheckinData.attendance_id) {
            const dismissedKey = 'quick_claim_dismissed_' + activeCheckinData.attendance_id;
            if (!sessionStorage.getItem(dismissedKey)) {
                setTimeout(() => {
                    promptEquipmentUsage(activeCheckinData);
                }, 350);
            }
        }

        // Re-open equipment claim modal button handler
        const reopenBtn = document.getElementById('btn-reopen-equipment-modal');
        if (reopenBtn && activeCheckinData) {
            reopenBtn.addEventListener('click', () => {
                promptEquipmentUsage(activeCheckinData);
            });
        }

        // Attendance history client-side pagination (4 items per page)
        (function initHistPagination() {
            const rows = document.querySelectorAll('.hist-row');
            const itemsPerPage = 4;
            let currentPage = 1;

            if (!rows || rows.length === 0) return;

            function showPage(page) {
                const total = rows.length;
                const totalPages = Math.ceil(total / itemsPerPage) || 1;
                if (page < 1) page = 1;
                if (page > totalPages) page = totalPages;
                currentPage = page;

                const start = (page - 1) * itemsPerPage;
                const end = Math.min(start + itemsPerPage, total);

                rows.forEach((r, idx) => {
                    r.style.display = (idx >= start && idx < end) ? 'flex' : 'none';
                });

                const info = document.getElementById('hist-pagination-info');
                if (info) {
                    info.innerHTML = `Showing <strong style="color:var(--ink);">${start + 1}–${end}</strong> of <strong style="color:var(--ink);">${total}</strong>`;
                }

                const bar = document.getElementById('hist-pagination-bar');
                const nav = document.getElementById('hist-pagination-nav');
                if (bar) {
                    bar.style.display = total > 0 ? 'flex' : 'none';
                }
                if (!nav) return;

                let html = '';
                // Prev button
                if (currentPage > 1) {
                    html += `<button type="button" class="hist-page-btn" data-page="${currentPage - 1}" title="Previous Page">&lsaquo;</button>`;
                } else {
                    html += `<button type="button" class="hist-page-btn" disabled style="opacity:0.35; cursor:not-allowed;">&lsaquo;</button>`;
                }

                // Page numbers
                for (let p = 1; p <= totalPages; p++) {
                    if (totalPages <= 6 || p === 1 || p === totalPages || (p >= currentPage - 1 && p <= currentPage + 1)) {
                        html += `<button type="button" class="hist-page-btn ${p === currentPage ? 'active' : ''}" data-page="${p}">${p}</button>`;
                    } else if (p === currentPage - 2 || p === currentPage + 2) {
                        html += `<span style="color:var(--muted); padding:0 2px; font-size:10px;">…</span>`;
                    }
                }

                // Next button
                if (currentPage < totalPages) {
                    html += `<button type="button" class="hist-page-btn" data-page="${currentPage + 1}" title="Next Page">&rsaquo;</button>`;
                } else {
                    html += `<button type="button" class="hist-page-btn" disabled style="opacity:0.35; cursor:not-allowed;">&rsaquo;</button>`;
                }

                nav.innerHTML = html;
                nav.querySelectorAll('.hist-page-btn[data-page]').forEach(b => {
                    b.addEventListener('click', () => {
                        const target = parseInt(b.getAttribute('data-page'), 10);
                        showPage(target);
                    });
                });
            }

            showPage(1);
        })();
    });
    </script>
    <?php
    render_footer();
}
