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
    $planCount = trainer_plan_count($coachId);
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

    <!-- Welcome Banner -->
    <div class="skeleton-content animate-fade-in" style="background: linear-gradient(135deg, rgba(199,255,34,0.12) 0%, rgba(66,219,165,0.06) 100%); border: 1px solid rgba(199,255,34,0.25); border-radius: 16px; padding: 28px 32px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; box-shadow: 0 4px 24px rgba(0,0,0,0.15); backdrop-filter: blur(16px);">
        <div>
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                <h2 style="margin: 0; font-size: 26px; color: var(--ink);"><?= h($greeting) ?>, <?= h($user['first_name']) ?>!</h2>
                <span style="background: var(--lime); color: var(--bg); padding: 3px 12px; border-radius: 12px; font-size: 12px; font-weight: bold; letter-spacing: 0.5px;">TRAINER</span>
            </div>
            <p style="margin: 0; color: var(--muted); font-size: 14px; line-height: 1.5;">
                <?php if ($pendingCount > 0): ?>
                    You have <strong style="color: var(--lime);"><?= $pendingCount ?></strong> pending appointment request<?= $pendingCount > 1 ? 's' : '' ?> waiting for your review.
                <?php else: ?>
                    All caught up! No pending requests at the moment.
                <?php endif; ?>
            </p>
        </div>
        <div style="display: flex; gap: 10px;">
            <a href="index.php?page=trainer_members" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-size: 14px; transition: all 0.2s;">
                View Clients
            </a>
        </div>
    </div>

    <?php render_announcement_carousel(get_active_announcements('trainers')); ?>

    <!-- 4 Metric Cards -->
    <div class="skeleton-content animate-fade-in delay-1" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 200px), 1fr)); gap: 16px; margin-bottom: 24px;">
        <!-- Assigned Clients -->
        <div class="trainer-metric-card" style="background: var(--bg); border: 1px solid var(--line); border-radius: 12px; padding: 20px; transition: transform 0.2s, box-shadow 0.2s; cursor: default; box-shadow: 0 2px 8px rgba(0,0,0,0.08);" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 8px 24px rgba(0,0,0,0.15)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)'">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                <div style="width: 36px; height: 36px; background: rgba(199,255,34,0.15); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="var(--lime)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <span style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted);">Active Clients</span>
            </div>
            <strong style="font-size: 32px; color: var(--ink); display: block;"><?= $clientCount ?></strong>
        </div>

        <!-- Pending Requests -->
        <div class="trainer-metric-card" style="background: var(--bg); border: 1px solid <?= $pendingCount > 0 ? 'rgba(245, 158, 11, 0.4)' : 'var(--line)' ?>; border-radius: 12px; padding: 20px; transition: transform 0.2s, box-shadow 0.2s; cursor: default; box-shadow: 0 2px 8px rgba(0,0,0,0.08);" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 8px 24px rgba(0,0,0,0.15)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)'">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                <div style="width: 36px; height: 36px; background: rgba(245, 158, 11, 0.15); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <span style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted);">Pending Requests</span>
            </div>
            <strong style="font-size: 32px; color: <?= $pendingCount > 0 ? '#f59e0b' : 'var(--ink)' ?>; display: block;"><?= $pendingCount ?></strong>
        </div>

        <!-- Training Plans -->
        <div class="trainer-metric-card" style="background: var(--bg); border: 1px solid var(--line); border-radius: 12px; padding: 20px; transition: transform 0.2s, box-shadow 0.2s; cursor: default; box-shadow: 0 2px 8px rgba(0,0,0,0.08);" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 8px 24px rgba(0,0,0,0.15)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)'">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                <div style="width: 36px; height: 36px; background: rgba(124, 92, 252, 0.15); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="#7c5cfc" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                </div>
                <span style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted);">Training Plans</span>
            </div>
            <strong style="font-size: 32px; color: var(--ink); display: block;"><?= $planCount ?></strong>
        </div>

        <!-- Total Earnings -->
        <div class="trainer-metric-card" style="background: var(--bg); border: 1px solid var(--line); border-radius: 12px; padding: 20px; transition: transform 0.2s, box-shadow 0.2s; cursor: default; box-shadow: 0 2px 8px rgba(0,0,0,0.08);" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 8px 24px rgba(0,0,0,0.15)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)'">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                <div style="width: 36px; height: 36px; background: rgba(34, 197, 94, 0.15); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: bold; color: #22c55e;">₱</div>
                <span style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted);">Total Earned</span>
            </div>
            <strong style="font-size: 32px; color: var(--ink); display: block;">₱<?= number_format($totalEarnings, 0) ?></strong>
            <?php if ($pendingEarnings > 0): ?>
                <span style="font-size: 12px; color: #f59e0b; margin-top: 4px; display: block;">₱<?= number_format($pendingEarnings, 0) ?> pending</span>
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
        @media (max-width: 768px) {
            .skeleton-content[style*="grid-template-columns: 1fr 1fr"] {
                grid-template-columns: 1fr !important;
            }
        }
    </style>
<?php
}
