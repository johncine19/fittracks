<?php
declare(strict_types=1);

function staff_dashboard(PDO $pdo, array $user): void
{
    $gym = get_user_gym($user);
    $gymId = $gym ? (int)$gym['gym_id'] : 0;
    $gymName = $gym['name'] ?? 'FitTrack Gym';

    if (!$gymId) {
        echo '<section class="panel"><h2>No Gym Assigned</h2><p>Your staff account is not assigned to a gym yet. Please contact your Gym Owner.</p></section>';
        return;
    }

    auto_checkout_past_attendance();

    // Handle quick checkout POST from dashboard
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'staff_checkout') {
        verify_csrf();
        $attId = (int) post('attendance_id');
        if ($attId > 0) {
            $pdo->prepare('UPDATE attendance SET check_out_time = NOW() WHERE attendance_id = ? AND gym_id = ? AND check_out_time IS NULL')
                ->execute([$attId, $gymId]);
            flash('Member checked out successfully.', 'success');
            redirect('dashboard');
            return;
        }
    }

    // Key Operational Metrics
    $activeOnFloor = (int) scalar(
        'SELECT COUNT(*) FROM attendance WHERE gym_id = ? AND check_out_time IS NULL AND DATE(check_in_time) = CURDATE()',
        [$gymId]
    );

    $todayTotalVisits = (int) scalar(
        'SELECT COUNT(*) FROM attendance WHERE gym_id = ? AND DATE(check_in_time) = CURDATE()',
        [$gymId]
    );

    $todayWalkIns = (int) scalar(
        'SELECT COUNT(*) FROM walk_in_transactions WHERE gym_id = ? AND DATE(visit_date) = CURDATE()',
        [$gymId]
    );

    $expiringSoon = (int) scalar(
        'SELECT COUNT(DISTINCT m.user_id) 
         FROM memberships m 
         JOIN membership_plans mp ON mp.plan_id = m.plan_id 
         WHERE mp.gym_id = ? AND m.status = "active" 
           AND m.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)',
        [$gymId]
    );

    // Live On-Floor Members (Currently in gym)
    $activeMembers = query_all(
        'SELECT a.attendance_id, a.check_in_time, a.check_in_method,
                u.user_id, u.first_name, u.last_name, u.profile_picture, u.email, u.phone,
                (SELECT mp.plan_name FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = u.user_id AND m.status = "active" LIMIT 1) AS plan_name
         FROM attendance a
         JOIN users u ON u.user_id = a.user_id
         WHERE a.gym_id = ? AND a.check_out_time IS NULL AND DATE(a.check_in_time) = CURDATE()
         ORDER BY a.check_in_time DESC
         LIMIT 20',
        [$gymId]
    );

    // Recent Walk-in Passes Sold Today
    $recentWalkIns = query_all(
        'SELECT w.transaction_id, w.guest_name, w.contact_info AS guest_phone, w.payment_method, w.amount_paid, w.visit_date AS created_at
         FROM walk_in_transactions w
         WHERE w.gym_id = ? AND DATE(w.visit_date) = CURDATE()
         ORDER BY w.transaction_id DESC
         LIMIT 5',
        [$gymId]
    );

    $greeting = 'Good morning';
    $hour = (int) date('G');
    if ($hour >= 12 && $hour < 17) $greeting = 'Good afternoon';
    elseif ($hour >= 17) $greeting = 'Good evening';
    ?>

    <!-- Staff Welcome Header -->
    <div class="staff-dashboard-banner" style="border-radius: 16px; padding: 24px 28px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                <span style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); font-size: 11px; font-weight: 800; padding: 3px 9px; border-radius: 999px; text-transform: uppercase; letter-spacing: 0.05em;">
                    Front Desk Terminal
                </span>
                <span class="staff-dashboard-banner-meta" style="font-size: 13px; font-weight: 600;">
                    <?= h($gymName) ?> &bull; <?= date('l, F j, Y') ?>
                </span>
            </div>
            <h1 class="staff-dashboard-banner-title" style="margin: 0; font-size: 26px; font-weight: 800;">
                <?= h($greeting) ?>, <?= h($user['first_name']) ?>!
            </h1>
            <p class="staff-dashboard-banner-description" style="margin: 4px 0 0; font-size: 13.5px;">
                Front desk check-in, pass ticketing, and floor management.
            </p>
        </div>
        <div class="staff-dashboard-actions" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="index.php?page=scanner" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px; font-weight: 700; text-decoration: none;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                Launch QR Scanner
            </a>
            <a href="index.php?page=walk_ins" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px; font-weight: 700; text-decoration: none;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/></svg>
                Issue Walk-In Pass
            </a>
            <button type="button" onclick="openStaffEODModal()" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px; font-weight: 700; border-color: rgba(132, 204, 22, 0.4); color: var(--lime); cursor: pointer;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                EOD Settlement Slip
            </button>
        </div>
        <div class="staff-mobile-fab-wrap">
            <div id="staffMobileActionMenu" class="staff-mobile-action-menu" hidden>
                <a href="index.php?page=scanner" class="staff-mobile-action-item">Launch QR Scanner</a>
                <a href="index.php?page=walk_ins" class="staff-mobile-action-item">Issue Walk-In Pass</a>
                <button type="button" onclick="openStaffEODModal(); closeStaffMobileActions();" class="staff-mobile-action-item">EOD Settlement Slip</button>
            </div>
            <button type="button" class="staff-mobile-fab" aria-label="Open staff actions" aria-expanded="false" aria-controls="staffMobileActionMenu" onclick="toggleStaffMobileActions(this)">
                <svg class="staff-mobile-fab-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            </button>
        </div>
    </div>

    <script>
    function toggleStaffMobileActions(button) {
        const menu = document.getElementById('staffMobileActionMenu');
        if (!menu) return;
        const open = button.getAttribute('aria-expanded') !== 'true';
        menu.hidden = !open;
        button.setAttribute('aria-expanded', String(open));
        button.setAttribute('aria-label', open ? 'Close staff actions' : 'Open staff actions');
        button.classList.toggle('is-open', open);
    }
    function closeStaffMobileActions() {
        const menu = document.getElementById('staffMobileActionMenu');
        const button = document.querySelector('.staff-mobile-fab');
        if (!menu || !button) return;
        menu.hidden = true;
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-label', 'Open staff actions');
        button.classList.remove('is-open');
    }
    </script>

    <!-- Quick Stats Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div class="panel" style="margin: 0; padding: 20px; border-radius: 14px; border: 1px solid var(--line); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(34, 197, 94, 0.15); color: #22c55e; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            </div>
            <div>
                <div style="font-size: 12.5px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Inside Gym Now</div>
                <div style="font-size: 28px; font-weight: 800; color: #22c55e; line-height: 1.2;"><?= $activeOnFloor ?></div>
                <div style="font-size: 11.5px; color: var(--muted);">Live on workout floor</div>
            </div>
        </div>

        <div class="panel" style="margin: 0; padding: 20px; border-radius: 14px; border: 1px solid var(--line); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(56, 189, 248, 0.15); color: #38bdf8; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
            </div>
            <div>
                <div style="font-size: 12.5px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Today's Check-ins</div>
                <div style="font-size: 28px; font-weight: 800; color: var(--ink); line-height: 1.2;"><?= $todayTotalVisits ?></div>
                <div style="font-size: 11.5px; color: var(--muted);">Total turnstile scans today</div>
            </div>
        </div>

        <div class="panel" style="margin: 0; padding: 20px; border-radius: 14px; border: 1px solid var(--line); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(168, 85, 247, 0.15); color: #a855f7; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/></svg>
            </div>
            <div>
                <div style="font-size: 12.5px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Walk-In Passes Sold</div>
                <div style="font-size: 28px; font-weight: 800; color: #a855f7; line-height: 1.2;"><?= $todayWalkIns ?></div>
                <div style="font-size: 11.5px; color: var(--muted);">Day passes issued today</div>
            </div>
        </div>

        <div class="panel" style="margin: 0; padding: 20px; border-radius: 14px; border: 1px solid var(--line); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(245, 158, 11, 0.15); color: #f59e0b; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            </div>
            <div>
                <div style="font-size: 12.5px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Expiring in 48h</div>
                <div style="font-size: 28px; font-weight: 800; color: #f59e0b; line-height: 1.2;"><?= $expiringSoon ?></div>
                <div style="font-size: 11.5px; color: var(--muted);">Members needing renewal</div>
            </div>
        </div>
    </div>

    <!-- Active On Floor Table -->
    <div class="panel" style="border-radius: 16px; padding: 24px; margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 12px;">
            <div>
                <h2 style="margin: 0; font-size: 19px; font-weight: 800; color: var(--ink);">
                    Active Members on Floor (<?= count($activeMembers) ?>)
                </h2>
                <p style="margin: 2px 0 0; color: var(--muted); font-size: 13px;">
                    Real-time list of members currently logged into the facility.
                </p>
            </div>
            <div style="display: flex; gap: 8px;">
                <a href="index.php?page=attendance" class="btn btn-secondary btn-sm" style="text-decoration: none;">View Attendance History &rarr;</a>
            </div>
        </div>

        <?php if (empty($activeMembers)): ?>
            <div style="text-align: center; padding: 40px 20px; color: var(--muted);">
                <div style="font-size: 16px; font-weight: 700; color: var(--ink);">No active check-ins right now</div>
                <p style="font-size: 13px; margin: 4px 0 16px;">Scan a member's QR code or register a walk-in to check them in.</p>
                <a href="index.php?page=scanner" class="btn btn-primary btn-sm" style="text-decoration: none;">Launch Scanner</a>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13.5px;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--line); color: var(--muted); text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.05em;">
                            <th style="padding: 10px 14px;">Member</th>
                            <th style="padding: 10px 14px;">Membership Plan</th>
                            <th style="padding: 10px 14px;">Check-In Time</th>
                            <th style="padding: 10px 14px;">Time Inside</th>
                            <th style="padding: 10px 14px; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeMembers as $m): ?>
                            <?php
                                $inTime = new DateTime($m['check_in_time']);
                                $now = new DateTime();
                                $diff = $inTime->diff($now);
                                $durationStr = ($diff->h > 0 ? $diff->h . 'h ' : '') . max(1, $diff->i) . 'm';
                            ?>
                            <tr style="border-bottom: 1px solid var(--line);">
                                <td style="padding: 12px 14px;">
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <div style="width: 38px; height: 38px; border-radius: 50%; background: var(--surface); border: 1px solid var(--line); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; overflow: hidden; flex-shrink: 0;">
                                            <?php if (!empty($m['profile_picture'])): ?>
                                                <img src="<?= h(upload_url($m['profile_picture'])) ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                                            <?php else: ?>
                                                <?= strtoupper(substr($m['first_name'], 0, 1)) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div style="font-weight: 700; color: var(--ink);">
                                                <?= h($m['first_name'] . ' ' . $m['last_name']) ?>
                                            </div>
                                            <div style="font-size: 12px; color: var(--muted);">
                                                <?= h($m['phone'] ?: $m['email']) ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding: 12px 14px;">
                                    <span style="background: rgba(56, 189, 248, 0.12); color: #0284c7; padding: 3px 8px; border-radius: 6px; font-size: 11.5px; font-weight: 700;">
                                        <?= h($m['plan_name'] ?: 'Day Pass / Standard') ?>
                                    </span>
                                </td>
                                <td style="padding: 12px 14px; color: var(--ink); font-weight: 600;">
                                    <?= date('g:i A', strtotime($m['check_in_time'])) ?>
                                </td>
                                <td style="padding: 12px 14px; color: #22c55e; font-weight: 700;">
                                    <?= h($durationStr) ?>
                                </td>
                                <td style="padding: 12px 14px; text-align: right;">
                                    <form method="post" style="margin: 0; display: inline-block;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="staff_checkout">
                                        <input type="hidden" name="attendance_id" value="<?= (int)$m['attendance_id'] ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm" style="font-size: 12px; padding: 6px 12px; border-radius: 8px;">
                                            Check Out
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Quick EOD Daily Settlement Logic for Front Desk -->
    <script>
    window.openStaffEODModal = function() {
        if (typeof Swal === 'undefined') {
            alert('Loading daily settlement...');
            return;
        }

        Swal.fire({
            title: 'Loading Daily Settlement...',
            text: 'Fetching real-time End-of-Day register numbers',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
                const formData = new FormData();
                formData.append('action', 'get_eod_data');
                formData.append('csrf_token', <?= json_encode(csrf_token()) ?>);
                formData.append('gym_id', <?= $gymId ?>);

                fetch('index.php?page=dashboard', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                })
                .then(r => r.json())
                .then(res => {
                    if (!res.success) {
                        throw new Error(res.error || 'Failed to load settlement data.');
                    }
                    const d = res.data;
                    const gymName = <?= json_encode($gymName) ?>;

                    Swal.fire({
                        width: 'min(94vw, 460px)',
                        html: `
                            <div style="text-align:left;font-family:'Inter',system-ui,sans-serif;margin-top:4px;">
                                <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line);padding-bottom:12px;margin-bottom:14px;padding-right:36px;">
                                    <div>
                                        <div style="font-size:16px;font-weight:800;color:var(--ink);">${gymName}</div>
                                        <div style="font-size:12px;color:var(--muted);margin-top:2px;">Daily Settlement &bull; <strong>${d.date_formatted}</strong></div>
                                    </div>
                                    <span class="eod-reading-badge" style="background:rgba(132,204,22,0.12);color:var(--lime);font-size:11px;font-weight:800;padding:3px 8px;border-radius:6px;border:1px solid rgba(132,204,22,0.3);">
                                        Z-READING
                                    </span>
                                </div>

                                <div style="background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:14px;text-align:center;margin-bottom:14px;">
                                    <div style="font-size:11.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em;">Total Daily Gross Revenue</div>
                                    <div style="font-size:26px;font-weight:900;color:${d.is_masked ? 'var(--muted)' : 'var(--lime)'};margin:4px 0 2px;letter-spacing:${d.is_masked ? '3px' : 'normal'};">${d.total_gross_display || '••••••'}</div>
                                    ${d.is_masked ? '<div style="font-size:11.5px;color:#f59e0b;font-weight:600;">🔒 Staff Financial Privacy Active</div>' : `<div style="font-size:11.5px;color:var(--muted);">${d.total_transactions} total transactions logged today</div>`}
                                </div>

                                <table style="width:100%;border-collapse:collapse;font-size:13px;line-height:1.6;margin-bottom:14px;">
                                    <tr style="border-bottom:1px solid var(--line);">
                                        <td style="padding:7px 0;color:var(--muted);">Memberships / Plans:</td>
                                        <td style="padding:7px 0;text-align:right;font-weight:700;color:var(--ink);">${d.subscriptions_revenue_display || '••••••'} <span style="font-size:11.5px;color:var(--muted);">(${d.subscriptions_count} sales)</span></td>
                                    </tr>
                                    <tr style="border-bottom:1px solid var(--line);">
                                        <td style="padding:7px 0;color:var(--muted);">Walk-In Passes:</td>
                                        <td style="padding:7px 0;text-align:right;font-weight:700;color:var(--ink);">${d.walkins_revenue_display || '••••••'} <span style="font-size:11.5px;color:var(--muted);">(${d.walkins_count} passes)</span></td>
                                    </tr>
                                    <tr style="border-bottom:1px solid var(--line);">
                                        <td style="padding:7px 0;color:var(--muted);">Facility Check-Ins:</td>
                                        <td style="padding:7px 0;text-align:right;font-weight:700;color:#38bdf8;">${d.checkins_count} check-ins</td>
                                    </tr>
                                    <tr style="border-bottom:1px solid var(--line);">
                                        <td style="padding:7px 0;color:var(--muted);">Expiring Tomorrow:</td>
                                        <td style="padding:7px 0;text-align:right;font-weight:700;color:#f59e0b;">${d.expiring_tomorrow} members</td>
                                    </tr>
                                </table>

                                <div style="display:flex;gap:8px;margin-top:16px;">
                                    <button type="button" onclick="printStaffSlip('${gymName}', '${d.date_formatted}', '${d.total_gross_display || '••••••'}', '${d.subscriptions_revenue_display || '••••••'}', '${d.subscriptions_count}', '${d.walkins_revenue_display || '••••••'}', '${d.walkins_count}', '${d.checkins_count}', '${d.expiring_tomorrow}', '${d.active_members}', ${Boolean(d.is_masked)})" class="btn btn-secondary" style="flex:1;padding:9px;font-size:12.5px;font-weight:700;border-radius:8px;cursor:pointer;">
                                        Print Slip
                                    </button>
                                    <button type="button" onclick="sendStaffEODEmail()" class="btn btn-primary" style="flex:1;padding:9px;font-size:12.5px;font-weight:700;border-radius:8px;cursor:pointer;" title="Email full settlement to Gym Owner">
                                        Email Owner
                                    </button>
                                </div>
                            </div>
                        `,
                        showConfirmButton: false,
                        showCloseButton: true,
                        allowOutsideClick: true,
                        allowEscapeKey: true,
                        customClass: { popup: 'staff-eod-settlement-modal' },
                        didOpen: (popup) => {
                            const closeBtn = popup.querySelector('.swal2-close');
                            if (closeBtn) {
                                closeBtn.setAttribute('aria-label', 'Close settlement');
                                closeBtn.onclick = (event) => {
                                    event.preventDefault();
                                    event.stopPropagation();
                                    Swal.close();
                                };
                            }
                        }
                    });
                })
                .catch(err => {
                    Swal.fire('Error', err.message || 'Could not load settlement data.', 'error');
                });
            }
        });
    };

    window.printStaffSlip = function(gym, date, totalDisp, subDisp, subCnt, walkDisp, walkCnt, attCnt, expCnt, activeCnt, isMasked) {
        const printWindow = window.open('', '_blank', 'width=420,height=600');
        if (!printWindow) return alert('Please allow popups to print slip.');
        printWindow.document.write(`
            <html><head><title>Daily Settlement</title><style>body{font-family:monospace;width:280px;margin:20px auto;font-size:13px;}.c{text-align:center;}.b{font-weight:bold;}.r{display:flex;justify-content:space-between;margin:4px 0;}.l{border-top:1px dashed #000;margin:8px 0;}</style></head>
            <body><div class="c b" style="font-size:16px;">${gym}</div><div class="c">EOD SETTLEMENT (Z-READING)</div><div class="c">${date}</div><div class="l"></div>
            <div class="r"><span>Subscriptions (${subCnt}):</span><span>${subDisp}</span></div>
            <div class="r"><span>Walk-Ins (${walkCnt}):</span><span>${walkDisp}</span></div><div class="l"></div>
            <div class="r b" style="font-size:15px;"><span>TOTAL GROSS:</span><span>${totalDisp}</span></div>
            ${isMasked ? '<div class="c" style="font-size:10px;color:#555;margin:4px 0;">[STAFF FINANCIAL PRIVACY ACTIVE]</div>' : ''}
            <div class="l"></div>
            <div class="r"><span>Facility Check-Ins:</span><span>${attCnt}</span></div>
            <div class="r"><span>Expiring Tomorrow:</span><span>${expCnt}</span></div>
            <div class="l"></div><div class="c" style="font-size:11px;margin-top:12px;">Official Staff Shift Closing &bull; FitTrack</div>
            <script>window.onload=function(){window.print();}<\/script></body></html>
        `);
        printWindow.document.close();
    };

    window.sendStaffEODEmail = function() {
        const formData = new FormData();
        formData.append('action', 'send_eod_email');
        formData.append('csrf_token', <?= json_encode(csrf_token()) ?>);
        formData.append('gym_id', <?= $gymId ?>);

        Swal.fire({
            title: 'Emailing Settlement to Owner...',
            didOpen: () => {
                Swal.showLoading();
                fetch('index.php?page=dashboard', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                })
                .then(r => r.json())
                .then(res => {
                    Swal.fire(res.success ? 'Success!' : 'Notice', res.message || 'Settlement dispatched to owner inbox.', res.success ? 'success' : 'info');
                })
                .catch(err => {
                    Swal.fire('Error', err.message || 'Failed to dispatch email.', 'error');
                });
            }
        });
    };
    </script>
    <?php
}
