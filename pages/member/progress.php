<?php
declare(strict_types=1);

function progress_page(): void
{
    $user = require_roles(['member', 'trainer']);
    $memberId = $user['role'] === 'trainer'
        ? (int) post('member_user_id', $_GET['member_user_id'] ?? 0)
        : (int) $user['user_id'];

    // Fetch member details
    $stmt = db()->prepare('SELECT u.first_name, u.last_name, u.profile_picture, mp.* FROM users u LEFT JOIN member_profiles mp ON u.user_id = mp.user_id WHERE u.user_id = ?');
    $stmt->execute([$memberId]);
    $member = $stmt->fetch();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user['role'] === 'member') {
        $validator = new Validator();
        $valid = $validator->validate($_POST, [
            'log_date'         => 'required',
            'weight_kg'        => 'required|numeric|min_num:20|max_num:300',
            'body_fat_percent' => 'numeric|min_num:1|max_num:70',
            'chest_cm'         => 'numeric|min_num:30|max_num:200',
            'waist_cm'         => 'numeric|min_num:30|max_num:200',
            'hips_cm'          => 'numeric|min_num:30|max_num:200',
            'arm_cm'           => 'numeric|min_num:10|max_num:100'
        ]);

        if (!$valid) {
            flash($validator->firstError() ?? 'Invalid input parameters.', 'danger');
            redirect('progress');
        }

        $logDate = post('log_date');
        $existingLogId = scalar('SELECT log_id FROM progress_logs WHERE user_id = ? AND log_date = ?', [$memberId, $logDate]);

        if ($existingLogId) {
            db()->prepare(
                'UPDATE progress_logs SET weight_kg = ?, body_fat_percent = ?, chest_cm = ?, waist_cm = ?, hips_cm = ?, arm_cm = ?, notes = ?, recorded_by = ? WHERE log_id = ?'
            )->execute([
                post('weight_kg'),
                post('body_fat_percent') ?: null,
                post('chest_cm')         ?: null,
                post('waist_cm')         ?: null,
                post('hips_cm')          ?: null,
                post('arm_cm')           ?: null,
                post('notes'),
                $user['user_id'],
                $existingLogId
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
        db()->prepare('UPDATE member_profiles SET weight_kg = ? WHERE user_id = ?')
           ->execute([post('weight_kg'), $memberId]);

        if (can_recalculate_workout($memberId)) {
            generate_workout_plan($memberId);
            notify_user($memberId, 'system', 'Workout plan updated', 'Your workout plan was refreshed after logging new progress.');
            $flashMsg = 'Progress logged and workout plan updated.';
        } else {
            $flashMsg = 'Progress logged.';
        }
        
        notify_user($memberId, 'milestone', 'Progress logged', 'Nice work — your latest measurements were saved.');
        flash($flashMsg, 'success');
        redirect('progress');
    }

    // Fetch in DESC order for the table; reverse for the chart
    $rows = $memberId
        ? query_all('SELECT * FROM progress_logs WHERE user_id = ? ORDER BY log_date DESC', [$memberId])
        : [];

    $chartLabels  = [];
    $chartWeights = [];
    foreach (array_reverse($rows) as $r) {
        $chartLabels[]  = date('M j', strtotime($r['log_date']));
        $chartWeights[] = (float) $r['weight_kg'];
    }

    render_header('Progress', $user);
    ?>
    <style>
        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 18px;
            border-bottom: 1px solid var(--line);
            gap: 16px;
        }
        .progress-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            flex-wrap: wrap;
        }
        .progress-goal-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: color-mix(in srgb, var(--lime) 12%, transparent);
            color: var(--ink);
            border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            max-width: 100%;
            min-width: 0;
        }
        .progress-goal-pill .goal-val {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .progress-count-pill {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
            background: var(--panel-soft, rgba(255, 255, 255, 0.05));
            border: 1px solid var(--line);
            white-space: nowrap;
            flex-shrink: 0;
        }
        .btn-log-action {
            background: var(--lime);
            color: var(--lime-btn-text, #090b10);
            font-weight: 800;
            padding: 9px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.12);
            white-space: nowrap;
            flex-shrink: 0;
            transition: transform 0.15s ease, opacity 0.15s ease;
        }
        .btn-log-action:hover {
            transform: translateY(-1px);
            opacity: 0.94;
        }
        .btn-log-action:active {
            transform: translateY(0);
        }

        @media (max-width: 768px) {
            .progress-header {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 14px !important;
                padding-bottom: 16px !important;
                margin-bottom: 20px !important;
            }
            .progress-meta {
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
                width: 100% !important;
                gap: 8px !important;
                flex-wrap: nowrap !important;
            }
            .btn-log-action {
                width: 100% !important;
                display: flex !important;
                justify-content: center !important;
                align-items: center !important;
                padding: 12px 16px !important;
                font-size: 14px !important;
                border-radius: 10px !important;
                box-sizing: border-box !important;
            }
        }

        @media (max-width: 420px) {
            .progress-goal-pill {
                font-size: 12px !important;
                padding: 4px 10px !important;
            }
            .progress-count-pill {
                font-size: 11px !important;
                padding: 3px 8px !important;
            }
        }

        /* Top View Switcher & Panes */
        .progress-view-nav {
            display: flex;
            gap: 8px;
            margin: 8px 0 24px 0;
            background: rgba(16, 19, 27, 0.85);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 6px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.18);
            max-width: 440px;
        }

        [data-theme="light"] .progress-view-nav {
            background: rgba(255, 255, 255, 0.96);
            border-color: #cbd5e1;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
        }

        button.progress-nav-tab,
        .progress-nav-tab {
            flex: 1 1 auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: unset;
            padding: 11px 18px;
            border-radius: 10px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--muted);
            font: inherit;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: none;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            white-space: nowrap;
            text-decoration: none;
        }

        button.progress-nav-tab svg,
        .progress-nav-tab svg {
            flex-shrink: 0;
            transition: stroke 0.2s ease, transform 0.2s ease;
        }

        button.progress-nav-tab:hover,
        .progress-nav-tab:hover {
            color: var(--ink);
            background: color-mix(in srgb, var(--ink) 6%, transparent);
        }

        button.progress-nav-tab.active,
        .progress-nav-tab.active {
            color: var(--lime);
            background: color-mix(in srgb, var(--lime) 14%, var(--panel-soft));
            border-color: color-mix(in srgb, var(--lime) 40%, transparent);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        button.progress-nav-tab.active svg,
        .progress-nav-tab.active svg {
            stroke: var(--lime);
            transform: scale(1.08);
        }

        button.progress-nav-tab .tab-badge-pill,
        .progress-nav-tab .tab-badge-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            height: 18px;
            padding: 0 6px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 800;
            background: rgba(255, 255, 255, 0.08);
            color: var(--muted);
        }

        button.progress-nav-tab.active .tab-badge-pill,
        .progress-nav-tab.active .tab-badge-pill {
            background: var(--lime);
            color: #0b110e;
        }

        [data-theme="light"] button.progress-nav-tab {
            color: #64748b;
        }

        [data-theme="light"] button.progress-nav-tab:hover {
            color: #0f172a;
            background: rgba(0, 0, 0, 0.04);
        }

        [data-theme="light"] button.progress-nav-tab.active {
            color: var(--lime);
            background: #ffffff;
            border-color: #cbd5e1;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        [data-theme="light"] button.progress-nav-tab .tab-badge-pill {
            background: rgba(0, 0, 0, 0.06);
            color: #64748b;
        }

        [data-theme="light"] button.progress-nav-tab.active .tab-badge-pill {
            background: var(--lime);
            color: #ffffff;
        }

        .tab-label-compact {
            display: none !important;
        }

        .progress-view-pane {
            animation: fadeInProgressPane 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes fadeInProgressPane {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 580px) {
            .progress-view-nav {
                max-width: 100%;
                padding: 5px;
                gap: 6px;
                margin-bottom: 20px;
            }
            button.progress-nav-tab,
            .progress-nav-tab {
                padding: 10px 12px;
                font-size: 13px;
            }
        }

        @media (max-width: 440px) {
            .tab-label-full {
                display: none !important;
            }
            .tab-label-compact {
                display: inline !important;
            }
            button.progress-nav-tab,
            .progress-nav-tab {
                padding: 8px 8px;
                font-size: 12.5px;
                gap: 6px;
            }
            button.progress-nav-tab svg,
            .progress-nav-tab svg {
                width: 15px;
                height: 15px;
            }
            button.progress-nav-tab .tab-badge-pill,
            .progress-nav-tab .tab-badge-pill {
                height: 17px;
                min-width: 17px;
                font-size: 10px;
                padding: 0 5px;
            }
        }

        /* Mobile Progress Log Cards (Approach 1: Zero Horizontal Scroll) */
        .history-desktop-table {
            display: block;
        }

        .history-mobile-cards {
            display: none;
            flex-direction: column;
            gap: 12px;
        }

        .progress-log-card {
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: border-color 0.2s ease, transform 0.15s ease;
        }

        [data-theme="light"] .progress-log-card {
            background: #ffffff;
            border-color: #e2e8f0;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.03);
        }

        .log-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .log-card-date {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 14px;
            font-weight: 700;
            color: var(--ink);
        }

        .log-card-date svg {
            color: var(--muted);
            flex-shrink: 0;
        }

        .log-card-weight {
            display: inline-flex;
            align-items: baseline;
            gap: 3px;
            background: color-mix(in srgb, var(--lime) 14%, transparent);
            color: var(--lime);
            border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: -0.2px;
        }

        [data-theme="light"] .log-card-weight {
            background: #f0fdf4;
            color: #16a34a;
            border-color: #bbf7d0;
        }

        .log-card-weight .unit {
            font-size: 11px;
            font-weight: 600;
            opacity: 0.85;
        }

        .log-card-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(75px, 1fr));
            gap: 8px;
            padding: 10px 12px;
            background: var(--panel-soft, rgba(255, 255, 255, 0.03));
            border-radius: 10px;
            border: 1px solid var(--line);
        }

        [data-theme="light"] .log-card-metrics {
            background: #f8fafc;
            border-color: #f1f5f9;
        }

        .metric-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .metric-label {
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--muted);
            font-weight: 600;
        }

        .metric-val {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--ink);
        }

        .log-card-notes {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 8px;
            background: rgba(128, 128, 128, 0.06);
            color: var(--muted);
            font-size: 12.5px;
            line-height: 1.4;
        }

        .log-card-notes svg {
            color: var(--muted);
            margin-top: 2px;
            flex-shrink: 0;
        }

        @media (max-width: 768px) {
            .history-desktop-table {
                display: none !important;
            }
            .history-mobile-cards {
                display: flex !important;
            }
        }
    </style>
    <div class="skeleton-wrapper">
        <section class="panel">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--line);">
                <div style="display:flex;gap:10px;align-items:center;">
                    <div class="sk sk-rect" style="width:140px;height:28px;border-radius:20px"></div>
                    <div class="sk sk-text" style="width:80px;height:12px"></div>
                </div>
                <div class="sk sk-rect" style="width:130px;height:36px;border-radius:8px"></div>
            </div>
            <?php render_skeleton_chart(); ?>
            <div style="margin-top:24px">
                <div class="sk sk-title" style="width:180px;margin-bottom:12px"></div>
                <?php render_skeleton_table(8, 6); ?>
            </div>
        </section>
    </div>
    <section class="panel skeleton-content sk-display-block">
        <!-- Progress Action Header -->
        <div class="progress-header">
            <div class="progress-meta">
                <?php if ($user['role'] === 'trainer' && $member): ?>
                    <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                        <?= render_avatar($member, 'small') ?>
                        <span class="progress-goal-pill">
                            <span style="width: 7px; height: 7px; border-radius: 50%; background: var(--lime); display: inline-block; flex-shrink: 0;"></span>
                            <span style="color: var(--muted); font-weight: 500;">Client:</span>
                            <strong class="goal-val" style="color: var(--ink); font-weight: 700;"><?= h($member['first_name'] . ' ' . $member['last_name']) ?></strong>
                        </span>
                    </div>
                <?php else: ?>
                    <?php if (!empty($member['primary_goal'])): ?>
                        <span class="progress-goal-pill">
                            <span style="width: 7px; height: 7px; border-radius: 50%; background: var(--lime); display: inline-block; flex-shrink: 0;"></span>
                            <span style="color: var(--muted); font-weight: 500;">Goal:</span>
                            <strong class="goal-val" style="color: var(--ink); font-weight: 700;"><?= h(ucwords(str_replace('_', ' ', $member['primary_goal']))) ?></strong>
                        </span>
                    <?php else: ?>
                        <span class="progress-goal-pill">
                            <span style="width: 7px; height: 7px; border-radius: 50%; background: var(--lime); display: inline-block; flex-shrink: 0;"></span>
                            <strong class="goal-val" style="color: var(--ink); font-weight: 700;">Progress Overview</strong>
                        </span>
                    <?php endif; ?>
                <?php endif; ?>

                <span class="progress-count-pill">
                    <?= count($rows) ?> log<?= count($rows) !== 1 ? 's' : '' ?> recorded
                </span>
            </div>

            <?php if ($user['role'] === 'member'): ?>
                <button onclick="logProgress()" class="btn btn-lime btn-log-action">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span>Log Progress</span>
                </button>
            <?php endif; ?>
        </div>



        <?php if (count($rows) >= 2): 
            $current = $rows[0];
            $baseline = $rows[count($rows) - 1];
            
            $weightStart = (float) $baseline['weight_kg'];
            $weightCurr = (float) $current['weight_kg'];
            $weightDelta = $weightCurr - $weightStart;
            $weightSign = $weightDelta > 0 ? '+' : '';
            $weightColor = $weightDelta > 0 ? 'var(--danger)' : 'var(--lime)'; // Assuming weight loss is good. Adjust if needed.

            $bfStart = $baseline['body_fat_percent'] ? (float) $baseline['body_fat_percent'] : null;
            $bfCurr = $current['body_fat_percent'] ? (float) $current['body_fat_percent'] : null;
            
            $goal = $member['primary_goal'] ?? '';
            $showArm = in_array($goal, ['Growing larger biceps and arms', 'Gaining lean body mass', 'Increasing maximum strength', 'muscle_gain']);
            $showChest = in_array($goal, ['Developing a wide chest', 'Gaining lean body mass', 'Increasing maximum strength', 'muscle_gain']);
            $showWaist = in_array($goal, ['Building a visible six-pack', 'Losing excess body fat', 'Reaching body recomposition', 'fat_loss']);
            $showHips = in_array($goal, ['Shaping the lower body', 'Losing excess body fat', 'fat_loss']);
        ?>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 200px), 1fr)); gap: 15px; margin-bottom: 2rem;">
            <div style="background: var(--bg); padding: 15px; border-radius: 8px; border: 1px solid var(--line);">
                <div style="color: var(--muted); font-size: 13px; font-weight: 600; text-transform: uppercase; margin-bottom: 8px;">Weight</div>
                <div style="display: flex; justify-content: space-between; align-items: baseline;">
                    <div>
                        <div style="font-size: 24px; font-weight: bold; color: var(--ink);"><?= h(number_format($weightCurr, 1)) ?> <span style="font-size: 14px; font-weight: normal; color: var(--muted);">kg</span></div>
                        <div style="font-size: 13px; color: var(--muted);">Start: <?= h(number_format($weightStart, 1)) ?> kg</div>
                    </div>
                    <?php if ($member['target_weight_kg']): 
                        $dist = $weightCurr - (float)$member['target_weight_kg'];
                    ?>
                        <div style="text-align: right;">
                            <div style="font-size: 13px; color: var(--muted); margin-bottom: 4px;">Target: <?= h($member['target_weight_kg']) ?> kg</div>
                            <?php if (abs($dist) < 0.1): ?>
                                <div style="padding: 4px 8px; border-radius: 4px; font-size: 13px; font-weight: bold; background: var(--lime)20; color: var(--lime);">Goal Reached!</div>
                            <?php else: ?>
                                <div style="padding: 4px 8px; border-radius: 4px; font-size: 13px; font-weight: bold; background: var(--panel-soft); color: var(--ink);"><?= h(number_format(abs($dist), 1)) ?> kg to go</div>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($weightDelta !== 0.0): ?>
                        <div style="padding: 4px 8px; border-radius: 4px; font-size: 13px; font-weight: bold; background: <?= $weightColor ?>20; color: <?= $weightColor ?>;">
                            <?= $weightSign ?><?= h(number_format($weightDelta, 1)) ?>
                        </div>
                    <?php else: ?>
                        <div style="padding: 4px 8px; border-radius: 4px; font-size: 13px; font-weight: bold; background: var(--line); color: var(--muted);">
                            No change
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($bfStart !== null && $bfCurr !== null): 
                $bfDelta = $bfCurr - $bfStart;
                $bfSign = $bfDelta > 0 ? '+' : '';
                $bfColor = $bfDelta > 0 ? 'var(--danger)' : 'var(--lime)';
            ?>
            <div style="background: var(--bg); padding: 15px; border-radius: 8px; border: 1px solid var(--line);">
                <div style="color: var(--muted); font-size: 13px; font-weight: 600; text-transform: uppercase; margin-bottom: 8px;">Body Fat</div>
                <div style="display: flex; justify-content: space-between; align-items: baseline;">
                    <div>
                        <div style="font-size: 24px; font-weight: bold; color: var(--ink);"><?= h(number_format($bfCurr, 1)) ?> <span style="font-size: 14px; font-weight: normal; color: var(--muted);">%</span></div>
                        <div style="font-size: 13px; color: var(--muted);">Start: <?= h(number_format($bfStart, 1)) ?> %</div>
                    </div>
                    <?php if ($member['target_body_fat_percent']): 
                        $dist = $bfCurr - (float)$member['target_body_fat_percent'];
                    ?>
                        <div style="text-align: right;">
                            <div style="font-size: 13px; color: var(--muted); margin-bottom: 4px;">Target: <?= h($member['target_body_fat_percent']) ?> %</div>
                            <?php if ($dist <= 0): ?>
                                <div style="padding: 4px 8px; border-radius: 4px; font-size: 13px; font-weight: bold; background: var(--lime)20; color: var(--lime);">Goal Reached!</div>
                            <?php else: ?>
                                <div style="padding: 4px 8px; border-radius: 4px; font-size: 13px; font-weight: bold; background: var(--panel-soft); color: var(--ink);"><?= h(number_format($dist, 1)) ?> % to go</div>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($bfDelta !== 0.0): ?>
                        <div style="padding: 4px 8px; border-radius: 4px; font-size: 13px; font-weight: bold; background: <?= $bfColor ?>20; color: <?= $bfColor ?>;">
                            <?= $bfSign ?><?= h(number_format($bfDelta, 1)) ?>
                        </div>
                    <?php else: ?>
                        <div style="padding: 4px 8px; border-radius: 4px; font-size: 13px; font-weight: bold; background: var(--line); color: var(--muted);">
                            No change
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php 
            $extraMetrics = [
                ['key' => 'arm_cm', 'label' => 'Arm Size', 'unit' => 'cm', 'show' => $showArm, 'better' => 'up', 'target' => !empty($member['target_arm_cm']) ? (float)$member['target_arm_cm'] : null],
                ['key' => 'chest_cm', 'label' => 'Chest Size', 'unit' => 'cm', 'show' => $showChest, 'better' => 'up', 'target' => !empty($member['target_chest_cm']) ? (float)$member['target_chest_cm'] : null],
                ['key' => 'waist_cm', 'label' => 'Waist Size', 'unit' => 'cm', 'show' => $showWaist, 'better' => 'down', 'target' => !empty($member['target_waist_cm']) ? (float)$member['target_waist_cm'] : null],
                ['key' => 'hips_cm', 'label' => 'Hip Size', 'unit' => 'cm', 'show' => $showHips, 'better' => 'down', 'target' => null],
            ];
            foreach ($extraMetrics as $m):
                if (!$m['show']) continue;
                $mStart = $baseline[$m['key']] ? (float) $baseline[$m['key']] : null;
                $mCurr = $current[$m['key']] ? (float) $current[$m['key']] : null;
                if ($mStart !== null && $mCurr !== null):
                    $mDelta = $mCurr - $mStart;
                    $mSign = $mDelta > 0 ? '+' : '';
                    $mColor = 'var(--line)';
                    if ($mDelta !== 0.0) {
                        if ($m['better'] === 'up') {
                            $mColor = $mDelta > 0 ? 'var(--lime)' : 'var(--danger)';
                        } else {
                            $mColor = $mDelta < 0 ? 'var(--lime)' : 'var(--danger)';
                        }
                    }
            ?>
            <div style="background: var(--bg); padding: 15px; border-radius: 8px; border: 1px solid var(--line);">
                <div style="color: var(--muted); font-size: 13px; font-weight: 600; text-transform: uppercase; margin-bottom: 8px;"><?= $m['label'] ?></div>
                <div style="display: flex; justify-content: space-between; align-items: baseline;">
                    <div>
                        <div style="font-size: 24px; font-weight: bold; color: var(--ink);"><?= h(number_format($mCurr, 1)) ?> <span style="font-size: 14px; font-weight: normal; color: var(--muted);"><?= $m['unit'] ?></span></div>
                        <div style="font-size: 13px; color: var(--muted);">Start: <?= h(number_format($mStart, 1)) ?> <?= $m['unit'] ?></div>
                    </div>
                    <?php if ($m['target'] !== null): 
                        $dist = $m['better'] === 'up' ? ($m['target'] - $mCurr) : ($mCurr - $m['target']);
                    ?>
                        <div style="text-align: right;">
                            <div style="font-size: 13px; color: var(--muted); margin-bottom: 4px;">Target: <?= h($m['target']) ?> <?= $m['unit'] ?></div>
                            <?php if ($dist <= 0): ?>
                                <div style="padding: 4px 8px; border-radius: 4px; font-size: 13px; font-weight: bold; background: var(--lime)20; color: var(--lime);">Goal Reached! 🏆</div>
                            <?php else: ?>
                                <div style="padding: 4px 8px; border-radius: 4px; font-size: 13px; font-weight: bold; background: var(--panel-soft); color: var(--ink);"><?= h(number_format(abs($dist), 1)) ?> <?= $m['unit'] ?> to go</div>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($mDelta !== 0.0): ?>
                        <div style="padding: 4px 8px; border-radius: 4px; font-size: 13px; font-weight: bold; background: <?= $mColor ?>20; color: <?= $mColor ?>;">
                            <?= $mSign ?><?= h(number_format($mDelta, 1)) ?>
                        </div>
                    <?php else: ?>
                        <div style="padding: 4px 8px; border-radius: 4px; font-size: 13px; font-weight: bold; background: var(--line); color: var(--muted);">
                            No change
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php 
                endif;
            endforeach; 
            ?>
        </div>
        <?php endif; ?>

        <!-- Top View Switcher (Trend vs History) -->
        <nav class="progress-view-nav" aria-label="Progress Views">
            <button type="button" class="progress-nav-tab active" id="tab-btn-trend" onclick="switchProgressView('trend')" title="Weight Trend">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                </svg>
                <span>
                    <span class="tab-label-full">Weight Trend</span>
                    <span class="tab-label-compact">Trend</span>
                </span>
            </button>
            <button type="button" class="progress-nav-tab" id="tab-btn-history" onclick="switchProgressView('history')" title="Measurement History">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span>
                    <span class="tab-label-full">History Logs</span>
                    <span class="tab-label-compact">History</span>
                </span>
                <?php if (!empty($rows)): ?>
                    <span class="tab-badge-pill"><?= count($rows) ?></span>
                <?php endif; ?>
            </button>
        </nav>

        <!-- VIEW PANE 1: WEIGHT TREND -->
        <div id="view-pane-trend" class="progress-view-pane">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <div>
                    <h2 style="margin: 0 0 4px; font-size: 1.35rem; font-weight: 700;">Weight Trend</h2>
                    <p style="margin: 0; color: var(--muted); font-size: 0.9rem;">Track how your body weight evolves over time.</p>
                </div>
            </div>

            <?php if (count($chartWeights) >= 2): ?>
            <div style="background: var(--bg); padding: 20px; border-radius: 12px; border: 1px solid var(--line); margin-bottom: 2rem;">
                <canvas id="weightChart" style="max-height:280px; width: 100%;"></canvas>
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
                    const gridColor  = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.07)';
                    const tickColor  = isDark ? '#8792ad' : '#555';
                    window.weightChartInstance = new Chart(document.getElementById('weightChart'), {
                        type: 'line',
                        data: {
                            labels: <?= json_encode($chartLabels, JSON_HEX_TAG) ?>,
                            datasets: [{
                                label: 'Weight (kg)',
                                data: <?= json_encode($chartWeights, JSON_HEX_TAG) ?>,
                                borderColor: 'var(--lime)',
                                backgroundColor: 'color-mix(in srgb, var(--lime) 8%, transparent)',
                                borderWidth: 2,
                                pointRadius: 4,
                                pointBackgroundColor: 'var(--lime)',
                                tension: 0.3,
                                fill: true,
                            }
                            <?php if ($member['target_weight_kg']): ?>
                            , {
                                label: 'Target Weight (kg)',
                                data: Array(<?= count($chartLabels) ?>).fill(<?= (float)$member['target_weight_kg'] ?>),
                                borderColor: 'var(--orange)',
                                borderDash: [5, 5],
                                borderWidth: 2,
                                pointRadius: 0,
                                fill: false
                            }
                            <?php endif; ?>
                            ]
                        },
                        options: {
                            responsive: true,
                            plugins: { legend: { display: false } },
                            scales: {
                                y: { grid: { color: gridColor }, ticks: { color: tickColor } },
                                x: { grid: { color: gridColor }, ticks: { color: tickColor } }
                            }
                        }
                    });
                });
                </script>
            </div>
            <?php elseif ($rows): ?>
                <div style="text-align: center; padding: 40px 20px; background: var(--bg); border-radius: 12px; border: 1px dashed var(--line); margin-bottom: 2rem;">
                    <p style="color: var(--muted); margin: 0; font-size: 14px;">Log at least 2 entries to see your weight trend chart.</p>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                    <p>No progress entries logged yet.<br>Click "Log Progress" above to record your first measurement.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- VIEW PANE 2: HISTORY TABLE -->
        <div id="view-pane-history" class="progress-view-pane" style="display: none;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <div>
                    <h2 style="margin: 0 0 4px; font-size: 1.35rem; font-weight: 700;">History Logs</h2>
                    <p style="margin: 0; color: var(--muted); font-size: 0.9rem;">Complete chronological breakdown of your recorded body measurements.</p>
                </div>
            </div>
            <!-- Desktop / Tablet Table View (>= 768px) -->
            <div class="history-desktop-table table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Weight</th>
                            <th>Body Fat</th>
                            <th>Waist</th>
                            <th>Chest</th>
                            <th>Arm</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><strong><?= h(date('M j, Y', strtotime($row['log_date']))) ?></strong></td>
                                <td><?= $row['weight_kg'] ? h(number_format((float)$row['weight_kg'], 2)) . ' kg' : '-' ?></td>
                                <td><?= $row['body_fat_percent'] ? h(number_format((float)$row['body_fat_percent'], 1)) . '%' : '-' ?></td>
                                <td><?= $row['waist_cm'] ? h(number_format((float)$row['waist_cm'], 1)) . ' cm' : '-' ?></td>
                                <td><?= $row['chest_cm'] ? h(number_format((float)$row['chest_cm'], 1)) . ' cm' : '-' ?></td>
                                <td><?= $row['arm_cm'] ? h(number_format((float)$row['arm_cm'], 1)) . ' cm' : '-' ?></td>
                                <td><?= h($row['notes'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?>
                            <tr><td colspan="7" style="text-align:center; padding: 24px; color: var(--muted);">No progress logs recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card-List View (< 768px: Zero Horizontal Scroll) -->
            <div class="history-mobile-cards">
                <?php foreach ($rows as $row): 
                    $hasMeasurements = ($row['body_fat_percent'] || $row['waist_cm'] || $row['chest_cm'] || $row['arm_cm'] || !empty($row['hips_cm']));
                    $hasNotes = !empty(trim((string)($row['notes'] ?? '')));
                ?>
                    <div class="progress-log-card">
                        <div class="log-card-header">
                            <div class="log-card-date">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                </svg>
                                <span><?= h(date('M j, Y', strtotime($row['log_date']))) ?></span>
                            </div>
                            <?php if ($row['weight_kg']): ?>
                                <div class="log-card-weight">
                                    <?= h(number_format((float)$row['weight_kg'], 2)) ?> <span class="unit">kg</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($hasMeasurements): ?>
                            <div class="log-card-metrics">
                                <?php if ($row['body_fat_percent']): ?>
                                    <div class="metric-item">
                                        <span class="metric-label">Body Fat</span>
                                        <span class="metric-val"><?= h(number_format((float)$row['body_fat_percent'], 1)) ?>%</span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($row['waist_cm']): ?>
                                    <div class="metric-item">
                                        <span class="metric-label">Waist</span>
                                        <span class="metric-val"><?= h(number_format((float)$row['waist_cm'], 1)) ?> cm</span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($row['chest_cm']): ?>
                                    <div class="metric-item">
                                        <span class="metric-label">Chest</span>
                                        <span class="metric-val"><?= h(number_format((float)$row['chest_cm'], 1)) ?> cm</span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($row['arm_cm']): ?>
                                    <div class="metric-item">
                                        <span class="metric-label">Arm</span>
                                        <span class="metric-val"><?= h(number_format((float)$row['arm_cm'], 1)) ?> cm</span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($row['hips_cm'])): ?>
                                    <div class="metric-item">
                                        <span class="metric-label">Hips</span>
                                        <span class="metric-val"><?= h(number_format((float)$row['hips_cm'], 1)) ?> cm</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($hasNotes): ?>
                            <div class="log-card-notes">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                                </svg>
                                <span><?= h($row['notes']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <div style="text-align:center; padding: 32px 16px; color: var(--muted); background: var(--bg); border-radius: 12px; border: 1px dashed var(--line);">
                        No progress logs recorded yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <script>
        function switchProgressView(viewName) {
            const trendBtn = document.getElementById('tab-btn-trend');
            const historyBtn = document.getElementById('tab-btn-history');
            const trendPane = document.getElementById('view-pane-trend');
            const historyPane = document.getElementById('view-pane-history');

            if (viewName === 'history') {
                if (trendBtn) trendBtn.classList.remove('active');
                if (historyBtn) historyBtn.classList.add('active');
                if (trendPane) trendPane.style.display = 'none';
                if (historyPane) historyPane.style.display = 'block';
                try { history.replaceState(null, null, '#history'); } catch(e){}
            } else {
                if (trendBtn) trendBtn.classList.add('active');
                if (historyBtn) historyBtn.classList.remove('active');
                if (trendPane) trendPane.style.display = 'block';
                if (historyPane) historyPane.style.display = 'none';
                try { history.replaceState(null, null, '#trend'); } catch(e){}
                if (window.weightChartInstance) {
                    window.weightChartInstance.resize();
                }
            }
        }

        if (window.location.hash === '#history') {
            switchProgressView('history');
        }
        </script>
    </section>
    
    <script>
    <?php
    $recentWeight = '';
    $recentBf = '';
    $recentWaist = '';
    $recentChest = '';
    $recentArm = '';
    $recentHips = '';

    // Scan previous logs for the most recent non-empty values
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

    // Auto-calculate body fat percent if not explicitly recorded and measurements exist
    if ($recentBf === '' && !empty($member['height_cm']) && !empty($member['neck_cm']) && $recentWaist !== '') {
        $h = (float)$member['height_cm'];
        $n = (float)$member['neck_cm'];
        $w = (float)$recentWaist;
        $sex = strtolower((string)($member['biological_sex'] ?? 'male'));
        $hip = (float)($recentHips ?: ($member['hip_cm'] ?? 0));

        if ($sex === 'male' && $w > $n && $h > 0 && ($w - $n) > 0) {
            $estBf = 495 / (1.0324 - 0.19077 * log10($w - $n) + 0.15456 * log10($h)) - 450;
            if ($estBf >= 3 && $estBf <= 55) {
                $recentBf = round($estBf, 1);
            }
        } elseif ($sex === 'female' && ($w + $hip) > $n && $h > 0 && ($w + $hip - $n) > 0) {
            $estBf = 495 / (1.29579 - 0.35004 * log10($w + $hip - $n) + 0.22100 * log10($h)) - 450;
            if ($estBf >= 5 && $estBf <= 60) {
                $recentBf = round($estBf, 1);
            }
        }
    }
    $hasPrefilledMetrics = ($recentWeight !== '' || $recentWaist !== '' || $recentBf !== '');
    ?>
    function calculateBodyFat(e) {
        if (e) e.preventDefault();
        
        // Capture current form state to restore it later
        const currentForm = document.getElementById('progressForm');
        let savedState = null;
        if (currentForm) {
            savedState = {
                log_date: currentForm.log_date.value,
                weight_kg: currentForm.weight_kg.value,
                body_fat_percent: currentForm.body_fat_percent.value,
                waist_cm: currentForm.waist_cm.value,
                chest_cm: currentForm.chest_cm.value,
                arm_cm: currentForm.arm_cm.value,
                notes: currentForm.notes.value
            };
        }
        
        const savedBfSettings = JSON.parse(localStorage.getItem('fittracks_bf_settings') || '{}');
        const defaultGender = savedBfSettings.gender || '<?= h(strtolower((string)($member['biological_sex'] ?? 'male'))) ?>';
        const defaultHeight = savedBfSettings.height || '<?= h((string)($member['height_cm'] ?? '')) ?>';
        const defaultNeck = savedBfSettings.neck || '<?= h((string)($member['neck_cm'] ?? '')) ?>';
        const defaultHip = savedBfSettings.hip || '<?= h((string)($recentHips ?: ($member['hip_cm'] ?? ''))) ?>';
        
        Swal.fire({
            title: 'Estimate Body Fat %',
            html: `
                <div style="text-align: left; font-size: 14px; color: var(--muted); margin-bottom: 15px;">
                    This estimate uses the U.S. Navy Method. You will need a tape measure.
                </div>
                <form id="bfCalcForm" style="text-align: left; display: flex; flex-direction: column; gap: 12px;">
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Gender *
                            <select name="gender" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                                <option value="male" ${defaultGender === 'male' ? 'selected' : ''}>Male</option>
                                <option value="female" ${defaultGender === 'female' ? 'selected' : ''}>Female</option>
                            </select>
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Height (cm) *
                            <input name="height" type="number" step="0.1" class="form-control" placeholder="e.g. 175" value="${defaultHeight}" required style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Neck (cm) *
                            <input name="neck" type="number" step="0.1" class="form-control" placeholder="e.g. 38" value="${defaultNeck}" required style="width: 100%; box-sizing: border-box;">
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Waist (cm) *
                            <input name="waist" type="number" step="0.1" class="form-control" placeholder="e.g. 85" value="${savedState ? savedState.waist_cm : '<?= h((string)$recentWaist) ?>'}" required style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    
                    <div id="hipContainer" style="display:${defaultGender === 'female' ? 'flex' : 'none'}; gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Hip (cm) *
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
                
                // Save settings for next time
                localStorage.setItem('fittracks_bf_settings', JSON.stringify({ gender, height, neck }));
                
                if (gender === 'male' && waist <= neck) {
                    Swal.showValidationMessage('Waist measurement must be greater than neck measurement.');
                    return false;
                }
                
                if (gender === 'female' && (waist + hip) <= neck) {
                    Swal.showValidationMessage('Waist + Hip measurement must be greater than neck measurement.');
                    return false;
                }
                
                let bodyFat = 0;
                // U.S. Navy Method formulas (using cm)
                if (gender === 'male') {
                    bodyFat = 495 / (1.0324 - 0.19077 * Math.log10(waist - neck) + 0.15456 * Math.log10(height)) - 450;
                } else {
                    bodyFat = 495 / (1.29579 - 0.35004 * Math.log10(waist + hip - neck) + 0.22100 * Math.log10(height)) - 450;
                }
                
                if (isNaN(bodyFat) || bodyFat < 2 || bodyFat > 60) {
                    Swal.showValidationMessage('Invalid measurements. Please check your inputs (ensure they are in cm).');
                    return false;
                }
                
                return bodyFat.toFixed(1);
            }
        }).then((result) => {
            if (result.isConfirmed) {
                // Return to log progress modal and populate with calculation
                if (savedState) savedState.body_fat_percent = result.value;
                logProgress(savedState || { body_fat_percent: result.value });
            } else if (result.dismiss === Swal.DismissReason.cancel || result.dismiss === Swal.DismissReason.backdrop) {
                // Go back to the log progress modal restoring the exact previous state
                logProgress(savedState);
            }
        });
    }

    function logProgress(savedState = null) {
        const defaultDate = savedState && savedState.log_date !== undefined ? savedState.log_date : '<?= h(date('Y-m-d')) ?>';
        const defaultWeight = savedState && savedState.weight_kg !== undefined ? savedState.weight_kg : '<?= h((string)$recentWeight) ?>';
        const defaultBf = savedState && savedState.body_fat_percent !== undefined ? savedState.body_fat_percent : '<?= h((string)$recentBf) ?>';
        const defaultWaist = savedState && savedState.waist_cm !== undefined ? savedState.waist_cm : '<?= h((string)$recentWaist) ?>';
        const defaultChest = savedState && savedState.chest_cm !== undefined ? savedState.chest_cm : '<?= h((string)$recentChest) ?>';
        const defaultArm = savedState && savedState.arm_cm !== undefined ? savedState.arm_cm : '<?= h((string)$recentArm) ?>';
        const defaultNotes = savedState && savedState.notes !== undefined ? savedState.notes : '';

        Swal.fire({
            title: 'Log Progress',
            html: `
                <form id="progressForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <?= csrf_field() ?>
                    <?php if ($user['role'] === 'trainer'): ?>
                        <input type="hidden" name="member_user_id" value="<?= (int) $memberId ?>">
                    <?php endif; ?>
                    <?php if ($hasPrefilledMetrics): ?>
                    <div style="display:flex;align-items:center;gap:7px;padding:8px 12px;border-radius:8px;background:rgba(199,255,34,0.08);border:1px solid rgba(199,255,34,0.25);color:var(--lime);font-size:12px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        <span>Pre-populated with your latest recorded measurements. Adjust any values as needed.</span>
                    </div>
                    <?php endif; ?>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Date *
                            <input name="log_date" type="date" class="form-control" required value="${defaultDate}" style="width: 100%; box-sizing: border-box;">
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Weight (kg) *
                            <input name="weight_kg" type="number" step="0.01" class="form-control" placeholder="e.g. 72.5" value="${defaultWeight}" required style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">
                            Body fat % <a href="#" onclick="calculateBodyFat(event)" style="float:right; color:var(--lime); text-decoration:none;">Calculate</a>
                            <input name="body_fat_percent" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultBf}" style="width: 100%; box-sizing: border-box;">
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Waist (cm)
                            <input name="waist_cm" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultWaist}" style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Chest (cm)
                            <input name="chest_cm" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultChest}" style="width: 100%; box-sizing: border-box;">
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Arms (cm)
                            <input name="arm_cm" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultArm}" style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Notes
                        <input name="notes" class="form-control" placeholder="Any notes about this entry" value="${defaultNotes}" style="width: 100%; box-sizing: border-box;">
                    </label>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Log progress',
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
                
                // Validate that measurements are likely in cm, not inches
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
