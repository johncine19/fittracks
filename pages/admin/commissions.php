<?php
declare(strict_types=1);

function commissions_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner']);
    $isOwner = $user['role'] === 'gym_owner';
    $gymId = 0;
    if ($isOwner) {
        require_gym_feature('commissions');
        $gymId = (int) (get_user_gym_id($user) ?? 0);
        if ($gymId <= 0) {
            flash('No gym found for your account.', 'danger');
            redirect('dashboard');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        $pdo = db();

        if ($action === 'mark_paid') {
            $commissionId = (int) post('commission_id');
            if ($isOwner) {
                // Gym owners may only settle commissions earned at their own gym
                $pdo->prepare("
                    UPDATE trainer_commissions tc
                    JOIN payments p ON p.payment_id = tc.payment_id
                    JOIN memberships m ON m.membership_id = p.membership_id
                    JOIN membership_plans mp ON mp.plan_id = m.plan_id
                    SET tc.status = 'paid'
                    WHERE tc.commission_id = ? AND tc.status = 'pending' AND mp.gym_id = ?
                ")->execute([$commissionId, $gymId]);
            } else {
                $pdo->prepare("UPDATE trainer_commissions SET status = 'paid' WHERE commission_id = ? AND status = 'pending'")
                    ->execute([$commissionId]);
            }
            audit_log($user['user_id'], 'mark_paid', 'commission', (string) $commissionId);
            flash('Commission marked as paid.');
            redirect('commissions&tab=payouts');
        }

        if ($action === 'reverse_commission') {
            $commissionId = (int) post('commission_id');
            $rawReason = (string) post('reversal_reason', 'Manual commission reversal');
            $reason = trim(rawurldecode($rawReason));
            if ($reason === '') {
                $reason = 'Manual commission reversal';
            }

            // Verify gym ownership if gym_owner
            $whereOwner = '';
            $checkParams = [$commissionId];
            if ($isOwner) {
                $whereOwner = ' AND mp.gym_id = ?';
                $checkParams[] = $gymId;
            }

            $commRow = query_all("
                SELECT tc.*, mp.gym_id, m.user_id AS member_user_id
                FROM trainer_commissions tc
                JOIN payments p ON p.payment_id = tc.payment_id
                JOIN memberships m ON m.membership_id = p.membership_id
                JOIN membership_plans mp ON mp.plan_id = m.plan_id
                WHERE tc.commission_id = ? $whereOwner
                LIMIT 1
            ", $checkParams)[0] ?? null;

            if ($commRow && in_array($commRow['status'], ['pending', 'paid'], true)) {
                $now = date('Y-m-d H:i:s');
                $endAssignment = (post('end_assignment') === '1');
                $flashMsg = '';

                if ($commRow['status'] === 'pending') {
                    $pdo->prepare('
                        UPDATE trainer_commissions
                        SET status = "cancelled", reversal_reason = ?, reversed_at = ?, reversed_by = ?
                        WHERE commission_id = ?
                    ')->execute([$reason, $now, $user['user_id'], $commissionId]);
                    audit_log($user['user_id'], 'cancel', 'commission', (string)$commissionId, json_encode(['reason' => $reason]));
                    $flashMsg = 'Pending commission cancelled.';
                } else {
                    // Paid commission: mark as reversed and insert offsetting debit ledger entry
                    $pdo->prepare('
                        UPDATE trainer_commissions
                        SET status = "reversed", reversal_reason = ?, reversed_at = ?, reversed_by = ?
                        WHERE commission_id = ?
                    ')->execute([$reason, $now, $user['user_id'], $commissionId]);

                    $debitAmount = -abs((float)$commRow['amount']);
                    $debitBase = $commRow['base_amount'] !== null ? -abs((float)$commRow['base_amount']) : null;
                    $pdo->prepare('
                        INSERT INTO trainer_commissions
                            (trainer_id, payment_id, amount, base_amount, rate_applied, base_rate, rate_source, status, reversal_reason, reversed_at, reversed_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, "reversed", ?, ?, ?)
                    ')->execute([
                        (int)$commRow['trainer_id'],
                        (int)$commRow['payment_id'],
                        $debitAmount,
                        $debitBase,
                        $commRow['rate_applied'],
                        $commRow['base_rate'],
                        $commRow['rate_source'] ?? 'plan',
                        'Reversal debit: ' . $reason,
                        $now,
                        $user['user_id']
                    ]);

                    audit_log($user['user_id'], 'reverse', 'commission', (string)$commissionId, json_encode(['reason' => $reason, 'debit_amount' => $debitAmount]));
                    $flashMsg = 'Paid commission reversed with immutable debit entry logged.';
                }

                // If requested, also end the trainer client assignment
                if ($endAssignment && !empty($commRow['member_user_id']) && !empty($commRow['trainer_id'])) {
                    $pdo->prepare("
                        UPDATE trainer_assignments ta
                        JOIN trainer_profiles tp ON tp.trainer_id = ta.trainer_id
                        SET ta.status = 'ended', ta.ended_date = CURDATE()
                        WHERE ta.member_user_id = ? AND tp.user_id = ? AND ta.status = 'active'
                    ")->execute([(int)$commRow['member_user_id'], (int)$commRow['trainer_id']]);

                    audit_log($user['user_id'], 'end_assignment', 'trainer_assignment', (string)$commRow['member_user_id'], json_encode([
                        'reason' => 'Commission reversed: ' . $reason,
                        'trainer_user_id' => (int)$commRow['trainer_id']
                    ]));
                    $flashMsg .= ' Trainer client assignment was also ended.';
                }

                flash($flashMsg);
            } else {
                flash('Commission record not eligible for reversal.', 'danger');
            }
            redirect('commissions&tab=payouts');
        }

        if ($isOwner && $action === 'save_trainer_rates') {
            $rates = $_POST['rates'] ?? [];
            $changed = [];
            if (is_array($rates)) {
                $stmt = $pdo->prepare('UPDATE trainer_profiles SET commission_rate = ? WHERE trainer_id = ? AND gym_id = ?');
                foreach ($rates as $trainerId => $rate) {
                    $rate = trim((string) $rate);
                    $value = $rate === '' ? null : max(0.0, min(100.0, (float) $rate));
                    $stmt->execute([$value, (int) $trainerId, $gymId]);
                    if ($stmt->rowCount() > 0) {
                        $changed[(int) $trainerId] = $value;
                    }
                }
            }
            if ($changed) {
                audit_log($user['user_id'], 'edit', 'commission_rates', null, json_encode($changed), $gymId);
            }
            flash('Trainer commission rates saved.');
            redirect('commissions&tab=settings');
        }
    }

    // Active tab: 'payouts' or 'settings'
    $activeTab = ($_GET['tab'] ?? 'payouts');
    if (!in_array($activeTab, ['payouts', 'settings'], true)) {
        $activeTab = 'payouts';
    }

    // ── Commission list (gym-scoped for owners, optional trainer filter) ──
    $page = max(1, (int)($_GET['p'] ?? 1));
    $limit = 15;
    $offset = ($page - 1) * $limit;
    $filterTrainerId = (int) ($_GET['trainer_id'] ?? 0);
    $searchQ = trim((string) ($_GET['q'] ?? ''));

    $from = 'FROM trainer_commissions c
         JOIN users u ON u.user_id = c.trainer_id
         JOIN payments p ON p.payment_id = c.payment_id
         JOIN memberships m ON m.membership_id = p.membership_id
         JOIN users mu ON mu.user_id = m.user_id
         JOIN membership_plans mp ON mp.plan_id = m.plan_id';
    $where = [];
    $params = [];
    if ($isOwner) {
        $where[] = 'mp.gym_id = ?';
        $params[] = $gymId;
    }
    if ($filterTrainerId > 0) {
        $where[] = 'c.trainer_id = (SELECT user_id FROM trainer_profiles WHERE trainer_id = ?)';
        $params[] = $filterTrainerId;
    }
    if ($searchQ !== '') {
        $where[] = '(CONCAT(u.first_name, " ", u.last_name) LIKE ? OR CONCAT(mu.first_name, " ", mu.last_name) LIKE ? OR p.receipt_number LIKE ? OR mp.plan_name LIKE ?)';
        $searchParam = '%' . $searchQ . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $total = (int) scalar('SELECT COUNT(*) ' . $from . $whereSql, $params);
    $totalPages = (int) ceil($total / $limit);

    $commissions = query_all(
        'SELECT c.*,
                CONCAT(u.first_name, " ", u.last_name) AS trainer_name,
                p.receipt_number,
                p.amount AS payment_amount,
                p.payment_date,
                mp.plan_name,
                CONCAT(mu.first_name, " ", mu.last_name) AS member_name
         ' . $from . $whereSql . '
         ORDER BY c.created_at DESC
         LIMIT ' . $limit . ' OFFSET ' . $offset,
        $params
    );

    // ── Settings data (gym owners only) ──
    $trainers = [];
    if ($isOwner) {
        $trainers = query_all(
            'SELECT tp.trainer_id, tp.user_id, tp.commission_rate, tp.specialization,
                    CONCAT(u.first_name, " ", u.last_name) AS name
             FROM trainer_profiles tp
             JOIN users u ON u.user_id = tp.user_id
             WHERE tp.gym_id = ? AND u.status = "active"
             ORDER BY u.first_name, u.last_name',
            [$gymId]
        );
        foreach ($trainers as &$t) {
            $t['metrics'] = trainer_commission_metrics((int) $t['trainer_id'], (int) $t['user_id']);
        }
        unset($t);
    }

    $rateLabel = static function (array $row): string {
        if ($row['rate_applied'] === null) {
            return '—';
        }
        return rtrim(rtrim(number_format((float) $row['rate_applied'], 2), '0'), '.') . '%';
    };

    render_header('Trainer Commissions', $user);
    ?>
    <style>
        .commissions-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            padding: 4px;
            background: rgba(0,0,0,0.2);
            border-radius: 12px;
            width: fit-content;
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
        .commissions-tab-btn {
            background: transparent;
            color: var(--muted);
            border: none;
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .commissions-tab-btn:hover {
            color: var(--ink);
        }
        .commissions-tab-btn.active {
            background: var(--surface);
            color: var(--ink);
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .commissions-tab-content {
            display: none;
        }
        .commissions-tab-content.active {
            display: block;
        }

        .admin-commission-mobile-list { display: none; }
        .commission-settings-grid { display: grid; grid-template-columns: 1fr; max-width: 680px; margin-bottom: 24px; }
        .commission-settings-card { padding: 18px; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); }
        .commission-settings-card h3 { margin: 0 0 4px; font-size: 16px; color: var(--ink); }
        .commission-settings-card .hint { margin: 0 0 14px; font-size: 12.5px; color: var(--muted); line-height: 1.45; }
        .trainer-search-wrap {
            position: relative;
            margin-bottom: 14px;
        }
        .trainer-search-wrap svg {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            pointer-events: none;
        }
        .trainer-search-input {
            width: 100% !important;
            padding-left: 36px !important;
            box-sizing: border-box !important;
            margin: 0 !important;
            background: var(--bg) !important;
            border-radius: 8px !important;
        }
        .trainer-rates-list {
            max-height: 380px;
            overflow-y: auto;
            padding-right: 4px;
        }
        .commission-rate-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-top: 1px solid var(--line); }
        .commission-rate-row:first-of-type { border-top: 0; }
        .commission-rate-meta { font-size: 12px; color: var(--muted); }
        .commission-rate-input { width: 90px !important; text-align: right; margin: 0 !important; }
        .commission-tier-chip { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; background: rgba(163,230,53,.12); color: var(--lime); }
        .commission-tier-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 0; border-top: 1px solid var(--line); font-size: 13.5px; color: var(--ink); }
        .commission-tier-form { display: grid; grid-template-columns: 1.4fr 1fr 1fr auto; gap: 8px; margin-top: 14px; align-items: end; }
        .commission-tier-form label { font-size: 11px; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
        .commission-tier-form .form-control { margin-top: 4px; width: 100%; box-sizing: border-box; }
        @media (max-width: 900px) {
            .commission-settings-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .commission-tier-form { grid-template-columns: 1fr 1fr; }
            .admin-commission-table { display: none; }
            .admin-commission-mobile-list { display: grid; gap: 12px; }
            .admin-commission-card {
                padding: 16px;
                border: 1px solid var(--line);
                border-radius: 12px;
                background: var(--surface);
            }
            .admin-commission-card-head {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 12px;
                margin-bottom: 14px;
            }
            .admin-commission-trainer { margin: 0; color: var(--ink); font-size: 16px; }
            .admin-commission-amount { color: var(--lime); font-size: 18px; font-weight: 800; white-space: nowrap; }
            .admin-commission-details {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
                padding-top: 12px;
                border-top: 1px solid var(--line);
            }
            .admin-commission-label {
                display: block;
                margin-bottom: 4px;
                color: var(--muted);
                font-size: 10px;
                font-weight: 700;
                letter-spacing: .06em;
                text-transform: uppercase;
            }
            .admin-commission-value { color: var(--ink); font-size: 13px; line-height: 1.4; overflow-wrap: anywhere; }
            .admin-commission-action { width: 100%; margin-top: 14px; padding: 8px 10px !important; }
        }
    </style>
    <section class="panel">
        <div class="page-header">
            <div>
                <p style="margin: 0;">Manage pending and paid payouts to trainers, as well as commission rates.</p>
            </div>
        </div>

        <?php if ($isOwner): ?>
        <div class="commissions-tabs">
            <button type="button" class="commissions-tab-btn <?= $activeTab === 'payouts' ? 'active' : '' ?>" data-tab="payouts" onclick="switchCommissionTab('payouts')">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                Commission Payouts (<?= $total ?>)
            </button>
            <button type="button" class="commissions-tab-btn <?= $activeTab === 'settings' ? 'active' : '' ?>" data-tab="settings" onclick="switchCommissionTab('settings')">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                Commission Settings
            </button>
        </div>
        <?php endif; ?>

        <!-- TAB: Commission Payouts List -->
        <div id="tab-payouts" class="commissions-tab-content <?= $activeTab === 'payouts' ? 'active' : '' ?>">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
                <p class="section-label" style="margin:0;">
                    <?= $filterTrainerId > 0 || $searchQ !== '' ? 'Filtered Commissions' : 'All Commissions' ?> (<?= $total ?>)
                    <?php if ($filterTrainerId > 0 || $searchQ !== ''): ?>
                        · <a href="index.php?page=commissions&tab=payouts" style="color:var(--lime); font-size:12px;">Reset filters</a>
                    <?php endif; ?>
                </p>
                <form method="get" action="index.php" style="display:flex; align-items:center; gap:8px; margin:0;">
                    <input type="hidden" name="page" value="commissions">
                    <input type="hidden" name="tab" value="payouts">
                    <?php if ($filterTrainerId > 0): ?>
                        <input type="hidden" name="trainer_id" value="<?= $filterTrainerId ?>">
                    <?php endif; ?>
                    <div style="position:relative;">
                        <input type="text" name="q" value="<?= h($searchQ) ?>" placeholder="Search trainer, member, receipt..." 
                               class="form-control" style="width:240px; margin:0; padding-right:28px; font-size:13px; height:36px; box-sizing:border-box;">
                        <?php if ($searchQ !== ''): ?>
                            <a href="index.php?page=commissions&tab=payouts<?= $filterTrainerId > 0 ? '&trainer_id=' . $filterTrainerId : '' ?>" 
                               style="position:absolute; right:8px; top:50%; transform:translateY(-50%); color:var(--muted); text-decoration:none; font-size:16px;">&times;</a>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-sm" style="height:36px; padding:0 12px;">Search</button>
                </form>
            </div>
            <?php if (!$commissions): ?>
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    <p>No commissions logged yet.</p>
                </div>
            <?php else: ?>
            <div class="table-wrap admin-commission-table">
                <table>
                    <thead>
                        <tr>
                            <th>Trainer</th>
                            <th>Member</th>
                            <th>Plan</th>
                            <th>Receipt</th>
                            <th>Rate</th>
                            <th>Commission</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th style="text-align:center; width:60px;">Note</th>
                            <th style="text-align:right">Action</th>
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
                            <td><strong><?= h($row['trainer_name']) ?></strong></td>
                            <td><?= h($row['member_name']) ?></td>
                            <td style="font-size:13px;color:var(--muted)"><?= h($row['plan_name']) ?></td>
                            <td style="font-size:13px;font-family:monospace;color:var(--muted)"><?= h($row['receipt_number']) ?></td>
                            <td style="font-size:13px;color:var(--muted)" title="<?= h(($row['rate_source'] ?? 'plan') === 'trainer' ? 'Trainer rate' : 'Plan rate') ?> on <?= h(money($row['base_amount'] ?? $row['payment_amount'])) ?>"><?= h($rateLabel($row)) ?></td>
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
                            <td style="text-align:right">
                                <?php if ($row['status'] === 'pending'): ?>
                                    <button class="btn btn-sm" onclick="markPaid(<?= $row['commission_id'] ?>)" style="padding:4px 8px; font-size:12px; background:transparent; color:var(--lime); border:1px solid var(--lime); border-radius:4px; cursor:pointer;">Mark Paid</button>
                                    <button class="btn btn-sm" onclick="reverseCommission(<?= $row['commission_id'] ?>, 'pending')" style="padding:4px 8px; font-size:12px; background:transparent; color:var(--muted); border:1px solid var(--line); border-radius:4px; cursor:pointer; margin-left:4px;">Cancel</button>
                                <?php elseif ($row['status'] === 'paid' && !$isNegative): ?>
                                    <button class="btn btn-sm" onclick="reverseCommission(<?= $row['commission_id'] ?>, 'paid')" style="padding:4px 8px; font-size:12px; background:transparent; color:#ef4444; border:1px solid rgba(239, 68, 68, 0.4); border-radius:4px; cursor:pointer;">Reverse</button>
                                <?php else: ?>
                                    <span style="font-size:12px;color:var(--muted)"><?= $row['status'] === 'reversed' ? 'Reversed' : 'Settled' ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="admin-commission-mobile-list">
                <?php foreach ($commissions as $row):
                    $statusClass = $row['status'] === 'paid' ? 'badge badge-active' : 'badge badge-pending';
                ?>
                    <article class="admin-commission-card">
                        <div class="admin-commission-card-head">
                            <h3 class="admin-commission-trainer"><?= h($row['trainer_name']) ?></h3>
                            <span class="admin-commission-amount"><?= h(money($row['amount'])) ?></span>
                        </div>
                        <div class="admin-commission-details">
                            <div>
                                <span class="admin-commission-label">Member</span>
                                <div class="admin-commission-value"><?= h($row['member_name']) ?></div>
                            </div>
                            <div>
                                <span class="admin-commission-label">Plan</span>
                                <div class="admin-commission-value"><?= h($row['plan_name']) ?></div>
                            </div>
                            <div>
                                <span class="admin-commission-label">Receipt</span>
                                <div class="admin-commission-value" style="font-family:monospace;"><?= h($row['receipt_number']) ?></div>
                            </div>
                            <div>
                                <span class="admin-commission-label">Rate</span>
                                <div class="admin-commission-value"><?= h($rateLabel($row)) ?></div>
                            </div>
                            <div>
                                <span class="admin-commission-label">Date</span>
                                <div class="admin-commission-value"><?= h(date('M j, Y', strtotime($row['created_at']))) ?></div>
                            </div>
                            <div>
                                <span class="admin-commission-label">Status</span>
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
                        <?php if ($row['status'] === 'pending'): ?>
                            <button class="btn btn-sm admin-commission-action" onclick="markPaid(<?= $row['commission_id'] ?>)" style="background:transparent; color:var(--lime); border:1px solid var(--lime); border-radius:6px; cursor:pointer;">Mark Paid</button>
                            <button class="btn btn-sm admin-commission-action" onclick="reverseCommission(<?= $row['commission_id'] ?>, 'pending')" style="background:transparent; color:var(--muted); border:1px solid var(--line); border-radius:6px; cursor:pointer; margin-top:6px;">Cancel</button>
                        <?php elseif ($row['status'] === 'paid' && !$isNegative): ?>
                            <button class="btn btn-sm admin-commission-action" onclick="reverseCommission(<?= $row['commission_id'] ?>, 'paid')" style="background:transparent; color:#ef4444; border:1px solid rgba(239, 68, 68, 0.4); border-radius:6px; cursor:pointer;">Reverse</button>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php 
            $paginationBase = '?page=commissions&tab=payouts';
            if ($filterTrainerId > 0) $paginationBase .= '&trainer_id=' . $filterTrainerId;
            if ($searchQ !== '') $paginationBase .= '&q=' . urlencode($searchQ);
            render_pagination($page, $totalPages, $paginationBase); 
            ?>
            <?php endif; ?>
        </div>

        <?php if ($isOwner): ?>
        <!-- TAB: Commission Settings -->
        <div id="tab-settings" class="commissions-tab-content <?= $activeTab === 'settings' ? 'active' : '' ?>">
            <p class="section-label">Commission Settings</p>
            <div class="commission-settings-grid">
                <!-- Per-trainer rates -->
                <form method="post" class="commission-settings-card">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_trainer_rates">
                    <h3>Trainer Rates</h3>
                    <p class="hint">Set a personal commission % per trainer. Leave blank to use each membership plan's own rate. Plans with a 0% rate never pay commission.</p>
                    <?php if (!$trainers): ?>
                        <p class="hint" style="margin:0;">No active trainers at your gym yet.</p>
                    <?php else: ?>
                        <div class="trainer-search-wrap">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" id="trainerRateSearch" class="form-control trainer-search-input" placeholder="Search trainers by name or specialization..." oninput="filterTrainerRates(this.value)">
                        </div>
                        <div class="trainer-rates-list" id="trainerRatesList">
                            <?php foreach ($trainers as $t): ?>
                                <div class="commission-rate-row" data-trainer-name="<?= h(strtolower($t['name'] . ' ' . ($t['specialization'] ?? ''))) ?>">
                                    <div>
                                        <strong style="color:var(--ink); font-size:14px;"><?= h($t['name']) ?></strong>
                                        <?php if (!empty($t['specialization'])): ?>
                                             <span style="font-size:12px; color:var(--muted); margin-left: 4px;">(<?= h($t['specialization']) ?>)</span>
                                        <?php endif; ?>
                                        <div class="commission-rate-meta">
                                            <?= (int) $t['metrics']['active_clients'] ?> active client(s) ·
                                            <?= h(money($t['metrics']['monthly_revenue'])) ?> this month
                                        </div>
                                    </div>
                                    <div style="display:flex; align-items:center; gap:6px;">
                                        <input type="number" step="0.01" min="0" max="100" name="rates[<?= (int) $t['trainer_id'] ?>]"
                                               class="form-control commission-rate-input" placeholder="Plan"
                                               value="<?= $t['commission_rate'] !== null ? h((string) (float) $t['commission_rate']) : '' ?>">
                                        <span style="color:var(--muted); font-size:13px;">%</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p id="noTrainersFoundMsg" class="hint" style="display:none; margin: 12px 0 0; text-align: center;">No matching trainers found.</p>
                        <button type="submit" class="btn" style="margin-top:14px; width:100%;">Save Trainer Rates</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <script>
    function filterTrainerRates(query) {
        const q = (query || '').toLowerCase().trim();
        const rows = document.querySelectorAll('#trainerRatesList .commission-rate-row');
        let visibleCount = 0;

        rows.forEach(row => {
            const name = row.getAttribute('data-trainer-name') || '';
            if (!q || name.includes(q)) {
                row.style.display = 'flex';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        const noMatchMsg = document.getElementById('noTrainersFoundMsg');
        if (noMatchMsg) {
            noMatchMsg.style.display = (visibleCount === 0 && rows.length > 0) ? 'block' : 'none';
        }
    }

    function switchCommissionTab(tab, updateHistory = true) {
        document.querySelectorAll('.commissions-tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.commissions-tab-content').forEach(content => content.classList.remove('active'));
        
        const activeBtn = document.querySelector(`.commissions-tab-btn[data-tab="${tab}"]`);
        if (activeBtn) activeBtn.classList.add('active');
        
        const activeTabContent = document.getElementById(`tab-${tab}`);
        if (activeTabContent) activeTabContent.classList.add('active');

        if (updateHistory) {
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('tab', tab);
                window.history.replaceState(null, '', url.toString());
            } catch (e) {}
        }
    }

    window.addEventListener('popstate', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const tab = urlParams.get('tab') || 'payouts';
        switchCommissionTab(tab, false);
    });

    function markPaid(id) {
        Swal.fire({
            title: 'Mark as Paid?',
            text: "This will settle the commission for the trainer.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, mark paid',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_paid">
                    <input type="hidden" name="commission_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    function reverseCommission(id, currentStatus) {
        const isPaid = (currentStatus === 'paid');
        Swal.fire({
            title: isPaid ? 'Reverse Commission?' : 'Cancel Commission?',
            html: `
                <p style="font-size: 13px; color: var(--muted); margin-bottom: 12px; line-height: 1.4;">
                    ${isPaid 
                        ? 'This commission was already marked as paid. Reversing it will create an immutable offset debit ledger entry.'
                        : 'This pending commission will be cancelled and marked in the ledger.'}
                </p>
                <input id="swal-rev-reason" class="swal2-input" placeholder="Enter reason (e.g. Member refunded, cancelled)..." style="font-size: 13px; width: 85%; margin: 8px auto; display: block; box-sizing: border-box;">
                <label style="display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 14px; font-size: 13px; color: var(--ink); cursor: pointer; user-select: none;">
                    <input type="checkbox" id="swal-end-assignment" checked style="accent-color: var(--primary); width: 16px; height: 16px; cursor: pointer;">
                    <span><strong>Also end trainer assignment</strong> for this client</span>
                </label>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: isPaid ? 'Yes, reverse & debit' : 'Yes, cancel',
            confirmButtonColor: '#ef4444',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            focusConfirm: false,
            preConfirm: () => {
                const reasonInput = document.getElementById('swal-rev-reason');
                const reason = reasonInput ? reasonInput.value.trim() : '';
                if (!reason) {
                    Swal.showValidationMessage('Please enter a reversal reason for audit tracking.');
                    return false;
                }
                const endAssignment = document.getElementById('swal-end-assignment') ? document.getElementById('swal-end-assignment').checked : false;
                return { reason, endAssignment };
            }
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reverse_commission">
                    <input type="hidden" name="commission_id" value="${id}">
                    <input type="hidden" name="reversal_reason" value="${encodeURIComponent(result.value.reason)}">
                    <input type="hidden" name="end_assignment" value="${result.value.endAssignment ? '1' : '0'}">
                `;
                document.body.appendChild(form);
                form.submit();
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
