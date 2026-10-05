<?php
declare(strict_types=1);

function trainer_dashboard(PDO $pdo, array $user): void
{
    render_skeleton_banner();
    render_skeleton_stats(4);
    render_skeleton_cards(3);
    echo '<div class="skeleton-content">';

    $stmt = $pdo->prepare('SELECT trainer_id FROM trainer_profiles WHERE user_id = ?');
    $stmt->execute([$user['user_id']]);
    $coachId = (int) ($stmt->fetchColumn() ?: 0);

    // Gather stats
    $clientCount = (int) scalar('SELECT COUNT(*) FROM trainer_assignments WHERE trainer_id = ? AND status = "active"', [$coachId]);
    $pendingCount = (int) scalar('SELECT COUNT(*) FROM trainer_assignments WHERE trainer_id = ? AND status = "pending_trainer"', [$coachId]);
    $planCount = (int) scalar('SELECT COUNT(*) FROM training_plans WHERE trainer_id = ? AND status IN ("active", "draft")', [$coachId]);
    $totalEarnings = (float) scalar('SELECT COALESCE(SUM(amount), 0) FROM trainer_commissions WHERE trainer_id = ? AND status = "paid"', [$coachId]);
    $pendingEarnings = (float) scalar('SELECT COALESCE(SUM(amount), 0) FROM trainer_commissions WHERE trainer_id = ? AND status = "pending"', [$coachId]);

    // Upcoming appointments (today & future)
    $upcomingAppointments = query_all(
        'SELECT ca.assignment_id, ca.assigned_date, ca.ended_date, ca.status,
                u.first_name, u.last_name, u.profile_picture, u.email
         FROM trainer_assignments ca
         JOIN users u ON u.user_id = ca.member_user_id
         WHERE ca.trainer_id = ? AND ca.status IN ("active", "pending_trainer")
           AND DATE(ca.assigned_date) >= CURDATE()
         ORDER BY ca.assigned_date ASC
         LIMIT 5',
        [$coachId]
    );

    // Recent clients
    $recentClients = query_all(
        'SELECT ca.assigned_date, ca.status, u.first_name, u.last_name, u.profile_picture, u.email,
                mp.primary_goal
         FROM trainer_assignments ca
         JOIN users u ON u.user_id = ca.member_user_id
         LEFT JOIN member_profiles mp ON mp.user_id = u.user_id
         WHERE ca.trainer_id = ? AND ca.status = "active"
         ORDER BY ca.assigned_date DESC
         LIMIT 4',
        [$coachId]
    );

    // Welcome Banner
    $greeting = 'Good morning';
    $hour = (int) date('G');
    if ($hour >= 12 && $hour < 17) $greeting = 'Good afternoon';
    elseif ($hour >= 17) $greeting = 'Good evening';
    ?>
    </div>

    <!-- Welcome Banner (Compact & Non-stacking on Mobile) -->
    <div class="trainer-welcome-banner skeleton-content sk-display-block animate-fade-in">
        <div class="trainer-welcome-inner">
            <div class="trainer-welcome-text">
                <div class="trainer-welcome-header">
                    <h2 class="trainer-welcome-title"><?= h($greeting) ?>, <?= h($user['first_name']) ?>!</h2>
                </div>
                <p class="trainer-welcome-sub">
                    <?php if ($pendingCount > 0): ?>
                        You have <strong style="color: var(--lime);"><?= $pendingCount ?></strong> pending appointment request<?= $pendingCount > 1 ? 's' : '' ?> waiting for review.
                    <?php else: ?>
                        All caught up! No pending requests at the moment.
                    <?php endif; ?>
                </p>
            </div>
            <a href="index.php?page=trainer_members" class="trainer-welcome-btn">
                <span>View Clients</span>
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
            </a>
        </div>
    </div>

    <?php render_announcement_carousel(get_active_announcements('trainers')); ?>

    <!-- 4 Metric Cards (2x2 on mobile, 4 columns on desktop) -->
    <div class="trainer-metrics-grid skeleton-content sk-display-grid animate-fade-in delay-1">
        <!-- Assigned Clients -->
        <div class="trainer-metric-card" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 24px rgba(0,0,0,0.15)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)'">
            <div class="trainer-metric-head">
                <div class="trainer-metric-icon" style="background: rgba(199,255,34,0.15);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="var(--lime)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <span class="trainer-metric-label">Active Clients</span>
            </div>
            <strong class="trainer-metric-value"><?= $clientCount ?></strong>
        </div>

        <!-- Pending Requests -->
        <div class="trainer-metric-card" style="<?= $pendingCount > 0 ? 'border-color: rgba(245, 158, 11, 0.4);' : '' ?>" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 24px rgba(0,0,0,0.15)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)'">
            <div class="trainer-metric-head">
                <div class="trainer-metric-icon" style="background: rgba(245, 158, 11, 0.15);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <span class="trainer-metric-label">Pending Requests</span>
            </div>
            <strong class="trainer-metric-value" style="<?= $pendingCount > 0 ? 'color: #f59e0b;' : '' ?>"><?= $pendingCount ?></strong>
        </div>

        <!-- Training Plans -->
        <div class="trainer-metric-card" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 24px rgba(0,0,0,0.15)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)'">
            <div class="trainer-metric-head">
                <div class="trainer-metric-icon" style="background: rgba(124, 92, 252, 0.15);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="#7c5cfc" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                </div>
                <span class="trainer-metric-label">Training Plans</span>
            </div>
            <strong class="trainer-metric-value"><?= $planCount ?></strong>
        </div>

        <!-- Total Earnings -->
        <div class="trainer-metric-card" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 24px rgba(0,0,0,0.15)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)'">
            <div class="trainer-metric-head">
                <div class="trainer-metric-icon" style="background: rgba(34, 197, 94, 0.15); font-size: 18px; font-weight: bold; color: #22c55e;">₱</div>
                <span class="trainer-metric-label">Total Earned</span>
            </div>
            <strong class="trainer-metric-value">₱<?= number_format($totalEarnings, 0) ?></strong>
            <?php if ($pendingEarnings > 0): ?>
                <span class="trainer-metric-sub">₱<?= number_format($pendingEarnings, 0) ?> pending</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Two-Column: Upcoming Appointments & Recent Clients -->
    <div class="skeleton-content animate-fade-in delay-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
        
        <!-- Upcoming Appointments -->
        <section class="panel" style="padding: 0; overflow: hidden;">
            <div style="padding: 20px 24px 16px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center;">
                <h2 style="margin: 0; font-size: 18px; color: var(--ink);">Upcoming Appointments</h2>
                <?php if ($pendingCount > 0): ?>
                    <a href="index.php?page=trainer_members" style="font-size: 13px; color: var(--lime); text-decoration: none;">View all →</a>
                <?php endif; ?>
            </div>
            <div style="padding: 0 24px 20px;">
                <?php if ($upcomingAppointments): ?>
                    <?php foreach ($upcomingAppointments as $apt): ?>
                        <?php
                        $aptDate = strtotime($apt['assigned_date']);
                        $isToday = date('Y-m-d', $aptDate) === date('Y-m-d');
                        $isSingleDay = !empty($apt['ended_date']);
                        $statusColor = $apt['status'] === 'active' ? '#22c55e' : '#f59e0b';
                        $statusLabel = $apt['status'] === 'active' ? 'Confirmed' : 'Pending';
                        ?>
                        <div style="display: flex; align-items: center; gap: 14px; padding: 14px 0; border-bottom: 1px solid rgba(255,255,255,0.04);">
                            <div style="width: 48px; text-align: center; flex-shrink: 0;">
                                <div style="font-size: 11px; text-transform: uppercase; color: <?= $isToday ? 'var(--lime)' : 'var(--muted)' ?>; letter-spacing: 0.5px; font-weight: 600;"><?= $isToday ? 'TODAY' : date('M', $aptDate) ?></div>
                                <div style="font-size: 22px; font-weight: 700; color: var(--ink);"><?= date('j', $aptDate) ?></div>
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-weight: 600; color: var(--ink); font-size: 14px;"><?= h($apt['first_name'] . ' ' . $apt['last_name']) ?></div>
                                <div style="font-size: 12px; color: var(--muted); margin-top: 2px;">
                                    <?= date('g:i A', $aptDate) ?>
                                    <?php if ($isSingleDay): ?>
                                        <span style="margin-left: 6px; font-size: 10px; background: rgba(245,158,11,0.15); color: #f59e0b; padding: 1px 6px; border-radius: 6px;">1-Day</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span style="font-size: 11px; background: rgba(<?= $apt['status'] === 'active' ? '34,197,94' : '245,158,11' ?>, 0.15); color: <?= $statusColor ?>; padding: 3px 8px; border-radius: 6px; font-weight: 600;"><?= $statusLabel ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px 0; color: var(--muted);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="none" stroke="var(--line)" stroke-width="1.5" viewBox="0 0 24 24" style="margin-bottom: 12px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <p style="font-size: 14px; margin: 0;">No upcoming appointments</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Recent Clients -->
        <section class="panel" style="padding: 0; overflow: hidden;">
            <div style="padding: 20px 24px 16px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center;">
                <h2 style="margin: 0; font-size: 18px; color: var(--ink);">Active Clients</h2>
                <a href="index.php?page=trainer_members" style="font-size: 13px; color: var(--lime); text-decoration: none;">Manage →</a>
            </div>
            <div style="padding: 0 24px 20px;">
                <?php if ($recentClients): ?>
                    <?php foreach ($recentClients as $client): ?>
                        <div style="display: flex; align-items: center; gap: 14px; padding: 14px 0; border-bottom: 1px solid rgba(255,255,255,0.04);">
                            <?= render_avatar($client, 'small') ?>
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-weight: 600; color: var(--ink); font-size: 14px;"><?= h($client['first_name'] . ' ' . $client['last_name']) ?></div>
                                <div style="font-size: 12px; color: var(--muted); margin-top: 2px;"><?= h($client['email']) ?></div>
                            </div>
                            <span style="font-size: 11px; background: rgba(124,92,252,0.12); color: #7c5cfc; padding: 3px 8px; border-radius: 6px; font-weight: 500; white-space: nowrap;"><?= h(ucwords(str_replace('_', ' ', $client['primary_goal'] ?? 'General'))) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px 0; color: var(--muted);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="none" stroke="var(--line)" stroke-width="1.5" viewBox="0 0 24 24" style="margin-bottom: 12px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <p style="font-size: 14px; margin: 0;">No active clients yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <style>
        /* Compact Welcome Banner */
        .trainer-welcome-banner,
        body.loaded .trainer-welcome-banner {
            display: block !important;
            background: linear-gradient(135deg, rgba(20, 26, 38, 0.85) 0%, rgba(13, 17, 25, 0.95) 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 18px 24px;
            margin-bottom: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            position: relative;
            overflow: hidden;
        }
        .trainer-welcome-banner::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, var(--lime) 0%, color-mix(in srgb, var(--lime) 30%, transparent) 75%, transparent 100%);
            pointer-events: none;
        }

        /* Light Theme Banner - Crisp white card with subtle shadow matching UI panels */
        [data-theme="light"] .trainer-welcome-banner,
        body.loaded[data-theme="light"] .trainer-welcome-banner,
        html[data-theme="light"] .trainer-welcome-banner {
            background: #ffffff !important;
            border: 1px solid var(--line) !important;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04) !important;
        }

        .trainer-welcome-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .trainer-welcome-text {
            flex: 1;
            min-width: 0;
        }
        .trainer-welcome-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 4px;
            flex-wrap: wrap;
        }
        .trainer-welcome-title {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1.25;
        }
        .trainer-welcome-sub {
            margin: 0;
            color: var(--muted);
            font-size: 13.5px;
            line-height: 1.45;
        }
        .trainer-welcome-btn {
            background: var(--lime);
            color: var(--bg);
            font-weight: 700;
            padding: 9px 18px;
            text-decoration: none;
            border-radius: 8px;
            font-size: 13.5px;
            transition: all 0.2s;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }
        .trainer-welcome-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(199,255,34,0.3);
        }

        .trainer-metrics-grid,
        body.loaded .trainer-metrics-grid {
            display: grid !important;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .trainer-metric-card {
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 20px;
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: default;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-width: 0;
        }
        .trainer-metric-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            min-width: 0;
        }
        .trainer-metric-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .trainer-metric-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            line-height: 1.25;
            min-width: 0;
            overflow-wrap: break-word;
        }
        .trainer-metric-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--ink);
            display: block;
            line-height: 1.1;
            letter-spacing: -0.02em;
        }
        .trainer-metric-sub {
            font-size: 12px;
            color: #f59e0b;
            margin-top: 4px;
            display: block;
            font-weight: 500;
        }

        /* 2 by 2 grid on tablet & mobile views */
        @media (max-width: 900px) {
            .trainer-welcome-banner,
            body.loaded .trainer-welcome-banner {
                padding: 12px 14px !important;
                margin-bottom: 14px !important;
                border-radius: 12px !important;
            }
            .trainer-welcome-inner {
                gap: 10px !important;
            }
            .trainer-welcome-header {
                gap: 6px !important;
                margin-bottom: 2px !important;
            }
            .trainer-welcome-title {
                font-size: 16px !important;
            }
            .trainer-welcome-sub {
                font-size: 11.5px !important;
                line-height: 1.35 !important;
            }
            .trainer-welcome-btn {
                padding: 7px 12px !important;
                font-size: 12px !important;
                border-radius: 6px !important;
            }
            .trainer-welcome-btn svg {
                width: 12px !important;
                height: 12px !important;
            }
            .skeleton-wrapper .sk-rect.banner {
                height: 70px !important;
                margin-bottom: 14px !important;
            }

            .trainer-metrics-grid,
            body.loaded .trainer-metrics-grid {
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: 12px !important;
                margin-bottom: 20px !important;
            }
            .skeleton-wrapper .stats-row {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: 12px !important;
            }
            .trainer-metric-card {
                padding: 14px 14px !important;
            }
            .trainer-metric-head {
                gap: 8px !important;
                margin-bottom: 8px !important;
            }
            .trainer-metric-icon {
                width: 30px !important;
                height: 30px !important;
                border-radius: 6px !important;
            }
            .trainer-metric-icon svg {
                width: 15px !important;
                height: 15px !important;
            }
            .trainer-metric-label {
                font-size: 11px !important;
                letter-spacing: 0.04em !important;
            }
            .trainer-metric-value {
                font-size: 24px !important;
            }
            .trainer-metric-sub {
                font-size: 11px !important;
            }
        }

        @media (max-width: 420px) {
            .trainer-welcome-banner,
            body.loaded .trainer-welcome-banner {
                padding: 10px 12px !important;
            }
            .trainer-welcome-title {
                font-size: 15px !important;
            }
            .trainer-welcome-sub {
                font-size: 11px !important;
            }
            .trainer-welcome-btn {
                padding: 6px 10px !important;
                font-size: 11px !important;
            }

            .trainer-metrics-grid {
                gap: 10px !important;
            }
            .skeleton-wrapper .stats-row {
                gap: 10px !important;
            }
            .trainer-metric-card {
                padding: 12px 10px !important;
                border-radius: 10px !important;
            }
            .trainer-metric-head {
                gap: 6px !important;
                margin-bottom: 6px !important;
            }
            .trainer-metric-icon {
                width: 26px !important;
                height: 26px !important;
            }
            .trainer-metric-icon svg {
                width: 13px !important;
                height: 13px !important;
            }
            .trainer-metric-label {
                font-size: 10px !important;
                letter-spacing: 0.02em !important;
            }
            .trainer-metric-value {
                font-size: 22px !important;
            }
        }

        @media (max-width: 768px) {
            .skeleton-content[style*="grid-template-columns: 1fr 1fr"] {
                grid-template-columns: 1fr !important;
            }
        }
    </style>
<?php
}
