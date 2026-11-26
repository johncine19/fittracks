<?php
declare(strict_types=1);

function trainers_page(): void
{
    $user = require_roles(['member']);
    
    $isGymMember = db()->prepare('SELECT 1 FROM gym_members WHERE user_id = ?');
    $isGymMember->execute([$user['user_id']]);
    if (!$isGymMember->fetchColumn()) {
        flash('Please select a gym first to view this page.', 'warning');
        redirect('gym_selection');
    }
    
    $currentGym = get_user_gym($user);
    $gymId = $currentGym ? (int) $currentGym['gym_id'] : 0;
    
    $ptPackages = $gymId > 0
        ? query_all(
            'SELECT package_id, trainer_id, package_name, package_type, session_count, duration_days, price, description
             FROM trainer_packages
             WHERE gym_id = ? AND is_active = 1
             ORDER BY price ASC, package_id ASC',
            [$gymId]
        )
        : [];
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'request_appointment') {
        $trainerId = (int) post('trainer_id');
        $trainerRow = query_all(
            'SELECT trainer_id, user_id, session_rate FROM trainer_profiles WHERE trainer_id = ? AND gym_id = ? LIMIT 1',
            [$trainerId, $gymId]
        )[0] ?? null;

        if (!$trainerRow) {
            flash('Selected trainer was not found for this gym.', 'danger');
            redirect('trainers');
        }

        $aptType = post('appointment_type', 'single');
        if (!in_array($aptType, ['single', 'range', 'package'], true)) {
            $aptType = 'single';
        }
        $packageId = (int) post('package_id', 0);
        $selectedPkg = null;

        if ($aptType === 'package' || $packageId > 0) {
            $selectedPkg = query_all(
                'SELECT * FROM trainer_packages WHERE package_id = ? AND gym_id = ? AND is_active = 1 AND (trainer_id IS NULL OR trainer_id = ?) LIMIT 1',
                [$packageId, $gymId, $trainerId]
            )[0] ?? null;
            if (!$selectedPkg) {
                flash('Please select a valid Personal Training package.', 'danger');
                redirect('trainers');
            }
            $aptType = 'package';
        }

        $reqDate = post('appointment_date');
        if (!$reqDate) {
            flash('Please select an appointment date and time.', 'danger');
            redirect('trainers');
        }
        $assignedDate = date('Y-m-d H:i:s', strtotime($reqDate));
        $endedDate = null;

        if ($selectedPkg) {
            $durationDays = (int) ($selectedPkg['duration_days'] ?? 0);
            if ($durationDays > 0) {
                $endedDate = date('Y-m-d 23:59:59', strtotime($reqDate . ' +' . $durationDays . ' days'));
            }
            $fee = max(0.0, (float) $selectedPkg['price']);
            $paymentType = 'pt_package';
            $sessionsPurchased = $selectedPkg['package_type'] === 'monthly_coaching'
                ? max(1, (int) ($selectedPkg['session_count'] ?: 30))
                : max(1, (int) ($selectedPkg['session_count'] ?: 1));
            $activityTitle = (string) $selectedPkg['package_name'];
            $paymentNotes = 'PT Package: ' . $selectedPkg['package_name'];
        } elseif ($aptType === 'range') {
            $reqEndDate = post('appointment_end_date');
            if (!$reqEndDate) {
                $endedDate = date('Y-m-d 23:59:59', strtotime($reqDate));
            } else {
                $startTime = strtotime($reqDate);
                $endTime = strtotime($reqEndDate);
                if ($endTime < strtotime(date('Y-m-d', $startTime))) {
                    flash('End date cannot be earlier than start date.', 'danger');
                    redirect('trainers');
                }
                $endedDate = date('Y-m-d 23:59:59', $endTime);
            }
            $fee = max(0.0, (float) ($trainerRow['session_rate'] ?? 0));
            $paymentType = 'pt_session';
            $sessionsPurchased = 1;
            $activityTitle = null;
            $paymentNotes = 'Per-session PT appointment (Date Range)';
            $packageId = 0;
        } else {
            // Specific Day Only: session automatically ends at 23:59:59 of that day
            $endedDate = date('Y-m-d 23:59:59', strtotime($reqDate));
            $fee = max(0.0, (float) ($trainerRow['session_rate'] ?? 0));
            $paymentType = 'pt_session';
            $sessionsPurchased = 1;
            $activityTitle = null;
            $paymentNotes = 'Per-session PT appointment';
            $packageId = 0;
        }
        
        // Check if there is already an active or pending assignment for this facility
        $existing = scalar("
            SELECT ca.assignment_id 
            FROM trainer_assignments ca 
            JOIN trainer_profiles tp ON tp.trainer_id = ca.trainer_id 
            WHERE ca.member_user_id = ? " . ($gymId > 0 ? " AND tp.gym_id = ?" : "") . " 
              AND ca.status IN ('active', 'pending_admin', 'pending_trainer')
        ", $gymId > 0 ? [$user['user_id'], $gymId] : [$user['user_id']]);
        
        if ($existing) {
            flash('You already have an active or pending trainer appointment for this facility. You must end it before requesting a new one.', 'danger');
        } else {
            $assignmentGroupId = bin2hex(random_bytes(16));
            $paymentStatus = $fee > 0 ? 'unpaid' : 'complimentary';
            db()->prepare(
                'INSERT INTO trainer_assignments (group_id, trainer_id, member_user_id, package_id, payment_status, activity_title, assigned_date, ended_date, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending_trainer")'
            )->execute([
                $assignmentGroupId,
                $trainerId,
                $user['user_id'],
                $packageId > 0 ? $packageId : null,
                $paymentStatus,
                $activityTitle,
                $assignedDate,
                $endedDate,
            ]);
            $newAssignmentId = (int) db()->lastInsertId();

            if ($fee > 0) {
                $receiptNum = 'PT-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                db()->prepare(
                    'INSERT INTO pt_payments (gym_id, member_user_id, trainer_id, assignment_id, package_id, payment_type, sessions_purchased, sessions_remaining, amount, payment_date, payment_method, status, receipt_number, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), "cash", "pending", ?, ?)'
                )->execute([
                    $gymId,
                    $user['user_id'],
                    $trainerId,
                    $newAssignmentId,
                    $packageId > 0 ? $packageId : null,
                    $paymentType,
                    $sessionsPurchased,
                    $sessionsPurchased,
                    $fee,
                    $receiptNum,
                    $paymentNotes,
                ]);
                $ptPaymentId = (int) db()->lastInsertId();
                db()->prepare('UPDATE trainer_assignments SET pt_payment_id = ? WHERE assignment_id = ?')
                    ->execute([$ptPaymentId, $newAssignmentId]);
            }
            
            // Notify the specific trainer
            $trainerUserId = (int) ($trainerRow['user_id'] ?? 0);
            if ($trainerUserId > 0) {
                if ($selectedPkg) {
                    $dateStr = date('M j, Y g:i A', strtotime($assignedDate)) . ' (' . $selectedPkg['package_name'] . ' — ₱' . number_format($fee, 2) . ')';
                } elseif ($endedDate && date('Y-m-d', strtotime($assignedDate)) !== date('Y-m-d', strtotime($endedDate))) {
                    $dateStr = date('M j, Y g:i A', strtotime($assignedDate)) . ' to ' . date('M j, Y', strtotime($endedDate));
                } else {
                    $dateStr = date('M j, Y g:i A', strtotime($assignedDate)) . ' (Single Day Session)';
                }
                notify_user($trainerUserId, 'system', 'New Appointment Request', $user['first_name'] . ' ' . $user['last_name'] . ' has requested a PT booking with you starting ' . $dateStr . '.');
            }
            
            if ($fee > 0) {
                flash('Your PT booking request has been sent to the trainer. Please settle ₱' . number_format($fee, 2) . ' at the front desk to confirm payment.');
            } else {
                flash('Your appointment request has been sent directly to the trainer for approval.');
            }
        }
        redirect('trainers');
    }

    $trainers = query_all('SELECT tp.trainer_id, u.user_id, u.first_name, u.last_name, u.profile_picture, tp.specialization, tp.bio, tp.session_rate, (SELECT COUNT(*) FROM attendance WHERE user_id = u.user_id AND check_out_time IS NULL AND check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY) as is_present FROM trainer_profiles tp JOIN users u ON u.user_id = tp.user_id WHERE u.status = "active" AND tp.gym_id = ?', [$gymId]);
    
    $stmt = db()->prepare("
        SELECT ca.*, 
               u.first_name AS coach_fn, 
               u.last_name AS coach_ln, 
               u.profile_picture AS coach_picture,
               tp.specialization,
               pkg.package_name AS pt_package_name,
               ptp.status AS pt_pay_status,
               ptp.amount AS pt_pay_amount,
               ptp.payment_type AS pt_pay_type,
               ptp.sessions_purchased,
               ptp.sessions_remaining,
               (CASE WHEN ca.group_id IS NOT NULL AND ca.group_id != '' 
                     THEN (SELECT COUNT(*) FROM trainer_assignments WHERE group_id = ca.group_id AND status = 'active') 
                     ELSE 1 END) AS group_members_count
        FROM trainer_assignments ca
        JOIN trainer_profiles tp ON tp.trainer_id = ca.trainer_id
        JOIN users u ON u.user_id = tp.user_id
        LEFT JOIN trainer_packages pkg ON pkg.package_id = ca.package_id
        LEFT JOIN pt_payments ptp ON ptp.pt_payment_id = ca.pt_payment_id
        WHERE ca.member_user_id = ? " . ($gymId > 0 ? " AND tp.gym_id = ?" : "") . " 
          AND ca.status IN ('active', 'pending_admin', 'pending_trainer')
        ORDER BY ca.assigned_date DESC
    ");
    $stmt->execute($gymId > 0 ? [$user['user_id'], $gymId] : [$user['user_id']]);
    $activeAssignments = $stmt->fetchAll();
    $existingAssignment = $activeAssignments[0] ?? null;
    
    render_header('Trainers', $user);
    ?>
    <?php render_skeleton_cards(6); ?>
    <section class="panel skeleton-content sk-display-block">
        <div class="page-header">
            <div>
                <h1>Trainers</h1>
                <p>Browse our list of professional trainers and request an appointment.</p>
            </div>
        </div>

        <style>
        .active-assignment-card {
            background: var(--surface);
            border: 1px solid var(--lime);
            border-radius: 14px;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            box-shadow: 0 4px 20px rgba(132, 204, 22, 0.08);
            transition: all 0.2s ease;
        }
        .active-assignment-content {
            display: flex;
            align-items: center;
            gap: 14px;
            flex: 1;
            min-width: 0;
        }
        .active-assignment-avatar {
            flex-shrink: 0;
        }
        .active-assignment-details {
            flex: 1;
            min-width: 0;
        }
        .active-assignment-header {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .active-assignment-title {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: var(--ink);
            letter-spacing: -0.01em;
        }
        .active-assignment-badges {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }
        .badge-label-short {
            display: none;
        }
        .badge-label-long {
            display: inline;
        }
        .badge-group {
            background: rgba(132, 204, 22, 0.12);
            color: var(--lime);
            border: 1px solid rgba(132, 204, 22, 0.25);
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .active-assignment-meta {
            margin: 4px 0 0;
            font-size: 13px;
            color: var(--muted);
            line-height: 1.4;
        }
        .active-assignment-actions {
            display: flex;
            align-items: center;
            flex-shrink: 0;
        }
        .btn-message-trainer {
            text-decoration: none;
            padding: 9px 16px;
            font-size: 13px;
            font-weight: 600;
            background: var(--panel-soft, rgba(255, 255, 255, 0.04));
            border: 1px solid var(--line);
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: var(--ink);
            transition: all 0.18s ease;
            white-space: nowrap;
        }
        .btn-message-trainer:hover {
            background: rgba(132, 204, 22, 0.1);
            border-color: var(--lime);
            color: var(--lime);
        }

        /* Mobile Viewport Optimization (<= 640px) */
        @media (max-width: 640px) {
            .active-assignment-card {
                flex-direction: column;
                align-items: stretch;
                padding: 14px 16px;
                gap: 12px;
            }
            .active-assignment-content {
                align-items: flex-start;
                gap: 12px;
            }
            .active-assignment-avatar {
                margin-top: 2px;
            }
            .active-assignment-header {
                justify-content: space-between;
                align-items: center;
                gap: 8px;
                flex-wrap: nowrap;
            }
            .active-assignment-title {
                font-size: 15px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .badge-label-long {
                display: none;
            }
            .badge-label-short {
                display: inline;
            }
            .active-assignment-meta {
                font-size: 12px;
                margin-top: 3px;
                line-height: 1.45;
            }
            .active-assignment-actions {
                width: 100%;
            }
            .btn-message-trainer {
                width: 100%;
                justify-content: center;
                padding: 10px 14px;
                font-size: 13.5px;
                border-radius: 8px;
            }
        }
        </style>

        <?php if ($activeAssignments): ?>
            <div style="margin-top: 15px; margin-bottom: 24px; display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($activeAssignments as $assign): 
                    $cData = ['first_name' => $assign['coach_fn'], 'last_name' => $assign['coach_ln'], 'profile_picture' => $assign['coach_picture']];
                    $isGroup = !empty($assign['group_id']) && (int)$assign['group_members_count'] > 1;
                ?>
                    <div class="active-assignment-card">
                        <div class="active-assignment-content">
                            <div class="active-assignment-avatar">
                                <?= render_avatar($cData, 'medium') ?>
                            </div>
                            <div class="active-assignment-details">
                                <div class="active-assignment-header">
                                    <h3 class="active-assignment-title">
                                        <?= h($assign['activity_title'] ?: 'Trainer Assignment') ?>
                                    </h3>
                                    <div class="active-assignment-badges">
                                        <span class="badge badge-<?= str_replace(' ', '_', $assign['status']) ?>">
                                            <span class="badge-label-long"><?= $assign['status'] === 'active' ? 'Active Assignment' : h(ucwords(str_replace('_', ' ', $assign['status']))) ?></span>
                                            <span class="badge-label-short"><?= $assign['status'] === 'active' ? 'Active' : h(ucwords(str_replace('_', ' ', $assign['status']))) ?></span>
                                        </span>
                                        <?php if ($isGroup): ?>
                                            <span class="badge badge-group">
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                                <span>Group (<?= (int)$assign['group_members_count'] ?>)</span>
                                            </span>
                                        <?php endif; ?>
                                        <?php
                                            $assignPayStatus = (string) ($assign['pt_pay_status'] ?: ($assign['payment_status'] ?? ''));
                                            if ($assignPayStatus === 'paid'):
                                        ?>
                                            <span class="badge" style="background:rgba(34,197,94,0.12); color:#22c55e; border:1px solid rgba(34,197,94,0.28); font-size:11px; padding:2px 8px; border-radius:12px;">PT Paid</span>
                                        <?php elseif ($assignPayStatus === 'pending' || $assignPayStatus === 'unpaid'): ?>
                                            <span class="badge" style="background:rgba(245,158,11,0.12); color:#f59e0b; border:1px solid rgba(245,158,11,0.28); font-size:11px; padding:2px 8px; border-radius:12px;">PT Unpaid<?= !empty($assign['pt_pay_amount']) ? ' • ₱' . number_format((float)$assign['pt_pay_amount'], 0) : '' ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($assign['pt_pay_type']) && $assign['pt_pay_type'] === 'pt_package' && (int)($assign['sessions_purchased'] ?? 0) > 1): ?>
                                            <span class="badge badge-group"><?= (int)($assign['sessions_remaining'] ?? 0) ?>/<?= (int)$assign['sessions_purchased'] ?> sessions left</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php
                                    $schedText = 'Assigned ' . date('M j, Y', strtotime($assign['assigned_date']));
                                    if (!empty($assign['assigned_date']) && !empty($assign['ended_date'])) {
                                        if (date('Y-m-d', strtotime($assign['assigned_date'])) === date('Y-m-d', strtotime($assign['ended_date']))) {
                                            $schedText = 'Appointment: ' . date('M j, Y g:i A', strtotime($assign['assigned_date'])) . ' (Single Day)';
                                        } else {
                                            $schedText = 'Appointment: ' . date('M j, Y g:i A', strtotime($assign['assigned_date'])) . ' to ' . date('M j, Y', strtotime($assign['ended_date']));
                                        }
                                    }
                                ?>
                                <p class="active-assignment-meta">
                                    Coach <strong style="color: var(--ink);"><?= h($assign['coach_fn'] . ' ' . $assign['coach_ln']) ?></strong> &bull; <?= h($assign['specialization'] ?: 'General Fitness') ?> &bull; <?= $schedText ?>
                                </p>
                            </div>
                        </div>
                        <div class="active-assignment-actions">
                            <a href="index.php?page=messages" class="btn-message-trainer">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                                <span>Message Trainer</span>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr)); gap: 20px; margin-top: 20px;">
            <?php foreach ($trainers as $trainer):
                $trainerPkgs = array_values(array_filter($ptPackages, static function (array $pkg) use ($trainer): bool {
                    return $pkg['trainer_id'] === null || (int) $pkg['trainer_id'] === (int) $trainer['trainer_id'];
                }));
            ?>
                <div class="card" style="padding: 20px; border-radius: 12px; background: var(--bg); border: 1px solid var(--line); display: flex; flex-direction: column; align-items: center; text-align: center; position: relative;">
                    <?php if ($trainer['is_present']): ?>
                        <div style="position: absolute; top: 12px; right: 12px; background: rgba(34, 197, 94, 0.1); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.2); padding: 4px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; display: flex; align-items: center; gap: 4px;" title="Trainer is currently checked in at the gym">
                            <span style="width: 6px; height: 6px; background: #22c55e; border-radius: 50%; display: inline-block; box-shadow: 0 0 4px #22c55e;"></span> At the gym
                        </div>
                    <?php endif; ?>
                    <?= render_avatar(['first_name' => $trainer['first_name'], 'last_name' => $trainer['last_name'], 'profile_picture' => $trainer['profile_picture']], 'large') ?>
                    <h3 style="margin: 15px 0 5px;"><?= h($trainer['first_name'] . ' ' . $trainer['last_name']) ?></h3>
                    <div style="color: var(--lime); font-size: 14px; font-weight: 500; margin-bottom: 8px;"><?= h($trainer['specialization'] ?? 'General Trainer') ?></div>
                    
                    <div style="margin-bottom: 12px; display: inline-flex; align-items: center; gap: 6px; background: rgba(132, 204, 22, 0.08); border: 1px solid rgba(132, 204, 22, 0.2); padding: 3px 10px; border-radius: 12px; font-size: 12.5px; font-weight: 600; color: var(--lime);">
                        <?php if ((float)($trainer['session_rate'] ?? 0) > 0): ?>
                            <span>₱<?= number_format((float)$trainer['session_rate'], 2) ?></span> <span style="font-weight: 400; font-size: 11px; opacity: 0.85;">/ session</span>
                        <?php else: ?>
                            <span>Complimentary</span>
                        <?php endif; ?>
                    </div>

                    <p style="color: var(--muted); font-size: 14px; flex-grow: 1; margin-bottom: <?= $trainerPkgs ? '14px' : '20px' ?>;"><?= h($trainer['bio'] ?? 'No bio available.') ?></p>

                    <?php if ($trainerPkgs): ?>
                        <div style="width: 100%; text-align: left; background: rgba(255, 255, 255, 0.03); border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; margin-bottom: 14px; box-sizing: border-box;">
                            <div style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--muted); margin-bottom: 6px;">
                                PT Packages (<?= count($trainerPkgs) ?>)
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 6px;">
                                <?php foreach (array_slice($trainerPkgs, 0, 2) as $pkg):
                                    $pkgMeta = $pkg['package_type'] === 'monthly_coaching'
                                        ? ((int)($pkg['duration_days'] ?: 30) . 'd Monthly')
                                        : ((int)($pkg['session_count'] ?: 1) . ' Sessions');
                                ?>
                                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; font-size: 12.5px;">
                                        <span style="color: var(--ink); font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?= h($pkg['package_name']) ?>
                                            <span style="font-weight: 400; color: var(--muted); font-size: 11.5px;">· <?= h($pkgMeta) ?></span>
                                        </span>
                                        <strong style="color: var(--lime); white-space: nowrap;">₱<?= number_format((float)$pkg['price'], 2) ?></strong>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($trainerPkgs) > 2): ?>
                                    <div style="font-size: 11px; color: var(--muted);">+<?= count($trainerPkgs) - 2 ?> more package<?= (count($trainerPkgs) - 2) === 1 ? '' : 's' ?> inside</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <form id="form-trainer-<?= $trainer['trainer_id'] ?>" method="post" style="width: 100%;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="request_appointment">
                        <input type="hidden" name="trainer_id" value="<?= (int) $trainer['trainer_id'] ?>">
                        <?php if ($existingAssignment): ?>
                            <?php if ($existingAssignment['trainer_id'] == $trainer['trainer_id']): ?>
                                <button type="button" class="btn" style="width: 100%; background: rgba(199,255,34,0.1); color: var(--lime); border: 1px solid var(--lime); cursor: not-allowed; font-weight: 600;" disabled>
                                    <?= $existingAssignment['status'] === 'active' ? 'Currently Assigned' : 'Request Pending' ?>
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn" style="width: 100%; background: var(--surface); color: var(--muted); border: 1px solid var(--line); cursor: not-allowed;" disabled>Request Appointment</button>
                            <?php endif; ?>
                        <?php else: ?>
                            <input type="hidden" name="appointment_type" id="type-<?= $trainer['trainer_id'] ?>" value="single">
                            <input type="hidden" name="package_id" id="pkg-<?= $trainer['trainer_id'] ?>" value="0">
                            <input type="hidden" name="appointment_date" id="date-<?= $trainer['trainer_id'] ?>" value="">
                            <input type="hidden" name="appointment_end_date" id="end-date-<?= $trainer['trainer_id'] ?>" value="">
                            <button type="button" onclick="requestTrainerDate(<?= (int)$trainer['trainer_id'] ?>, <?= (float)($trainer['session_rate'] ?? 0) ?>, <?= htmlspecialchars(json_encode($trainer['first_name'] . ' ' . $trainer['last_name']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($trainerPkgs), ENT_QUOTES, 'UTF-8') ?>)" class="btn" style="width: 100%; background: var(--lime); color: var(--bg); border: none; font-weight: 600;">Book Personal Training</button>
                            <small style="display:block; margin-top:6px; font-size:11.5px; color:var(--muted);">Billed separately from gym membership</small>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if (!$trainers): ?>
            <div class="empty-state">
                <p>No trainers available at the moment.</p>
            </div>
        <?php endif; ?>
    </section>

    <script>
    window.swalAptMode = 'single';
    window.swalSessionRate = 0;
    window.swalTrainerName = 'Trainer';
    window.swalTrainerPkgs = [];

    window.swalRenderPtNotice = function() {
        const noticeEl = document.getElementById('swal-pt-notice');
        if (!noticeEl) return;

        let fee = window.swalSessionRate;
        let label = 'Per-Session PT Fee';
        let sub = `Personal training is billed separately from gym membership plans. Please settle this fee at the front desk for <strong>Coach ${window.swalTrainerName}</strong>.`;

        if (window.swalAptMode === 'package') {
            const sel = document.getElementById('swal-pkg-select');
            const pkgId = sel ? parseInt(sel.value, 10) : 0;
            const pkg = window.swalTrainerPkgs.find(p => parseInt(p.package_id, 10) === pkgId);
            if (pkg) {
                fee = parseFloat(pkg.price) || 0;
                const sessionsInfo = pkg.package_type === 'monthly_coaching'
                    ? (pkg.duration_days ? `${pkg.duration_days} days monthly coaching` : 'Monthly coaching')
                    : `${pkg.session_count || 1} session(s)`;
                label = `PT Package: ${pkg.package_name} (${sessionsInfo})`;
            }
        }

        if (fee > 0) {
            noticeEl.innerHTML = `
                <div style="margin-top:16px;padding:12px 14px;border-radius:10px;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);display:flex;align-items:flex-start;gap:10px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:2px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <div style="font-size:12.5px;color:#f59e0b;line-height:1.45;">
                        <div style="font-weight:700;margin-bottom:2px;">${label}: ₱${fee.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                        <span>${sub}</span>
                    </div>
                </div>
            `;
        } else {
            noticeEl.innerHTML = `
                <div style="margin-top:16px;padding:12px 14px;border-radius:10px;background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.25);display:flex;align-items:flex-start;gap:10px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:2px;"><circle cx="12" cy="12" r="10"/><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    <div style="font-size:12.5px;color:#22c55e;line-height:1.45;">
                        <div style="font-weight:700;margin-bottom:2px;">Complimentary Session</div>
                        <span>Coach ${window.swalTrainerName} offers this booking with no additional PT fee.</span>
                    </div>
                </div>
            `;
        }
    };

    window.swalSwitchMode = function(mode) {
        window.swalAptMode = mode;
        const modes = ['single', 'package', 'range'];
        modes.forEach((m) => {
            const tab = document.getElementById('swal-tab-' + m);
            const grp = document.getElementById('swal-group-' + m);
            if (tab) {
                if (m === mode) {
                    tab.style.background = 'var(--lime)';
                    tab.style.color = '#000';
                    tab.style.border = '1px solid var(--lime)';
                } else {
                    tab.style.background = 'transparent';
                    tab.style.color = 'var(--muted)';
                    tab.style.border = '1px solid transparent';
                }
            }
            if (grp) {
                grp.style.display = (m === mode) ? 'block' : 'none';
            }
        });
        window.swalRenderPtNotice();
    };

    function requestTrainerDate(trainerId, sessionRate = 0, trainerName = 'Trainer', packages = []) {
        window.swalTrainerPkgs = Array.isArray(packages) ? packages : [];
        window.swalSessionRate = parseFloat(sessionRate) || 0;
        window.swalTrainerName = trainerName;
        window.swalAptMode = window.swalTrainerPkgs.length > 0 ? 'package' : 'single';
        const minDateTime = '<?= date('Y-m-d\TH:i') ?>';
        const minDate = '<?= date('Y-m-d') ?>';
        const hasPkgs = window.swalTrainerPkgs.length > 0;

        const pkgOptionsHtml = window.swalTrainerPkgs.map((p) => {
            const pPrice = parseFloat(p.price || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            const meta = p.package_type === 'monthly_coaching'
                ? `${p.duration_days || 30}d Monthly`
                : `${p.session_count || 1} Sessions`;
            return `<option value="${p.package_id}">${p.package_name} — ${meta} (₱${pPrice})</option>`;
        }).join('');

        const modalHtml = `
            <div style="text-align:left; margin-top:10px;">
                <label style="font-size: 12px; font-weight: 700; color: var(--muted); margin-bottom: 8px; display: block; text-transform: uppercase; letter-spacing: 0.5px;">Booking Option</label>
                <div style="display: flex; gap: 6px; margin-bottom: 16px; background: rgba(255,255,255,0.05); padding: 4px; border-radius: 8px; border: 1px solid var(--line);">
                    ${hasPkgs ? `
                    <button type="button" id="swal-tab-package" onclick="swalSwitchMode('package')" style="flex: 1; padding: 8px 10px; border-radius: 6px; font-size: 12.5px; font-weight: 600; cursor: pointer; border: 1px solid var(--lime); background: var(--lime); color: #000; transition: all 0.2s;">
                        PT Package
                    </button>` : ''}
                    <button type="button" id="swal-tab-single" onclick="swalSwitchMode('single')" style="flex: 1; padding: 8px 10px; border-radius: 6px; font-size: 12.5px; font-weight: 600; cursor: pointer; border: 1px solid ${hasPkgs ? 'transparent' : 'var(--lime)'}; background: ${hasPkgs ? 'transparent' : 'var(--lime)'}; color: ${hasPkgs ? 'var(--muted)' : '#000'}; transition: all 0.2s;">
                        Single Session
                    </button>
                    <button type="button" id="swal-tab-range" onclick="swalSwitchMode('range')" style="flex: 1; padding: 8px 10px; border-radius: 6px; font-size: 12.5px; font-weight: 600; cursor: pointer; border: 1px solid transparent; background: transparent; color: var(--muted); transition: all 0.2s;">
                        Date Range
                    </button>
                </div>

                ${hasPkgs ? `
                <!-- PT Package Group -->
                <div id="swal-group-package">
                    <div style="margin-bottom: 12px;">
                        <label style="font-size: 13px; color: var(--ink); font-weight: 500; margin-bottom: 6px; display: block;">Select PT Package:</label>
                        <select id="swal-pkg-select" class="form-control" style="width:100%; box-sizing: border-box;" onchange="swalRenderPtNotice()">
                            ${pkgOptionsHtml}
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 13px; color: var(--ink); font-weight: 500; margin-bottom: 6px; display: block;">Preferred Start Date & Time:</label>
                        <input type="datetime-local" id="swal-input-pkg-date" class="form-control" style="width:100%; box-sizing: border-box;" min="${minDateTime}">
                    </div>
                    <div style="font-size: 12px; color: var(--muted); margin-top: 6px; line-height: 1.4;">
                        Session packs track remaining sessions automatically as your trainer completes each session.
                    </div>
                </div>` : ''}

                <!-- Single Day Group -->
                <div id="swal-group-single" style="display: ${hasPkgs ? 'none' : 'block'};">
                    <label style="font-size: 13px; color: var(--ink); font-weight: 500; margin-bottom: 6px; display: block;">Session Date & Time:</label>
                    <input type="datetime-local" id="swal-input-date" class="form-control" style="width:100%; box-sizing: border-box;" min="${minDateTime}">
                    <div style="font-size: 12px; color: var(--muted); margin-top: 6px; line-height: 1.4;">
                        Single-day personal training session that automatically concludes at 11:59 PM on the selected day.
                    </div>
                </div>

                <!-- Date Range Group -->
                <div id="swal-group-range" style="display: none;">
                    <div style="margin-bottom: 12px;">
                        <label style="font-size: 13px; color: var(--ink); font-weight: 500; margin-bottom: 6px; display: block;">Start Date & Time:</label>
                        <input type="datetime-local" id="swal-input-start-date" class="form-control" style="width:100%; box-sizing: border-box;" min="${minDateTime}" onchange="document.getElementById('swal-input-end-date').min = this.value.split('T')[0]">
                    </div>
                    <div>
                        <label style="font-size: 13px; color: var(--ink); font-weight: 500; margin-bottom: 6px; display: block;">End Date (Duration Ends):</label>
                        <input type="date" id="swal-input-end-date" class="form-control" style="width:100%; box-sizing: border-box;" min="${minDate}">
                    </div>
                    <div style="font-size: 12px; color: var(--muted); margin-top: 6px; line-height: 1.4;">
                        Coaching duration runs until 11:59 PM on the selected end date.
                    </div>
                </div>

                <div id="swal-pt-notice"></div>
            </div>
        `;

        Swal.fire({
            title: 'Book Personal Training',
            html: modalHtml,
            focusConfirm: false,
            showCancelButton: true,
            confirmButtonText: 'Submit Request',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            didOpen: () => {
                window.swalRenderPtNotice();
            },
            preConfirm: () => {
                if (window.swalAptMode === 'package') {
                    const pkgId = document.getElementById('swal-pkg-select')?.value || '0';
                    const startVal = document.getElementById('swal-input-pkg-date')?.value || '';
                    if (!startVal) {
                        Swal.showValidationMessage('Please select a preferred start date and time');
                        return false;
                    }
                    return { type: 'package', packageId: pkgId, startDate: startVal, endDate: '' };
                } else if (window.swalAptMode === 'single') {
                    const val = document.getElementById('swal-input-date').value;
                    if (!val) {
                        Swal.showValidationMessage('Please select a date and time for your appointment');
                        return false;
                    }
                    return { type: 'single', packageId: '0', startDate: val, endDate: '' };
                } else {
                    const startVal = document.getElementById('swal-input-start-date').value;
                    const endVal = document.getElementById('swal-input-end-date').value;
                    if (!startVal) {
                        Swal.showValidationMessage('Please select a start date and time');
                        return false;
                    }
                    if (!endVal) {
                        Swal.showValidationMessage('Please select an end date');
                        return false;
                    }
                    const startDateOnly = startVal.split('T')[0];
                    if (endVal < startDateOnly) {
                        Swal.showValidationMessage('End date cannot be earlier than start date');
                        return false;
                    }
                    return { type: 'range', packageId: '0', startDate: startVal, endDate: endVal };
                }
            }
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                document.getElementById('type-' + trainerId).value = result.value.type;
                document.getElementById('pkg-' + trainerId).value = result.value.packageId;
                document.getElementById('date-' + trainerId).value = result.value.startDate;
                document.getElementById('end-date-' + trainerId).value = result.value.endDate;
                document.getElementById('form-trainer-' + trainerId).submit();
            }
        });
    }
    </script>
    <?php
    render_footer();
}
