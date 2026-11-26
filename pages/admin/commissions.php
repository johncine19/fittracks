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
                // Gym owners may only settle commissions earned at their own gym (PT or legacy membership)
                $pdo->prepare("
                    UPDATE trainer_commissions tc
                    LEFT JOIN pt_payments ptp ON ptp.pt_payment_id = tc.pt_payment_id
                    LEFT JOIN payments p ON p.payment_id = tc.payment_id
                    LEFT JOIN memberships m ON m.membership_id = p.membership_id
                    LEFT JOIN membership_plans mp ON mp.plan_id = m.plan_id
                    SET tc.status = 'paid'
                    WHERE tc.commission_id = ? AND tc.status = 'pending' AND COALESCE(ptp.gym_id, mp.gym_id) = ?
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

            // Verify gym ownership if gym_owner (supports both PT & legacy membership commissions)
            $whereOwner = '';
            $checkParams = [$commissionId];
            if ($isOwner) {
                $whereOwner = ' AND COALESCE(ptp.gym_id, mp.gym_id) = ?';
                $checkParams[] = $gymId;
            }

            $commRow = query_all("
                SELECT tc.*, COALESCE(ptp.gym_id, mp.gym_id) AS gym_id,
                       COALESCE(ptp.member_user_id, m.user_id) AS member_user_id
                FROM trainer_commissions tc
                LEFT JOIN pt_payments ptp ON ptp.pt_payment_id = tc.pt_payment_id
                LEFT JOIN payments p ON p.payment_id = tc.payment_id
                LEFT JOIN memberships m ON m.membership_id = p.membership_id
                LEFT JOIN membership_plans mp ON mp.plan_id = m.plan_id
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
                            (trainer_id, payment_id, source_type, pt_payment_id, assignment_id, amount, base_amount, rate_applied, base_rate, rate_source, status, reversal_reason, reversed_at, reversed_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "reversed", ?, ?, ?)
                    ')->execute([
                        (int)$commRow['trainer_id'],
                        !empty($commRow['payment_id']) ? (int)$commRow['payment_id'] : null,
                        (string)($commRow['source_type'] ?? (!empty($commRow['pt_payment_id']) ? 'pt_session' : 'membership')),
                        !empty($commRow['pt_payment_id']) ? (int)$commRow['pt_payment_id'] : null,
                        !empty($commRow['assignment_id']) ? (int)$commRow['assignment_id'] : null,
                        $debitAmount,
                        $debitBase,
                        $commRow['rate_applied'],
                        $commRow['base_rate'],
                        $commRow['rate_source'] ?? 'trainer',
                        'Reversal debit: ' . $reason,
                        $now,
                        $user['user_id']
                    ]);

                    audit_log($user['user_id'], 'reverse', 'commission', (string)$commissionId, json_encode(['reason' => $reason, 'debit_amount' => $debitAmount]));
                    $flashMsg = 'Paid commission reversed with immutable debit entry logged.';
                }

                // If requested, also end the trainer client assignment
                if ($endAssignment && !empty($commRow['member_user_id']) && !empty($commRow['trainer_id'])) {
                    if (!empty($commRow['assignment_id'])) {
                        $pdo->prepare("UPDATE trainer_assignments SET status = 'ended', ended_date = CURDATE() WHERE assignment_id = ? AND status = 'active'")
                            ->execute([(int)$commRow['assignment_id']]);
                    } else {
                        $pdo->prepare("
                            UPDATE trainer_assignments ta
                            JOIN trainer_profiles tp ON tp.trainer_id = ta.trainer_id
                            SET ta.status = 'ended', ta.ended_date = CURDATE()
                            WHERE ta.member_user_id = ? AND tp.user_id = ? AND ta.status = 'active'
                        ")->execute([(int)$commRow['member_user_id'], (int)$commRow['trainer_id']]);
                    }

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

        if ($isOwner && $action === 'save_pt_package') {
            $pkgName = trim((string) post('package_name', ''));
            $pkgType = post('package_type') === 'monthly_coaching' ? 'monthly_coaching' : 'session_pack';
            $sessionCount = $pkgType === 'session_pack' ? max(1, (int) post('session_count', 1)) : max(1, (int) post('session_count', 30));
            $durationDays = max(1, (int) post('duration_days', 30));
            $pkgPrice = max(0.0, (float) post('price', 0));
            $pkgCommRaw = trim((string) post('commission_rate', ''));
            $pkgComm = $pkgCommRaw === '' ? null : max(0.0, min(100.0, (float) $pkgCommRaw));
            $pkgTrainerId = (int) post('trainer_id', 0);
            $pkgDesc = trim((string) post('description', ''));

            if ($pkgName === '' || $pkgPrice <= 0) {
                flash('Package name and a positive price are required.', 'danger');
            } else {
                $validTrainerId = null;
                if ($pkgTrainerId > 0) {
                    $belongs = (bool) scalar('SELECT 1 FROM trainer_profiles WHERE trainer_id = ? AND gym_id = ? LIMIT 1', [$pkgTrainerId, $gymId]);
                    if ($belongs) {
                        $validTrainerId = $pkgTrainerId;
                    }
                }
                $pdo->prepare('
                    INSERT INTO trainer_packages
                        (gym_id, trainer_id, package_name, package_type, session_count, duration_days, price, commission_rate, description, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ')->execute([$gymId, $validTrainerId, $pkgName, $pkgType, $sessionCount, $durationDays, $pkgPrice, $pkgComm, $pkgDesc ?: null]);
                audit_log($user['user_id'], 'create', 'trainer_package', (string)$pdo->lastInsertId(), json_encode(['package_name' => $pkgName, 'price' => $pkgPrice]), $gymId);
                flash('PT package "' . $pkgName . '" added successfully.');
            }
            redirect('commissions&tab=settings');
        }

        if ($isOwner && $action === 'toggle_pt_package') {
            $pkgId = (int) post('package_id', 0);
            if ($pkgId > 0) {
                $pdo->prepare('UPDATE trainer_packages SET is_active = IF(is_active = 1, 0, 1) WHERE package_id = ? AND gym_id = ?')->execute([$pkgId, $gymId]);
                flash('PT package status updated.');
            }
            redirect('commissions&tab=settings');
        }

        if ($isOwner && $action === 'delete_pt_package') {
            $pkgId = (int) post('package_id', 0);
            if ($pkgId > 0) {
                $pdo->prepare('DELETE FROM trainer_packages WHERE package_id = ? AND gym_id = ?')->execute([$pkgId, $gymId]);
                flash('PT package deleted.');
            }
            redirect('commissions&tab=settings');
        }

        if ($isOwner && $action === 'record_pt_payment') {
            $memberUserId = (int) post('member_user_id', 0);
            $trainerProfileId = (int) post('trainer_id', 0);
            $packageId = (int) post('package_id', 0);
            $payType = $packageId > 0 ? 'pt_package' : 'pt_session';
            $sessionsPurchased = max(1, (int) post('sessions_purchased', 1));
            $amount = max(0.0, (float) post('amount', 0));
            $payMethod = trim((string) post('payment_method', 'cash')) ?: 'cash';
            $payDate = trim((string) post('payment_date', date('Y-m-d'))) ?: date('Y-m-d');
            $payStatus = post('status') === 'pending' ? 'pending' : 'paid';
            $notes = trim((string) post('notes', ''));
            $receipt = 'PT-' . date('Ymd') . '-' . random_int(1000, 9999);

            $trainerRow = query_one('SELECT trainer_id, user_id, session_rate FROM trainer_profiles WHERE trainer_id = ? AND gym_id = ? LIMIT 1', [$trainerProfileId, $gymId]);
            $memberOk = $memberUserId > 0 && (bool) scalar('SELECT 1 FROM gym_members WHERE gym_id = ? AND user_id = ? LIMIT 1', [$gymId, $memberUserId]);

            if (!$trainerRow || !$memberOk) {
                flash('Please select a valid member and trainer from your gym.', 'danger');
                redirect('commissions&tab=pt_payments');
            }

            $validPkgId = null;
            $pkgLabel = 'PT Session';
            if ($packageId > 0) {
                $pkgRow = query_one('SELECT * FROM trainer_packages WHERE package_id = ? AND gym_id = ? LIMIT 1', [$packageId, $gymId]);
                if ($pkgRow) {
                    $validPkgId = (int)$pkgRow['package_id'];
                    $pkgLabel = (string)$pkgRow['package_name'];
                    if ($amount <= 0) {
                        $amount = (float)$pkgRow['price'];
                    }
                    if (!empty($pkgRow['session_count'])) {
                        $sessionsPurchased = (int)$pkgRow['session_count'];
                    }
                }
            } elseif ($amount <= 0) {
                $amount = (float)$trainerRow['session_rate'];
            }

            if ($amount <= 0) {
                flash('Please enter a valid PT payment amount greater than 0.', 'danger');
                redirect('commissions&tab=pt_payments');
            }

            // Link to active assignment if one exists between this member and trainer
            $linkedAssignmentId = scalar("SELECT assignment_id FROM trainer_assignments WHERE trainer_id = ? AND member_user_id = ? AND status IN ('active','pending_trainer','pending_admin') ORDER BY assignment_id DESC LIMIT 1", [$trainerProfileId, $memberUserId]);

            $pdo->prepare('
                INSERT INTO pt_payments
                    (gym_id, member_user_id, trainer_id, assignment_id, package_id, payment_type, sessions_purchased, sessions_remaining, amount, payment_date, payment_method, status, receipt_number, processed_by, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $gymId,
                $memberUserId,
                $trainerProfileId,
                $linkedAssignmentId ? (int)$linkedAssignmentId : null,
                $validPkgId,
                $payType,
                $sessionsPurchased,
                $sessionsPurchased,
                $amount,
                $payDate,
                $payMethod,
                $payStatus,
                $receipt,
                $user['user_id'],
                $notes ?: null,
            ]);
            $newPtPaymentId = (int)$pdo->lastInsertId();

            if ($payStatus === 'paid') {
                process_pt_commission($newPtPaymentId);
                notify_user($memberUserId, 'system', 'PT Payment Recorded', money($amount) . ' received for ' . $pkgLabel . '. Receipt: ' . $receipt . '.');
                notify_user((int)$trainerRow['user_id'], 'system', 'PT Commission Earned', 'A PT payment (' . $pkgLabel . ', ' . money($amount) . ') was settled and credited to your commissions.');
            }

            audit_log($user['user_id'], 'create', 'pt_payment', (string)$newPtPaymentId, json_encode(['amount' => $amount, 'type' => $payType, 'status' => $payStatus, 'receipt' => $receipt]), $gymId);
            flash($payStatus === 'paid' ? 'PT payment recorded and trainer commission credited.' : 'Pending PT payment recorded.');
            redirect('commissions&tab=pt_payments');
        }

        if ($isOwner && $action === 'mark_pt_payment_paid') {
            $ptPayId = (int) post('pt_payment_id', 0);
            $ptRow = query_one('SELECT * FROM pt_payments WHERE pt_payment_id = ? AND gym_id = ? AND status = "pending" LIMIT 1', [$ptPayId, $gymId]);
            if ($ptRow) {
                $pdo->prepare('UPDATE pt_payments SET status = "paid", processed_by = ? WHERE pt_payment_id = ?')->execute([$user['user_id'], $ptPayId]);
                process_pt_commission($ptPayId);
                audit_log($user['user_id'], 'mark_paid', 'pt_payment', (string)$ptPayId, null, $gymId);
                flash('PT payment marked as paid and trainer commission credited.');
            }
            redirect('commissions&tab=pt_payments');
        }

        if ($isOwner && $action === 'refund_pt_payment') {
            $ptPayId = (int) post('pt_payment_id', 0);
            $reason = trim(rawurldecode((string) post('reversal_reason', 'PT payment refunded'))) ?: 'PT payment refunded';
            $ptRow = query_one('SELECT * FROM pt_payments WHERE pt_payment_id = ? AND gym_id = ? AND status = "paid" LIMIT 1', [$ptPayId, $gymId]);
            if ($ptRow) {
                $pdo->prepare('UPDATE pt_payments SET status = "refunded" WHERE pt_payment_id = ?')->execute([$ptPayId]);
                reverse_trainer_commission_for_pt_payment($ptPayId, $reason, (int)$user['user_id'], true);
                audit_log($user['user_id'], 'refund', 'pt_payment', (string)$ptPayId, json_encode(['reason' => $reason]), $gymId);
                flash('PT payment refunded and associated trainer commission reversed.');
            }
            redirect('commissions&tab=pt_payments');
        }

        if ($isOwner && $action === 'save_trainer_rates') {
            $rates = $_POST['rates'] ?? [];
            $sessionRates = $_POST['session_rates'] ?? [];
            $changed = [];
            if (is_array($rates)) {
                $stmt = $pdo->prepare('UPDATE trainer_profiles SET commission_rate = ?, session_rate = ? WHERE trainer_id = ? AND gym_id = ?');
                foreach ($rates as $trainerId => $rate) {
                    $rate = trim((string) $rate);
                    $value = $rate === '' ? null : max(0.0, min(100.0, (float) $rate));
                    $sRate = isset($sessionRates[$trainerId]) ? max(0.0, (float) $sessionRates[$trainerId]) : 0.00;
                    $stmt->execute([$value, $sRate, (int) $trainerId, $gymId]);
                    if ($stmt->rowCount() > 0) {
                        $changed[(int) $trainerId] = ['commission' => $value, 'session_rate' => $sRate];
                    }
                }
            }
            if ($changed) {
                audit_log($user['user_id'], 'edit', 'commission_rates', null, json_encode($changed), $gymId);
            }
            flash('Trainer commission and session rates saved.');
            redirect('commissions&tab=settings');
        }
    }

    // Active tab: 'payouts', 'pt_payments', or 'settings'
    $activeTab = ($_GET['tab'] ?? 'payouts');
    $allowedTabs = $isOwner ? ['payouts', 'pt_payments', 'settings'] : ['payouts'];
    if (!in_array($activeTab, $allowedTabs, true)) {
        $activeTab = 'payouts';
    }

    // ── Commission list (gym-scoped for owners, optional trainer filter) ──
    $page = max(1, (int)($_GET['p'] ?? 1));
    $limit = 15;
    $offset = ($page - 1) * $limit;
    $filterTrainerId = (int) ($_GET['trainer_id'] ?? 0);
    $searchQ = trim((string) ($_GET['q'] ?? ''));
    $sourceFilter = (string) ($_GET['source'] ?? 'all');
    if (!in_array($sourceFilter, ['all', 'pt', 'legacy'], true)) {
        $sourceFilter = 'all';
    }

    $from = 'FROM trainer_commissions c
         JOIN users u ON u.user_id = c.trainer_id
         LEFT JOIN pt_payments ptp ON ptp.pt_payment_id = c.pt_payment_id
         LEFT JOIN trainer_packages tpkg ON tpkg.package_id = ptp.package_id
         LEFT JOIN users pmu ON pmu.user_id = ptp.member_user_id
         LEFT JOIN payments p ON p.payment_id = c.payment_id
         LEFT JOIN memberships m ON m.membership_id = p.membership_id
         LEFT JOIN users mu ON mu.user_id = m.user_id
         LEFT JOIN membership_plans mp ON mp.plan_id = m.plan_id';
    $where = [];
    $params = [];
    if ($isOwner) {
        $where[] = 'COALESCE(ptp.gym_id, mp.gym_id) = ?';
        $params[] = $gymId;
    }
    if ($filterTrainerId > 0) {
        $where[] = 'c.trainer_id = (SELECT user_id FROM trainer_profiles WHERE trainer_id = ?)';
        $params[] = $filterTrainerId;
    }
    if ($sourceFilter === 'pt') {
        $where[] = 'c.pt_payment_id IS NOT NULL';
    } elseif ($sourceFilter === 'legacy') {
        $where[] = 'c.pt_payment_id IS NULL';
    }
    if ($searchQ !== '') {
        $where[] = '(CONCAT(u.first_name, " ", u.last_name) LIKE ? OR CONCAT(COALESCE(pmu.first_name, mu.first_name, ""), " ", COALESCE(pmu.last_name, mu.last_name, "")) LIKE ? OR COALESCE(ptp.receipt_number, p.receipt_number, "") LIKE ? OR COALESCE(tpkg.package_name, mp.plan_name, "PT Session") LIKE ?)';
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
         ' . $from . $whereSql . '
         ORDER BY c.created_at DESC
         LIMIT ' . $limit . ' OFFSET ' . $offset,
        $params
    );

    // ── Settings, PT Packages & PT Payments data (gym owners only) ──
    $trainers = [];
    $ptPackages = [];
    $ptPayments = [];
    $gymMembers = [];
    if ($isOwner) {
        $trainers = query_all(
            'SELECT tp.trainer_id, tp.user_id, tp.commission_rate, tp.session_rate, tp.specialization,
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

        $ptPackages = query_all(
            'SELECT tpkg.*, CONCAT(u.first_name, " ", u.last_name) AS trainer_name
             FROM trainer_packages tpkg
             LEFT JOIN trainer_profiles tp ON tp.trainer_id = tpkg.trainer_id
             LEFT JOIN users u ON u.user_id = tp.user_id
             WHERE tpkg.gym_id = ?
             ORDER BY tpkg.is_active DESC, tpkg.price ASC',
            [$gymId]
        );

        $ptPayments = query_all(
            'SELECT ptp.*,
                    CONCAT(mu.first_name, " ", mu.last_name) AS member_name,
                    CONCAT(tu.first_name, " ", tu.last_name) AS trainer_name,
                    tpkg.package_name,
                    tp.commission_rate AS trainer_comm_rate,
                    tpkg.commission_rate AS package_comm_rate,
                    g.default_commission_rate AS gym_comm_rate,
                    (SELECT tc.rate_applied FROM trainer_commissions tc WHERE tc.pt_payment_id = ptp.pt_payment_id AND tc.amount >= 0 ORDER BY tc.commission_id ASC LIMIT 1) AS applied_comm_rate
             FROM pt_payments ptp
             JOIN users mu ON mu.user_id = ptp.member_user_id
             JOIN trainer_profiles tp ON tp.trainer_id = ptp.trainer_id
             JOIN users tu ON tu.user_id = tp.user_id
             LEFT JOIN trainer_packages tpkg ON tpkg.package_id = ptp.package_id
             LEFT JOIN gyms g ON g.gym_id = ptp.gym_id
             WHERE ptp.gym_id = ?
             ORDER BY ptp.created_at DESC
             LIMIT 50',
            [$gymId]
        );

        $gymMembers = query_all(
            'SELECT u.user_id, CONCAT(u.first_name, " ", u.last_name) AS full_name, u.email
             FROM gym_members gm
             JOIN users u ON u.user_id = gm.user_id
             WHERE gm.gym_id = ? AND u.role = "member" AND u.status = "active"
             ORDER BY u.first_name ASC, u.last_name ASC',
            [$gymId]
        );

        $gymDefaultCommRaw = scalar('SELECT default_commission_rate FROM gyms WHERE gym_id = ? LIMIT 1', [$gymId]);
        $ptPlatformSetting = get_setting('platform_default_pt_commission_rate', '100.00');
        $ptPlatformDefaultRate = is_numeric($ptPlatformSetting) ? max(0.0, min(100.0, (float)$ptPlatformSetting)) : 100.00;
        $gymFallbackPtRate = ($gymDefaultCommRaw !== null && (float)$gymDefaultCommRaw > 0)
            ? max(0.0, min(100.0, (float)$gymDefaultCommRaw))
            : $ptPlatformDefaultRate;
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
        .commissions-tabs {
            display: inline-flex;
            gap: 6px;
            margin-bottom: 20px;
            padding: 5px;
            background: var(--panel-soft, rgba(15, 23, 42, 0.06));
            border: 1px solid var(--line);
            border-radius: 12px;
            width: fit-content;
            flex-wrap: wrap;
        }
        [data-theme="dark"] .commissions-tabs {
            background: rgba(255, 255, 255, 0.04);
            border-color: rgba(255, 255, 255, 0.1);
        }
        .commissions-tab-btn {
            background: transparent;
            color: var(--muted);
            border: 1px solid transparent;
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
        .commissions-tab-btn:hover:not(.active) {
            color: var(--ink);
            background: rgba(148, 163, 184, 0.1);
        }
        .commissions-tab-btn.active {
            background: var(--lime, #84cc16);
            color: var(--lime-contrast, #080b0d);
            font-weight: 700;
            box-shadow: 0 2px 10px color-mix(in srgb, var(--lime, #84cc16) 28%, transparent);
        }
        .commissions-tab-content {
            display: none;
        }
        .commissions-tab-content.active {
            display: block;
        }

        .admin-commission-mobile-list { display: none; }
        .commission-settings-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; margin-bottom: 24px; align-items: start; }
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
            max-height: 420px;
            overflow-y: auto;
            padding-right: 4px;
        }
        .commission-rate-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 0; border-top: 1px solid var(--line); }
        .commission-rate-row:first-of-type { border-top: 0; }
        .commission-rate-meta { font-size: 12px; color: var(--muted); margin-top: 2px; }
        .commission-rate-inputs-group { display: flex; align-items: center; gap: 14px; flex-shrink: 0; }
        .commission-rate-input { width: 85px !important; text-align: right; margin: 0 !important; padding: 6px 10px !important; font-size: 13px !important; font-weight: 600; }
        .commission-tier-chip { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; background: rgba(163,230,53,.12); color: var(--lime); }
        .commission-tier-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 0; border-top: 1px solid var(--line); font-size: 13.5px; color: var(--ink); }
        .commission-tier-form { display: grid; grid-template-columns: 1.4fr 1fr 1fr auto; gap: 8px; margin-top: 14px; align-items: end; }
        .commission-tier-form label { font-size: 11px; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
        .commission-tier-form .form-control { margin-top: 4px; width: 100%; box-sizing: border-box; }
        @media (max-width: 900px) {
            .commission-settings-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .commission-rate-row { flex-direction: column; align-items: flex-start; gap: 10px; }
            .commission-rate-inputs-group { width: 100%; justify-content: space-between; gap: 10px; }
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
                <p style="margin: 0;">Manage Personal Training (PT) payments, coaching packages, trainer commission splits, and payouts — separated from facility membership plans.</p>
            </div>
        </div>

        <?php if ($isOwner): ?>
        <div class="commissions-tabs">
            <button type="button" class="commissions-tab-btn <?= $activeTab === 'payouts' ? 'active' : '' ?>" data-tab="payouts" onclick="switchCommissionTab('payouts')">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                Trainer Payouts (<?= $total ?>)
            </button>
            <button type="button" class="commissions-tab-btn <?= $activeTab === 'pt_payments' ? 'active' : '' ?>" data-tab="pt_payments" onclick="switchCommissionTab('pt_payments')">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                PT Payments (<?= count($ptPayments) ?>)
            </button>
            <button type="button" class="commissions-tab-btn <?= $activeTab === 'settings' ? 'active' : '' ?>" data-tab="settings" onclick="switchCommissionTab('settings')">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                PT Packages &amp; Rates
            </button>
        </div>
        <?php endif; ?>

        <!-- TAB: Trainer Payouts List -->
        <div id="tab-payouts" class="commissions-tab-content <?= $activeTab === 'payouts' ? 'active' : '' ?>">
            <?php
                $baseFilterUrl = 'index.php?page=commissions&tab=payouts'
                    . ($filterTrainerId > 0 ? '&trainer_id=' . $filterTrainerId : '')
                    . ($searchQ !== '' ? '&q=' . urlencode($searchQ) : '');
            ?>
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
                <div style="display:flex; align-items:center; flex-wrap:wrap; gap:10px;">
                    <p class="section-label" style="margin:0;">
                        <?= ($filterTrainerId > 0 || $searchQ !== '' || $sourceFilter !== 'all') ? 'Filtered Payouts' : 'All Trainer Payouts' ?> (<?= $total ?>)
                        <?php if ($filterTrainerId > 0 || $searchQ !== '' || $sourceFilter !== 'all'): ?>
                            · <a href="index.php?page=commissions&tab=payouts" style="color:var(--lime); font-size:12px;">Reset filters</a>
                        <?php endif; ?>
                    </p>
                    <div style="display:inline-flex; gap:4px; padding:3px; border-radius:8px; border:1px solid var(--line); background:var(--panel-soft, rgba(148,163,184,0.08));">
                        <?php foreach (['all' => 'All', 'pt' => 'PT Only', 'legacy' => 'Legacy Membership'] as $sKey => $sLabel):
                            $isActiveSrc = $sourceFilter === $sKey;
                        ?>
                            <a href="<?= h($baseFilterUrl . ($sKey !== 'all' ? '&source=' . $sKey : '')) ?>"
                               style="padding:4px 10px; border-radius:6px; font-size:12px; font-weight:<?= $isActiveSrc ? '700' : '600' ?>; text-decoration:none; <?= $isActiveSrc ? 'background:var(--lime); color:var(--lime-contrast, #080b0d);' : 'color:var(--muted);' ?>">
                                <?= h($sLabel) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <form method="get" action="index.php" style="display:flex; align-items:center; gap:8px; margin:0;">
                    <input type="hidden" name="page" value="commissions">
                    <input type="hidden" name="tab" value="payouts">
                    <?php if ($sourceFilter !== 'all'): ?>
                        <input type="hidden" name="source" value="<?= h($sourceFilter) ?>">
                    <?php endif; ?>
                    <?php if ($filterTrainerId > 0): ?>
                        <input type="hidden" name="trainer_id" value="<?= $filterTrainerId ?>">
                    <?php endif; ?>
                    <div style="position:relative;">
                        <input type="text" name="q" value="<?= h($searchQ) ?>" placeholder="Search trainer, member, receipt..." 
                               class="form-control" style="width:240px; margin:0; padding-right:28px; font-size:13px; height:36px; box-sizing:border-box;">
                        <?php if ($searchQ !== ''): ?>
                            <a href="index.php?page=commissions&tab=payouts<?= $sourceFilter !== 'all' ? '&source=' . h($sourceFilter) : '' ?><?= $filterTrainerId > 0 ? '&trainer_id=' . $filterTrainerId : '' ?>" 
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
                            <td style="font-size:13px;"><strong><?= h($row['trainer_name']) ?></strong></td>
                            <td style="font-size:13px;"><?= h($row['member_name']) ?></td>
                            <td style="font-size:13px;color:var(--muted)"><?= h($row['plan_name']) ?></td>
                            <td style="font-size:13px;font-family:monospace;color:var(--muted)"><?= h($row['receipt_number']) ?></td>
                            <td style="font-size:13px;color:var(--muted)" title="<?= h(($row['rate_source'] ?? 'plan') === 'trainer' ? 'Trainer rate' : 'Plan rate') ?> on <?= h(money($row['base_amount'] ?? $row['payment_amount'])) ?>"><?= h($rateLabel($row)) ?></td>
                            <td style="font-size:13px;"><strong style="color:<?= $isNegative ? '#ef4444' : 'var(--lime)' ?>"><?= h(money($row['amount'])) ?></strong></td>
                            <td style="font-size:13px;"><?= h(date('M j, Y', strtotime($row['created_at']))) ?></td>
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
            if ($sourceFilter !== 'all') $paginationBase .= '&source=' . urlencode($sourceFilter);
            if ($filterTrainerId > 0) $paginationBase .= '&trainer_id=' . $filterTrainerId;
            if ($searchQ !== '') $paginationBase .= '&q=' . urlencode($searchQ);
            render_pagination($page, $totalPages, $paginationBase); 
            ?>
            <?php endif; ?>
        </div>

        <?php if ($isOwner): ?>
        <!-- TAB: PT Payments -->
        <div id="tab-pt_payments" class="commissions-tab-content <?= $activeTab === 'pt_payments' ? 'active' : '' ?>">
            <style>
                .pt-pay-toolbar {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    gap: 12px;
                    flex-wrap: wrap;
                    margin-bottom: 14px;
                }
                .pt-modal-backdrop {
                    display: none;
                    position: fixed;
                    inset: 0;
                    z-index: 99999;
                    background: rgba(2, 6, 23, 0.72);
                    backdrop-filter: blur(4px);
                    align-items: center;
                    justify-content: center;
                    padding: 16px;
                }
                .pt-modal-backdrop.open {
                    display: flex;
                }
                .pt-modal-dialog {
                    width: 100%;
                    max-width: 720px;
                    max-height: 92vh;
                    overflow-y: auto;
                    background: var(--surface, #ffffff);
                    border: 1px solid var(--line);
                    border-radius: 16px;
                    padding: 22px 24px;
                    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.45);
                }
                .pt-pay-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-start;
                    gap: 14px;
                    flex-wrap: nowrap;
                    margin-bottom: 16px;
                    padding-bottom: 14px;
                    border-bottom: 1px solid var(--line);
                }
                .pt-pay-header-title {
                    flex: 1;
                    min-width: 0;
                }
                .pt-pay-header-actions {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    flex-shrink: 0;
                }
                .pt-pay-header h3 {
                    margin: 0 0 4px;
                    font-size: 17px;
                    color: var(--ink);
                }
                .pt-pay-header p {
                    margin: 0;
                    font-size: 12.5px;
                    color: var(--muted);
                }
                .pt-modal-close {
                    width: 32px;
                    height: 32px;
                    border-radius: 8px;
                    border: 1px solid var(--line);
                    background: transparent;
                    color: var(--muted);
                    font-size: 20px;
                    line-height: 1;
                    cursor: pointer;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                }
                .pt-modal-close:hover {
                    color: var(--ink);
                    border-color: var(--muted);
                }
                .pt-split-badge {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    padding: 5px 12px;
                    border-radius: 999px;
                    font-size: 12px;
                    font-weight: 600;
                    background: rgba(132, 204, 22, 0.1);
                    color: var(--lime);
                    border: 1px solid rgba(132, 204, 22, 0.28);
                    white-space: nowrap;
                }
                .pt-pay-row-2 {
                    display: grid;
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                    gap: 14px;
                    margin-bottom: 14px;
                    align-items: end;
                }
                .pt-pay-row-3 {
                    display: grid;
                    grid-template-columns: 110px 1fr 1fr;
                    gap: 14px;
                    margin-bottom: 14px;
                    align-items: end;
                }
                .pt-pay-field {
                    position: relative;
                }
                .pt-pay-field label {
                    display: flex;
                    align-items: center;
                    gap: 4px;
                    height: 18px;
                    line-height: 18px;
                    font-size: 11px;
                    color: var(--muted);
                    font-weight: 700;
                    text-transform: uppercase;
                    letter-spacing: 0.4px;
                    margin-bottom: 6px;
                }
                .pt-pay-field .form-control {
                    margin: 0 !important;
                    width: 100%;
                    height: 40px !important;
                    box-sizing: border-box;
                }
                /* Searchable Select */
                .pt-ss-wrap {
                    position: relative;
                    width: 100%;
                }
                .pt-ss-native-hidden {
                    position: absolute !important;
                    opacity: 0 !important;
                    pointer-events: none !important;
                    width: 1px !important;
                    height: 1px !important;
                    bottom: 0;
                    left: 50%;
                }
                .pt-ss-trigger {
                    width: 100%;
                    height: 40px;
                    padding: 0 34px 0 12px;
                    border-radius: 8px;
                    border: 1px solid var(--line);
                    background: var(--bg);
                    color: var(--ink);
                    font-size: 13.5px;
                    text-align: left;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    box-sizing: border-box;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    position: relative;
                }
                .pt-ss-trigger.placeholder {
                    color: var(--muted);
                }
                .pt-ss-trigger::after {
                    content: '';
                    position: absolute;
                    right: 12px;
                    top: 50%;
                    width: 6px;
                    height: 6px;
                    border-right: 1.8px solid var(--muted);
                    border-bottom: 1.8px solid var(--muted);
                    transform: translateY(-65%) rotate(45deg);
                    pointer-events: none;
                }
                .pt-ss-menu {
                    display: none;
                    position: absolute;
                    top: calc(100% + 4px);
                    left: 0;
                    right: 0;
                    z-index: 40;
                    background: var(--surface, #ffffff);
                    border: 1px solid var(--line);
                    border-radius: 10px;
                    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.25);
                    overflow: hidden;
                }
                .pt-ss-menu.open {
                    display: block;
                }
                .pt-ss-menu.drop-up {
                    top: auto;
                    bottom: calc(100% + 4px);
                    box-shadow: 0 -12px 28px rgba(0, 0, 0, 0.25);
                }
                .pt-ss-search-box {
                    padding: 8px;
                    border-bottom: 1px solid var(--line);
                    background: var(--surface, #ffffff);
                }
                .pt-ss-search-input {
                    width: 100%;
                    height: 34px;
                    padding: 0 10px;
                    border-radius: 7px;
                    border: 1px solid var(--line);
                    background: var(--bg);
                    color: var(--ink);
                    font-size: 13px;
                    box-sizing: border-box;
                    outline: none;
                }
                .pt-ss-search-input:focus {
                    border-color: var(--lime);
                }
                .pt-ss-list {
                    max-height: 200px;
                    overflow-y: auto;
                    padding: 4px;
                }
                .pt-ss-option {
                    width: 100%;
                    text-align: left;
                    padding: 8px 10px;
                    border: none;
                    border-radius: 6px;
                    background: transparent;
                    color: var(--ink);
                    font-size: 13px;
                    cursor: pointer;
                    display: block;
                }
                .pt-ss-option:hover,
                .pt-ss-option.selected {
                    background: rgba(132, 204, 22, 0.14);
                    color: var(--ink);
                    font-weight: 600;
                }
                .pt-ss-empty {
                    padding: 10px 12px;
                    font-size: 12.5px;
                    color: var(--muted);
                    text-align: center;
                }
                .pt-pay-footer {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    gap: 14px;
                    flex-wrap: wrap;
                    padding-top: 14px;
                    border-top: 1px solid var(--line);
                    margin-top: 6px;
                }
                .pt-pay-preview {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    flex-wrap: wrap;
                    font-size: 13px;
                    color: var(--muted);
                }
                .pt-pay-preview strong {
                    color: var(--ink);
                }
                @media (max-width: 640px) {
                    .commissions-tabs {
                        display: flex;
                        width: 100%;
                        box-sizing: border-box;
                        flex-wrap: nowrap;
                        overflow-x: auto;
                        -webkit-overflow-scrolling: touch;
                        scrollbar-width: none;
                    }
                    .commissions-tabs::-webkit-scrollbar {
                        display: none;
                    }
                    .commissions-tab-btn {
                        flex: 0 0 auto;
                        white-space: nowrap;
                        padding: 8px 12px;
                        font-size: 13px;
                    }
                    .pt-pkg-card-head {
                        flex-direction: column !important;
                        align-items: stretch !important;
                    }
                    .pt-pkg-card-head .pt-pkg-add-btn {
                        width: 100%;
                        justify-content: center;
                    }
                    .pt-modal-dialog {
                        padding: 18px 16px;
                    }
                    .pt-pay-header {
                        position: relative;
                        flex-direction: column;
                        align-items: flex-start;
                        gap: 10px;
                    }
                    .pt-pay-header-title {
                        width: 100%;
                        padding-right: 42px;
                        box-sizing: border-box;
                    }
                    .pt-pay-header-actions {
                        width: 100%;
                        justify-content: flex-start;
                    }
                    .pt-modal-close {
                        position: absolute;
                        top: 0;
                        right: 0;
                    }
                    .pt-pay-row-2,
                    .pt-pay-row-3 {
                        grid-template-columns: 1fr;
                    }
                }
            </style>

            <div class="pt-pay-toolbar">
                <div>
                    <p class="section-label" style="margin:0;">Recent PT Payments (<?= count($ptPayments) ?>)</p>
                    <span style="font-size:12.5px; color:var(--muted);">Personal training session &amp; package payments — billed separately from gym membership plans.</span>
                </div>
                <button type="button" class="btn" onclick="openPtPaymentModal()" style="height:38px; padding:0 16px; font-weight:700; display:inline-flex; align-items:center; gap:6px;">
                    <span>+ Record PT Payment</span>
                </button>
            </div>

            <!-- Modal: Record PT Session / Package Payment -->
            <div id="ptPaymentModal" class="pt-modal-backdrop" onclick="if (event.target === this) closePtPaymentModal();">
                <div class="pt-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ptPaymentModalTitle">
                    <div class="pt-pay-header">
                        <div class="pt-pay-header-title">
                            <h3 id="ptPaymentModalTitle">Record PT Session / Package Payment</h3>
                            <p>Marking a payment as <strong>Paid</strong> automatically credits the trainer's share.</p>
                        </div>
                        <div class="pt-pay-header-actions">
                            <button type="button" class="pt-modal-close" onclick="closePtPaymentModal()" aria-label="Close modal">&times;</button>
                        </div>
                    </div>

                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="record_pt_payment">

                        <div class="pt-pay-row-2">
                            <div class="pt-pay-field">
                                <label>Member *</label>
                                <select name="member_user_id" id="ptPayMemberSelect" class="form-control" required data-searchable-placeholder="Search member by name...">
                                    <option value="">Select member...</option>
                                    <?php foreach ($gymMembers as $gm): ?>
                                        <option value="<?= (int)$gm['user_id'] ?>"><?= h($gm['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="pt-pay-field">
                                <label>Trainer *</label>
                                <select name="trainer_id" id="ptPayTrainerSelect" class="form-control" required onchange="syncPtPayDefaults()" data-searchable-placeholder="Search trainer by name...">
                                    <option value="" data-rate="0" data-comm-rate="">Select trainer...</option>
                                    <?php foreach ($trainers as $t): ?>
                                        <option value="<?= (int)$t['trainer_id'] ?>"
                                                data-rate="<?= h((string)(float)($t['session_rate'] ?? 0)) ?>"
                                                data-comm-rate="<?= $t['commission_rate'] !== null ? h((string)(float)$t['commission_rate']) : '' ?>">
                                            <?= h($t['name']) ?> (<?= h(money((float)($t['session_rate'] ?? 0))) ?>/sess)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="pt-pay-row-2">
                            <div class="pt-pay-field">
                                <label>Billing Type / Package</label>
                                <select name="package_id" id="ptPayPackageSelect" class="form-control" onchange="syncPtPayDefaults()">
                                    <option value="0" data-price="0" data-sessions="1" data-comm-rate="">Single Per-Session Fee</option>
                                    <?php foreach ($ptPackages as $pkg): if (!$pkg['is_active']) continue; ?>
                                        <option value="<?= (int)$pkg['package_id'] ?>"
                                                data-price="<?= h((string)(float)$pkg['price']) ?>"
                                                data-sessions="<?= (int)($pkg['session_count'] ?: 1) ?>"
                                                data-comm-rate="<?= $pkg['commission_rate'] !== null ? h((string)(float)$pkg['commission_rate']) : '' ?>">
                                            <?= h($pkg['package_name']) ?> (<?= h(money((float)$pkg['price'])) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="pt-pay-field">
                                <label>Payment Status</label>
                                <select name="status" class="form-control">
                                    <option value="paid">Paid — Credit Trainer</option>
                                    <option value="pending">Pending — Pay Later</option>
                                </select>
                            </div>
                        </div>

                        <div class="pt-pay-row-3">
                            <div class="pt-pay-field">
                                <label>Sessions</label>
                                <input type="number" name="sessions_purchased" id="ptPaySessionsInput" min="1" value="1" class="form-control">
                            </div>
                            <div class="pt-pay-field">
                                <label>Amount (₱) *</label>
                                <input type="number" step="0.01" min="0.01" name="amount" id="ptPayAmountInput" required placeholder="0.00" class="form-control" oninput="updatePtSplitPreview()">
                            </div>
                            <div class="pt-pay-field">
                                <label>Payment Method</label>
                                <select name="payment_method" class="form-control">
                                    <option value="cash">Cash</option>
                                    <option value="gcash">GCash</option>
                                    <option value="maya">Maya</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="card">Card</option>
                                </select>
                            </div>
                        </div>

                        <div class="pt-pay-footer">
                            <div class="pt-pay-preview" id="ptPaySplitPreview">
                                <span>Payout Preview:</span>
                                <strong>Trainer gets ₱0.00 (<?= rtrim(rtrim(number_format($gymFallbackPtRate, 2), '0'), '.') ?>%)</strong>
                                <span>·</span>
                                <span>Gym keeps <strong>₱0.00 (<?= rtrim(rtrim(number_format(max(0, 100 - $gymFallbackPtRate), 2), '0'), '.') ?>%)</strong></span>
                            </div>
                            <div style="display:flex; gap:8px; margin-left:auto;">
                                <button type="button" class="btn btn-sm" onclick="closePtPaymentModal()" style="height:40px; padding:0 16px; background:transparent; color:var(--muted); border:1px solid var(--line);">Cancel</button>
                                <button type="submit" class="btn" style="height:40px; padding:0 20px; font-weight:700;">Record PT Payment</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php if (!$ptPayments): ?>
                <div class="empty-state">
                    <p>No PT session or package payments recorded yet.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Receipt</th>
                                <th>Member</th>
                                <th>Trainer</th>
                                <th>Type / Package</th>
                                <th>Sessions</th>
                                <th>Amount</th>
                                <th>Split</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $ptPlatformSetting = get_setting('platform_default_pt_commission_rate', '100.00');
                            $ptPlatformDefault = is_numeric($ptPlatformSetting) ? max(0.0, min(100.0, (float)$ptPlatformSetting)) : 100.00;
                            foreach ($ptPayments as $ptp):
                                if ($ptp['applied_comm_rate'] !== null) {
                                    $trainerSharePct = (float)$ptp['applied_comm_rate'];
                                } elseif ($ptp['trainer_comm_rate'] !== null) {
                                    $trainerSharePct = (float)$ptp['trainer_comm_rate'];
                                } elseif ($ptp['package_comm_rate'] !== null) {
                                    $trainerSharePct = (float)$ptp['package_comm_rate'];
                                } elseif ($ptp['gym_comm_rate'] !== null && (float)$ptp['gym_comm_rate'] > 0) {
                                    $trainerSharePct = (float)$ptp['gym_comm_rate'];
                                } else {
                                    $trainerSharePct = $ptPlatformDefault;
                                }
                                $trainerSharePct = max(0.0, min(100.0, $trainerSharePct));
                                $gymCutPct = max(0.0, 100.0 - $trainerSharePct);
                                $fmtPct = static fn(float $v): string => rtrim(rtrim(number_format($v, 2), '0'), '.') . '%';
                            ?>
                                <tr>
                                    <td style="font-family:monospace; font-size:12.5px; color:var(--muted);"><?= h($ptp['receipt_number'] ?? '—') ?></td>
                                    <td style="font-size:13px;"><strong><?= h($ptp['member_name']) ?></strong></td>
                                    <td style="font-size:13px;"><?= h($ptp['trainer_name']) ?></td>
                                    <td style="font-size:13px; color:var(--muted);"><?= h($ptp['package_name'] ? ('Package: ' . $ptp['package_name']) : 'Per-Session PT') ?></td>
                                    <td style="font-size:13px;"><?= (int)$ptp['sessions_remaining'] ?> / <?= (int)$ptp['sessions_purchased'] ?> left</td>
                                    <td style="font-size:13px;"><strong style="color:var(--lime);"><?= h(money((float)$ptp['amount'])) ?></strong></td>
                                    <td style="font-size:12px; color:var(--muted); white-space:nowrap;">
                                        Trainer Share (<strong style="color:var(--ink);"><?= h($fmtPct($trainerSharePct)) ?></strong>) / Gym Cut (<strong style="color:var(--ink);"><?= h($fmtPct($gymCutPct)) ?></strong>)
                                    </td>
                                    <td style="font-size:12.5px; text-transform:uppercase; color:var(--muted);"><?= h($ptp['payment_method']) ?></td>
                                    <td>
                                        <span class="badge <?= $ptp['status'] === 'paid' ? 'badge-active' : ($ptp['status'] === 'pending' ? 'badge-pending' : '') ?>">
                                            <?= h(ucfirst($ptp['status'])) ?>
                                        </span>
                                    </td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <?php if ($ptp['status'] === 'pending'): ?>
                                            <form method="post" style="display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="mark_pt_payment_paid">
                                                <input type="hidden" name="pt_payment_id" value="<?= (int)$ptp['pt_payment_id'] ?>">
                                                <button type="submit" class="btn btn-sm" style="padding:4px 8px; font-size:12px; background:transparent; color:var(--lime); border:1px solid var(--lime); border-radius:4px; cursor:pointer;">Mark Paid</button>
                                            </form>
                                        <?php elseif ($ptp['status'] === 'paid'): ?>
                                            <button type="button" onclick="refundPtPayment(<?= (int)$ptp['pt_payment_id'] ?>)" class="btn btn-sm" style="padding:4px 8px; font-size:12px; background:transparent; color:#ef4444; border:1px solid rgba(239,68,68,0.4); border-radius:4px; cursor:pointer;">Refund</button>
                                        <?php else: ?>
                                            <span style="font-size:12px; color:var(--muted);"><?= h(ucfirst($ptp['status'])) ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB: PT Packages & Commission Settings -->
        <div id="tab-settings" class="commissions-tab-content <?= $activeTab === 'settings' ? 'active' : '' ?>">
            <p class="section-label">PT Packages &amp; Trainer Commission Rates</p>
            <div class="commission-settings-grid">
                <!-- Per-trainer rates -->
                <form method="post" class="commission-settings-card">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_trainer_rates">
                    <h3>Trainer PT Rates &amp; Session Fees</h3>
                    <p class="hint">Set each trainer's personal PT share % and per-session coaching fee. Leave % blank to inherit package/gym default (<strong>100%</strong> to trainer by default).</p>
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
                                    <div style="flex:1; min-width:0;">
                                        <strong style="color:var(--ink); font-size:14px;"><?= h($t['name']) ?></strong>
                                        <?php if (!empty($t['specialization'])): ?>
                                             <span style="font-size:12px; color:var(--muted); margin-left: 4px;">(<?= h($t['specialization']) ?>)</span>
                                        <?php endif; ?>
                                        <div class="commission-rate-meta">
                                            <?= (int) $t['metrics']['active_clients'] ?> active client(s) ·
                                            <?= h(money($t['metrics']['monthly_revenue'])) ?> PT/commission rev this month
                                        </div>
                                    </div>
                                    <div class="commission-rate-inputs-group">
                                        <div style="display:flex; flex-direction:column; align-items:flex-end; gap:3px;">
                                            <span style="font-size:10px; color:var(--muted); text-transform:uppercase; font-weight:700; letter-spacing:0.3px;">PT Split</span>
                                            <div style="display:flex; align-items:center; gap:4px;">
                                                <input type="number" step="0.01" min="0" max="100" name="rates[<?= (int) $t['trainer_id'] ?>]"
                                                       class="form-control commission-rate-input" placeholder="Default"
                                                       value="<?= $t['commission_rate'] !== null ? h((string) (float) $t['commission_rate']) : '' ?>" style="width:75px !important;" title="Trainer PT commission rate %">
                                                <span style="color:var(--muted); font-size:12px; font-weight:600;">%</span>
                                            </div>
                                        </div>
                                        <div style="display:flex; flex-direction:column; align-items:flex-end; gap:3px;">
                                            <span style="font-size:10px; color:var(--muted); text-transform:uppercase; font-weight:700; letter-spacing:0.3px;">Session Fee</span>
                                            <div style="display:flex; align-items:center; gap:4px;">
                                                <span style="color:var(--lime); font-size:12.5px; font-weight:700;">₱</span>
                                                <input type="number" step="0.01" min="0" name="session_rates[<?= (int) $t['trainer_id'] ?>]"
                                                       class="form-control commission-rate-input" placeholder="0.00"
                                                       value="<?= isset($t['session_rate']) ? h((string) (float) $t['session_rate']) : '0.00' ?>" style="width:85px !important;" title="Direct per-session fee">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p id="noTrainersFoundMsg" class="hint" style="display:none; margin: 12px 0 0; text-align: center;">No matching trainers found.</p>
                        <button type="submit" class="btn" style="margin-top:14px; width:100%;">Save Trainer Rates &amp; Fees</button>
                    <?php endif; ?>
                </form>

                <!-- PT Packages Management Card -->
                <div class="commission-settings-card">
                    <div class="pt-pkg-card-head" style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
                        <div style="flex:1; min-width:0;">
                            <h3>Personal Training Packages</h3>
                            <p class="hint" style="margin-bottom:0;">Create multi-session packs or monthly coaching bundles sold separately from gym memberships.</p>
                        </div>
                        <button type="button" class="btn btn-sm pt-pkg-add-btn" onclick="openPtPackageModal()" style="height:36px; padding:0 14px; font-weight:700; white-space:nowrap; display:inline-flex; align-items:center;">+ Add PT Package</button>
                    </div>

                    <?php if ($ptPackages): ?>
                        <div style="display:flex; flex-direction:column; gap:8px;">
                            <?php foreach ($ptPackages as $pkg): ?>
                                <div style="padding:10px 12px; border:1px solid var(--line); border-radius:10px; background:var(--bg); display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
                                    <div style="min-width:0;">
                                        <div style="font-size:13.5px; font-weight:700; color:var(--ink);">
                                            <?= h($pkg['package_name']) ?>
                                            <?php if (!$pkg['is_active']): ?>
                                                <span class="badge" style="font-size:10px; margin-left:4px;">Paused</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size:12px; color:var(--muted); margin-top:2px;">
                                            <strong style="color:var(--lime);"><?= h(money((float)$pkg['price'])) ?></strong>
                                            · <?= $pkg['package_type'] === 'monthly_coaching' ? ((int)$pkg['duration_days'] . ' days coaching') : ((int)$pkg['session_count'] . ' sessions') ?>
                                            · <?= $pkg['commission_rate'] !== null ? (rtrim(rtrim(number_format((float)$pkg['commission_rate'], 2), '0'), '.') . '% split') : 'Default split' ?>
                                            <?= !empty($pkg['trainer_name']) ? (' · ' . h($pkg['trainer_name'])) : ' · Any Trainer' ?>
                                        </div>
                                    </div>
                                    <div style="display:flex; gap:6px;">
                                        <form method="post" style="margin:0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_pt_package">
                                            <input type="hidden" name="package_id" value="<?= (int)$pkg['package_id'] ?>">
                                            <button type="button" onclick="confirmPtPackageAction(this.form, <?= $pkg['is_active'] ? "'pause'" : "'activate'" ?>, <?= htmlspecialchars(json_encode((string)$pkg['package_name']), ENT_QUOTES, 'UTF-8') ?>)" class="btn btn-sm" style="padding:4px 8px; font-size:11.5px; background:transparent; color:var(--ink); border:1px solid var(--line); border-radius:6px; cursor:pointer;">
                                                <?= $pkg['is_active'] ? 'Pause' : 'Activate' ?>
                                            </button>
                                        </form>
                                        <form method="post" style="margin:0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_pt_package">
                                            <input type="hidden" name="package_id" value="<?= (int)$pkg['package_id'] ?>">
                                            <button type="button" onclick="confirmPtPackageAction(this.form, 'delete', <?= htmlspecialchars(json_encode((string)$pkg['package_name']), ENT_QUOTES, 'UTF-8') ?>)" class="btn btn-sm" style="padding:4px 8px; font-size:11.5px; background:transparent; color:#ef4444; border:1px solid rgba(239,68,68,0.35); border-radius:6px; cursor:pointer;">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state" style="padding:24px 16px;">
                            <p style="margin:0;">No PT packages created yet. Click <strong>+ Add PT Package</strong> to create one.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modal: Add PT Package -->
                <div id="ptPackageModal" class="pt-modal-backdrop" onclick="if (event.target === this) closePtPackageModal();">
                    <div class="pt-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ptPackageModalTitle" style="max-width:600px;">
                        <div class="pt-pay-header">
                            <div class="pt-pay-header-title">
                                <h3 id="ptPackageModalTitle">Add Personal Training Package</h3>
                                <p>Sold separately from gym membership plans. Leave PT Commission % blank to use the default split.</p>
                            </div>
                            <div class="pt-pay-header-actions">
                                <button type="button" class="pt-modal-close" onclick="closePtPackageModal()" aria-label="Close modal">&times;</button>
                            </div>
                        </div>

                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_pt_package">

                            <div class="pt-pay-field" style="margin-bottom:14px;">
                                <label>Package Name *</label>
                                <input type="text" name="package_name" required placeholder="e.g. 10 PT Sessions Pack" class="form-control">
                            </div>

                            <div class="pt-pay-row-2">
                                <div class="pt-pay-field">
                                    <label>Type</label>
                                    <select name="package_type" class="form-control">
                                        <option value="session_pack">Session Pack</option>
                                        <option value="monthly_coaching">Monthly Coaching</option>
                                    </select>
                                </div>
                                <div class="pt-pay-field">
                                    <label>Price (₱) *</label>
                                    <input type="number" step="0.01" min="1" name="price" required placeholder="3500.00" class="form-control">
                                </div>
                            </div>

                            <div class="pt-pay-row-2">
                                <div class="pt-pay-field">
                                    <label>Sessions</label>
                                    <input type="number" min="1" name="session_count" value="10" class="form-control">
                                </div>
                                <div class="pt-pay-field">
                                    <label>Validity (Days)</label>
                                    <input type="number" min="1" name="duration_days" value="30" class="form-control">
                                </div>
                            </div>

                            <div class="pt-pay-row-2" style="margin-bottom:16px;">
                                <div class="pt-pay-field">
                                    <label>PT Commission %</label>
                                    <input type="number" step="0.01" min="0" max="100" name="commission_rate" placeholder="e.g. 100" class="form-control">
                                </div>
                                <div class="pt-pay-field">
                                    <label>Trainer Scope</label>
                                    <select name="trainer_id" id="ptPkgTrainerSelect" class="form-control" data-searchable-placeholder="Search trainer or Any..." data-dropdown-position="up">
                                        <option value="0">Any Trainer at Gym</option>
                                        <?php foreach ($trainers as $t): ?>
                                            <option value="<?= (int)$t['trainer_id'] ?>"><?= h($t['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="pt-pay-footer">
                                <span style="font-size:12.5px; color:var(--muted);">Default split: <strong style="color:var(--ink);">100% Trainer / 0% Gym</strong></span>
                                <div style="display:flex; gap:8px; margin-left:auto;">
                                    <button type="button" class="btn btn-sm" onclick="closePtPackageModal()" style="height:40px; padding:0 16px; background:transparent; color:var(--muted); border:1px solid var(--line);">Cancel</button>
                                    <button type="submit" class="btn" style="height:40px; padding:0 20px; font-weight:700;">+ Add PT Package</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <script>
    const PT_FALLBACK_RATE = <?= json_encode((float)($gymFallbackPtRate ?? 100.0)) ?>;

    function initPtSearchableSelect(selectEl) {
        if (!selectEl || selectEl.dataset.ssInit === '1') return;
        selectEl.dataset.ssInit = '1';

        const wrap = document.createElement('div');
        wrap.className = 'pt-ss-wrap';
        selectEl.parentNode.insertBefore(wrap, selectEl);
        wrap.appendChild(selectEl);
        selectEl.classList.add('pt-ss-native-hidden');
        selectEl.tabIndex = -1;

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'pt-ss-trigger';

        const menu = document.createElement('div');
        menu.className = 'pt-ss-menu';

        const searchBox = document.createElement('div');
        searchBox.className = 'pt-ss-search-box';
        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.className = 'pt-ss-search-input';
        searchInput.placeholder = selectEl.getAttribute('data-searchable-placeholder') || 'Search...';
        searchBox.appendChild(searchInput);

        const list = document.createElement('div');
        list.className = 'pt-ss-list';

        menu.appendChild(searchBox);
        menu.appendChild(list);
        wrap.appendChild(trigger);
        wrap.appendChild(menu);

        const syncTriggerText = () => {
            const opt = selectEl.options[selectEl.selectedIndex];
            const txt = opt ? opt.textContent.trim() : 'Select...';
            trigger.textContent = txt;
            trigger.classList.toggle('placeholder', !selectEl.value);
        };

        const renderList = (query = '') => {
            const q = query.trim().toLowerCase();
            list.innerHTML = '';
            let matches = 0;
            Array.from(selectEl.options).forEach((opt) => {
                if (!opt.value && q !== '') return;
                const text = opt.textContent.trim();
                if (q !== '' && !text.toLowerCase().includes(q)) return;
                matches++;
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'pt-ss-option' + (opt.value === selectEl.value ? ' selected' : '');
                btn.textContent = text;
                btn.addEventListener('click', () => {
                    selectEl.value = opt.value;
                    selectEl.dispatchEvent(new Event('change', { bubbles: true }));
                    syncTriggerText();
                    menu.classList.remove('open');
                });
                list.appendChild(btn);
            });
            if (matches === 0) {
                const empty = document.createElement('div');
                empty.className = 'pt-ss-empty';
                empty.textContent = 'No matches found';
                list.appendChild(empty);
            }
        };

        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = menu.classList.contains('open');
            document.querySelectorAll('.pt-ss-menu.open').forEach((m) => m.classList.remove('open'));
            if (!isOpen) {
                const forceUp = selectEl.getAttribute('data-dropdown-position') === 'up';
                const rect = trigger.getBoundingClientRect();
                const spaceBelow = window.innerHeight - rect.bottom;
                menu.classList.toggle('drop-up', forceUp || (spaceBelow < 240 && rect.top > spaceBelow));
                menu.classList.add('open');
                searchInput.value = '';
                renderList('');
                setTimeout(() => searchInput.focus(), 10);
            }
        });

        searchInput.addEventListener('input', () => renderList(searchInput.value));
        searchInput.addEventListener('click', (e) => e.stopPropagation());
        selectEl.addEventListener('change', syncTriggerText);

        syncTriggerText();
        renderList('');
    }

    document.addEventListener('click', () => {
        document.querySelectorAll('.pt-ss-menu.open').forEach((m) => m.classList.remove('open'));
    });

    function openPtPaymentModal() {
        const modal = document.getElementById('ptPaymentModal');
        if (!modal) return;
        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
        initPtSearchableSelect(document.getElementById('ptPayMemberSelect'));
        initPtSearchableSelect(document.getElementById('ptPayTrainerSelect'));
        modal.classList.add('open');
        updatePtSplitPreview();
    }

    function closePtPaymentModal() {
        const modal = document.getElementById('ptPaymentModal');
        if (modal) modal.classList.remove('open');
    }

    function confirmPtPackageAction(form, actionType, pkgName) {
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

    function openPtPackageModal() {
        const modal = document.getElementById('ptPackageModal');
        if (!modal) return;
        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
        initPtSearchableSelect(document.getElementById('ptPkgTrainerSelect'));
        modal.classList.add('open');
    }

    function closePtPackageModal() {
        const modal = document.getElementById('ptPackageModal');
        if (modal) modal.classList.remove('open');
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closePtPaymentModal();
            closePtPackageModal();
        }
    });

    function updatePtSplitPreview() {
        const pkgSel = document.getElementById('ptPayPackageSelect');
        const trSel = document.getElementById('ptPayTrainerSelect');
        const amtInp = document.getElementById('ptPayAmountInput');
        const preview = document.getElementById('ptPaySplitPreview');
        if (!amtInp || !preview) return;

        const trOpt = trSel && trSel.selectedIndex >= 0 ? trSel.options[trSel.selectedIndex] : null;
        const pkgOpt = pkgSel && pkgSel.selectedIndex >= 0 ? pkgSel.options[pkgSel.selectedIndex] : null;
        const trComm = trOpt ? trOpt.getAttribute('data-comm-rate') : '';
        const pkgComm = pkgOpt ? pkgOpt.getAttribute('data-comm-rate') : '';

        let pct = PT_FALLBACK_RATE;
        if (trComm !== null && trComm !== '') {
            pct = parseFloat(trComm);
        } else if (pkgComm !== null && pkgComm !== '') {
            pct = parseFloat(pkgComm);
        }
        pct = Math.max(0, Math.min(100, isNaN(pct) ? PT_FALLBACK_RATE : pct));
        const gymPct = Math.max(0, 100 - pct);

        const amt = Math.max(0, parseFloat(amtInp.value || '0') || 0);
        const trainerAmt = amt * (pct / 100);
        const gymAmt = amt - trainerAmt;

        const fmtPct = (v) => Number.isInteger(v) ? String(v) : v.toFixed(2).replace(/\.?0+$/, '');
        const fmtMoney = (v) => '₱' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        preview.innerHTML = `<span>Payout Preview:</span> <strong style="color:var(--lime);">Trainer gets ${fmtMoney(trainerAmt)} (${fmtPct(pct)}%)</strong> <span>·</span> <span>Gym keeps <strong>${fmtMoney(gymAmt)} (${fmtPct(gymPct)}%)</strong></span>`;
    }

    function syncPtPayDefaults() {
        const pkgSel = document.getElementById('ptPayPackageSelect');
        const trSel = document.getElementById('ptPayTrainerSelect');
        const amtInp = document.getElementById('ptPayAmountInput');
        const sessInp = document.getElementById('ptPaySessionsInput');
        if (!pkgSel || !amtInp) return;

        const pkgOpt = pkgSel.options[pkgSel.selectedIndex];
        const pkgId = parseInt(pkgSel.value || '0', 10);
        if (pkgId > 0 && pkgOpt) {
            const pPrice = parseFloat(pkgOpt.getAttribute('data-price') || '0');
            const pSess = parseInt(pkgOpt.getAttribute('data-sessions') || '1', 10);
            if (pPrice > 0) amtInp.value = pPrice.toFixed(2);
            if (sessInp) sessInp.value = String(pSess > 0 ? pSess : 1);
        } else if (trSel) {
            const trOpt = trSel.options[trSel.selectedIndex];
            const tRate = trOpt ? parseFloat(trOpt.getAttribute('data-rate') || '0') : 0;
            if (tRate > 0) amtInp.value = tRate.toFixed(2);
            if (sessInp) sessInp.value = '1';
        }
        updatePtSplitPreview();
    }

    function refundPtPayment(ptPaymentId) {
        Swal.fire({
            title: 'Refund PT Payment?',
            html: '<p style="font-size:13px; color:var(--muted); margin-bottom:10px;">This marks the PT payment as refunded and cancels/reverses the linked trainer commission.</p><input id="swal-pt-refund-reason" class="swal2-input" placeholder="Enter refund reason..." style="font-size:13px; width:85%; margin:8px auto; display:block;">',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, refund',
            confirmButtonColor: '#ef4444',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const r = (document.getElementById('swal-pt-refund-reason')?.value || '').trim();
                if (!r) {
                    Swal.showValidationMessage('Please enter a refund reason.');
                    return false;
                }
                return r;
            }
        }).then((res) => {
            if (res.isConfirmed && res.value) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="refund_pt_payment">
                    <input type="hidden" name="pt_payment_id" value="${ptPaymentId}">
                    <input type="hidden" name="reversal_reason" value="${encodeURIComponent(res.value)}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

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
