<?php
declare(strict_types=1);

function my_commissions_page(): void
{
    $user = require_roles(['trainer']);
    $coachId = ensure_coach_profile((int) $user['user_id']);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = post('action', '');
        if ($action === 'save_my_pt_package') {
            $trainerGymId = (int) scalar('SELECT gym_id FROM trainer_profiles WHERE trainer_id = ? LIMIT 1', [$coachId]);
            $pkgName = trim((string) post('package_name', ''));
            $pkgType = post('package_type', 'session_pack') === 'monthly_coaching' ? 'monthly_coaching' : 'session_pack';
            $pkgPrice = max(0.0, (float) post('price', 0));
            $sessionCount = $pkgType === 'session_pack' ? max(1, (int) post('session_count', 1)) : null;
            $durationDays = max(1, (int) post('duration_days', 30));

            if ($trainerGymId <= 0 || $pkgName === '' || $pkgPrice <= 0) {
                flash('Please enter a valid package name and price greater than 0.', 'danger');
            } else {
                db()->prepare(
                    'INSERT INTO trainer_packages (gym_id, trainer_id, package_name, package_type, session_count, duration_days, price, commission_rate, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 1)'
                )->execute([$trainerGymId, $coachId, $pkgName, $pkgType, $sessionCount, $durationDays, $pkgPrice]);
                flash('PT package "' . $pkgName . '" added.');
            }
            redirect('my_commissions');
        } elseif ($action === 'toggle_my_pt_package') {
            $pkgId = (int) post('package_id', 0);
            if ($pkgId > 0) {
                db()->prepare('UPDATE trainer_packages SET is_active = IF(is_active = 1, 0, 1) WHERE package_id = ? AND trainer_id = ?')
                    ->execute([$pkgId, $coachId]);
                flash('PT package status updated.');
            }
            redirect('my_commissions');
        } elseif ($action === 'delete_my_pt_package') {
            $pkgId = (int) post('package_id', 0);
            if ($pkgId > 0) {
                db()->prepare('DELETE FROM trainer_packages WHERE package_id = ? AND trainer_id = ?')
                    ->execute([$pkgId, $coachId]);
                flash('PT package removed.');
            }
            redirect('my_commissions');
        } elseif ($action === 'update_session_rate') {
            $newRate = max(0.0, (float) post('session_rate', 0));
            db()->prepare('UPDATE trainer_profiles SET session_rate = ? WHERE trainer_id = ?')->execute([$newRate, $coachId]);
            flash('Your per-session rate has been updated successfully to ₱' . number_format($newRate, 2) . '.');
            redirect('my_commissions');
        }
    }

    $trainerGymId = (int) scalar('SELECT gym_id FROM trainer_profiles WHERE trainer_id = ? LIMIT 1', [$coachId]);
    $currentSessionRate = (float) scalar('SELECT session_rate FROM trainer_profiles WHERE trainer_id = ?', [$coachId]);
    $myPtPackages = query_all(
        'SELECT * FROM trainer_packages WHERE trainer_id = ? ORDER BY is_active DESC, price ASC, package_id DESC',
        [$coachId]
    );
    $gymWidePtPackages = $trainerGymId > 0
        ? query_all(
            'SELECT * FROM trainer_packages WHERE gym_id = ? AND trainer_id IS NULL AND is_active = 1 ORDER BY price ASC, package_id DESC',
            [$trainerGymId]
        )
        : [];

    $page = max(1, (int)($_GET['p'] ?? 1));
    $limit = 15;
    $offset = ($page - 1) * $limit;

    $countSql = 'SELECT COUNT(*) FROM trainer_commissions WHERE trainer_id = ' . (int)$user['user_id'];
    $total = (int) scalar($countSql);
    $totalPages = (int) ceil($total / $limit);

    $commissions = db()->query(
        'SELECT c.*, 
                COALESCE(ptp.receipt_number, p.receipt_number, "—") AS receipt_number,
                COALESCE(ptp.amount, p.amount, c.base_amount, 0) AS payment_amount,
                COALESCE(ptp.payment_date, p.payment_date) AS payment_date,
                CASE
                    WHEN c.pt_payment_id IS NOT NULL AND tpkg.package_name IS NOT NULL THEN CONCAT("PT Package: ", tpkg.package_name)
                    WHEN c.pt_payment_id IS NOT NULL THEN "PT Session"
                    WHEN mp.plan_name IS NOT NULL THEN CONCAT("Membership: ", mp.plan_name)
                    ELSE "Personal Training"
                END AS plan_name,
                COALESCE(
                    NULLIF(TRIM(CONCAT(COALESCE(pmu.first_name, ""), " ", COALESCE(pmu.last_name, ""))), ""),
                    NULLIF(TRIM(CONCAT(COALESCE(mu.first_name, ""), " ", COALESCE(mu.last_name, ""))), ""),
                    "Member"
                ) AS member_name
         FROM trainer_commissions c
         LEFT JOIN pt_payments ptp ON ptp.pt_payment_id = c.pt_payment_id
         LEFT JOIN trainer_packages tpkg ON tpkg.package_id = ptp.package_id
         LEFT JOIN users pmu ON pmu.user_id = ptp.member_user_id
         LEFT JOIN payments p ON p.payment_id = c.payment_id
         LEFT JOIN memberships m ON m.membership_id = p.membership_id
         LEFT JOIN users mu ON mu.user_id = m.user_id
         LEFT JOIN membership_plans mp ON mp.plan_id = m.plan_id
         WHERE c.trainer_id = ' . (int)$user['user_id'] . '
         ORDER BY c.created_at DESC
         LIMIT ' . $limit . ' OFFSET ' . $offset
    )->fetchAll();

    $stats = db()->query('
        SELECT 
            SUM(CASE WHEN status = "pending" THEN amount ELSE 0 END) AS pending_total,
            SUM(CASE WHEN status IN ("paid", "reversed") THEN amount ELSE 0 END) AS net_paid_total
        FROM trainer_commissions
        WHERE trainer_id = ' . (int)$user['user_id'] . '
    ')->fetch();

    // Current personal rate (PT sessions & packages)
    $profile = query_all('SELECT trainer_id, gym_id, commission_rate FROM trainer_profiles WHERE user_id = ? LIMIT 1', [(int) $user['user_id']])[0] ?? null;
    $myMetrics = ['active_clients' => 0, 'monthly_revenue' => 0.0];
    if ($profile) {
        $myMetrics = trainer_commission_metrics((int) $profile['trainer_id'], (int) $user['user_id']);
    }
    $fmtPct = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 2), '0'), '.') . '%';
    $myRateText = ($profile && $profile['commission_rate'] !== null) ? $fmtPct($profile['commission_rate']) : 'Package / Gym rate';

    render_header('My Commissions', $user);
    ?>
    <style>
        .commission-mobile-list { display: none; }
        @media (max-width: 640px) {
            .commission-history-table { display: none; }
            .commission-mobile-list { display: grid; gap: 12px; }
            .commission-mobile-card {
                padding: 16px;
                border: 1px solid var(--line);
                border-radius: 12px;
                background: var(--surface);
            }
            .commission-mobile-card-head {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 12px;
                margin-bottom: 14px;
            }
            .commission-mobile-member { margin: 0; color: var(--ink); font-size: 16px; }
            .commission-mobile-amount { color: var(--lime); font-size: 18px; font-weight: 800; white-space: nowrap; }
            .commission-mobile-details {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
                padding-top: 12px;
                border-top: 1px solid var(--line);
            }
            .commission-mobile-label {
                display: block;
                margin-bottom: 4px;
                color: var(--muted);
                font-size: 10px;
                font-weight: 700;
                letter-spacing: .06em;
                text-transform: uppercase;
            }
            .commission-mobile-value { color: var(--ink); font-size: 13px; line-height: 1.4; overflow-wrap: anywhere; }
        }
        .btn-reason-info {
            width: 22px !important;
            height: 22px !important;
            min-width: 22px !important;
            min-height: 22px !important;
            max-width: 22px !important;
            max-height: 22px !important;
            aspect-ratio: 1 / 1 !important;
            border-radius: 50% !important;
            box-sizing: border-box !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 0 !important;
            line-height: 0 !important;
            vertical-align: middle !important;
            flex-shrink: 0 !important;
            background: rgba(255, 255, 255, 0.08) !important;
            border: 1px solid var(--line) !important;
            color: var(--ink) !important;
            cursor: pointer !important;
            transition: all 0.15s ease !important;
        }
        .btn-reason-info:hover {
            background: rgba(255, 255, 255, 0.18) !important;
            border-color: var(--muted) !important;
            color: #ffffff !important;
            transform: scale(1.08) !important;
        }
        .btn-reason-info svg {
            width: 12px !important;
            height: 12px !important;
            display: block !important;
            flex-shrink: 0 !important;
        }
    </style>
    <section class="panel">
        <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
            <div>
                <h1>My Commissions</h1>
                <p>Track your earnings from personal training sessions &amp; coaching packages.</p>
            </div>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <button type="button" onclick="openTrainerPackagesModal()" class="btn" style="background:var(--panel-soft, rgba(255,255,255,0.04)); border:1px solid var(--line); color:var(--ink); font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; display:inline-flex; align-items:center; gap:8px; cursor:pointer;" title="View gym-wide PT packages and manage your personal packages">
                    <span style="color:var(--muted); font-weight:normal;">My PT Packages:</span>
                    <strong style="color:var(--lime);"><?= count($myPtPackages) + count($gymWidePtPackages) ?></strong>
                </button>
                <button type="button" onclick="editMySessionRate(<?= $currentSessionRate ?>)" class="btn" style="background:var(--panel-soft, rgba(255,255,255,0.04)); border:1px solid var(--line); color:var(--ink); font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; display:inline-flex; align-items:center; gap:8px; cursor:pointer;" title="Click to update your per-session fee">
                    <span style="color:var(--muted); font-weight:normal;">My Session Rate:</span>
                    <strong style="color:var(--lime);"><?= $currentSessionRate > 0 ? '₱' . number_format($currentSessionRate, 2) : 'Free / Included' ?></strong>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                </button>
            </div>
        </div>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr)); gap:15px; margin-bottom: 20px;">
            <div style="background:var(--bg); padding:15px; border-radius:8px; border:1px solid var(--line);">
                <div style="color:var(--muted); font-size:13px; margin-bottom:5px;">Pending Earnings</div>
                <div style="font-size:24px; font-weight:bold; color:var(--ink);"><?= h(money($stats['pending_total'] ?? 0)) ?></div>
            </div>
            <div style="background:var(--bg); padding:15px; border-radius:8px; border:1px solid var(--line);">
                <div style="color:var(--muted); font-size:13px; margin-bottom:5px;">Net Paid Earnings (All Time)</div>
                <div style="font-size:24px; font-weight:bold; color:var(--lime);"><?= h(money($stats['net_paid_total'] ?? 0)) ?></div>
            </div>
            <div style="background:var(--bg); padding:15px; border-radius:8px; border:1px solid var(--line);">
                <div style="color:var(--muted); font-size:13px; margin-bottom:5px;">Your Commission Rate</div>
                <div style="font-size:24px; font-weight:bold; color:var(--ink);">
                    <?= h($myRateText) ?>
                </div>
                <div style="color:var(--muted); font-size:12px; margin-top:4px;">
                    <?= (int) $myMetrics['active_clients'] ?> active client(s) · <?= h(money($myMetrics['monthly_revenue'])) ?> revenue this month
                </div>
            </div>
        </div>

        <p class="section-label">Commission History (<?= $total ?>)</p>
        <?php if (!$commissions): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <p>You haven't earned any commissions yet.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap commission-history-table">
            <table>
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Plan</th>
                        <th>Receipt</th>
                        <th>Rate</th>
                        <th>Commission</th>
                        <th>Date Earned</th>
                        <th>Status</th>
                        <th style="text-align:center; width:60px;">Note</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($commissions as $row): 
                    $statusClass = match ($row['status']) {
                        'paid' => 'badge badge-active',
                        'pending' => 'badge badge-pending',
                        'reversed' => 'badge',
                        'cancelled' => 'badge',
                        default => 'badge'
                    };
                    $statusStyle = match ($row['status']) {
                        'reversed' => 'background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3);',
                        'cancelled' => 'background: rgba(148, 163, 184, 0.15); color: var(--muted); border: 1px solid var(--line);',
                        default => ''
                    };
                    $isNegative = (float)$row['amount'] < 0;
                ?>
                    <tr>
                        <td><strong><?= h($row['member_name']) ?></strong></td>
                        <td style="font-size:13px;color:var(--muted)"><?= h($row['plan_name']) ?></td>
                        <td style="font-size:13px;font-family:monospace;color:var(--muted)"><?= h($row['receipt_number']) ?></td>
                        <td style="font-size:13px;color:var(--muted)"><?= $row['rate_applied'] !== null ? h($fmtPct($row['rate_applied'])) : '—' ?></td>
                        <td><strong style="color:<?= $isNegative ? '#ef4444' : 'var(--lime)' ?>"><?= h(money($row['amount'])) ?></strong></td>
                        <td><?= h(date('M j, Y', strtotime($row['created_at']))) ?></td>
                        <td>
                                <span class="<?= $statusClass ?>" style="<?= $statusStyle ?>"><?= h(ucfirst($row['status'])) ?></span>
                            </td>
                            <td style="text-align:center;">
                                <?php if (!empty($row['reversal_reason'])): 
                                    $reasonPayload = htmlspecialchars(json_encode([
                                        'status' => ucfirst($row['status']),
                                        'reason' => rawurldecode((string)$row['reversal_reason']),
                                        'date' => !empty($row['reversed_at']) ? date('M j, Y g:i A', strtotime($row['reversed_at'])) : date('M j, Y', strtotime($row['created_at']))
                                    ]), ENT_QUOTES, 'UTF-8');
                                ?>
                                    <button type="button" class="btn-reason-info" onclick="showReasonModal(<?= $reasonPayload ?>)" title="View reason note">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                                    </button>
                                <?php else: ?>
                                    <span style="color:var(--muted); font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="commission-mobile-list">
            <?php foreach ($commissions as $row):
                $statusClass = $row['status'] === 'paid' ? 'badge badge-active' : 'badge badge-pending';
            ?>
                <article class="commission-mobile-card">
                    <div class="commission-mobile-card-head">
                        <h3 class="commission-mobile-member"><?= h($row['member_name']) ?></h3>
                        <span class="commission-mobile-amount"><?= h(money($row['amount'])) ?></span>
                    </div>
                    <div class="commission-mobile-details">
                        <div>
                            <span class="commission-mobile-label">Plan</span>
                            <div class="commission-mobile-value"><?= h($row['plan_name']) ?></div>
                        </div>
                        <div>
                            <span class="commission-mobile-label">Receipt</span>
                            <div class="commission-mobile-value" style="font-family:monospace;"><?= h($row['receipt_number']) ?></div>
                        </div>
                        <div>
                            <span class="commission-mobile-label">Date earned</span>
                            <div class="commission-mobile-value"><?= h(date('M j, Y', strtotime($row['created_at']))) ?></div>
                        </div>
                        <div>
                            <span class="commission-mobile-label">Status</span>
                            <span class="<?= $statusClass ?>"><?= h(ucfirst($row['status'])) ?></span>
                            <?php if (!empty($row['reversal_reason'])): 
                                $reasonPayload = htmlspecialchars(json_encode([
                                    'status' => ucfirst($row['status']),
                                    'reason' => rawurldecode((string)$row['reversal_reason']),
                                    'date' => !empty($row['reversed_at']) ? date('M j, Y g:i A', strtotime($row['reversed_at'])) : date('M j, Y', strtotime($row['created_at']))
                                ]), ENT_QUOTES, 'UTF-8');
                            ?>
                                <button type="button" class="btn-reason-info" onclick="showReasonModal(<?= $reasonPayload ?>)" title="View reason note" style="margin-left:6px !important;">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php render_pagination($page, $totalPages, '?page=my_commissions'); ?>
        <?php endif; ?>
    </section>
    <!-- Modal: Trainer PT Packages -->
    <div id="trainerPackagesModal" style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(2,6,23,0.72); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:16px;" onclick="if (event.target === this) closeTrainerPackagesModal();">
        <div role="dialog" aria-modal="true" aria-labelledby="trainerPackagesModalTitle" style="width:100%; max-width:620px; max-height:92vh; overflow-y:auto; background:var(--surface, #ffffff); border:1px solid var(--line); border-radius:16px; padding:22px 24px; box-shadow:0 24px 64px rgba(0,0,0,0.45);">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; padding-bottom:14px; margin-bottom:16px; border-bottom:1px solid var(--line);">
                <div style="flex:1; min-width:0;">
                    <h3 id="trainerPackagesModalTitle" style="margin:0 0 4px; font-size:17px; color:var(--ink);">My Personal Training Packages</h3>
                    <p style="margin:0; font-size:12.5px; color:var(--muted);">Packages you create appear on your trainer card for members to book. Commission split follows your gym's configured rate.</p>
                </div>
                <button type="button" onclick="closeTrainerPackagesModal()" aria-label="Close modal" style="width:32px; height:32px; border-radius:8px; border:1px solid var(--line); background:transparent; color:var(--muted); font-size:20px; line-height:1; cursor:pointer; flex-shrink:0;">&times;</button>
            </div>

            <?php if ($myPtPackages): ?>
                <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:var(--muted); margin-bottom:8px;">Your Personal Packages (<?= count($myPtPackages) ?>)</div>
                <div style="display:flex; flex-direction:column; gap:8px; margin-bottom:18px;">
                    <?php foreach ($myPtPackages as $pkg): ?>
                        <div style="padding:10px 12px; border:1px solid var(--line); border-radius:10px; background:var(--bg); display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
                            <div style="min-width:0;">
                                <div style="font-size:13.5px; font-weight:700; color:var(--ink);">
                                    <?= h($pkg['package_name']) ?>
                                    <?php if (!$pkg['is_active']): ?>
                                        <span class="badge" style="font-size:10px; margin-left:4px;">Paused</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:12px; color:var(--muted); margin-top:2px;">
                                    <strong style="color:var(--lime);">₱<?= number_format((float)$pkg['price'], 2) ?></strong>
                                    · <?= $pkg['package_type'] === 'monthly_coaching' ? ((int)$pkg['duration_days'] . ' days coaching') : ((int)$pkg['session_count'] . ' sessions') ?>
                                </div>
                            </div>
                            <div style="display:flex; gap:6px;">
                                <form method="post" style="margin:0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_my_pt_package">
                                    <input type="hidden" name="package_id" value="<?= (int)$pkg['package_id'] ?>">
                                    <button type="button" onclick="confirmMyPtPackageAction(this.form, <?= $pkg['is_active'] ? "'pause'" : "'activate'" ?>, <?= htmlspecialchars(json_encode((string)$pkg['package_name']), ENT_QUOTES, 'UTF-8') ?>)" class="btn btn-sm" style="padding:4px 8px; font-size:11.5px; background:transparent; color:var(--ink); border:1px solid var(--line); border-radius:6px; cursor:pointer;">
                                        <?= $pkg['is_active'] ? 'Pause' : 'Activate' ?>
                                    </button>
                                </form>
                                <form method="post" style="margin:0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_my_pt_package">
                                    <input type="hidden" name="package_id" value="<?= (int)$pkg['package_id'] ?>">
                                    <button type="button" onclick="confirmMyPtPackageAction(this.form, 'delete', <?= htmlspecialchars(json_encode((string)$pkg['package_name']), ENT_QUOTES, 'UTF-8') ?>)" class="btn btn-sm" style="padding:4px 8px; font-size:11.5px; background:transparent; color:#ef4444; border:1px solid rgba(239,68,68,0.35); border-radius:6px; cursor:pointer;">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="font-size:13px; color:var(--muted); margin:0 0 14px;">You haven't created any personal PT packages yet.</p>
            <?php endif; ?>

            <?php if ($gymWidePtPackages): ?>
                <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:var(--muted); margin-bottom:8px;">Gym-Wide PT Packages (<?= count($gymWidePtPackages) ?>)</div>
                <div style="display:flex; flex-direction:column; gap:8px; margin-bottom:18px;">
                    <?php foreach ($gymWidePtPackages as $pkg): ?>
                        <div style="padding:10px 12px; border:1px solid var(--line); border-radius:10px; background:var(--bg); display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
                            <div style="min-width:0;">
                                <div style="font-size:13.5px; font-weight:700; color:var(--ink);">
                                    <?= h($pkg['package_name']) ?>
                                    <span class="badge" style="font-size:10px; margin-left:6px;">Gym-Wide</span>
                                </div>
                                <div style="font-size:12px; color:var(--muted); margin-top:2px;">
                                    <strong style="color:var(--lime);">₱<?= number_format((float)$pkg['price'], 2) ?></strong>
                                    · <?= $pkg['package_type'] === 'monthly_coaching' ? ((int)$pkg['duration_days'] . ' days coaching') : ((int)$pkg['session_count'] . ' sessions') ?>
                                </div>
                            </div>
                            <span style="font-size:11.5px; color:var(--muted);">Managed by Gym</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" style="border-top:1px solid var(--line); padding-top:14px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_my_pt_package">
                <div style="margin-bottom:12px;">
                    <label style="display:flex; align-items:center; height:18px; line-height:18px; font-size:11px; color:var(--muted); font-weight:700; text-transform:uppercase; margin-bottom:6px;">Package Name *</label>
                    <input type="text" name="package_name" required placeholder="e.g. 10 PT Sessions Pack" class="form-control" style="margin:0; width:100%; height:40px; box-sizing:border-box;">
                </div>
                <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; margin-bottom:12px; align-items:end;">
                    <div>
                        <label style="display:flex; align-items:center; height:18px; line-height:18px; font-size:11px; color:var(--muted); font-weight:700; text-transform:uppercase; margin-bottom:6px;">Type</label>
                        <select name="package_type" class="form-control" style="margin:0; width:100%; height:40px; box-sizing:border-box;">
                            <option value="session_pack">Session Pack</option>
                            <option value="monthly_coaching">Monthly Coaching</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:flex; align-items:center; height:18px; line-height:18px; font-size:11px; color:var(--muted); font-weight:700; text-transform:uppercase; margin-bottom:6px;">Price (₱) *</label>
                        <input type="number" step="0.01" min="1" name="price" required placeholder="3500.00" class="form-control" style="margin:0; width:100%; height:40px; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:flex; align-items:center; height:18px; line-height:18px; font-size:11px; color:var(--muted); font-weight:700; text-transform:uppercase; margin-bottom:6px;">Sessions</label>
                        <input type="number" min="1" name="session_count" value="10" class="form-control" style="margin:0; width:100%; height:40px; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:flex; align-items:center; height:18px; line-height:18px; font-size:11px; color:var(--muted); font-weight:700; text-transform:uppercase; margin-bottom:6px;">Validity (Days)</label>
                        <input type="number" min="1" name="duration_days" value="30" class="form-control" style="margin:0; width:100%; height:40px; box-sizing:border-box;">
                    </div>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:8px; padding-top:12px; border-top:1px solid var(--line);">
                    <button type="button" onclick="closeTrainerPackagesModal()" class="btn btn-sm" style="height:40px; padding:0 16px; background:transparent; color:var(--muted); border:1px solid var(--line);">Close</button>
                    <button type="submit" class="btn" style="height:40px; padding:0 20px; font-weight:700;">+ Add PT Package</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function confirmMyPtPackageAction(form, actionType, pkgName) {
        if (!form) return;
        const isDelete = actionType === 'delete';
        const isPause = actionType === 'pause';
        const title = isDelete ? 'Delete PT Package?' : (isPause ? 'Pause PT Package?' : 'Activate PT Package?');
        const text = isDelete
            ? `Delete "${pkgName}"? Past payment records will remain intact, but members will no longer see this package.`
            : (isPause
                ? `Pause "${pkgName}"? Members won't see it for new bookings until you activate it again.`
                : `Activate "${pkgName}" so members can book it again?`);
        const confirmText = isDelete ? 'Yes, delete' : (isPause ? 'Yes, pause' : 'Yes, activate');
        const confirmColor = isDelete ? '#ef4444' : (isPause ? '#f59e0b' : 'var(--lime-dark)');

        Swal.fire({
            title,
            text,
            icon: isDelete ? 'warning' : 'question',
            showCancelButton: true,
            confirmButtonText: confirmText,
            confirmButtonColor: confirmColor,
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            didOpen: () => {
                const c = Swal.getContainer();
                if (c) c.style.zIndex = '100000';
            }
        }).then((res) => {
            if (res.isConfirmed) form.submit();
        });
    }

    function openTrainerPackagesModal() {
        const modal = document.getElementById('trainerPackagesModal');
        if (!modal) return;
        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
        modal.style.display = 'flex';
    }

    function closeTrainerPackagesModal() {
        const modal = document.getElementById('trainerPackagesModal');
        if (modal) modal.style.display = 'none';
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeTrainerPackagesModal();
    });

    function editMySessionRate(currentRate) {
        Swal.fire({
            title: 'My Per-Session Rate',
            html: `
                <div style="text-align:left; margin-top:8px;">
                    <p style="font-size:13px; color:var(--muted); margin-bottom:14px; line-height:1.45;">
                        Set your per-session personal training fee. Personal training sessions and packages are billed separately from gym membership plans and generate your commission when paid.
                    </p>
                    <form id="rateForm" method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_session_rate">
                        <label style="font-size:12.5px; font-weight:700; color:var(--ink); display:block; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.5px;">Session Fee (PHP):</label>
                        <div style="position:relative;">
                            <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); font-weight:700; color:var(--lime);">₱</span>
                            <input type="number" step="0.01" min="0" name="session_rate" id="swal_session_rate" class="form-control" value="${parseFloat(currentRate || 0).toFixed(2)}" style="padding-left:28px; width:100%; box-sizing:border-box; font-size:15px; font-weight:600;" required>
                        </div>
                        <small style="display:block; margin-top:6px; color:var(--muted); font-size:11.5px;">Set to 0.00 if your appointments are complimentary / included.</small>
                    </form>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Save Rate',
            confirmButtonColor: 'var(--lime-dark)',
            preConfirm: () => {
                const form = document.getElementById('rateForm');
                if (form) form.submit();
            }
        });
    }

    function showReasonModal(data) {
        if (!data) return;
        const status = data.status || 'Note';
        const reason = data.reason || 'No description provided.';
        const date = data.date || '';

        Swal.fire({
            title: status + ' Note',
            html: `
                <div style="text-align: left; padding: 6px 0; font-size: 14px; line-height: 1.5; color: var(--ink);">
                    <div style="background: var(--surface); border: 1px solid var(--line); border-radius: 8px; padding: 14px; word-break: break-word;">
                        ${reason.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>')}
                    </div>
                    ${date ? `<div style="margin-top: 10px; font-size: 12px; color: var(--muted); text-align: right;">Logged: ${date}</div>` : ''}
                </div>
            `,
            icon: 'info',
            confirmButtonText: 'Close',
            confirmButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)'
        });
    }
    </script>
    <?php
    render_footer();
}
