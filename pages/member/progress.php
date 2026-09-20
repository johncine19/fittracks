<?php
declare(strict_types=1);

function progress_page(): void
{
    $user = require_roles(['member', 'trainer']);
    $memberId = $user['role'] === 'trainer'
        ? (int) post('member_user_id', $_GET['member_user_id'] ?? 0)
        : (int) $user['user_id'];

    if ($memberId <= 0 && $user['role'] === 'trainer') {
        // If trainer with no member selected, grab first assigned client or redirect
        $firstClient = (int) scalar(
            'SELECT member_user_id FROM trainer_assignments WHERE trainer_id = (SELECT trainer_id FROM trainer_profiles WHERE user_id = ?) AND status = "active" LIMIT 1',
            [$user['user_id']]
        );
        if ($firstClient > 0) {
            $memberId = $firstClient;
        }
    }

    // Fetch member details
    $stmt = db()->prepare('
        SELECT u.first_name, u.last_name, u.email, u.phone, u.status, u.created_at AS user_created_at, u.profile_picture, mp.* 
        FROM users u 
        LEFT JOIN member_profiles mp ON u.user_id = mp.user_id 
        WHERE u.user_id = ?'
    );
    $stmt->execute([$memberId]);
    $member = $stmt->fetch();
    $isFemale = strtolower((string)($member['biological_sex'] ?? '')) === 'female';

    if (!$member && $user['role'] === 'trainer') {
        render_header('Member Progress', $user);
        echo '<div class="panel" style="text-align:center; padding: 48px 24px;">
            <h3>No Member Selected</h3>
            <p style="color:var(--muted); margin-bottom: 20px;">Please select an assigned client to review their progress hub.</p>
            <a href="index.php?page=trainer" class="btn btn-lime">Back to Clients</a>
        </div>';
        render_footer();
        return;
    }

    // POST: Log progress or update measurements
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($user['role'], ['member', 'trainer'], true)) {
        $action = post('action', 'log_progress');

        if ($action === 'quick_note') {
            $noteText = trim((string) post('note_text', ''));
            if ($noteText !== '') {
                // If trainer, record in trainer_messages; if member, record in progress log note or message
                if ($user['role'] === 'trainer') {
                    db()->prepare('INSERT INTO trainer_messages (sender_id, recipient_id, message_text) VALUES (?, ?, ?)')
                        ->execute([$user['user_id'], $memberId, $noteText]);
                    notify_user($memberId, 'system', 'New Trainer Note', 'Your trainer left a note on your progress hub.');
                    flash('Coach note saved.', 'success');
                } else {
                    // Member note on latest or today's progress log
                    $today = date('Y-m-d');
                    $existingToday = scalar('SELECT log_id FROM progress_logs WHERE user_id = ? AND log_date = ?', [$memberId, $today]);
                    if ($existingToday) {
                        db()->prepare('UPDATE progress_logs SET notes = CONCAT(IFNULL(notes,""), IF(notes IS NULL OR notes="","", "\n"), ?) WHERE log_id = ?')
                            ->execute([$noteText, $existingToday]);
                    } else {
                        $currWeight = (float)($member['weight_kg'] ?? 70.0);
                        db()->prepare('INSERT INTO progress_logs (user_id, log_date, weight_kg, notes, recorded_by) VALUES (?, ?, ?, ?, ?)')
                            ->execute([$memberId, $today, $currWeight, $noteText, $user['user_id']]);
                    }
                    flash('Note saved to your log.', 'success');
                }
            }
            redirect('progress' . ($user['role'] === 'trainer' ? '&member_user_id=' . $memberId : '') . '#notes');
        }

        $validator = new Validator();
        $valid = $validator->validate($_POST, [
            'log_date'         => 'required',
            'weight_kg'        => 'required|numeric|min_num:20|max_num:300',
            'body_fat_percent' => 'numeric|min_num:1|max_num:70',
            'neck_cm'          => 'numeric|min_num:20|max_num:100',
            'chest_cm'         => 'numeric|min_num:30|max_num:200',
            'waist_cm'         => 'numeric|min_num:30|max_num:200',
            'hips_cm'          => 'numeric|min_num:30|max_num:200',
            'arm_cm'           => 'numeric|min_num:10|max_num:100'
        ]);

        if (!$valid) {
            flash($validator->firstError() ?? 'Invalid input parameters.', 'danger');
            redirect('progress' . ($user['role'] === 'trainer' ? '&member_user_id=' . $memberId : ''));
        }

        $logDate = post('log_date');
        $logId = (int) post('log_id', 0);

        if ($logId > 0) {
            db()->prepare(
                'UPDATE progress_logs SET weight_kg = ?, body_fat_percent = ?, chest_cm = ?, waist_cm = ?, hips_cm = ?, arm_cm = ?, notes = ?, recorded_by = ? WHERE log_id = ? AND user_id = ?'
            )->execute([
                post('weight_kg'),
                post('body_fat_percent') ?: null,
                post('chest_cm')         ?: null,
                post('waist_cm')         ?: null,
                post('hips_cm')          ?: null,
                post('arm_cm')           ?: null,
                post('notes'),
                $user['user_id'],
                $logId,
                $memberId
            ]);
        } else {
            db()->prepare(
                'INSERT INTO progress_logs
                 (user_id, log_date, weight_kg, body_fat_percent, chest_cm, waist_cm, hips_cm, arm_cm, notes, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $memberId,
                $logDate,
                post('weight_kg'),
                post('body_fat_percent') ?: null,
                post('chest_cm')         ?: null,
                post('waist_cm')         ?: null,
                post('hips_cm')          ?: null,
                post('arm_cm')           ?: null,
                post('notes'),
                $user['user_id'],
            ]);
        }

        // Update member_profiles table with latest values
        $updateProfileCols = ['weight_kg = ?'];
        $updateProfileVals = [post('weight_kg')];
        if (post('neck_cm')) {
            $updateProfileCols[] = 'neck_cm = ?';
            $updateProfileVals[] = post('neck_cm');
        }
        if (post('waist_cm')) {
            $updateProfileCols[] = 'waist_cm = ?';
            $updateProfileVals[] = post('waist_cm');
        }
        if (post('hips_cm')) {
            $updateProfileCols[] = 'hip_cm = ?';
            $updateProfileVals[] = post('hips_cm');
        }
        if (post('chest_cm')) {
            $updateProfileCols[] = 'chest_cm = ?';
            $updateProfileVals[] = post('chest_cm');
        }
        if (post('arm_cm')) {
            $updateProfileCols[] = 'arm_cm = ?';
            $updateProfileVals[] = post('arm_cm');
        }
        $updateProfileVals[] = $memberId;
        db()->prepare('UPDATE member_profiles SET ' . implode(', ', $updateProfileCols) . ' WHERE user_id = ?')
           ->execute($updateProfileVals);

        if (can_recalculate_workout($memberId)) {
            generate_workout_plan($memberId);
            notify_user($memberId, 'system', 'Workout plan updated', 'Your workout plan was refreshed after logging new progress.');
            $flashMsg = 'Progress logged and workout plan updated.';
        } else {
            $flashMsg = 'Progress logged successfully.';
        }
        
        notify_user($memberId, 'milestone', 'Progress logged', 'Nice work — your latest measurements were saved.');
        flash($flashMsg, 'success');
        redirect('progress' . ($user['role'] === 'trainer' ? '&member_user_id=' . $memberId : ''));
    }

    // Fetch progress logs in DESC order for table & cards; reverse for charts
    $rows = $memberId
        ? query_all('SELECT * FROM progress_logs WHERE user_id = ? ORDER BY log_date DESC, created_at DESC, log_id DESC', [$memberId])
        : [];

    // Scan previous logs for the most recent non-empty values
    $recentWeight = '';
    $recentBf     = '';
    $recentNeck   = !empty($member['neck_cm']) ? (float)$member['neck_cm'] : '';
    $recentWaist  = '';
    $recentChest  = '';
    $recentArm    = '';
    $recentHips   = '';

    foreach ($rows as $r) {
        if ($recentWeight === '' && !empty($r['weight_kg']) && (float)$r['weight_kg'] > 0) {
            $recentWeight = (float)$r['weight_kg'];
        }
        if ($recentBf === '' && !empty($r['body_fat_percent']) && (float)$r['body_fat_percent'] > 0) {
            $recentBf = (float)$r['body_fat_percent'];
        }
        if ($recentWaist === '' && !empty($r['waist_cm']) && (float)$r['waist_cm'] > 0) {
            $recentWaist = (float)$r['waist_cm'];
        }
        if ($recentChest === '' && !empty($r['chest_cm']) && (float)$r['chest_cm'] > 0) {
            $recentChest = (float)$r['chest_cm'];
        }
        if ($recentArm === '' && !empty($r['arm_cm']) && (float)$r['arm_cm'] > 0) {
            $recentArm = (float)$r['arm_cm'];
        }
        if ($recentHips === '' && !empty($r['hips_cm']) && (float)$r['hips_cm'] > 0) {
            $recentHips = (float)$r['hips_cm'];
        }
    }

    // Fall back to baseline physical profile if not found in progress logs
    if ($recentWeight === '' && !empty($member['weight_kg']) && (float)$member['weight_kg'] > 0) {
        $recentWeight = (float)$member['weight_kg'];
    }
    if ($recentWaist === '' && !empty($member['waist_cm']) && (float)$member['waist_cm'] > 0) {
        $recentWaist = (float)$member['waist_cm'];
    }
    if ($recentHips === '' && !empty($member['hip_cm']) && (float)$member['hip_cm'] > 0) {
        $recentHips = (float)$member['hip_cm'];
    }
    if ($recentChest === '' && !empty($member['chest_cm']) && (float)$member['chest_cm'] > 0) {
        $recentChest = (float)$member['chest_cm'];
    }
    if ($recentArm === '' && !empty($member['arm_cm']) && (float)$member['arm_cm'] > 0) {
        $recentArm = (float)$member['arm_cm'];
    }

    // --- SMART GENDER & ANATOMICAL BODY MEASUREMENT DETECTION ---
    $explicitSex = strtolower(trim((string)($member['biological_sex'] ?? '')));
    if ($explicitSex === 'female' || $explicitSex === 'f' || $explicitSex === 'woman') {
        $isFemale = true;
    } elseif ($explicitSex === 'male' || $explicitSex === 'm' || $explicitSex === 'man') {
        $isFemale = false;
    } else {
        // Smart Anthropometric Inference from Body Measurements:
        // Women typically exhibit clinical Waist-to-Hip Ratio (WHR) <= 0.82
        // or slender neck (< 34.5cm) and narrower waist (< 74cm)
        $wVal = (float)$recentWaist;
        $hVal = (float)$recentHips;
        $nVal = (float)$recentNeck;

        if ($wVal > 0 && $hVal > 0) {
            $whr = $wVal / $hVal;
            $isFemale = ($whr <= 0.82);
        } elseif ($nVal > 0 && $nVal < 34.5 && $wVal > 0 && $wVal < 74.0) {
            $isFemale = true;
        } else {
            $isFemale = false;
        }
    }

    // Auto-calculate body fat percent if not explicitly recorded and measurements exist (U.S. Navy Method)
    $heightCm = !empty($member['height_cm']) ? (float)$member['height_cm'] : 0.0;
    if ($recentBf === '' && $heightCm > 0 && !empty($recentNeck) && $recentWaist !== '') {
        $h = $heightCm;
        $n = (float)$recentNeck;
        $w = (float)$recentWaist;
        $sex = $isFemale ? 'female' : 'male';
        $hip = (float)($recentHips ?: ($member['hip_cm'] ?? 0));

        if ($sex === 'male' && $w > $n && ($w - $n) > 0) {
            $estBf = 495 / (1.0324 - 0.19077 * log10($w - $n) + 0.15456 * log10($h)) - 450;
            if ($estBf >= 3 && $estBf <= 55) {
                $recentBf = round($estBf, 1);
            }
        } elseif ($sex === 'female' && ($w + $hip) > $n && ($w + $hip - $n) > 0) {
            $estBf = 495 / (1.29579 - 0.35004 * log10($w + $hip - $n) + 0.22100 * log10($h)) - 450;
            if ($estBf >= 5 && $estBf <= 60) {
                $recentBf = round($estBf, 1);
            }
        }
    }

    // --- KPI 1: WEIGHT & MOM DELTA ---
    $weightCurr = $recentWeight !== '' ? (float)$recentWeight : 0.0;
    $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
    $monthAgoLog = null;
    foreach ($rows as $r) {
        if ($r['log_date'] <= $thirtyDaysAgo && !empty($r['weight_kg'])) {
            $monthAgoLog = $r;
            break;
        }
    }
    if (!$monthAgoLog && count($rows) > 1) {
        $monthAgoLog = $rows[count($rows) - 1];
    }
    $weightDelta = null;
    if ($monthAgoLog && !empty($monthAgoLog['weight_kg']) && $weightCurr > 0) {
        $weightDelta = round($weightCurr - (float)$monthAgoLog['weight_kg'], 1);
    }

    // --- KPI 2: HEIGHT ---
    // $heightCm already parsed

    // --- KPI 3: BMI ---
    $bmi = ($heightCm > 0 && $weightCurr > 0) ? round($weightCurr / pow($heightCm / 100, 2), 1) : null;
    $bmiCategory = 'Normal range';
    $bmiClass = 'badge-lime';
    if ($bmi !== null) {
        if ($bmi < 18.5) {
            $bmiCategory = 'Underweight';
            $bmiClass = 'badge-info';
        } elseif ($bmi <= 24.9) {
            $bmiCategory = 'Normal range';
            $bmiClass = 'badge-lime';
        } elseif ($bmi <= 29.9) {
            $bmiCategory = 'Overweight';
            $bmiClass = 'badge-amber';
        } else {
            $bmiCategory = 'Obese';
            $bmiClass = 'badge-danger';
        }
    }

    // --- KPI 4: BODY FAT & MOM DELTA ---
    $bfCurr = $recentBf !== '' ? (float)$recentBf : null;
    $bfDelta = null;
    if ($monthAgoLog && !empty($monthAgoLog['body_fat_percent']) && $bfCurr !== null) {
        $bfDelta = round($bfCurr - (float)$monthAgoLog['body_fat_percent'], 1);
    }

    // --- ACTIVE WORKOUT PLAN & EXERCISES ---
    $planStmt = db()->prepare('
        SELECT p.*, tp.user_id AS t_user_id, u.first_name AS t_first, u.last_name AS t_last 
        FROM training_plans p 
        LEFT JOIN trainer_profiles tp ON p.trainer_id = tp.trainer_id 
        LEFT JOIN users u ON u.user_id = tp.user_id 
        WHERE p.member_user_id = ? AND p.status = "active" 
        ORDER BY p.plan_id DESC LIMIT 1'
    );
    $planStmt->execute([$memberId]);
    $activePlan = $planStmt->fetch() ?: null;

    $exercisesByDow = [];
    $totalPlanExercises = 0;
    if ($activePlan) {
        $exStmt = db()->prepare('
            SELECT tpe.*, e.name AS exercise_name, e.category, e.muscle_group 
            FROM training_plan_exercises tpe 
            JOIN exercises e ON tpe.exercise_id = e.exercise_id 
            WHERE tpe.plan_id = ? 
            ORDER BY tpe.day_of_week ASC, tpe.sequence_order ASC'
        );
        $exStmt->execute([$activePlan['plan_id']]);
        foreach ($exStmt->fetchAll() as $ex) {
            $dow = (int)($ex['day_of_week'] ?? 1);
            $exercisesByDow[$dow][] = $ex;
            $totalPlanExercises++;
        }
    }

    // Weekly consistency metrics
    $weeklyTarget = !empty($member['weekly_workout_target']) ? (int)$member['weekly_workout_target'] : 4;
    $workoutsThisWeek = (int) scalar(
        'SELECT COUNT(DISTINCT check_in_date) FROM (
            SELECT DATE(check_in_time) as check_in_date FROM attendance WHERE user_id = ? AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
            UNION
            SELECT completed_date as check_in_date FROM exercise_completions WHERE user_id = ? AND completed_date >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
        ) t',
        [$memberId, $memberId]
    );
    $weeklyPct = $weeklyTarget > 0 ? min(100, max(0, round(($workoutsThisWeek / $weeklyTarget) * 100))) : 0;

    // --- RECENT ACTIVITY TIMELINE ---
    $recentActivities = [];
    // Progress logs
    foreach (array_slice($rows, 0, 3) as $pl) {
        $recentActivities[] = [
            'type'      => 'progress',
            'title'     => 'Logged Body Measurements',
            'desc'      => number_format((float)$pl['weight_kg'], 1) . ' kg' . ($pl['body_fat_percent'] ? ' • ' . number_format((float)$pl['body_fat_percent'], 1) . '% Body Fat' : ''),
            'date_raw'  => $pl['log_date'],
            'time_text' => date('M j, Y', strtotime($pl['log_date'])),
            'timestamp' => strtotime($pl['log_date'] . ' 12:00:00'),
            'icon'      => 'scale'
        ];
    }
    // Attendance check-ins
    $recentAttendance = query_all('SELECT check_in_time FROM attendance WHERE user_id = ? ORDER BY check_in_time DESC LIMIT 3', [$memberId]);
    foreach ($recentAttendance as $att) {
        $recentActivities[] = [
            'type'      => 'attendance',
            'title'     => 'Completed Gym Workout',
            'desc'      => 'Checked in at ' . date('g:i A', strtotime($att['check_in_time'])),
            'date_raw'  => date('Y-m-d', strtotime($att['check_in_time'])),
            'time_text' => date('M j, Y', strtotime($att['check_in_time'])),
            'timestamp' => strtotime($att['check_in_time']),
            'icon'      => 'dumbbell'
        ];
    }
    // Exercise completions
    $recentCompletions = query_all('
        SELECT ec.*, e.name AS exercise_name 
        FROM exercise_completions ec 
        JOIN exercises e ON ec.exercise_id = e.exercise_id 
        WHERE ec.user_id = ? 
        ORDER BY ec.completed_date DESC LIMIT 3', 
        [$memberId]
    );
    foreach ($recentCompletions as $comp) {
        $recentActivities[] = [
            'type'      => 'exercise',
            'title'     => 'Completed ' . $comp['exercise_name'],
            'desc'      => 'Workout exercise completed',
            'date_raw'  => $comp['completed_date'],
            'time_text' => date('M j, Y', strtotime($comp['completed_date'])),
            'timestamp' => strtotime($comp['completed_date'] . ' 12:00:00'),
            'icon'      => 'check'
        ];
    }
    usort($recentActivities, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
    $recentActivities = array_slice($recentActivities, 0, 4);

    // --- NOTES FEED ---
    $notesFeed = [];
    foreach ($rows as $pl) {
        if (!empty(trim((string)($pl['notes'] ?? '')))) {
            $notesFeed[] = [
                'author'    => $member['first_name'] . ' (Self)',
                'badge'     => 'Member Note',
                'badge_cls' => 'badge-info',
                'content'   => $pl['notes'],
                'date'      => date('M j, Y', strtotime($pl['log_date'])),
                'timestamp' => strtotime($pl['log_date'])
            ];
        }
    }
    $recentMessages = query_all('
        SELECT tm.*, u.first_name, u.last_name, u.role 
        FROM trainer_messages tm 
        JOIN users u ON tm.sender_id = u.user_id 
        WHERE (tm.sender_id = ? AND tm.recipient_id = ?) OR (tm.sender_id = ? AND tm.recipient_id = ?) 
        ORDER BY tm.sent_at DESC LIMIT 15',
        [$memberId, $user['user_id'], $user['user_id'], $memberId]
    );
    foreach ($recentMessages as $msg) {
        $isTrainer = $msg['role'] === 'trainer';
        $notesFeed[] = [
            'author'    => $msg['first_name'] . ' ' . $msg['last_name'],
            'badge'     => $isTrainer ? 'Trainer Note' : 'Message',
            'badge_cls' => $isTrainer ? 'badge-lime' : 'badge-info',
            'content'   => $msg['message_text'],
            'date'      => date('M j, Y g:i A', strtotime($msg['sent_at'])),
            'timestamp' => strtotime($msg['sent_at'])
        ];
    }
    usort($notesFeed, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

    // --- CHART DATA PREPARATION ---
    $chartDataPoints = [];
    $chronoRows = array_reverse($rows);
    $dateCounts = array_count_values(array_column($rows, 'log_date'));

    foreach ($chronoRows as $r) {
        $w = (float)$r['weight_kg'];
        $bf = !empty($r['body_fat_percent']) ? (float)$r['body_fat_percent'] : null;
        $b = ($heightCm > 0 && $w > 0) ? round($w / pow($heightCm / 100, 2), 1) : null;

        $dStr = $r['log_date'];
        if (($dateCounts[$dStr] ?? 0) > 1 && !empty($r['created_at'])) {
            $timeLabel = date('M j, g:i A', strtotime($r['created_at']));
        } else {
            $timeLabel = date('M j', strtotime($dStr));
        }

        $chartDataPoints[] = [
            'date'     => $r['log_date'],
            'label'    => $timeLabel,
            'weight'   => $w,
            'body_fat' => $bf,
            'bmi'      => $b
        ];
    }

    render_header('Member Hub & Progress', $user);
    ?>

    <style>
        /* === 360 MEMBER HUB STYLES === */
        :root {
            --hub-card-bg: color-mix(in srgb, var(--panel-soft, rgba(255, 255, 255, 0.04)) 70%, transparent);
            --hub-card-border: var(--line, rgba(255, 255, 255, 0.08));
            --hub-glow: rgba(199, 255, 34, 0.15);
        }

        [data-theme="light"] {
            --hub-card-bg: #ffffff;
            --hub-card-border: #e2e8f0;
            --hub-glow: rgba(22, 163, 74, 0.12);
        }

        /* 1. Header Profile Banner */
        .member-hub-profile-card {
            background: var(--hub-card-bg);
            border: 1px solid var(--hub-card-border);
            border-radius: 18px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            position: relative;
            overflow: hidden;
        }

        .member-hub-profile-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--lime), #38bdf8, transparent);
        }

        .member-hub-profile-card,
        .member-hub-profile-card *,
        .hub-tab-pane,
        .hub-tab-pane * {
            box-sizing: border-box;
        }

        .profile-card-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
            width: 100%;
            max-width: 100%;
        }

        .profile-bio-group {
            display: flex;
            align-items: center;
            gap: 20px;
            min-width: 0;
            flex: 1 1 auto;
            max-width: 100%;
        }

        .profile-avatar-wrap {
            position: relative;
            flex-shrink: 0;
        }

        .profile-avatar-img {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--lime);
            background: var(--panel-soft);
            display: grid;
            place-items: center;
            font-size: 26px;
            font-weight: 800;
            color: var(--lime);
            box-shadow: 0 4px 14px var(--hub-glow);
        }

        .avatar-online-dot {
            position: absolute;
            bottom: 2px;
            right: 4px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #22c55e;
            border: 2px solid var(--bg);
        }

        .profile-info {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
        }

        .profile-name-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .profile-name-title {
            margin: 0;
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--ink);
            letter-spacing: -0.02em;
        }

        .status-pill-active {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            background: rgba(34, 197, 94, 0.12);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.25);
        }

        .status-pill-active::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #22c55e;
            box-shadow: 0 0 6px #22c55e;
        }

        .profile-desktop-meta {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .profile-meta-pills {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 13px;
            color: var(--muted);
        }

        .meta-pill-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .meta-pill-item strong {
            color: var(--ink);
            font-weight: 600;
        }

        .meta-goal-chip {
            background: color-mix(in srgb, var(--lime) 15%, transparent);
            color: var(--lime);
            border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
            padding: 2px 9px;
            border-radius: 12px;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: capitalize;
        }

        [data-theme="light"] .meta-goal-chip {
            color: #15803d;
            background: #f0fdf4;
            border-color: #bbf7d0;
        }

        /* Mobile Profile Detail Cards Grid (hidden by default on desktop) */
        .profile-mobile-cards {
            display: none;
        }

        .profile-actions-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            flex-shrink: 0;
        }

        .btn-hub-primary {
            background: var(--lime);
            color: #0b110e;
            font-weight: 800;
            padding: 10px 20px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 14px var(--hub-glow);
            transition: all 0.2s ease;
            text-decoration: none;
            font-size: 14px;
        }

        .btn-hub-primary:hover {
            transform: translateY(-1px);
            filter: brightness(1.05);
        }

        .btn-hub-secondary {
            background: var(--panel-soft, rgba(255, 255, 255, 0.06));
            border: 1px solid var(--line);
            color: var(--ink);
            font-weight: 700;
            padding: 10px 16px;
            border-radius: 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            text-decoration: none;
            font-size: 13.5px;
        }

        .btn-hub-secondary:hover {
            background: color-mix(in srgb, var(--ink) 8%, transparent);
            border-color: color-mix(in srgb, var(--ink) 20%, transparent);
        }

        /* 2. Navigation Tabs */
        .member-hub-tabs-nav {
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(16, 19, 27, 0.85);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border: 1px solid var(--hub-card-border);
            border-radius: 14px;
            padding: 6px;
            margin-bottom: 24px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }

        .member-hub-tabs-nav::-webkit-scrollbar {
            display: none;
        }

        [data-theme="light"] .member-hub-tabs-nav {
            background: rgba(255, 255, 255, 0.94);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
        }

        .hub-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 10px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--muted);
            font: inherit;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            white-space: nowrap;
            text-decoration: none;
            flex-shrink: 0;
        }

        .hub-tab-btn:hover {
            color: var(--ink);
            background: color-mix(in srgb, var(--ink) 6%, transparent);
        }

        .hub-tab-btn.active {
            color: var(--lime);
            background: color-mix(in srgb, var(--lime) 14%, var(--panel-soft));
            border-color: color-mix(in srgb, var(--lime) 35%, transparent);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        [data-theme="light"] .hub-tab-btn.active {
            color: #15803d;
            background: #f0fdf4;
            border-color: #bbf7d0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .hub-tab-pane {
            display: none;
            animation: fadeInHubPane 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .hub-tab-pane.active {
            display: block;
        }

        @keyframes fadeInHubPane {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* 3. Top 4 KPI Cards */
        .hub-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .hub-kpi-card {
            background: var(--hub-card-bg);
            border: 1px solid var(--hub-card-border);
            border-radius: 14px;
            padding: 18px 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 12px;
            transition: transform 0.2s ease, border-color 0.2s ease;
        }

        .hub-kpi-card:hover {
            transform: translateY(-2px);
            border-color: color-mix(in srgb, var(--lime) 40%, transparent);
        }

        .kpi-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .kpi-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
        }

        .kpi-icon-pill {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: grid;
            place-items: center;
            background: var(--panel-soft);
            color: var(--muted);
        }

        .kpi-value-row {
            display: flex;
            align-items: baseline;
            gap: 6px;
        }

        .kpi-number {
            font-size: 26px;
            font-weight: 800;
            color: var(--ink);
            line-height: 1.1;
            letter-spacing: -0.02em;
        }

        .kpi-unit {
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
        }

        .kpi-delta-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11.5px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            width: fit-content;
        }

        .kpi-delta-up-good {
            background: rgba(34, 197, 94, 0.12);
            color: #22c55e;
        }

        .kpi-delta-up-warn {
            background: rgba(239, 68, 68, 0.12);
            color: #ef4444;
        }

        .kpi-delta-neutral {
            background: var(--panel-soft);
            color: var(--muted);
        }

        .badge-lime {
            background: color-mix(in srgb, var(--lime) 15%, transparent);
            color: var(--lime);
            border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
        }
        [data-theme="light"] .badge-lime {
            background: #f0fdf4;
            color: #16a34a;
            border-color: #bbf7d0;
        }

        .badge-info {
            background: rgba(56, 189, 248, 0.12);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.3);
        }

        .badge-amber {
            background: rgba(245, 158, 11, 0.12);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.12);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        /* 4. Overview 2-Column Grid */
        .hub-overview-grid {
            display: grid;
            grid-template-columns: 420px 1fr;
            gap: 24px;
            align-items: start;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }

        .hub-col-left, .hub-col-right {
            min-width: 0;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }

        .hub-card {
            background: var(--hub-card-bg);
            border: 1px solid var(--hub-card-border);
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.05);
            margin-bottom: 24px;
            min-width: 0;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            overflow: hidden;
        }

        .hub-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--line);
            flex-wrap: wrap;
        }

        .hub-card-header > .btn {
            width: auto !important;
            flex-shrink: 0;
        }

        .hub-card-title-group h3 {
            margin: 0 0 4px;
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--ink);
        }

        .hub-card-title-group p {
            margin: 0;
            font-size: 12.5px;
            color: var(--muted);
        }

        .hub-header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Silhouette & Anatomical Body Measurements */
        .silhouette-container {
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 8px 0;
            min-height: auto;
            max-width: 100%;
            overflow: hidden;
        }

        .silhouette-svg {
            width: 100%;
            max-width: 340px;
            height: auto;
            max-height: 350px;
            filter: drop-shadow(0 4px 12px rgba(0,0,0,0.15));
            transition: all 0.2s ease;
        }

        .svg-landmark-badge {
            cursor: default;
            transition: transform 0.2s ease;
        }

        .svg-landmark-badge:hover .svg-badge-box {
            stroke: var(--lime);
        }

        .svg-badge-box {
            fill: var(--panel-soft);
            stroke: var(--hub-card-border);
            stroke-width: 1;
            transition: stroke 0.2s ease, fill 0.2s ease;
        }

        [data-theme="light"] .svg-badge-box {
            fill: #ffffff;
            stroke: #e2e8f0;
        }

        .svg-badge-dot {
            fill: var(--lime);
            filter: drop-shadow(0 0 4px var(--lime));
        }

        [data-theme="light"] .svg-badge-dot {
            fill: #16a34a;
            filter: drop-shadow(0 0 3px rgba(22, 163, 74, 0.4));
        }

        .svg-badge-label {
            fill: var(--muted);
            font-size: 7.5px;
            font-weight: 700;
            font-family: inherit;
            letter-spacing: 0.05em;
        }

        .svg-badge-val {
            fill: var(--ink);
            font-size: 11px;
            font-weight: 800;
            font-family: inherit;
        }

        .svg-badge-val.has-val {
            fill: var(--lime);
        }

        [data-theme="light"] .svg-badge-val.has-val {
            fill: #15803d;
        }

        /* Anatomical Silhouette Graphic Images */
        .silhouette-img-dark-theme {
            display: block;
            filter: drop-shadow(0 0 6px var(--lime)) drop-shadow(0 0 1px var(--lime));
            opacity: 0.95;
            transition: opacity 0.25s ease;
        }

        .silhouette-img-light-theme {
            display: none;
            filter: drop-shadow(0 1px 3px rgba(0,0,0,0.25));
            opacity: 0.9;
            transition: opacity 0.25s ease;
        }

        [data-theme="light"] .silhouette-img-dark-theme {
            display: none !important;
        }

        [data-theme="light"] .silhouette-img-light-theme {
            display: block !important;
        }

        /* Bottom Row Grid (Recent Activity & Quick Actions) */
        .hub-bottom-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            width: 100%;
            box-sizing: border-box;
        }

        /* Current Workout Plan Card */
        .routine-split-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: color-mix(in srgb, var(--lime) 14%, transparent);
            color: var(--lime);
            border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 8px;
        }

        [data-theme="light"] .routine-split-tag {
            color: #15803d;
            background: #f0fdf4;
            border-color: #bbf7d0;
        }

        .dow-pill-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
            margin: 16px 0;
        }

        .dow-pill {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 8px 4px;
            border-radius: 8px;
            background: var(--panel-soft);
            border: 1px solid var(--line);
            text-align: center;
            font-size: 11px;
            transition: all 0.2s ease;
        }

        .dow-pill.has-workout {
            background: color-mix(in srgb, var(--lime) 12%, var(--panel-soft));
            border-color: color-mix(in srgb, var(--lime) 35%, transparent);
        }

        .dow-pill .day-name {
            font-weight: 700;
            color: var(--muted);
            margin-bottom: 2px;
        }

        .dow-pill.has-workout .day-name {
            color: var(--lime);
        }

        [data-theme="light"] .dow-pill.has-workout .day-name {
            color: #15803d;
        }

        .dow-pill .workout-count {
            font-size: 10px;
            color: var(--ink);
            font-weight: 600;
        }

        /* Progress Overview Chart Card */
        .chart-controls-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .metric-toggle-group,
        .timeframe-toggle-group {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: var(--panel-soft);
            padding: 3px;
            border-radius: 10px;
            border: 1px solid var(--line);
        }

        .toggle-btn {
            border: none;
            background: transparent;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 7px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .toggle-btn:hover {
            color: var(--ink);
        }

        .toggle-btn.active {
            background: var(--bg);
            color: var(--lime);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
        }

        [data-theme="light"] .toggle-btn.active {
            background: #ffffff;
            color: #15803d;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.06);
        }

        .chart-canvas-wrap {
            position: relative;
            height: 280px;
            width: 100%;
            margin-bottom: 18px;
        }

        .chart-summary-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            padding-top: 14px;
            border-top: 1px solid var(--line);
        }

        .summary-stat-box {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .summary-stat-box .stat-label {
            font-size: 11px;
            color: var(--muted);
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.04em;
        }

        .summary-stat-box .stat-delta {
            font-size: 15px;
            font-weight: 800;
            color: var(--ink);
        }

        /* Recent Activity Timeline */
        .timeline-feed {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .timeline-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            position: relative;
        }

        .timeline-item:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 32px;
            left: 17px;
            bottom: -8px;
            width: 2px;
            background: var(--line);
        }

        .timeline-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--panel-soft);
            border: 1px solid var(--line);
            display: grid;
            place-items: center;
            color: var(--lime);
            flex-shrink: 0;
            z-index: 1;
        }

        .timeline-content {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex: 1;
        }

        .timeline-title {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--ink);
        }

        .timeline-desc {
            font-size: 12px;
            color: var(--muted);
        }

        .timeline-time {
            font-size: 11px;
            color: var(--muted);
            opacity: 0.8;
            margin-top: 2px;
        }

        /* Quick Actions & Tips */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 16px;
        }

        .btn-quick-act {
            background: var(--panel-soft);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 12px 14px;
            color: var(--ink);
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.15s ease;
            text-align: center;
            text-decoration: none;
            box-sizing: border-box;
            width: 100%;
        }

        .btn-quick-act:hover {
            border-color: var(--lime);
            background: color-mix(in srgb, var(--lime) 8%, var(--panel-soft));
            transform: translateY(-1px);
        }

        .tip-callout-card {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px;
            border-radius: 12px;
            background: rgba(56, 189, 248, 0.08);
            border: 1px solid rgba(56, 189, 248, 0.2);
            color: var(--ink);
            font-size: 12.5px;
            line-height: 1.45;
        }

        .tip-callout-card svg {
            color: #38bdf8;
            flex-shrink: 0;
            margin-top: 2px;
        }

        /* === RESPONSIVE DETAIL CARD SYSTEM (MOBILE) === */
        .mobile-detail-card {
            background: var(--bg);
            border: 1px solid var(--hub-card-border);
            border-radius: 14px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: transform 0.15s ease, border-color 0.15s ease;
        }

        [data-theme="light"] .mobile-detail-card {
            background: #ffffff;
            border-color: #e2e8f0;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }

        .mdc-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--line);
        }

        .mdc-title {
            font-size: 14.5px;
            font-weight: 700;
            color: var(--ink);
        }

        .mdc-body-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .mdc-stat {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .mdc-label {
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
            font-weight: 700;
        }

        .mdc-val {
            font-size: 14px;
            font-weight: 700;
            color: var(--ink);
        }

        .mdc-val.val-highlight {
            color: var(--lime);
        }

        [data-theme="light"] .mdc-val.val-highlight {
            color: #15803d;
        }

        .mdc-footer {
            margin-top: 4px;
        }

        .w-100 {
            width: 100% !important;
        }

        /* Note Cards */
        .note-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .note-author-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .note-author-name {
            color: var(--ink);
            font-size: 14px;
        }

        .note-date-text {
            font-size: 11.5px;
            color: var(--muted);
            white-space: nowrap;
        }

        /* Desktop vs Mobile Display Controllers */
        .measurements-desktop-table,
        .workout-desktop-table,
        .progress-desktop-table {
            display: block;
        }

        .measurements-mobile-cards,
        .workout-mobile-cards,
        .progress-mobile-cards {
            display: none;
        }

        /* Responsive Breakpoints */
        @media (max-width: 1024px) {
            .hub-kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .hub-overview-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 860px) {
            .hub-bottom-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
        }

        @media (max-width: 768px) {
            /* Switch desktop tables to responsive mobile cards */
            .measurements-desktop-table,
            .workout-desktop-table,
            .progress-desktop-table {
                display: none !important;
            }

            .measurements-mobile-cards,
            .workout-mobile-cards,
            .progress-mobile-cards {
                display: flex !important;
                flex-direction: column;
                gap: 12px;
            }

            .hub-card-header {
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
                flex-wrap: wrap !important;
                gap: 10px !important;
            }

            .hub-card-header > .btn {
                width: auto !important;
                margin-left: auto;
                justify-content: center !important;
            }

            .hub-header-actions {
                width: 100% !important;
                display: flex !important;
                flex-direction: column !important;
                gap: 8px !important;
            }

            .hub-header-actions .btn {
                width: 100% !important;
                justify-content: center !important;
            }
        }

        @media (max-width: 640px) {
            .member-hub-profile-card {
                padding: 16px 14px;
            }

            .profile-card-inner {
                flex-direction: column !important;
                align-items: center !important;
                text-align: center !important;
            }

            .profile-bio-group {
                flex-direction: column !important;
                align-items: center !important;
                text-align: center !important;
                width: 100% !important;
                gap: 14px !important;
            }

            .profile-info {
                align-items: center !important;
                width: 100% !important;
            }

            .profile-name-row {
                justify-content: center !important;
                gap: 8px !important;
            }

            .profile-name-title {
                font-size: 1.45rem !important;
                word-break: break-word;
            }

            /* Hide inline meta text and show structured cards */
            .profile-desktop-meta {
                display: none !important;
            }

            .profile-mobile-cards {
                display: grid !important;
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
                width: 100%;
                margin-top: 12px;
                text-align: left;
            }

            .p-detail-card {
                background: var(--panel-soft);
                border: 1px solid var(--line);
                border-radius: 10px;
                padding: 8px 12px;
                display: flex;
                flex-direction: column;
                gap: 2px;
                min-width: 0;
            }

            .p-detail-card-wide {
                grid-column: span 2;
            }

            .p-detail-label {
                font-size: 10.5px;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                color: var(--muted);
                font-weight: 700;
            }

            .p-detail-val {
                font-size: 13.5px;
                font-weight: 700;
                color: var(--ink);
            }

            .profile-actions-group {
                width: 100% !important;
                display: grid !important;
                grid-template-columns: 1fr 1fr;
                gap: 8px !important;
                margin-top: 10px;
                box-sizing: border-box;
            }

            .profile-actions-group .btn-hub-primary {
                grid-column: span 2;
                justify-content: center;
                padding: 12px 16px;
                font-size: 14px;
            }

            .profile-actions-group .btn-hub-secondary {
                justify-content: center;
                padding: 10px 12px;
                font-size: 13px;
                text-align: center;
            }

            .hub-kpi-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 10px;
                margin-bottom: 18px;
            }

            .hub-kpi-card {
                padding: 12px 14px;
                border-radius: 12px;
                gap: 8px;
                min-width: 0;
                overflow: hidden;
            }

            .kpi-title {
                font-size: 11px;
                letter-spacing: 0.04em;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .kpi-icon-pill {
                width: 28px;
                height: 28px;
                border-radius: 6px;
                flex-shrink: 0;
            }

            .kpi-icon-pill svg {
                width: 14px;
                height: 14px;
            }

            .kpi-value-row {
                gap: 4px;
                flex-wrap: wrap;
            }

            .kpi-number {
                font-size: 20px;
            }

            .kpi-unit {
                font-size: 11.5px;
            }

            .kpi-delta-pill {
                font-size: 10px;
                padding: 2px 6px;
                border-radius: 5px;
                white-space: nowrap;
                max-width: 100%;
                overflow: hidden;
                text-overflow: ellipsis;
                display: inline-block;
                line-height: 1.4;
            }

            /* Silhouette & Body Measurements for Mobile */
            .silhouette-container {
                min-height: auto;
                padding: 4px 0 2px;
            }

            .silhouette-svg {
                width: 100%;
                max-width: 310px;
                height: auto !important;
                max-height: 295px !important;
            }

            .chart-controls-row {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }

            .metric-toggle-group, .timeframe-toggle-group {
                width: 100%;
                display: flex;
                justify-content: space-between;
            }

            .toggle-btn {
                flex: 1;
                text-align: center;
                padding: 6px 4px;
                font-size: 11.5px;
            }

            .chart-summary-stats {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .dow-pill-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .quick-actions-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 8px;
            }

            .btn-quick-act {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                text-align: center !important;
                padding: 10px 8px;
                font-size: 12px;
                gap: 6px;
                border-radius: 9px;
                min-width: 0;
                width: 100%;
                box-sizing: border-box;
            }

            .btn-quick-act svg {
                width: 14px;
                height: 14px;
                flex-shrink: 0;
            }

            .btn-quick-act span {
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
        }

        @media (max-width: 420px) {
            .profile-name-title {
                font-size: 1.3rem !important;
            }

            .hub-kpi-grid {
                gap: 8px;
            }

            .hub-kpi-card {
                padding: 10px 10px;
                gap: 6px;
            }

            .kpi-number {
                font-size: 18px;
            }

            .kpi-title {
                font-size: 10px;
            }

            .kpi-icon-pill {
                width: 24px;
                height: 24px;
            }

            .kpi-icon-pill svg {
                width: 12px;
                height: 12px;
            }

            .kpi-delta-pill {
                font-size: 9.5px;
                padding: 2px 5px;
            }

            .quick-actions-grid {
                gap: 6px;
            }

            .btn-quick-act {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                text-align: center !important;
                padding: 8px 6px;
                font-size: 11px;
                gap: 5px;
                width: 100%;
                box-sizing: border-box;
            }

            .dow-pill-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 4px;
            }

            .dow-pill {
                padding: 6px 2px;
                font-size: 10px;
            }

            .silhouette-svg {
                max-width: 285px;
                max-height: 280px !important;
            }
        }
    </style>

    <!-- 1. HEADER PROFILE CARD -->
    <section class="member-hub-profile-card">
        <div class="profile-card-inner">
            <div class="profile-bio-group">
                <div class="profile-avatar-wrap">
                    <?php if (!empty($member['profile_picture'])): ?>
                        <img src="<?= h($member['profile_picture']) ?>" alt="<?= h($member['first_name']) ?>" class="profile-avatar-img">
                    <?php else: ?>
                        <div class="profile-avatar-img">
                            <?= strtoupper(substr($member['first_name'] ?? 'M', 0, 1) . substr($member['last_name'] ?? '', 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <span class="avatar-online-dot" title="Active"></span>
                </div>

                <div class="profile-info">
                    <div class="profile-name-row">
                        <h1 class="profile-name-title"><?= h($member['first_name'] . ' ' . $member['last_name']) ?></h1>
                        <span class="status-pill-active">Active Member</span>
                    </div>

                    <!-- Desktop Meta Pills (Hidden on small screens) -->
                    <div class="profile-desktop-meta">
                        <div class="profile-meta-pills">
                            <span class="meta-pill-item">
                                <span>Sex:</span> <strong><?= ucfirst(h($member['biological_sex'] ?? 'Not set')) ?></strong>
                            </span>
                            <span>•</span>
                            <span class="meta-pill-item">
                                <span>Age:</span> <strong><?= !empty($member['age']) ? h((string)$member['age']) . ' yrs' : '—' ?></strong>
                            </span>
                            <span>•</span>
                            <span class="meta-pill-item">
                                <span>Height:</span> <strong><?= $heightCm > 0 ? h(number_format($heightCm, 1)) . ' cm' : '—' ?></strong>
                            </span>
                            <span>•</span>
                            <span class="meta-pill-item">
                                <span>Weight:</span> <strong><?= $weightCurr > 0 ? h(number_format($weightCurr, 1)) . ' kg' : '—' ?></strong>
                            </span>
                        </div>

                        <div class="profile-meta-pills" style="margin-top: 2px;">
                            <?php if (!empty($member['primary_goal'])): ?>
                                <span class="meta-goal-chip">
                                    🎯 <?= h(str_replace('_', ' ', $member['primary_goal'])) ?>
                                </span>
                            <?php endif; ?>

                            <span class="meta-pill-item">
                                <span>Member Since:</span> <strong><?= date('M j, Y', strtotime($member['user_created_at'] ?? $member['created_at'])) ?></strong>
                            </span>
                            <span>•</span>
                            <span class="meta-pill-item">
                                <span>Training:</span> <strong><?= $weeklyTarget ?> days/week</strong>
                            </span>
                        </div>
                    </div>

                    <!-- Mobile Details Cards (Visible only on small screens) -->
                    <div class="profile-mobile-cards">
                        <div class="p-detail-card">
                            <span class="p-detail-label">Sex / Age</span>
                            <span class="p-detail-val"><?= ucfirst(h($member['biological_sex'] ?? '—')) ?>, <?= !empty($member['age']) ? h((string)$member['age']) . 'y' : '—' ?></span>
                        </div>
                        <div class="p-detail-card">
                            <span class="p-detail-label">Height</span>
                            <span class="p-detail-val"><?= $heightCm > 0 ? h(number_format($heightCm, 1)) . ' cm' : '—' ?></span>
                        </div>
                        <div class="p-detail-card">
                            <span class="p-detail-label">Weight</span>
                            <span class="p-detail-val"><?= $weightCurr > 0 ? h(number_format($weightCurr, 1)) . ' kg' : '—' ?></span>
                        </div>
                        <div class="p-detail-card">
                            <span class="p-detail-label">Training</span>
                            <span class="p-detail-val"><?= $weeklyTarget ?> days/wk</span>
                        </div>
                        <div class="p-detail-card p-detail-card-wide">
                            <span class="p-detail-label">Goal</span>
                            <span class="p-detail-val" style="color: var(--lime);">🎯 <?= h(ucwords(str_replace('_', ' ', $member['primary_goal'] ?? 'General Fitness'))) ?></span>
                        </div>
                        <div class="p-detail-card p-detail-card-wide">
                            <span class="p-detail-label">Member Since</span>
                            <span class="p-detail-val"><?= date('M j, Y', strtotime($member['user_created_at'] ?? $member['created_at'])) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Profile Actions -->
            <div class="profile-actions-group">
                <?php if ($user['role'] === 'trainer'): ?>
                    <button type="button" class="btn-hub-secondary" onclick="openNoteModal()">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <span>Message Client</span>
                    </button>
                    <a href="index.php?page=trainer&action=assign_plan&member_user_id=<?= (int)$memberId ?>" class="btn-hub-primary">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <span>Assign Workout</span>
                    </a>
                <?php else: ?>
                    <button type="button" class="btn-hub-secondary" onclick="openNoteModal()">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <span>Message Coach</span>
                    </button>
                    <a href="index.php?page=profile" class="btn-hub-secondary">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                        <span>Edit Profile</span>
                    </a>
                    <button type="button" class="btn-hub-primary" onclick="logProgress()">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <span>Log Progress</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- 2. NAVIGATION TABS -->
    <nav class="member-hub-tabs-nav" aria-label="Member Hub Tabs">
        <button type="button" class="hub-tab-btn active" id="hub-tab-btn-overview" onclick="switchHubTab('overview')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            <span>Overview</span>
        </button>
        <button type="button" class="hub-tab-btn" id="hub-tab-btn-measurements" onclick="switchHubTab('measurements')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
            <span>Measurements</span>
        </button>
        <button type="button" class="hub-tab-btn" id="hub-tab-btn-workout" onclick="switchHubTab('workout')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/><path d="m3 10 7-7"/><path d="m14 21 7-7"/></svg>
            <span>Workout Plan</span>
        </button>
        <button type="button" class="hub-tab-btn" id="hub-tab-btn-progress" onclick="switchHubTab('progress')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <span>Progress Logs</span>
            <?php if (!empty($rows)): ?>
                <span style="font-size: 11px; background: rgba(255,255,255,0.08); padding: 1px 6px; border-radius: 10px;"><?= count($rows) ?></span>
            <?php endif; ?>
        </button>
        <button type="button" class="hub-tab-btn" id="hub-tab-btn-notes" onclick="switchHubTab('notes')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <span>Notes</span>
            <?php if (!empty($notesFeed)): ?>
                <span style="font-size: 11px; background: rgba(255,255,255,0.08); padding: 1px 6px; border-radius: 10px;"><?= count($notesFeed) ?></span>
            <?php endif; ?>
        </button>
    </nav>

    <!-- 3. TAB PANE 1: OVERVIEW -->
    <div id="hub-pane-overview" class="hub-tab-pane active">
        <!-- TOP 4 KPI CARDS -->
        <div class="hub-kpi-grid">
            <!-- 1. Weight KPI -->
            <div class="hub-kpi-card">
                <div class="kpi-header">
                    <span class="kpi-title">Weight</span>
                    <div class="kpi-icon-pill">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 0 0-7.07 17.07L12 22l7.07-2.93A10 10 0 0 0 12 2z"/><circle cx="12" cy="11" r="3"/></svg>
                    </div>
                </div>
                <div class="kpi-value-row">
                    <span class="kpi-number"><?= $weightCurr > 0 ? h(number_format($weightCurr, 1)) : '—' ?></span>
                    <span class="kpi-unit">kg</span>
                </div>
                <div>
                    <?php if ($weightDelta !== null): ?>
                        <span class="kpi-delta-pill <?= $weightDelta > 0 ? 'kpi-delta-up-warn' : ($weightDelta < 0 ? 'kpi-delta-up-good' : 'kpi-delta-neutral') ?>">
                            <?= $weightDelta > 0 ? '+' : '' ?><?= $weightDelta ?> kg vs last mo
                        </span>
                    <?php else: ?>
                        <span class="kpi-delta-pill kpi-delta-neutral">Baseline logged</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 2. Height KPI -->
            <div class="hub-kpi-card">
                <div class="kpi-header">
                    <span class="kpi-title">Height</span>
                    <div class="kpi-icon-pill">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
                    </div>
                </div>
                <div class="kpi-value-row">
                    <span class="kpi-number"><?= $heightCm > 0 ? h(number_format($heightCm, 1)) : '—' ?></span>
                    <span class="kpi-unit">cm</span>
                </div>
                <div>
                    <span class="kpi-delta-pill kpi-delta-neutral">No change</span>
                </div>
            </div>

            <!-- 3. BMI KPI -->
            <div class="hub-kpi-card">
                <div class="kpi-header">
                    <span class="kpi-title">BMI Index</span>
                    <div class="kpi-icon-pill">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                    </div>
                </div>
                <div class="kpi-value-row">
                    <span class="kpi-number"><?= $bmi !== null ? h(number_format($bmi, 1)) : '—' ?></span>
                    <span class="kpi-unit">kg/m²</span>
                </div>
                <div>
                    <span class="kpi-delta-pill <?= $bmiClass ?>"><?= h($bmiCategory) ?></span>
                </div>
            </div>

            <!-- 4. Body Fat KPI -->
            <div class="hub-kpi-card">
                <div class="kpi-header">
                    <span class="kpi-title">Body Fat</span>
                    <div class="kpi-icon-pill">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
                    </div>
                </div>
                <div class="kpi-value-row">
                    <span class="kpi-number"><?= $bfCurr !== null ? h(number_format($bfCurr, 1)) : '—' ?></span>
                    <span class="kpi-unit">%</span>
                </div>
                <div>
                    <?php if ($bfDelta !== null): ?>
                        <span class="kpi-delta-pill <?= $bfDelta <= 0 ? 'kpi-delta-up-good' : 'kpi-delta-up-warn' ?>">
                            <?= $bfDelta > 0 ? '+' : '' ?><?= $bfDelta ?>% vs last mo
                        </span>
                    <?php else: ?>
                        <span class="kpi-delta-pill kpi-delta-neutral"><?= $bfCurr ? 'Navy formula estimate' : 'No records yet' ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- MAIN 2-COLUMN OVERVIEW GRID -->
        <div class="hub-overview-grid">
            <!-- LEFT COLUMN: Body Measurements & Current Workout Plan -->
            <div class="hub-col-left">
                <!-- BODY MEASUREMENTS WITH ANATOMICAL SILHOUETTE -->
                <div class="hub-card">
                    <div class="hub-card-header">
                        <div class="hub-card-title-group">
                            <h3>Body Measurements</h3>
                            <p>Real-time anatomical landmarks</p>
                        </div>
                        <button type="button" class="btn btn-sm btn-lime" onclick="logProgress()" style="font-size: 12px; padding: 6px 12px;">
                            Update
                        </button>
                    </div>

                    <div class="silhouette-container">
                        <!-- High-Tech Anatomical SVG Silhouette with Flanking Measurements -->
                        <svg class="silhouette-svg" viewBox="0 0 340 390" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <!-- Background glow ring -->
                            <ellipse cx="170" cy="195" rx="88" ry="165" stroke="var(--line)" stroke-width="1" stroke-dasharray="4 4" opacity="0.6"/>

                            <!-- Real Silhouette Contour Image (Male/Female from assets/silhoutte/) -->
                            <!-- Dark Theme Glowing Silhouette -->
                            <image id="silhouette-img-dark" class="silhouette-img-dark-theme" href="<?= $isFemale ? 'assets/silhoutte/woman_white.png' : 'assets/silhoutte/man_white.png' ?>" x="80" y="24" width="180" height="336" preserveAspectRatio="xMidYMid meet" />
                            <!-- Light Theme Slate Silhouette -->
                            <image id="silhouette-img-light" class="silhouette-img-light-theme" href="<?= $isFemale ? 'assets/silhoutte/woman_dark.png' : 'assets/silhoutte/man_dark.png' ?>" x="80" y="24" width="180" height="336" preserveAspectRatio="xMidYMid meet" />

                            <!-- Landmark Callout Lines & Indicator Dots & Measurement Badges -->
                            <!-- 1. Neck (Left): connects (170, 88) -> (84, 72) -->
                            <circle cx="170" cy="88" r="3.5" fill="var(--lime)"/>
                            <line x1="170" y1="88" x2="84" y2="72" stroke="var(--lime)" stroke-width="1.2" stroke-dasharray="2 2" opacity="0.85"/>
                            <circle cx="84" cy="72" r="2.5" fill="var(--lime)"/>

                            <g class="svg-landmark-badge">
                                <rect class="svg-badge-box" x="8" y="56" width="76" height="32" rx="6"/>
                                <circle class="svg-badge-dot" cx="17" cy="65" r="2"/>
                                <text class="svg-badge-label" x="23" y="68">NECK</text>
                                <text class="svg-badge-val <?= $recentNeck ? 'has-val' : '' ?>" x="17" y="82"><?= $recentNeck ? h(number_format((float)$recentNeck, 1)) . ' cm' : '—' ?></text>
                            </g>

                            <!-- 2. Chest (Right): connects (170, 126) -> (256, 120) -->
                            <circle cx="170" cy="126" r="3.5" fill="var(--lime)"/>
                            <line x1="170" y1="126" x2="256" y2="120" stroke="var(--lime)" stroke-width="1.2" stroke-dasharray="2 2" opacity="0.85"/>
                            <circle cx="256" cy="120" r="2.5" fill="var(--lime)"/>

                            <g class="svg-landmark-badge">
                                <rect class="svg-badge-box" x="256" y="104" width="76" height="32" rx="6"/>
                                <circle class="svg-badge-dot" cx="265" cy="113" r="2"/>
                                <text class="svg-badge-label" x="271" y="116">CHEST</text>
                                <text class="svg-badge-val <?= $recentChest ? 'has-val' : '' ?>" x="265" y="130"><?= $recentChest ? h(number_format((float)$recentChest, 1)) . ' cm' : '—' ?></text>
                            </g>

                            <!-- 3. Arms (Left): connects (130, 160) -> (84, 162) -->
                            <circle cx="130" cy="160" r="3.5" fill="var(--lime)"/>
                            <line x1="130" y1="160" x2="84" y2="162" stroke="var(--lime)" stroke-width="1.2" stroke-dasharray="2 2" opacity="0.85"/>
                            <circle cx="84" cy="162" r="2.5" fill="var(--lime)"/>

                            <g class="svg-landmark-badge">
                                <rect class="svg-badge-box" x="8" y="146" width="76" height="32" rx="6"/>
                                <circle class="svg-badge-dot" cx="17" cy="155" r="2"/>
                                <text class="svg-badge-label" x="23" y="158">ARMS</text>
                                <text class="svg-badge-val <?= $recentArm ? 'has-val' : '' ?>" x="17" y="172"><?= $recentArm ? h(number_format((float)$recentArm, 1)) . ' cm' : '—' ?></text>
                            </g>

                            <!-- 4. Waist (Right): connects (170, 194) -> (256, 196) -->
                            <circle cx="170" cy="194" r="3.5" fill="var(--lime)"/>
                            <line x1="170" y1="194" x2="256" y2="196" stroke="var(--lime)" stroke-width="1.2" stroke-dasharray="2 2" opacity="0.85"/>
                            <circle cx="256" cy="196" r="2.5" fill="var(--lime)"/>

                            <g class="svg-landmark-badge">
                                <rect class="svg-badge-box" x="256" y="180" width="76" height="32" rx="6"/>
                                <circle class="svg-badge-dot" cx="265" cy="189" r="2"/>
                                <text class="svg-badge-label" x="271" y="192">WAIST</text>
                                <text class="svg-badge-val <?= $recentWaist ? 'has-val' : '' ?>" x="265" y="206"><?= $recentWaist ? h(number_format((float)$recentWaist, 1)) . ' cm' : '—' ?></text>
                            </g>

                            <!-- 5. Hips (Left): connects (170, 238) -> (84, 242) -->
                            <circle cx="170" cy="238" r="3.5" fill="var(--lime)"/>
                            <line x1="170" y1="238" x2="84" y2="242" stroke="var(--lime)" stroke-width="1.2" stroke-dasharray="2 2" opacity="0.85"/>
                            <circle cx="84" cy="242" r="2.5" fill="var(--lime)"/>

                            <g class="svg-landmark-badge">
                                <rect class="svg-badge-box" x="8" y="226" width="76" height="32" rx="6"/>
                                <circle class="svg-badge-dot" cx="17" cy="235" r="2"/>
                                <text class="svg-badge-label" x="23" y="238">HIPS</text>
                                <text class="svg-badge-val <?= $recentHips ? 'has-val' : '' ?>" x="17" y="252"><?= $recentHips ? h(number_format((float)$recentHips, 1)) . ' cm' : '—' ?></text>
                            </g>
                        </svg>
                    </div>
                </div>

                <!-- CURRENT WORKOUT PLAN CARD -->
                <div class="hub-card">
                    <div class="hub-card-header">
                        <div class="hub-card-title-group">
                            <h3>Current Workout Plan</h3>
                            <p><?= $activePlan ? h($activePlan['title']) : 'Standard Fitness Routine' ?></p>
                        </div>
                        <span class="routine-split-tag"><?= $weeklyTarget ?> days/wk</span>
                    </div>

                    <div>
                        <!-- Consistency Progress Bar -->
                        <div style="display: flex; justify-content: space-between; align-items: baseline; font-size: 13px; margin-bottom: 6px;">
                            <span style="color: var(--muted); font-weight: 600;">This Week's Consistency</span>
                            <strong style="color: var(--ink);"><?= $workoutsThisWeek ?> / <?= $weeklyTarget ?> completed</strong>
                        </div>
                        <div style="height: 7px; background: var(--panel-soft); border-radius: 999px; overflow: hidden; margin-bottom: 16px;">
                            <div style="height: 100%; width: <?= $weeklyPct ?>%; background: var(--lime); border-radius: 999px; transition: width 0.3s ease;"></div>
                        </div>

                        <!-- Days of Week Pills -->
                        <?php
                        $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
                        ?>
                        <div class="dow-pill-grid">
                            <?php foreach ($dayNames as $dowNum => $dayLabel): 
                                $hasDayExercises = !empty($exercisesByDow[$dowNum]);
                                $exCount = $hasDayExercises ? count($exercisesByDow[$dowNum]) : 0;
                            ?>
                                <div class="dow-pill <?= $hasDayExercises ? 'has-workout' : '' ?>">
                                    <span class="day-name"><?= $dayLabel ?></span>
                                    <span class="workout-count"><?= $hasDayExercises ? $exCount . ' ex' : 'Rest' ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div style="margin-top: 14px; text-align: right;">
                            <a href="index.php?page=my_workout" class="btn btn-sm btn-secondary" style="font-size: 12px; font-weight: 700;">
                                View Full Routine & Schedule →
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT COLUMN: Progress Overview Chart & Recent Activity -->
            <div class="hub-col-right">
                <!-- PROGRESS OVERVIEW DYNAMIC CHART -->
                <div class="hub-card">
                    <div class="hub-card-header">
                        <div class="hub-card-title-group">
                            <h3>Progress Overview</h3>
                            <p>Visual trend across key performance indicators</p>
                        </div>

                        <!-- Metric Toggle -->
                        <div class="metric-toggle-group">
                            <button type="button" class="toggle-btn active" id="m-btn-weight" onclick="setChartMetric('weight')">Weight</button>
                            <button type="button" class="toggle-btn" id="m-btn-body_fat" onclick="setChartMetric('body_fat')">Body Fat</button>
                            <button type="button" class="toggle-btn" id="m-btn-bmi" onclick="setChartMetric('bmi')">BMI</button>
                        </div>
                    </div>

                    <!-- Timeframe Toggle -->
                    <div class="chart-controls-row">
                        <span style="font-size: 12px; color: var(--muted); font-weight: 600;" id="chartActiveMetricLabel">Weight (kg) over time</span>
                        <div class="timeframe-toggle-group">
                            <button type="button" class="toggle-btn" id="tf-btn-1m" onclick="setChartTimeframe('1m')">1M</button>
                            <button type="button" class="toggle-btn" id="tf-btn-3m" onclick="setChartTimeframe('3m')">3M</button>
                            <button type="button" class="toggle-btn" id="tf-btn-6m" onclick="setChartTimeframe('6m')">6M</button>
                            <button type="button" class="toggle-btn active" id="tf-btn-all" onclick="setChartTimeframe('all')">All</button>
                        </div>
                    </div>

                    <!-- Chart Canvas -->
                    <div class="chart-canvas-wrap">
                        <canvas id="hubTrendChart"></canvas>
                    </div>

                    <!-- Summary Deltas Below Chart -->
                    <?php
                    $firstLog = !empty($rows) ? $rows[count($rows) - 1] : null;
                    $totalWeightChange = ($firstLog && $weightCurr > 0 && !empty($firstLog['weight_kg'])) ? round($weightCurr - (float)$firstLog['weight_kg'], 1) : 0.0;
                    $firstBf = ($firstLog && !empty($firstLog['body_fat_percent'])) ? (float)$firstLog['body_fat_percent'] : null;
                    $totalBfChange = ($firstBf !== null && $bfCurr !== null) ? round($bfCurr - $firstBf, 1) : null;
                    $firstBmi = ($firstLog && $heightCm > 0 && !empty($firstLog['weight_kg'])) ? round((float)$firstLog['weight_kg'] / pow($heightCm/100, 2), 1) : null;
                    $totalBmiChange = ($firstBmi !== null && $bmi !== null) ? round($bmi - $firstBmi, 1) : null;
                    ?>
                    <div class="chart-summary-stats">
                        <div class="summary-stat-box">
                            <span class="stat-label">Weight Change</span>
                            <span class="stat-delta" style="color: <?= $totalWeightChange < 0 ? '#22c55e' : ($totalWeightChange > 0 ? '#f59e0b' : 'var(--ink)') ?>;">
                                <?= $totalWeightChange > 0 ? '+' : '' ?><?= $totalWeightChange ?> kg
                            </span>
                        </div>
                        <div class="summary-stat-box">
                            <span class="stat-label">Body Fat Change</span>
                            <span class="stat-delta" style="color: <?= ($totalBfChange !== null && $totalBfChange < 0) ? '#22c55e' : 'var(--ink)' ?>;">
                                <?= $totalBfChange !== null ? ($totalBfChange > 0 ? '+' : '') . $totalBfChange . '%' : '—' ?>
                            </span>
                        </div>
                        <div class="summary-stat-box">
                            <span class="stat-label">BMI Change</span>
                            <span class="stat-delta" style="color: <?= ($totalBmiChange !== null && $totalBmiChange < 0) ? '#22c55e' : 'var(--ink)' ?>;">
                                <?= $totalBmiChange !== null ? ($totalBmiChange > 0 ? '+' : '') . $totalBmiChange : '—' ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- RECENT ACTIVITY & QUICK ACTIONS ROW -->
                <div class="hub-bottom-grid">
                    <!-- RECENT ACTIVITY -->
                    <div class="hub-card" style="margin-bottom: 0;">
                        <div class="hub-card-header">
                            <div class="hub-card-title-group">
                                <h3>Recent Activity</h3>
                                <p>Latest logs and workouts</p>
                            </div>
                        </div>

                        <div class="timeline-feed">
                            <?php if ($recentActivities): ?>
                                <?php foreach ($recentActivities as $act): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-icon">
                                            <?php if ($act['icon'] === 'scale'): ?>
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 0 0-7.07 17.07L12 22l7.07-2.93A10 10 0 0 0 12 2z"/></svg>
                                            <?php elseif ($act['icon'] === 'dumbbell'): ?>
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6.5 6.5 11 11"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/></svg>
                                            <?php else: ?>
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                            <?php endif; ?>
                                        </div>
                                        <div class="timeline-content">
                                            <span class="timeline-title"><?= h($act['title']) ?></span>
                                            <span class="timeline-desc"><?= h($act['desc']) ?></span>
                                            <span class="timeline-time"><?= h($act['time_text']) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p style="color: var(--muted); font-size: 13px; margin: 0; padding: 12px 0;">No recorded activity yet. Click "Log Progress" to begin!</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- QUICK ACTIONS & PRO TIP -->
                    <div class="hub-card" style="margin-bottom: 0;">
                        <div class="hub-card-header">
                            <div class="hub-card-title-group">
                                <h3>Quick Actions</h3>
                                <p>Fast shortcuts</p>
                            </div>
                        </div>

                        <div class="quick-actions-grid">
                            <button type="button" class="btn-quick-act" onclick="logProgress()">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v8"/><path d="M8 12h8"/></svg>
                                <span>Log Progress</span>
                            </button>
                            <button type="button" class="btn-quick-act" onclick="calculateBodyFat(event)">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
                                <span>Body Fat Calc</span>
                            </button>
                            <a href="index.php?page=diet" class="btn-quick-act">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="2" x2="6" y2="5"/><line x1="10" y1="2" x2="10" y2="5"/><line x1="14" y1="2" x2="14" y2="5"/></svg>
                                <span>Dietary Plan</span>
                            </a>
                            <button type="button" class="btn-quick-act" onclick="openNoteModal()">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                <span>Leave Note</span>
                            </button>
                        </div>

                        <!-- Pro Tip Widget -->
                        <div class="tip-callout-card">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                            <div>
                                <strong style="display:block; margin-bottom: 2px;">Consistency Pro Tip</strong>
                                Weigh yourself at the same time each morning after waking up for the most reliable long-term trend line.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. TAB PANE 2: MEASUREMENTS -->
    <div id="hub-pane-measurements" class="hub-tab-pane">
        <div class="hub-card">
            <div class="hub-card-header">
                <div class="hub-card-title-group">
                    <h3>Anatomical Measurement Breakdown</h3>
                    <p>Track circumference metrics over time for muscle building and fat loss</p>
                </div>
                <div class="hub-header-actions">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="calculateBodyFat(event)">
                        Navy Body Fat Calculator
                    </button>
                    <button type="button" class="btn btn-sm btn-lime" onclick="logProgress()">
                        Update Measurements
                    </button>
                </div>
            </div>

            <!-- Desktop View: Table -->
            <div class="measurements-desktop-table table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Metric Area</th>
                            <th>Current Value</th>
                            <th>Target / Goal</th>
                            <th>Status / Delta</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Neck</strong></td>
                            <td><?= $recentNeck ? h(number_format((float)$recentNeck, 1)) . ' cm' : '—' ?></td>
                            <td>—</td>
                            <td><span class="badge badge-info">Circumference</span></td>
                            <td><button class="btn btn-xs btn-secondary" onclick="logProgress()">Edit</button></td>
                        </tr>
                        <tr>
                            <td><strong>Chest</strong></td>
                            <td><?= $recentChest ? h(number_format((float)$recentChest, 1)) . ' cm' : '—' ?></td>
                            <td><?= !empty($member['target_chest_cm']) ? h(number_format((float)$member['target_chest_cm'], 1)) . ' cm' : '—' ?></td>
                            <td>
                                <?php if (!empty($member['target_chest_cm']) && $recentChest): ?>
                                    <span class="badge badge-lime"><?= round((float)$member['target_chest_cm'] - (float)$recentChest, 1) ?> cm to target</span>
                                <?php else: ?>
                                    <span class="badge badge-info">Torso</span>
                                <?php endif; ?>
                            </td>
                            <td><button class="btn btn-xs btn-secondary" onclick="logProgress()">Edit</button></td>
                        </tr>
                        <tr>
                            <td><strong>Arms / Biceps</strong></td>
                            <td><?= $recentArm ? h(number_format((float)$recentArm, 1)) . ' cm' : '—' ?></td>
                            <td><?= !empty($member['target_arm_cm']) ? h(number_format((float)$member['target_arm_cm'], 1)) . ' cm' : '—' ?></td>
                            <td>
                                <?php if (!empty($member['target_arm_cm']) && $recentArm): ?>
                                    <span class="badge badge-lime"><?= round((float)$member['target_arm_cm'] - (float)$recentArm, 1) ?> cm to target</span>
                                <?php else: ?>
                                    <span class="badge badge-info">Upper Limb</span>
                                <?php endif; ?>
                            </td>
                            <td><button class="btn btn-xs btn-secondary" onclick="logProgress()">Edit</button></td>
                        </tr>
                        <tr>
                            <td><strong>Waist</strong></td>
                            <td><?= $recentWaist ? h(number_format((float)$recentWaist, 1)) . ' cm' : '—' ?></td>
                            <td><?= !empty($member['target_waist_cm']) ? h(number_format((float)$member['target_waist_cm'], 1)) . ' cm' : '—' ?></td>
                            <td>
                                <?php if (!empty($member['target_waist_cm']) && $recentWaist): ?>
                                    <span class="badge badge-lime"><?= round((float)$recentWaist - (float)$member['target_waist_cm'], 1) ?> cm to reduce</span>
                                <?php else: ?>
                                    <span class="badge badge-info">Core</span>
                                <?php endif; ?>
                            </td>
                            <td><button class="btn btn-xs btn-secondary" onclick="logProgress()">Edit</button></td>
                        </tr>
                        <tr>
                            <td><strong>Hips</strong></td>
                            <td><?= $recentHips ? h(number_format((float)$recentHips, 1)) . ' cm' : '—' ?></td>
                            <td>—</td>
                            <td><span class="badge badge-info">Pelvic</span></td>
                            <td><button class="btn btn-xs btn-secondary" onclick="logProgress()">Edit</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile View: Cards -->
            <div class="measurements-mobile-cards">
                <?php
                $measurementMetrics = [
                    ['label' => 'Neck', 'current' => $recentNeck, 'target' => null, 'cat' => 'Circumference'],
                    ['label' => 'Chest', 'current' => $recentChest, 'target' => $member['target_chest_cm'] ?? null, 'cat' => 'Torso'],
                    ['label' => 'Arms / Biceps', 'current' => $recentArm, 'target' => $member['target_arm_cm'] ?? null, 'cat' => 'Upper Limb'],
                    ['label' => 'Waist', 'current' => $recentWaist, 'target' => $member['target_waist_cm'] ?? null, 'cat' => 'Core'],
                    ['label' => 'Hips', 'current' => $recentHips, 'target' => null, 'cat' => 'Pelvic'],
                ];
                foreach ($measurementMetrics as $mm): 
                    $hasVal = !empty($mm['current']);
                    $hasTarget = !empty($mm['target']);
                ?>
                    <div class="mobile-detail-card">
                        <div class="mdc-header">
                            <strong class="mdc-title"><?= h($mm['label']) ?></strong>
                            <span class="badge badge-info"><?= h($mm['cat']) ?></span>
                        </div>
                        <div class="mdc-body-grid">
                            <div class="mdc-stat">
                                <span class="mdc-label">Current Value</span>
                                <span class="mdc-val <?= $hasVal ? 'val-highlight' : '' ?>"><?= $hasVal ? h(number_format((float)$mm['current'], 1)) . ' cm' : '—' ?></span>
                            </div>
                            <div class="mdc-stat">
                                <span class="mdc-label">Target Goal</span>
                                <span class="mdc-val"><?= $hasTarget ? h(number_format((float)$mm['target'], 1)) . ' cm' : '—' ?></span>
                            </div>
                        </div>
                        <div class="mdc-footer">
                            <button type="button" class="btn btn-sm btn-secondary w-100" onclick="logProgress()">
                                Update <?= h($mm['label']) ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- 5. TAB PANE 3: WORKOUT PLAN -->
    <div id="hub-pane-workout" class="hub-tab-pane">
        <div class="hub-card">
            <div class="hub-card-header">
                <div class="hub-card-title-group">
                    <h3>Active Training Routine</h3>
                    <p><?= $activePlan ? h($activePlan['title']) : 'No active training plan assigned yet.' ?></p>
                </div>
                <div class="hub-header-actions">
                    <?php if ($user['role'] === 'trainer'): ?>
                        <a href="index.php?page=trainer&action=assign_plan&member_user_id=<?= (int)$memberId ?>" class="btn btn-sm btn-lime">
                            Edit / Assign Plan
                        </a>
                    <?php else: ?>
                        <a href="index.php?page=my_workout" class="btn btn-sm btn-lime">
                            Interactive Workout View
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($activePlan && !empty($exercisesByDow)): ?>
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <?php foreach ($dayNames as $dowNum => $dayLabel): 
                        if (empty($exercisesByDow[$dowNum])) continue;
                        $dayExList = $exercisesByDow[$dowNum];
                    ?>
                        <div style="background: var(--bg); border: 1px solid var(--line); border-radius: 14px; padding: 16px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 8px;">
                                <h4 style="margin: 0; font-size: 1.05rem; color: var(--ink); font-weight: 700;">
                                    <?= $dayLabel ?> — Workout Day
                                </h4>
                                <span class="badge badge-lime"><?= count($dayExList) ?> exercises</span>
                            </div>

                            <!-- Desktop View: Table -->
                            <div class="workout-desktop-table table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Exercise</th>
                                            <th>Muscle Group</th>
                                            <th>Sets</th>
                                            <th>Reps</th>
                                            <th>Target Weight</th>
                                            <th>Rest</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dayExList as $dex): ?>
                                            <tr>
                                                <td><strong><?= h($dex['exercise_name']) ?></strong></td>
                                                <td><span class="badge badge-info"><?= h(ucwords(str_replace('_', ' ', (string)($dex['muscle_group'] ?? '')))) ?></span></td>
                                                <td><?= $dex['sets'] ?? 3 ?> sets</td>
                                                <td><?= h((string)($dex['reps'] ?? '10-12')) ?></td>
                                                <td><?= !empty($dex['target_weight_kg']) ? h($dex['target_weight_kg']) . ' kg' : 'Bodyweight' ?></td>
                                                <td><?= !empty($dex['rest_seconds']) ? h($dex['rest_seconds']) . 's' : '60s' ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Mobile View: Cards -->
                            <div class="workout-mobile-cards">
                                <?php foreach ($dayExList as $dex): ?>
                                    <div class="mobile-detail-card">
                                        <div class="mdc-header">
                                            <strong class="mdc-title"><?= h($dex['exercise_name']) ?></strong>
                                            <span class="badge badge-info"><?= h(ucwords(str_replace('_', ' ', (string)($dex['muscle_group'] ?? '')))) ?></span>
                                        </div>
                                        <div class="mdc-body-grid" style="grid-template-columns: repeat(3, 1fr);">
                                            <div class="mdc-stat">
                                                <span class="mdc-label">Sets × Reps</span>
                                                <span class="mdc-val"><?= $dex['sets'] ?? 3 ?> × <?= h((string)($dex['reps'] ?? '10-12')) ?></span>
                                            </div>
                                            <div class="mdc-stat">
                                                <span class="mdc-label">Target</span>
                                                <span class="mdc-val"><?= !empty($dex['target_weight_kg']) ? h($dex['target_weight_kg']) . 'kg' : 'Bodywt' ?></span>
                                            </div>
                                            <div class="mdc-stat">
                                                <span class="mdc-label">Rest</span>
                                                <span class="mdc-val"><?= !empty($dex['rest_seconds']) ? h($dex['rest_seconds']) . 's' : '60s' ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 48px 20px;">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color: var(--muted); margin-bottom: 12px;"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/></svg>
                    <h4 style="margin: 0 0 6px;">No workout plan configured yet</h4>
                    <p style="color: var(--muted); margin: 0 0 16px;">Generate a plan automatically based on your fitness goals or consult your gym trainer.</p>
                    <form method="post" action="index.php?page=my_workout" style="display: inline-block;">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-lime">Generate Workout Plan</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 6. TAB PANE 4: PROGRESS LOGS -->
    <div id="hub-pane-progress" class="hub-tab-pane">
        <div class="hub-card">
            <div class="hub-card-header">
                <div class="hub-card-title-group">
                    <h3>Historical Progress Logs</h3>
                    <p>Complete chronological archive of all weight and body measurement logs</p>
                </div>
                <div class="hub-header-actions">
                    <button type="button" class="btn btn-sm btn-lime" onclick="logProgress()">
                        + Log Progress
                    </button>
                </div>
            </div>

            <!-- Desktop View: Table -->
            <div class="progress-desktop-table table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Weight</th>
                            <th>Body Fat %</th>
                            <th>Waist</th>
                            <th>Chest</th>
                            <th>Arm</th>
                            <th>Hips</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows): ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><strong><?= date('M j, Y', strtotime($row['log_date'])) ?></strong></td>
                                    <td><?= !empty($row['weight_kg']) ? h(number_format((float)$row['weight_kg'], 1)) . ' kg' : '—' ?></td>
                                    <td><?= !empty($row['body_fat_percent']) ? h(number_format((float)$row['body_fat_percent'], 1)) . '%' : '—' ?></td>
                                    <td><?= !empty($row['waist_cm']) ? h(number_format((float)$row['waist_cm'], 1)) . ' cm' : '—' ?></td>
                                    <td><?= !empty($row['chest_cm']) ? h(number_format((float)$row['chest_cm'], 1)) . ' cm' : '—' ?></td>
                                    <td><?= !empty($row['arm_cm']) ? h(number_format((float)$row['arm_cm'], 1)) . ' cm' : '—' ?></td>
                                    <td><?= !empty($row['hips_cm']) ? h(number_format((float)$row['hips_cm'], 1)) . ' cm' : '—' ?></td>
                                    <td><?= !empty($row['notes']) ? h($row['notes']) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 36px; color: var(--muted);">
                                    No progress logs recorded yet. Click "+ Log Progress" to record your baseline metrics.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile View: Cards -->
            <div class="progress-mobile-cards">
                <?php if ($rows): ?>
                    <?php foreach ($rows as $row): ?>
                        <div class="mobile-detail-card">
                            <div class="mdc-header">
                                <div style="display: flex; align-items: center; gap: 7px; color: var(--ink);">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                                    <strong><?= date('M j, Y', strtotime($row['log_date'])) ?></strong>
                                </div>
                                <span class="badge badge-lime" style="font-size: 13.5px; font-weight: 800;">
                                    <?= !empty($row['weight_kg']) ? h(number_format((float)$row['weight_kg'], 1)) . ' kg' : '—' ?>
                                </span>
                            </div>
                            <div class="mdc-body-grid" style="grid-template-columns: repeat(3, 1fr); margin-top: 4px;">
                                <div class="mdc-stat">
                                    <span class="mdc-label">Body Fat</span>
                                    <span class="mdc-val"><?= !empty($row['body_fat_percent']) ? h(number_format((float)$row['body_fat_percent'], 1)) . '%' : '—' ?></span>
                                </div>
                                <div class="mdc-stat">
                                    <span class="mdc-label">Waist</span>
                                    <span class="mdc-val"><?= !empty($row['waist_cm']) ? h(number_format((float)$row['waist_cm'], 1)) . ' cm' : '—' ?></span>
                                </div>
                                <div class="mdc-stat">
                                    <span class="mdc-label">Chest</span>
                                    <span class="mdc-val"><?= !empty($row['chest_cm']) ? h(number_format((float)$row['chest_cm'], 1)) . ' cm' : '—' ?></span>
                                </div>
                                <div class="mdc-stat">
                                    <span class="mdc-label">Arms</span>
                                    <span class="mdc-val"><?= !empty($row['arm_cm']) ? h(number_format((float)$row['arm_cm'], 1)) . ' cm' : '—' ?></span>
                                </div>
                                <div class="mdc-stat">
                                    <span class="mdc-label">Hips</span>
                                    <span class="mdc-val"><?= !empty($row['hips_cm']) ? h(number_format((float)$row['hips_cm'], 1)) . ' cm' : '—' ?></span>
                                </div>
                            </div>
                            <?php if (!empty($row['notes'])): ?>
                                <div style="display: flex; align-items: flex-start; gap: 7px; margin-top: 6px; padding: 8px 10px; background: rgba(255,255,255,0.03); border-radius: 8px; font-size: 12.5px; color: var(--muted); border: 1px solid var(--line);">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-top: 2px; flex-shrink: 0;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                    <span><?= h($row['notes']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 32px 16px; color: var(--muted); background: var(--bg); border-radius: 12px; border: 1px dashed var(--line);">
                        No progress logs recorded yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 7. TAB PANE 5: NOTES -->
    <div id="hub-pane-notes" class="hub-tab-pane">
        <div class="hub-card">
            <div class="hub-card-header">
                <div class="hub-card-title-group">
                    <h3>Coaching & Check-In Notes</h3>
                    <p>Communication timeline between coach and client</p>
                </div>
                <div class="hub-header-actions">
                    <button type="button" class="btn btn-sm btn-lime" onclick="openNoteModal()">
                        + Add Note
                    </button>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 14px;">
                <?php if ($notesFeed): ?>
                    <?php foreach ($notesFeed as $note): ?>
                        <div class="mobile-detail-card" style="padding: 16px;">
                            <div class="note-card-header">
                                <div class="note-author-group">
                                    <strong class="note-author-name"><?= h($note['author']) ?></strong>
                                    <span class="badge <?= $note['badge_cls'] ?>"><?= h($note['badge']) ?></span>
                                </div>
                                <span class="note-date-text"><?= h($note['date']) ?></span>
                            </div>
                            <p style="margin: 0; color: var(--ink); font-size: 13.5px; line-height: 1.5; white-space: pre-wrap;"><?= h($note['content']) ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: var(--muted);">
                        No notes or messages recorded yet. Click "+ Add Note" to post an update.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- JAVASCRIPT: TAB SWITCHER, DYNAMIC CHART.JS, LOG PROGRESS & NAVY CALCULATOR -->
    <script>
    // Tab Switching Logic with Hash Support
    function switchHubTab(tabName) {
        const tabs = ['overview', 'measurements', 'workout', 'progress', 'notes'];
        tabs.forEach(t => {
            const btn = document.getElementById('hub-tab-btn-' + t);
            const pane = document.getElementById('hub-pane-' + t);
            if (t === tabName) {
                if (btn) btn.classList.add('active');
                if (pane) pane.classList.add('active');
            } else {
                if (btn) btn.classList.remove('active');
                if (pane) pane.classList.remove('active');
            }
        });
        try {
            history.replaceState(null, null, '#' + tabName);
        } catch(e) {}

        if (tabName === 'overview' && window.hubChartInstance) {
            window.hubChartInstance.resize();
        }
    }

    // Auto-select tab if URL has hash
    window.addEventListener('DOMContentLoaded', () => {
        const hash = window.location.hash.replace('#', '');
        if (['overview', 'measurements', 'workout', 'progress', 'notes'].includes(hash)) {
            switchHubTab(hash);
        }
        initHubChart();
    });

    // Chart.js Data & Logic
    const rawChartData = <?= json_encode($chartDataPoints, JSON_HEX_TAG) ?>;
    let currentMetric = 'weight';
    let currentTimeframe = 'all';

    function setChartMetric(metric) {
        currentMetric = metric;
        ['weight', 'body_fat', 'bmi'].forEach(m => {
            const btn = document.getElementById('m-btn-' + m);
            if (btn) btn.classList.toggle('active', m === metric);
        });

        const labelMap = {
            'weight': 'Weight (kg) over time',
            'body_fat': 'Body Fat (%) over time',
            'bmi': 'Body Mass Index (BMI) over time'
        };
        const labelEl = document.getElementById('chartActiveMetricLabel');
        if (labelEl) labelEl.textContent = labelMap[metric] || 'Progress over time';

        renderFilteredChart();
    }

    function setChartTimeframe(tf) {
        currentTimeframe = tf;
        ['1m', '3m', '6m', 'all'].forEach(t => {
            const btn = document.getElementById('tf-btn-' + t);
            if (btn) btn.classList.toggle('active', t === tf);
        });
        renderFilteredChart();
    }

    function renderFilteredChart() {
        if (!window.hubChartInstance) return;

        // Filter data by timeframe
        const now = new Date();
        let cutoffDate = null;
        if (currentTimeframe === '1m') {
            cutoffDate = new Date();
            cutoffDate.setMonth(now.getMonth() - 1);
        } else if (currentTimeframe === '3m') {
            cutoffDate = new Date();
            cutoffDate.setMonth(now.getMonth() - 3);
        } else if (currentTimeframe === '6m') {
            cutoffDate = new Date();
            cutoffDate.setMonth(now.getMonth() - 6);
        }

        const filtered = rawChartData.filter(d => {
            if (!cutoffDate) return true;
            return new Date(d.date) >= cutoffDate;
        });

        const labels = filtered.map(d => d.label);
        const dataValues = filtered.map(d => d[currentMetric]);

        const metricLabels = {
            'weight': 'Weight (kg)',
            'body_fat': 'Body Fat (%)',
            'bmi': 'BMI'
        };

        window.hubChartInstance.data.labels = labels;
        window.hubChartInstance.data.datasets[0].label = metricLabels[currentMetric];
        window.hubChartInstance.data.datasets[0].data = dataValues;
        window.hubChartInstance.update();
    }

    function initHubChart() {
        const ctx = document.getElementById('hubTrendChart');
        if (!ctx) return;

        const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
        const gridColor  = isDark ? 'rgba(255,255,255,0.06)' : '#e2e8f0';
        const tickColor  = isDark ? '#94a3b8' : '#64748b';
        const axisBorder = isDark ? 'rgba(255,255,255,0.12)' : '#cbd5e1';

        const labels = rawChartData.map(d => d.label);
        const weights = rawChartData.map(d => d.weight);

        window.hubChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Weight (kg)',
                    data: weights,
                    borderColor: '#c7ff22',
                    backgroundColor: 'rgba(199, 255, 34, 0.12)',
                    borderWidth: 2.8,
                    pointRadius: 4.5,
                    pointBackgroundColor: isDark ? '#c7ff22' : '#ffffff',
                    pointBorderColor: '#c7ff22',
                    pointBorderWidth: 2,
                    tension: 0.35,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isDark ? '#1e2430' : '#ffffff',
                        titleColor: isDark ? '#ffffff' : '#0f172a',
                        bodyColor: isDark ? '#cbd5e1' : '#334155',
                        borderColor: isDark ? 'rgba(255,255,255,0.1)' : '#e2e8f0',
                        borderWidth: 1,
                        padding: 10,
                        boxPadding: 4,
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        grid: { color: gridColor },
                        border: { color: axisBorder },
                        ticks: { color: tickColor, font: { weight: '600', size: 11 } }
                    },
                    x: {
                        grid: { color: gridColor },
                        border: { color: axisBorder },
                        ticks: { color: tickColor, font: { weight: '600', size: 11 } }
                    }
                }
            }
        });
    }

    // Modal: Note submission
    function openNoteModal() {
        Swal.fire({
            title: 'Add Check-In Note',
            html: `
                <form id="noteForm" method="post" style="text-align: left; margin-top: 10px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="quick_note">
                    <?php if ($user['role'] === 'trainer'): ?>
                        <input type="hidden" name="member_user_id" value="<?= (int) $memberId ?>">
                    <?php endif; ?>
                    <label style="display:block; color:var(--muted); font-size:13px; margin-bottom:6px;">Your Note or Feedback:</label>
                    <textarea name="note_text" class="form-control" rows="4" placeholder="Enter notes or updates..." required style="width:100%; box-sizing:border-box; border-radius:8px;"></textarea>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Save Note',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const f = document.getElementById('noteForm');
                if (!f.note_text.value.trim()) {
                    Swal.showValidationMessage('Please write a note.');
                    return false;
                }
                f.submit();
            }
        });
    }

    // Modal: U.S. Navy Method Body Fat Calculator
    function calculateBodyFat(e) {
        if (e) e.preventDefault();
        
        const currentForm = document.getElementById('progressForm');
        let savedState = null;
        if (currentForm) {
            savedState = {
                log_date: currentForm.log_date.value,
                weight_kg: currentForm.weight_kg.value,
                body_fat_percent: currentForm.body_fat_percent.value,
                neck_cm: currentForm.neck_cm ? currentForm.neck_cm.value : '',
                waist_cm: currentForm.waist_cm.value,
                chest_cm: currentForm.chest_cm.value,
                arm_cm: currentForm.arm_cm.value,
                hips_cm: currentForm.hips_cm ? currentForm.hips_cm.value : '',
                notes: currentForm.notes.value
            };
        }
        
        const savedBfSettings = JSON.parse(localStorage.getItem('fittracks_bf_settings') || '{}');
        const defaultGender = savedBfSettings.gender || '<?= h(strtolower((string)($member['biological_sex'] ?? 'male'))) ?>';
        const defaultHeight = savedBfSettings.height || '<?= h((string)$heightCm) ?>';
        const defaultNeck = savedBfSettings.neck || '<?= h((string)$recentNeck) ?>';
        const defaultHip = savedBfSettings.hip || '<?= h((string)$recentHips) ?>';
        
        Swal.fire({
            title: 'Estimate Body Fat %',
            html: `
                <div style="text-align: left; font-size: 13.5px; color: var(--muted); margin-bottom: 15px;">
                    This estimate uses the standard <strong>U.S. Navy Circumference Formula</strong>.
                </div>
                <form id="bfCalcForm" style="text-align: left; display: flex; flex-direction: column; gap: 12px;">
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Biological Sex *
                            <select name="gender" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                                <option value="male" ${defaultGender === 'male' ? 'selected' : ''}>Male</option>
                                <option value="female" ${defaultGender === 'female' ? 'selected' : ''}>Female</option>
                            </select>
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Height (cm) *
                            <input name="height" type="number" step="0.1" class="form-control" placeholder="e.g. 175" value="${defaultHeight}" required style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Neck (cm) *
                            <input name="neck" type="number" step="0.1" class="form-control" placeholder="e.g. 38" value="${defaultNeck}" required style="width: 100%; box-sizing: border-box;">
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Waist (cm) *
                            <input name="waist" type="number" step="0.1" class="form-control" placeholder="e.g. 82" value="${savedState ? savedState.waist_cm : '<?= h((string)$recentWaist) ?>'}" required style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    
                    <div id="hipContainer" style="display:${defaultGender === 'female' ? 'flex' : 'none'}; gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Hips (cm) *
                            <input name="hip" type="number" step="0.1" class="form-control" placeholder="Widest part (for females)" value="${defaultHip}" style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Calculate',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            didOpen: () => {
                const form = document.getElementById('bfCalcForm');
                form.gender.addEventListener('change', (e) => {
                    document.getElementById('hipContainer').style.display = e.target.value === 'female' ? 'flex' : 'none';
                });
            },
            preConfirm: () => {
                const form = document.getElementById('bfCalcForm');
                const gender = form.gender.value;
                const height = parseFloat(form.height.value);
                const neck = parseFloat(form.neck.value);
                const waist = parseFloat(form.waist.value);
                const hip = parseFloat(form.hip.value);
                
                if (!height || !neck || !waist || (gender === 'female' && !hip)) {
                    Swal.showValidationMessage('Please fill all required measurements');
                    return false;
                }
                
                localStorage.setItem('fittracks_bf_settings', JSON.stringify({ gender, height, neck, hip }));
                
                if (gender === 'male' && waist <= neck) {
                    Swal.showValidationMessage('Waist measurement must be greater than neck measurement.');
                    return false;
                }
                
                if (gender === 'female' && (waist + hip) <= neck) {
                    Swal.showValidationMessage('Waist + Hip measurement must be greater than neck measurement.');
                    return false;
                }
                
                let bodyFat = 0;
                if (gender === 'male') {
                    bodyFat = 495 / (1.0324 - 0.19077 * Math.log10(waist - neck) + 0.15456 * Math.log10(height)) - 450;
                } else {
                    bodyFat = 495 / (1.29579 - 0.35004 * Math.log10(waist + hip - neck) + 0.22100 * Math.log10(height)) - 450;
                }
                
                if (isNaN(bodyFat) || bodyFat < 2 || bodyFat > 60) {
                    if (bodyFat < 2) {
                        Swal.showValidationMessage(`Calculated body fat is ${bodyFat.toFixed(1)}% (below 2%). A waist of ${waist} cm is too small compared to neck (${neck} cm) for this formula. Please check your waist circumference.`);
                    } else if (bodyFat > 60) {
                        Swal.showValidationMessage(`Calculated body fat is ${bodyFat.toFixed(1)}% (above 60%). Please verify your measurements.`);
                    } else {
                        Swal.showValidationMessage('Invalid measurements. Please verify numbers in centimeters.');
                    }
                    return false;
                }
                
                return {
                    bf: bodyFat.toFixed(1),
                    neck: neck,
                    waist: waist,
                    hip: hip
                };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const res = result.value;
                if (savedState) {
                    savedState.body_fat_percent = res.bf;
                    savedState.neck_cm = res.neck;
                    savedState.waist_cm = res.waist;
                    if (res.hip) savedState.hips_cm = res.hip;
                }
                logProgress(savedState || {
                    body_fat_percent: res.bf,
                    neck_cm: res.neck,
                    waist_cm: res.waist,
                    hips_cm: res.hip
                });
            } else if (result.dismiss === Swal.DismissReason.cancel && savedState) {
                logProgress(savedState);
            }
        });
    }

    // Modal: Comprehensive Progress & Measurements Log
    function logProgress(savedState = null) {
        const defaultDate = savedState && savedState.log_date !== undefined ? savedState.log_date : '<?= h(date('Y-m-d')) ?>';
        const defaultWeight = savedState && savedState.weight_kg !== undefined ? savedState.weight_kg : '<?= h((string)$recentWeight) ?>';
        const defaultBf = savedState && savedState.body_fat_percent !== undefined ? savedState.body_fat_percent : '<?= h((string)$recentBf) ?>';
        const defaultNeck = savedState && savedState.neck_cm !== undefined ? savedState.neck_cm : '<?= h((string)$recentNeck) ?>';
        const defaultWaist = savedState && savedState.waist_cm !== undefined ? savedState.waist_cm : '<?= h((string)$recentWaist) ?>';
        const defaultChest = savedState && savedState.chest_cm !== undefined ? savedState.chest_cm : '<?= h((string)$recentChest) ?>';
        const defaultArm = savedState && savedState.arm_cm !== undefined ? savedState.arm_cm : '<?= h((string)$recentArm) ?>';
        const defaultHips = savedState && savedState.hips_cm !== undefined ? savedState.hips_cm : '<?= h((string)$recentHips) ?>';
        const defaultNotes = savedState && savedState.notes !== undefined ? savedState.notes : '';

        Swal.fire({
            title: 'Log Progress & Measurements',
            html: `
                <form id="progressForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="log_progress">
                    <?php if ($user['role'] === 'trainer'): ?>
                        <input type="hidden" name="member_user_id" value="<?= (int) $memberId ?>">
                    <?php endif; ?>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Date *
                            <input name="log_date" type="date" class="form-control" required value="${defaultDate}" style="width: 100%; box-sizing: border-box;">
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Weight (kg) *
                            <input name="weight_kg" type="number" step="0.01" class="form-control" placeholder="e.g. 74.0" value="${defaultWeight}" required style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">
                            Body Fat % <a href="#" onclick="calculateBodyFat(event)" style="float:right; color:var(--lime); text-decoration:none; font-weight:700;">Calculate</a>
                            <input name="body_fat_percent" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultBf}" style="width: 100%; box-sizing: border-box;">
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Neck (cm)
                            <input name="neck_cm" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultNeck}" style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Chest (cm)
                            <input name="chest_cm" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultChest}" style="width: 100%; box-sizing: border-box;">
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Waist (cm)
                            <input name="waist_cm" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultWaist}" style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Arms (cm)
                            <input name="arm_cm" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultArm}" style="width: 100%; box-sizing: border-box;">
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Hips (cm)
                            <input name="hips_cm" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultHips}" style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    
                    <label style="display:block; color: var(--muted); font-size: 13px;">Notes
                        <input name="notes" class="form-control" placeholder="Any workout or energy notes" value="${defaultNotes}" style="width: 100%; box-sizing: border-box;">
                    </label>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Save Progress',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('progressForm');
                if (!form.log_date.value || !form.weight_kg.value) {
                    Swal.showValidationMessage('Please fill all required fields (Date & Weight)');
                    return false;
                }

                const waist = parseFloat(form.waist_cm.value);
                const chest = parseFloat(form.chest_cm.value);
                const arm = parseFloat(form.arm_cm.value);

                if (!isNaN(waist) && waist > 0 && waist < 45) {
                    Swal.showValidationMessage('Waist measurement seems too small. Please ensure you entered it in centimeters (cm), not inches.');
                    return false;
                }

                if (!isNaN(chest) && chest > 0 && chest < 55) {
                    Swal.showValidationMessage('Chest measurement seems too small. Please ensure you entered it in centimeters (cm), not inches.');
                    return false;
                }

                if (!isNaN(arm) && arm > 0 && arm < 18) {
                    Swal.showValidationMessage('Arm measurement seems too small. Please ensure you entered it in centimeters (cm), not inches.');
                    return false;
                }

                form.submit();
            }
        });
    }
    </script>
    <?php
    render_footer();
}
