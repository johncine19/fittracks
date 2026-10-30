<?php
declare(strict_types=1);

function gyms_page(): void
{
    $user = require_roles(['platform_admin']);
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $gymId = (int) post('gym_id');
        $action = post('action');

        if ($action === 'suspend') {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE gyms SET status = "suspended" WHERE gym_id = ?')->execute([$gymId]);
            $pdo->prepare('UPDATE users SET status = "suspended" WHERE user_id = (SELECT owner_user_id FROM gyms WHERE gym_id = ?)')
                ->execute([$gymId]);
            $pdo->commit();
            flash('Gym suspended successfully.', 'success');
        } elseif ($action === 'reactivate') {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE gyms SET status = "approved" WHERE gym_id = ?')->execute([$gymId]);
            $pdo->prepare('UPDATE users SET status = "active" WHERE user_id = (SELECT owner_user_id FROM gyms WHERE gym_id = ?)')
                ->execute([$gymId]);
            $pdo->commit();
            flash('Gym reactivated.', 'success');
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM gyms WHERE gym_id = ?')->execute([$gymId]);
            flash('Gym deleted successfully.', 'success');
        } elseif ($action === 'update_subscription') {
            $plan = trim((string) post('subscription_plan'));
            if ($plan === '' || strtolower($plan) === 'none') {
                $plan = null;
            }
            $subStatus = trim((string) post('subscription_status')) ?: 'inactive';
            $billingCycle = strtolower(trim((string) post('subscription_billing_cycle'))) === 'yearly' ? 'yearly' : 'monthly';
            $renewalDate = trim((string) post('subscription_renewal_date')) ?: null;
            $recordPayment = (post('record_payment') === '1');
            $amount = (float) post('amount');
            $paymentMethod = trim((string) post('payment_method')) ?: 'cash';

            // Guardrail: if active or trialing, ensure the renewal date is a safe future date so the gym does not immediately lapse
            if ($subStatus === 'active') {
                if (empty($renewalDate) || strtotime($renewalDate) < strtotime('today')) {
                    $renewalDate = date('Y-m-d', strtotime($billingCycle === 'yearly' ? '+1 year' : '+1 month'));
                }
            } elseif ($subStatus === 'trialing') {
                if (empty($renewalDate) || strtotime($renewalDate) < strtotime('today')) {
                    $renewalDate = date('Y-m-d', strtotime('+14 days'));
                }
            }

            $pdo->beginTransaction();

            $resolvedPlanId = resolve_platform_subscription_plan_id($plan);
            $pdo->prepare('UPDATE gyms SET subscription_plan = ?, subscription_plan_id = ?, subscription_status = ?, subscription_renewal_date = ?, subscription_billing_cycle = ? WHERE gym_id = ?')
                ->execute([$plan, $resolvedPlanId, $subStatus, $renewalDate, $billingCycle, $gymId]);

            $ownerId = (int) $pdo->query('SELECT owner_user_id FROM gyms WHERE gym_id = ' . $gymId)->fetchColumn();
            $gymName = (string) $pdo->query('SELECT name FROM gyms WHERE gym_id = ' . $gymId)->fetchColumn();

            if ($recordPayment && $plan && $amount > 0) {
                $receiptNumber = 'SUB-ADM-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
                $startDate = date('Y-m-d');
                $endDate = $renewalDate ?: date('Y-m-d', strtotime($billingCycle === 'yearly' ? '+1 year' : '+1 month'));

                $pdo->prepare('
                    INSERT INTO gym_subscription_payments 
                    (gym_id, owner_user_id, plan_id, plan_name, amount, billing_cycle, payment_method, status, receipt_number, payment_date, start_date, end_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, "paid", ?, NOW(), ?, ?)
                ')->execute([
                    $gymId,
                    $ownerId,
                    $resolvedPlanId,
                    $plan,
                    $amount,
                    $billingCycle,
                    $paymentMethod,
                    $receiptNumber,
                    $startDate,
                    $endDate
                ]);
            }

            $pdo->commit();

            audit_log($user['user_id'], 'update_subscription', 'gym', (string) $gymId, json_encode([
                'plan' => $plan,
                'status' => $subStatus,
                'billing_cycle' => $billingCycle,
                'renewal_date' => $renewalDate,
                'recorded_payment' => $recordPayment ? $amount : false
            ]));

            $cycleLabel = $billingCycle === 'yearly' ? 'Annual' : 'Monthly';
            notify_user(
                $ownerId,
                'system',
                'Subscription Updated',
                "Your subscription for '{$gymName}' has been updated by Platform Admin to " . ($plan ? "{$plan} Plan ({$cycleLabel}, {$subStatus})" : "No Plan") . "."
            );

            flash("Subscription for '{$gymName}' updated successfully.", 'success');
        } elseif ($action === 'update_payout') {
            $xenditSubaccountId = trim((string) post('xendit_subaccount_id'));
            $xenditSubaccountId = $xenditSubaccountId !== '' ? $xenditSubaccountId : null;
            $payoutChannel = trim((string) post('payout_channel')) ?: 'gcash';
            $payoutNumber = trim((string) post('payout_account_number')) ?: null;
            $payoutName = trim((string) post('payout_account_name')) ?: null;

            save_gym_payout_account($gymId, [
                'xendit_subaccount_id' => $xenditSubaccountId,
                'payout_channel' => $payoutChannel,
                'payout_account_number' => $payoutNumber,
                'payout_account_name' => $payoutName,
            ]);

            $ownerId = (int) $pdo->query('SELECT owner_user_id FROM gyms WHERE gym_id = ' . $gymId)->fetchColumn();
            $gymName = (string) $pdo->query('SELECT name FROM gyms WHERE gym_id = ' . $gymId)->fetchColumn();

            audit_log($user['user_id'], 'update_payout_settings', 'gym', (string) $gymId, json_encode([
                'xendit_subaccount_id' => $xenditSubaccountId,
                'payout_channel' => $payoutChannel,
                'payout_account_number' => $payoutNumber,
                'payout_account_name' => $payoutName
            ]));

            notify_user(
                $ownerId,
                'system',
                'Payout Destination Updated',
                "Platform Admin updated payout destination settings for '{$gymName}' (" . strtoupper($payoutChannel) . ($payoutNumber ? " - {$payoutNumber}" : "") . ")."
            );

            flash("Payout settings for '{$gymName}' updated successfully.", 'success');
        }
        $returnUrl = trim((string) post('return_url'));
        if ($returnUrl !== '' && str_starts_with($returnUrl, 'index.php?page=gyms')) {
            header('Location: ' . $returnUrl);
            exit;
        }
        redirect('gyms');
    }

    // Auto-sync any expired subscriptions immediately so records and view stay accurate
    auto_expire_lapsed_gym_subscriptions();

    // ── Filter, Search & Pagination Parameters ──
    $q = trim((string)($_GET['q'] ?? ''));
    $facilityStatus = strtolower(trim((string)($_GET['status'] ?? 'all')));
    if (!in_array($facilityStatus, ['all', 'approved', 'suspended'], true)) {
        $facilityStatus = 'all';
    }

    $planFilter = trim((string)($_GET['plan'] ?? 'all'));
    $subStatusFilter = strtolower(trim((string)($_GET['sub_status'] ?? 'all')));
    if (!in_array($subStatusFilter, ['all', 'active', 'trialing', 'expired', 'inactive'], true)) {
        $subStatusFilter = 'all';
    }

    $page = max(1, (int)($_GET['p'] ?? 1));
    $perPage = 10;

    // Fast count for facility status navigation tabs
    $countsStmt = $pdo->query('
        SELECT 
            COUNT(*) AS total_all,
            SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) AS total_approved,
            SUM(CASE WHEN status = "suspended" THEN 1 ELSE 0 END) AS total_suspended
        FROM gyms
        WHERE status IN ("approved", "suspended")
    ');
    $tabCounts = $countsStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_all' => 0, 'total_approved' => 0, 'total_suspended' => 0];

    // Build WHERE clause
    $where = ['g.status IN ("approved", "suspended")'];
    $params = [];

    if ($facilityStatus !== 'all') {
        $where[] = 'g.status = :facility_status';
        $params[':facility_status'] = $facilityStatus;
    }

    if ($q !== '') {
        $where[] = '(
            g.name LIKE :q 
            OR CONCAT(u.first_name, " ", u.last_name) LIKE :q 
            OR u.first_name LIKE :q 
            OR u.last_name LIKE :q 
            OR u.email LIKE :q 
            OR u.phone LIKE :q 
            OR g.address LIKE :q
        )';
        $params[':q'] = '%' . $q . '%';
    }

    if ($planFilter !== 'all' && $planFilter !== '') {
        if (strtolower($planFilter) === 'none') {
            $where[] = '(g.subscription_plan IS NULL OR g.subscription_plan = "" OR LOWER(g.subscription_plan) = "none")';
        } else {
            $where[] = 'LOWER(g.subscription_plan) = LOWER(:plan)';
            $params[':plan'] = $planFilter;
        }
    }

    if ($subStatusFilter !== 'all') {
        if ($subStatusFilter === 'active') {
            $where[] = 'g.subscription_status = "active" AND (g.subscription_renewal_date IS NULL OR g.subscription_renewal_date >= CURDATE())';
        } elseif ($subStatusFilter === 'trialing') {
            $where[] = 'g.subscription_status = "trialing" AND (g.subscription_renewal_date IS NULL OR g.subscription_renewal_date >= CURDATE())';
        } elseif ($subStatusFilter === 'expired') {
            $where[] = '(g.subscription_status = "expired" OR (g.subscription_status IN ("active", "trialing") AND g.subscription_renewal_date IS NOT NULL AND g.subscription_renewal_date < CURDATE()))';
        } elseif ($subStatusFilter === 'inactive') {
            $where[] = '(g.subscription_status = "inactive" OR g.subscription_status IS NULL OR g.subscription_status = "")';
        }
    }

    $whereSql = implode(' AND ', $where);

    // Total filtered records
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM gyms g 
        JOIN users u ON u.user_id = g.owner_user_id 
        WHERE {$whereSql}
    ");
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $totalGyms = (int) $countStmt->fetchColumn();

    $totalPages = max(1, (int) ceil($totalGyms / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    // Fetch paginated slice
    $dataStmt = $pdo->prepare("
        SELECT g.*, u.first_name, u.last_name, u.email 
        FROM gyms g 
        JOIN users u ON u.user_id = g.owner_user_id 
        WHERE {$whereSql} 
        ORDER BY g.created_at DESC 
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) {
        $dataStmt->bindValue($k, $v);
    }
    $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();
    $gyms = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    if (function_exists('hydrate_gym_rows')) {
        $gyms = hydrate_gym_rows($gyms);
    }

    $availablePlatformPlans = get_platform_subscription_plans();
    $hasActiveFilters = ($facilityStatus !== 'all' || ($planFilter !== 'all' && $planFilter !== '') || $subStatusFilter !== 'all' || $q !== '');

    // Build pagination base URL preserving active filters
    $paginationParams = ['page' => 'gyms'];
    if ($facilityStatus !== 'all') {
        $paginationParams['status'] = $facilityStatus;
    }
    if ($planFilter !== 'all' && $planFilter !== '') {
        $paginationParams['plan'] = $planFilter;
    }
    if ($subStatusFilter !== 'all') {
        $paginationParams['sub_status'] = $subStatusFilter;
    }
    if ($q !== '') {
        $paginationParams['q'] = $q;
    }
    $paginationBaseUrl = 'index.php?' . http_build_query($paginationParams);
    $currentQueryUrl = 'index.php?' . http_build_query(array_merge(['page' => 'gyms'], $_GET));

    foreach ($gyms as &$gItem) {
        $gDocs = [];
        if (!empty($gItem['business_permit_url'])) {
            $gDocs[] = [
                'name' => 'Business Permit',
                'url' => upload_url($gItem['business_permit_url'], 'permits'),
                'icon' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
                'type' => 'doc'
            ];
        }
        if (!empty($gItem['barangay_clearance_url'])) {
            $gDocs[] = [
                'name' => 'Barangay Clearance',
                'url' => upload_url($gItem['barangay_clearance_url'], 'permits'),
                'icon' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>',
                'type' => 'doc'
            ];
        }
        if (!empty($gItem['valid_id_url'])) {
            $idLabel = !empty($gItem['valid_id_type']) ? $gItem['valid_id_type'] : 'Valid ID';
            $gDocs[] = [
                'name' => "Valid ID ({$idLabel})",
                'url' => upload_url($gItem['valid_id_url'], 'permits'),
                'icon' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><line x1="15" y1="8" x2="17" y2="8"/><line x1="15" y1="12" x2="17" y2="12"/><line x1="7" y1="16" x2="17" y2="16"/></svg>',
                'type' => 'doc'
            ];
        }
        if (!empty($gItem['secondary_valid_id_url'])) {
            $secIdLabel = !empty($gItem['secondary_valid_id_type']) ? $gItem['secondary_valid_id_type'] : 'Supporting ID';
            $gDocs[] = [
                'name' => "Supporting ID ({$secIdLabel})",
                'url' => upload_url($gItem['secondary_valid_id_url'], 'permits'),
                'icon' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><line x1="15" y1="8" x2="17" y2="8"/><line x1="15" y1="12" x2="17" y2="12"/><line x1="7" y1="16" x2="17" y2="16"/></svg>',
                'type' => 'doc'
            ];
        }
        if (!empty($gItem['fire_safety_cert_url'])) {
            $gDocs[] = [
                'name' => 'Fire Safety Cert. (FSIC)',
                'url' => upload_url($gItem['fire_safety_cert_url'], 'permits'),
                'icon' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
                'type' => 'doc'
            ];
        }
        if (!empty($gItem['fb_page_url'])) {
            $gDocs[] = [
                'name' => 'Facebook Page',
                'url' => $gItem['fb_page_url'],
                'icon' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>',
                'type' => 'link'
            ];
        }
        $gItem['docs'] = $gDocs;
        $gItem['doc_count'] = count($gDocs);
    }
    unset($gItem);

    render_header('All Gyms', $user);
?>
    <style>
    /* Desktop vs Mobile Toggle */
    .gyms-desktop-table {
        display: block;
    }
    .gyms-mobile-cards {
        display: none;
    }

    /* Keep page header title inline / unstacked on all screens */
    .gyms-page-header h1,
    .gyms-page-title {
        white-space: nowrap;
        font-size: clamp(1.15rem, 5vw, 1.75rem);
        letter-spacing: -0.02em;
        line-height: 1.25;
        margin: 0 0 4px;
    }

    @media (max-width: 768px) {
        .gyms-desktop-table {
            display: none !important;
        }
        .gyms-mobile-cards {
            display: flex !important;
            flex-direction: column;
            gap: 12px;
        }
        .gyms-page-header h1,
        .gyms-page-title {
            white-space: nowrap;
            font-size: clamp(1.02rem, 4.5vw, 1.35rem);
        }
    }

    @media (max-width: 420px) {
        .gyms-page-header h1,
        .gyms-page-title {
            white-space: nowrap;
            font-size: clamp(0.9rem, 4.2vw, 1.15rem);
        }
    }

    /* Mobile Gym Card Styles */
    .gym-card-item {
        background: color-mix(in srgb, var(--panel-soft) 45%, transparent);
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 14px 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        transition: all 0.2s ease;
    }
    html[data-theme="light"] .gym-card-item,
    [data-theme="light"] .gym-card-item {
        background: #ffffff;
        border-color: #e2e8f0;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
    }
    .gym-card-item:hover {
        border-color: color-mix(in srgb, var(--lime) 30%, transparent);
    }

    .gym-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 10px;
        padding-bottom: 10px;
        border-bottom: 1px solid color-mix(in srgb, var(--line) 60%, transparent);
    }
    .gym-card-identity {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 0;
    }
    .gym-card-avatar {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        background: color-mix(in srgb, var(--lime) 15%, transparent);
        color: var(--lime);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
    }
    html[data-theme="light"] .gym-card-avatar,
    [data-theme="light"] .gym-card-avatar {
        background: rgba(132, 204, 22, 0.15);
        color: #4d7c0f;
        border-color: rgba(132, 204, 22, 0.3);
    }
    .gym-card-names {
        min-width: 0;
        overflow: hidden;
    }
    .gym-card-title {
        font-weight: 700;
        font-size: 15px;
        color: var(--ink);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .gym-card-id {
        font-size: 12px;
        color: var(--muted);
        font-family: ui-monospace, monospace;
    }
    .gym-card-badges {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 4px;
        flex-shrink: 0;
    }

    .gym-card-details {
        display: grid;
        grid-template-columns: 1fr;
        gap: 8px;
        font-size: 13px;
    }
    .gym-card-detail-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        min-width: 0;
    }
    .gym-card-detail-label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: var(--muted);
        font-size: 12px;
        flex-shrink: 0;
    }
    .gym-card-detail-label svg {
        opacity: 0.75;
    }
    .gym-card-detail-value {
        color: var(--ink);
        font-weight: 500;
        text-align: right;
        word-break: break-all;
        max-width: 65%;
    }
    .gym-card-detail-value.email-value {
        color: var(--muted);
        font-size: 12.5px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 68%;
        display: block;
        user-select: all;
    }

    .gym-card-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        padding-top: 10px;
        border-top: 1px solid color-mix(in srgb, var(--line) 60%, transparent);
    }
    .gym-card-actions .btn-sm,
    .gym-card-actions form {
        flex: 1;
        min-width: 0;
        margin: 0;
    }
    .gym-card-actions form {
        display: flex;
    }
    .gym-card-actions .btn-sm,
    .gym-card-actions form button {
        width: 100%;
        display: inline-flex;
        justify-content: center;
        align-items: center;
        gap: 5px;
        padding: 7px 10px;
        font-size: 12px;
        font-weight: 600;
        border-radius: 6px;
        text-align: center;
        white-space: nowrap;
        box-sizing: border-box;
    }
        .gym-card-actions form button {
        margin: 0;
        width: auto;
    }

    /* Table container spacing for dropdown popups */
    .gyms-desktop-table.table-container {
        min-height: 420px;
        padding-bottom: 70px;
    }
    .gyms-desktop-table.table-container table td {
        vertical-align: middle !important;
    }

    /* Compact Document Dropdown Pill in All Gyms */
    .doc-pill-dropdown {
        position: relative !important;
        display: inline-block !important;
    }
    .doc-pill-dropdown.is-open {
        z-index: 1050 !important;
    }

    button.doc-pill-trigger,
    .doc-pill-trigger {
        display: inline-flex !important;
        align-items: center !important;
        gap: 6px !important;
        min-height: unset !important;
        height: auto !important;
        padding: 5px 11px !important;
        background: var(--panel-soft, rgba(255, 255, 255, 0.05)) !important;
        border: 1px solid var(--line, rgba(255, 255, 255, 0.12)) !important;
        border-radius: 99px !important;
        color: var(--ink, #f8fafc) !important;
        font-size: 12px !important;
        font-weight: 600 !important;
        cursor: pointer !important;
        transition: all 0.15s ease !important;
        white-space: nowrap !important;
        line-height: 1.2 !important;
        box-shadow: none !important;
        width: auto !important;
    }

    button.doc-pill-trigger:hover,
    .doc-pill-dropdown.is-open button.doc-pill-trigger,
    .doc-pill-dropdown.is-open .doc-pill-trigger {
        background: color-mix(in srgb, var(--accent, #c7ff22) 15%, transparent) !important;
        border-color: color-mix(in srgb, var(--accent, #c7ff22) 50%, transparent) !important;
        color: var(--accent, #c7ff22) !important;
        box-shadow: 0 0 12px color-mix(in srgb, var(--accent, #c7ff22) 20%, transparent) !important;
    }

    .doc-pill-chevron {
        transition: transform 0.2s ease;
        color: var(--muted, #8792ad) !important;
    }
    .doc-pill-dropdown.is-open .doc-pill-chevron {
        transform: rotate(180deg);
        color: var(--accent, #c7ff22) !important;
    }

    .doc-pill-menu {
        display: none !important;
        position: absolute !important;
        top: calc(100% + 6px) !important;
        left: 0 !important;
        z-index: 1000 !important;
        min-width: 240px !important;
        max-width: 320px !important;
        max-height: 220px !important;
        overflow-y: auto !important;
        overscroll-behavior: contain !important;
        background: var(--surface, #10131b) !important;
        background-color: #10131b !important;
        backdrop-filter: blur(16px) !important;
        -webkit-backdrop-filter: blur(16px) !important;
        border: 1px solid var(--line, rgba(255, 255, 255, 0.14)) !important;
        border-radius: 12px !important;
        box-shadow: 0 16px 36px rgba(0, 0, 0, 0.6) !important;
        padding: 6px !important;
        box-sizing: border-box !important;
        scrollbar-width: thin;
        scrollbar-color: var(--line, rgba(255, 255, 255, 0.2)) transparent;
    }

    .doc-pill-dropdown.drop-up .doc-pill-menu {
        top: auto !important;
        bottom: calc(100% + 6px) !important;
    }

    .doc-pill-menu::-webkit-scrollbar {
        width: 5px;
    }
    .doc-pill-menu::-webkit-scrollbar-track {
        background: transparent;
    }
    .doc-pill-menu::-webkit-scrollbar-thumb {
        background: var(--line, rgba(255, 255, 255, 0.2));
        border-radius: 99px;
    }

    .doc-pill-dropdown.is-open .doc-pill-menu {
        display: flex !important;
        flex-direction: column !important;
        gap: 3px !important;
    }

    .doc-menu-header {
        position: sticky !important;
        top: -6px !important;
        z-index: 2 !important;
        background: #10131b !important;
        padding: 6px 9px 4px !important;
        font-size: 10px !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.6px !important;
        color: var(--muted, #8792ad) !important;
        border-bottom: 1px solid var(--line, rgba(255, 255, 255, 0.08)) !important;
        margin-bottom: 3px !important;
        text-align: left !important;
    }

    button.doc-menu-item,
    a.doc-menu-item,
    .doc-menu-item {
        display: flex !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 8px !important;
        width: 100% !important;
        min-height: unset !important;
        height: auto !important;
        padding: 7px 9px !important;
        border-radius: 8px !important;
        background: transparent !important;
        border: none !important;
        color: var(--ink, #f8fafc) !important;
        font-size: 12px !important;
        font-weight: 500 !important;
        text-align: left !important;
        text-decoration: none !important;
        cursor: pointer !important;
        transition: all 0.12s ease !important;
        box-sizing: border-box !important;
        box-shadow: none !important;
    }

    button.doc-menu-item:hover,
    a.doc-menu-item:hover,
    .doc-menu-item:hover {
        background: color-mix(in srgb, var(--accent, #c7ff22) 14%, transparent) !important;
        color: #ffffff !important;
    }

    .doc-item-icon {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        width: 24px !important;
        height: 24px !important;
        min-width: 24px !important;
        border-radius: 6px !important;
        background: color-mix(in srgb, var(--accent, #c7ff22) 18%, transparent) !important;
        color: var(--accent, #c7ff22) !important;
        flex-shrink: 0 !important;
    }

    .doc-item-icon.link {
        background: rgba(59, 130, 246, 0.18) !important;
        color: #3b82f6 !important;
    }

    .doc-item-title {
        flex: 1 !important;
        min-width: 0 !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        text-align: left !important;
        color: #f8fafc !important;
        font-weight: 500 !important;
    }

    .doc-item-badge {
        font-size: 10px !important;
        font-weight: 700 !important;
        color: var(--muted, #8792ad) !important;
        padding: 2px 7px !important;
        border-radius: 4px !important;
        background: var(--panel-soft, rgba(255, 255, 255, 0.06)) !important;
        flex-shrink: 0 !important;
    }

    .doc-item-badge.link {
        color: #3b82f6 !important;
        background: rgba(59, 130, 246, 0.12) !important;
    }

    /* ── Gyms Toolbar, Tabs & Filters ── */
    .gyms-toolbar {
        display: flex;
        flex-direction: column;
        gap: 14px;
        margin: 18px 0 16px;
    }
    .gyms-tabs-pills {
        display: flex;
        gap: 8px;
        align-items: center;
        overflow-x: auto;
        padding-bottom: 2px;
        -webkit-overflow-scrolling: touch;
    }
    .gyms-nav-pill {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 7px 14px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        color: var(--muted);
        text-decoration: none;
        background: color-mix(in srgb, var(--panel-soft) 40%, transparent);
        border: 1px solid var(--line);
        transition: all 0.16s ease;
        white-space: nowrap;
    }
    .gyms-nav-pill:hover {
        color: var(--ink);
        border-color: color-mix(in srgb, var(--lime) 40%, transparent);
    }
    .gyms-nav-pill.active {
        background: color-mix(in srgb, var(--lime) 15%, transparent);
        color: var(--lime);
        border-color: color-mix(in srgb, var(--lime) 50%, transparent);
        font-weight: 700;
    }
    html[data-theme="light"] .gyms-nav-pill.active,
    [data-theme="light"] .gyms-nav-pill.active {
        background: rgba(132, 204, 22, 0.15);
        color: #3f6212;
        border-color: #84cc16;
    }
    .gyms-pill-badge {
        font-size: 11px;
        padding: 1px 7px;
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.08);
        color: inherit;
        font-weight: 700;
    }
    .gyms-nav-pill.active .gyms-pill-badge {
        background: var(--lime);
        color: #080b0d;
    }
    .badge-suspended-count {
        background: rgba(239, 68, 68, 0.25) !important;
        color: #ef4444 !important;
    }

    .gyms-filter-form {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        justify-content: space-between;
    }
    .gyms-search-wrap {
        position: relative;
        flex: 1;
        min-width: 250px;
        max-width: 380px;
    }
    .gyms-search-input {
        width: 100%;
        height: 38px;
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 8px;
        padding: 8px 30px 8px 34px;
        font-size: 13px;
        color: var(--ink);
        box-sizing: border-box;
        outline: none;
        transition: border-color 0.18s ease;
    }
    .gyms-search-input:focus {
        border-color: var(--lime);
    }
    .gyms-search-icon {
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--muted);
        pointer-events: none;
    }
    .gyms-search-clear {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--muted);
        text-decoration: none;
        font-size: 16px;
        line-height: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 18px;
        height: 18px;
    }
    .gyms-search-clear:hover {
        color: var(--ink);
    }
    .gyms-selects-wrap {
        display: flex !important;
        align-items: center !important;
        gap: 8px !important;
        flex-wrap: nowrap !important;
        flex-shrink: 0 !important;
    }
    .gyms-select {
        height: 38px !important;
        width: auto !important;
        min-width: 140px !important;
        max-width: 175px !important;
        display: inline-block !important;
        flex: 0 0 auto !important;
        background: var(--panel) !important;
        border: 1px solid var(--line) !important;
        border-radius: 8px !important;
        padding: 0 28px 0 12px !important;
        font-size: 13px !important;
        color: var(--ink) !important;
        cursor: pointer !important;
        outline: none !important;
        appearance: none !important;
        -webkit-appearance: none !important;
        -moz-appearance: none !important;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238792ad' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
        background-repeat: no-repeat !important;
        background-position: right 10px center !important;
        transition: border-color 0.18s ease !important;
    }
    .gyms-select:focus {
        border-color: var(--lime) !important;
    }
    .gyms-reset-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        height: 38px;
        padding: 0 12px;
        border-radius: 8px;
        border: 1px solid var(--line);
        background: color-mix(in srgb, var(--panel-soft) 40%, transparent);
        color: var(--muted);
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.16s ease;
    }
    .gyms-reset-btn:hover {
        color: var(--danger);
        border-color: var(--danger);
    }
    .gyms-meta-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 13px;
        color: var(--muted);
        margin: 4px 0 12px;
    }
    .gyms-filtered-tag {
        display: inline-block;
        font-size: 10.5px;
        padding: 1px 6px;
        border-radius: 4px;
        background: color-mix(in srgb, var(--lime) 15%, transparent);
        color: var(--lime);
        font-weight: 700;
        margin-left: 6px;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    /* ── Table Action Buttons & Kebab Menu ── */
    .gym-actions-wrap {
        display: inline-flex !important;
        align-items: center !important;
        gap: 6px !important;
        justify-content: flex-end !important;
        white-space: nowrap !important;
    }
    .action-kebab-dropdown {
        position: relative !important;
        display: inline-block !important;
    }
    .action-kebab-dropdown.is-open {
        z-index: 1050 !important;
    }
    .action-kebab-btn {
        width: 28px !important;
        height: 28px !important;
        border-radius: 6px !important;
        border: 1px solid var(--line, rgba(255, 255, 255, 0.12)) !important;
        background: var(--panel-soft, rgba(255, 255, 255, 0.05)) !important;
        color: var(--muted, #8792ad) !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        cursor: pointer !important;
        padding: 0 !important;
        transition: all 0.15s ease !important;
    }
    .action-kebab-btn:hover,
    .action-kebab-dropdown.is-open .action-kebab-btn {
        background: color-mix(in srgb, var(--lime) 15%, transparent) !important;
        color: var(--lime) !important;
        border-color: color-mix(in srgb, var(--lime) 40%, transparent) !important;
    }
    .action-kebab-menu {
        position: absolute !important;
        right: 0 !important;
        top: calc(100% + 5px) !important;
        min-width: 180px !important;
        background: #10131b !important;
        background-color: #10131b !important;
        opacity: 1 !important;
        border: 1px solid rgba(255, 255, 255, 0.16) !important;
        border-radius: 10px !important;
        padding: 5px !important;
        box-shadow: 0 16px 36px rgba(0, 0, 0, 0.75), 0 4px 12px rgba(0, 0, 0, 0.45) !important;
        display: none !important;
        z-index: 1060 !important;
        box-sizing: border-box !important;
    }
    html[data-theme="light"] .action-kebab-menu,
    [data-theme="light"] .action-kebab-menu {
        background: #ffffff !important;
        background-color: #ffffff !important;
        border-color: #cbd5e1 !important;
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.12) !important;
    }
    .action-kebab-dropdown.drop-up .action-kebab-menu {
        top: auto !important;
        bottom: calc(100% + 5px) !important;
    }
    .action-kebab-dropdown.is-open .action-kebab-menu {
        display: flex !important;
        flex-direction: column !important;
        gap: 2px !important;
    }
    .action-kebab-item {
        display: flex !important;
        align-items: center !important;
        gap: 8px !important;
        padding: 7px 10px !important;
        border-radius: 6px !important;
        font-size: 12.5px !important;
        font-weight: 500 !important;
        color: #f8fafc !important;
        background: transparent !important;
        border: none !important;
        width: 100% !important;
        text-align: left !important;
        text-decoration: none !important;
        cursor: pointer !important;
        transition: background 0.12s ease !important;
        box-sizing: border-box !important;
    }
    .action-kebab-item:hover {
        background: rgba(255, 255, 255, 0.08) !important;
        color: #ffffff !important;
    }
    html[data-theme="light"] .action-kebab-item {
        color: #0f172a !important;
    }
    html[data-theme="light"] .action-kebab-item:hover,
    [data-theme="light"] .action-kebab-item:hover {
        background: #f1f5f9 !important;
        color: #0f172a !important;
    }
    .action-kebab-item.is-warning {
        color: #f59e0b !important;
    }
    .action-kebab-item.is-warning:hover {
        background: rgba(245, 158, 11, 0.15) !important;
    }
    .action-kebab-item.is-success {
        color: var(--lime, #84cc16) !important;
    }
    .action-kebab-item.is-success:hover {
        background: color-mix(in srgb, var(--lime) 15%, transparent) !important;
    }
    .action-kebab-item.is-danger {
        color: #ef4444 !important;
    }
    .action-kebab-item.is-danger:hover {
        background: rgba(239, 68, 68, 0.15) !important;
    }
    .action-kebab-divider {
        height: 1px !important;
        background: rgba(255, 255, 255, 0.12) !important;
        margin: 4px 0 !important;
    }
    html[data-theme="light"] .action-kebab-divider,
    [data-theme="light"] .action-kebab-divider {
        background: #e2e8f0 !important;
    }

    @media (max-width: 768px) {
        .gyms-filter-form {
            flex-direction: column !important;
            align-items: stretch !important;
        }
        .gyms-search-wrap {
            max-width: 100% !important;
            width: 100% !important;
        }
        .gyms-selects-wrap {
            width: 100% !important;
            display: grid !important;
            grid-template-columns: 1fr 1fr auto !important;
            gap: 8px !important;
        }
        .gyms-selects-wrap .gyms-select {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
        }
    }
    </style>

    <section class="panel">
        <div class="page-header gyms-page-header">
            <div>
                <h1 class="gyms-page-title">All Gyms & Subscriptions</h1>
                <p>Manage registered gym facilities, accounts, and subscription tiers.</p>
            </div>
        </div>

        <?php
        $statusNavUrl = function(string $s) use ($q, $planFilter, $subStatusFilter): string {
            $params = ['page' => 'gyms', 'status' => $s];
            if ($planFilter !== 'all' && $planFilter !== '') $params['plan'] = $planFilter;
            if ($subStatusFilter !== 'all') $params['sub_status'] = $subStatusFilter;
            if ($q !== '') $params['q'] = $q;
            return 'index.php?' . http_build_query($params);
        };
        $clearSearchUrl = function() use ($facilityStatus, $planFilter, $subStatusFilter): string {
            $params = ['page' => 'gyms'];
            if ($facilityStatus !== 'all') $params['status'] = $facilityStatus;
            if ($planFilter !== 'all' && $planFilter !== '') $params['plan'] = $planFilter;
            if ($subStatusFilter !== 'all') $params['sub_status'] = $subStatusFilter;
            return 'index.php?' . http_build_query($params);
        };
        ?>

        <!-- ── Toolbar: Status Navigation, Search & Filters ── -->
        <div class="gyms-toolbar">
            <nav class="gyms-tabs-pills" aria-label="Filter Facility Status">
                <a href="<?= h($statusNavUrl('all')) ?>" class="gyms-nav-pill <?= $facilityStatus === 'all' ? 'active' : '' ?>">
                    <span>All Facilities</span>
                    <span class="gyms-pill-badge"><?= (int)$tabCounts['total_all'] ?></span>
                </a>
                <a href="<?= h($statusNavUrl('approved')) ?>" class="gyms-nav-pill <?= $facilityStatus === 'approved' ? 'active' : '' ?>">
                    <span>Approved</span>
                    <span class="gyms-pill-badge"><?= (int)$tabCounts['total_approved'] ?></span>
                </a>
                <a href="<?= h($statusNavUrl('suspended')) ?>" class="gyms-nav-pill <?= $facilityStatus === 'suspended' ? 'active' : '' ?>">
                    <span>Suspended</span>
                    <span class="gyms-pill-badge <?= (int)$tabCounts['total_suspended'] > 0 ? 'badge-suspended-count' : '' ?>"><?= (int)$tabCounts['total_suspended'] ?></span>
                </a>
            </nav>

            <form method="get" action="index.php" class="gyms-filter-form">
                <input type="hidden" name="page" value="gyms">
                <?php if ($facilityStatus !== 'all'): ?>
                    <input type="hidden" name="status" value="<?= h($facilityStatus) ?>">
                <?php endif; ?>

                <div class="gyms-search-wrap">
                    <svg class="gyms-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" name="q" class="gyms-search-input" placeholder="Search gym name, owner, email, phone..." value="<?= h($q) ?>">
                    <?php if ($q !== ''): ?>
                        <a href="<?= h($clearSearchUrl()) ?>" class="gyms-search-clear" title="Clear search">&times;</a>
                    <?php endif; ?>
                </div>

                <div class="gyms-selects-wrap">
                    <select name="plan" class="gyms-select" onchange="this.form.submit()">
                        <option value="all" <?= selected($planFilter, 'all') ?>>All Plans</option>
                        <option value="Free Trial" <?= selected(strtolower($planFilter), 'free trial') ?>>Free Trial</option>
                        <?php foreach ($availablePlatformPlans as $pk => $pDef): ?>
                            <option value="<?= h($pDef['name']) ?>" <?= selected(strtolower($planFilter), strtolower($pDef['name'])) ?>><?= h($pDef['name']) ?> Plan</option>
                        <?php endforeach; ?>
                        <option value="None" <?= selected(strtolower($planFilter), 'none') ?>>None / No Plan</option>
                    </select>

                    <select name="sub_status" class="gyms-select" onchange="this.form.submit()">
                        <option value="all" <?= selected($subStatusFilter, 'all') ?>>All Sub Statuses</option>
                        <option value="active" <?= selected($subStatusFilter, 'active') ?>>Active</option>
                        <option value="trialing" <?= selected($subStatusFilter, 'trialing') ?>>Trialing</option>
                        <option value="expired" <?= selected($subStatusFilter, 'expired') ?>>Expired</option>
                        <option value="inactive" <?= selected($subStatusFilter, 'inactive') ?>>Inactive</option>
                    </select>

                    <?php if ($hasActiveFilters): ?>
                        <a href="index.php?page=gyms" class="gyms-reset-btn" title="Reset all filters">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                            <span>Reset</span>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- ── Meta Results Count Bar ── -->
        <div class="gyms-meta-row">
            <div>
                <?php if ($totalGyms > 0): ?>
                    Showing <strong><?= $offset + 1 ?>–<?= min($totalGyms, $offset + count($gyms)) ?></strong> of <strong><?= $totalGyms ?></strong> <?= $totalGyms === 1 ? 'gym' : 'gyms' ?>
                    <?php if ($hasActiveFilters): ?>
                        <span class="gyms-filtered-tag">Filtered</span>
                    <?php endif; ?>
                <?php else: ?>
                    Showing <strong>0</strong> gyms
                <?php endif; ?>
            </div>
            <?php if ($totalPages > 1): ?>
                <div style="font-size: 12.5px; color: var(--muted);">
                    Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($totalGyms === 0): ?>
            <div class="empty-state" style="padding: 48px 20px; text-align: center; background: color-mix(in srgb, var(--panel-soft) 25%, transparent); border: 1px dashed var(--line); border-radius: 12px; margin: 12px 0 24px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--muted); opacity:0.6;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <h3 style="margin: 12px 0 6px; font-size: 16px; color: var(--ink);">No Gyms Found</h3>
                <p style="margin: 0 0 16px; color: var(--muted); font-size: 13.5px;">
                    <?= $hasActiveFilters ? 'No registered gyms match your active search and filter criteria.' : 'No active or suspended gyms are registered on the platform.' ?>
                </p>
                <?php if ($hasActiveFilters): ?>
                    <a href="index.php?page=gyms" class="btn-sm btn-primary" style="display:inline-flex; align-items:center; gap:6px; padding:7px 14px; text-decoration:none; font-weight:600;">
                        Clear All Filters
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <!-- Desktop / Tablet Table View (>= 769px) -->
        <div class="gyms-desktop-table table-container">
            <table>
                <thead>
                    <tr>
                        <th>Gym Name</th>
                        <th>Member Rating</th>
                        <th>Owner</th>
                        <th>Email</th>
                        <th>Facility Status</th>
                        <th>Documents</th>
                        <th>Subscription Plan</th>
                        <th>Sub Status</th>
                        <th>Renewal Date</th>
                        <th style="width: 145px; min-width: 145px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gyms as $gym): 
                        $subPlan = $gym['subscription_plan'] ?: 'None';
                        $subStatus = $gym['subscription_status'] ?: 'inactive';
                        $renewal = !empty($gym['subscription_renewal_date']) ? date('M j, Y', strtotime($gym['subscription_renewal_date'])) : '—';
                        $isRenewalPassed = !empty($gym['subscription_renewal_date']) && strtotime($gym['subscription_renewal_date']) < strtotime('today');
                        if ($isRenewalPassed && in_array(strtolower($subStatus), ['active', 'trialing'], true)) {
                            $subStatus = 'expired';
                        }
                        $planBadgeStyle = match(strtolower($subPlan)) {
                            'starter' => 'background: rgba(59, 130, 246, 0.15); color: #60a5fa;',
                            'professional' => 'background: rgba(34, 197, 94, 0.15); color: var(--lime);',
                            'business' => 'background: rgba(168, 85, 247, 0.15); color: #c084fc;',
                            default => 'background: rgba(148, 163, 184, 0.1); color: var(--muted);'
                        };
                        $statusBadgeClass = match(strtolower($subStatus)) {
                            'active' => 'badge-active',
                            'expired' => 'badge-inactive',
                            default => 'badge-pending'
                        };
                        $gStats = get_gym_rating_stats((int)$gym['gym_id']);
                    ?>
                        <tr>
                            <td><strong><?= h($gym['name']) ?></strong></td>
                            <td>
                                <a href="index.php?page=gym_profile&gym_id=<?= (int)$gym['gym_id'] ?>#sec-ratings" style="text-decoration:none; display:inline-flex; align-items:center; gap:5px;" title="View member ratings &amp; reviews">
                                    <span style="color:#f59e0b; font-weight:700; font-size:13px;">★ <?= number_format($gStats['avg_rating'], 1) ?></span>
                                    <span style="font-size:11px; color:var(--muted);">(<?= $gStats['total_reviews'] ?>)</span>
                                </a>
                            </td>
                            <td><?= h($gym['first_name'] . ' ' . $gym['last_name']) ?></td>
                            <td><?= h($gym['email']) ?></td>
                            <td>
                                <?php if ($gym['status'] === 'approved'): ?>
                                    <span class="badge badge-active">Approved</span>
                                <?php elseif ($gym['status'] === 'suspended'): ?>
                                    <span class="badge badge-inactive" style="color:var(--danger)">Suspended</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($gym['doc_count'] === 0): ?>
                                    <span class="badge" style="background: rgba(59, 130, 246, 0.12); color: #60a5fa; font-size: 11px; padding: 2px 7px; border-radius: 4px;" title="Directly provisioned by Platform Admin. Documents can be uploaded via Gym Profile.">Admin Provisioned</span>
                                <?php else: ?>
                                    <div class="doc-pill-dropdown" id="dropdown-<?= (int)$gym['gym_id'] ?>">
                                        <button type="button" class="doc-pill-trigger" onclick="toggleDocDropdown(<?= (int)$gym['gym_id'] ?>, event)" title="View <?= $gym['doc_count'] ?> attached documents">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                                <polyline points="14 2 14 8 20 8"></polyline>
                                            </svg>
                                            <span><?= $gym['doc_count'] ?> <?= $gym['doc_count'] === 1 ? 'Doc' : 'Docs' ?></span>
                                            <svg class="doc-pill-chevron" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                                <polyline points="6 9 12 15 18 9"></polyline>
                                            </svg>
                                        </button>
                                        <div class="doc-pill-menu" id="menu-<?= (int)$gym['gym_id'] ?>">
                                            <div class="doc-menu-header">
                                                <span>Attached Documents (<?= $gym['doc_count'] ?>)</span>
                                            </div>
                                            <?php foreach ($gym['docs'] as $dItem): ?>
                                                <?php if ($dItem['type'] === 'link'): ?>
                                                    <a href="<?= h($dItem['url']) ?>" target="_blank" rel="noopener noreferrer" class="doc-menu-item" onclick="closeAllDocDropdowns()">
                                                        <span class="doc-item-icon link"><?= $dItem['icon'] ?></span>
                                                        <span class="doc-item-title"><?= h($dItem['name']) ?></span>
                                                        <span class="doc-item-badge link">Visit ↗</span>
                                                    </a>
                                                <?php else: ?>
                                                    <button type="button" class="doc-menu-item" onclick="viewDocument('<?= h($dItem['url']) ?>', '<?= h(addslashes($dItem['name'])) ?>'); closeAllDocDropdowns();">
                                                        <span class="doc-item-icon"><?= $dItem['icon'] ?></span>
                                                        <span class="doc-item-title"><?= h($dItem['name']) ?></span>
                                                        <span class="doc-item-badge">Preview</span>
                                                    </button>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge" style="<?= $planBadgeStyle ?>; font-weight:700;">
                                    <?= h($subPlan) ?>
                                    <?php if (in_array(strtolower($subPlan), ['starter', 'professional', 'business'], true)): ?>
                                        <span style="font-size: 10px; opacity: 0.85; font-weight: 600; text-transform: uppercase;">(<?= ($gym['subscription_billing_cycle'] ?? 'monthly') === 'yearly' ? 'Yearly' : 'Monthly' ?>)</span>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $statusBadgeClass ?>">
                                    <?= h(ucfirst($subStatus)) ?>
                                </span>
                            </td>
                            <td><small style="color:var(--muted);"><?= h($renewal) ?></small></td>
                            <td style="white-space: nowrap; width: 145px; text-align: right;">
                                <div class="gym-actions-wrap">
                                    <button type="button" 
                                            class="btn-sm btn-primary" 
                                            style="padding: 5px 11px; font-size: 12px; font-weight: 700; white-space: nowrap;"
                                            data-gym-id="<?= (int)$gym['gym_id'] ?>"
                                            data-gym-name="<?= h($gym['name']) ?>"
                                            data-owner-name="<?= h($gym['first_name'] . ' ' . $gym['last_name']) ?>"
                                            data-plan="<?= h($gym['subscription_plan'] ?? '') ?>"
                                            data-status="<?= h($subStatus) ?>"
                                            data-renewal="<?= h($gym['subscription_renewal_date'] ?? '') ?>"
                                            data-cycle="<?= h($gym['subscription_billing_cycle'] ?? 'monthly') ?>"
                                            onclick="handleEditSubClick(this)"
                                            title="Edit Subscription Tier">
                                        Edit Sub
                                    </button>

                                    <div class="action-kebab-dropdown" id="action-dropdown-<?= (int)$gym['gym_id'] ?>">
                                        <button type="button" 
                                                class="action-kebab-btn" 
                                                onclick="toggleActionDropdown(<?= (int)$gym['gym_id'] ?>, event)" 
                                                title="More actions">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="1.5"></circle>
                                                <circle cx="12" cy="5" r="1.5"></circle>
                                                <circle cx="12" cy="19" r="1.5"></circle>
                                            </svg>
                                        </button>
                                        <div class="action-kebab-menu" id="action-menu-<?= (int)$gym['gym_id'] ?>">
                                            <button type="button" 
                                                    class="action-kebab-item"
                                                    data-gym-id="<?= (int)$gym['gym_id'] ?>"
                                                    data-gym-name="<?= h($gym['name']) ?>"
                                                    data-owner-name="<?= h($gym['first_name'] . ' ' . $gym['last_name']) ?>"
                                                    data-xendit-account="<?= h((string)($gym['xendit_subaccount_id'] ?? '')) ?>"
                                                    data-payout-channel="<?= h((string)($gym['payout_channel'] ?? 'gcash')) ?>"
                                                    data-payout-number="<?= h((string)($gym['payout_account_number'] ?? '')) ?>"
                                                    data-payout-name="<?= h((string)($gym['payout_account_name'] ?? '')) ?>"
                                                    onclick="closeAllActionDropdowns(); handleEditPayoutClick(this)">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                                                <span>Payout Settings</span>
                                            </button>

                                            <a href="index.php?page=gym_profile&gym_id=<?= (int)$gym['gym_id'] ?>" class="action-kebab-item" onclick="closeAllActionDropdowns()">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M3 7v14M21 7v14M6 11h4M6 15h4M14 11h4M14 15h4M9 21v-4h6v4M3 7l9-4 9 4"/></svg>
                                                <span>View Gym Profile</span>
                                            </a>

                                            <div class="action-kebab-divider"></div>

                                            <?php if ($gym['status'] === 'approved'): ?>
                                                <form method="post" action="index.php?page=gyms" style="margin:0; width:100%;">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="return_url" value="<?= h($currentQueryUrl) ?>">
                                                    <input type="hidden" name="gym_id" value="<?= (int) $gym['gym_id'] ?>">
                                                    <input type="hidden" name="action" value="suspend">
                                                    <button type="submit" class="action-kebab-item is-warning" data-confirm="Are you sure you want to suspend this gym? Their members will lose access.">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="10" y1="15" x2="10" y2="9"/><line x1="14" y1="15" x2="14" y2="9"/></svg>
                                                        <span>Suspend Facility</span>
                                                    </button>
                                                </form>
                                            <?php elseif ($gym['status'] === 'suspended'): ?>
                                                <form method="post" action="index.php?page=gyms" style="margin:0; width:100%;">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="return_url" value="<?= h($currentQueryUrl) ?>">
                                                    <input type="hidden" name="gym_id" value="<?= (int) $gym['gym_id'] ?>">
                                                    <input type="hidden" name="action" value="reactivate">
                                                    <button type="submit" class="action-kebab-item is-success" data-confirm="Reactivate this gym?">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                                        <span>Reactivate Facility</span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="post" action="index.php?page=gyms" style="margin:0; width:100%;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="return_url" value="<?= h($currentQueryUrl) ?>">
                                                <input type="hidden" name="gym_id" value="<?= (int) $gym['gym_id'] ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <button type="submit" class="action-kebab-item is-danger" data-confirm="Are you sure you want to permanently delete this gym? This cannot be undone.">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                                    <span>Delete Gym</span>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Card View (<= 768px) -->
        <div class="gyms-mobile-cards">
            <?php foreach ($gyms as $gym): 
                $subPlan = $gym['subscription_plan'] ?: 'None';
                $subStatus = $gym['subscription_status'] ?: 'inactive';
                $renewal = !empty($gym['subscription_renewal_date']) ? date('M j, Y', strtotime($gym['subscription_renewal_date'])) : '—';
                $isRenewalPassed = !empty($gym['subscription_renewal_date']) && strtotime($gym['subscription_renewal_date']) < strtotime('today');
                if ($isRenewalPassed && in_array(strtolower($subStatus), ['active', 'trialing'], true)) {
                    $subStatus = 'expired';
                }
                $planBadgeStyle = match(strtolower($subPlan)) {
                    'starter' => 'background: rgba(59, 130, 246, 0.15); color: #60a5fa;',
                    'professional' => 'background: rgba(34, 197, 94, 0.15); color: var(--lime);',
                    'business' => 'background: rgba(168, 85, 247, 0.15); color: #c084fc;',
                    default => 'background: rgba(148, 163, 184, 0.1); color: var(--muted);'
                };
                $statusBadgeClass = match(strtolower($subStatus)) {
                    'active' => 'badge-active',
                    'expired' => 'badge-inactive',
                    default => 'badge-pending'
                };
                $gStats = get_gym_rating_stats((int)$gym['gym_id']);
            ?>
                <div class="gym-card-item">
                    <div class="gym-card-header">
                        <div class="gym-card-identity">
                            <div class="gym-card-avatar">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M3 7v14M21 7v14M6 11h4M6 15h4M14 11h4M14 15h4M9 21v-4h6v4M3 7l9-4 9 4"/></svg>
                            </div>
                            <div class="gym-card-names">
                                <div class="gym-card-title"><?= h($gym['name']) ?></div>
                                <div class="gym-card-id">#<?= (int) $gym['gym_id'] ?></div>
                            </div>
                        </div>
                        <div class="gym-card-badges">
                            <?php if ($gym['status'] === 'approved'): ?>
                                <span class="badge badge-active">Approved</span>
                            <?php elseif ($gym['status'] === 'suspended'): ?>
                                <span class="badge badge-inactive" style="color:var(--danger)">Suspended</span>
                            <?php endif; ?>
                            <span class="badge" style="<?= $planBadgeStyle ?>; font-weight:700;">
                                <?= h($subPlan) ?>
                                <?php if (in_array(strtolower($subPlan), ['starter', 'professional', 'business'], true)): ?>
                                    <span style="font-size: 10px; opacity: 0.85; font-weight: 600; text-transform: uppercase;">(<?= ($gym['subscription_billing_cycle'] ?? 'monthly') === 'yearly' ? 'Yearly' : 'Monthly' ?>)</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>

                    <div class="gym-card-details">
                        <div class="gym-card-detail-item">
                            <span class="gym-card-detail-label">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" style="color:#f59e0b;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                Member Rating
                            </span>
                            <span class="gym-card-detail-value">
                                <a href="index.php?page=gym_profile&gym_id=<?= (int)$gym['gym_id'] ?>#sec-ratings" style="color:#f59e0b; font-weight:700; text-decoration:none;">
                                    ★ <?= number_format($gStats['avg_rating'], 1) ?> <small style="color:var(--muted); font-weight:normal;">(<?= $gStats['total_reviews'] ?>)</small>
                                </a>
                            </span>
                        </div>

                        <div class="gym-card-detail-item">
                            <span class="gym-card-detail-label">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                Owner
                            </span>
                            <span class="gym-card-detail-value"><?= h($gym['first_name'] . ' ' . $gym['last_name']) ?></span>
                        </div>

                        <div class="gym-card-detail-item">
                            <span class="gym-card-detail-label">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                Email
                            </span>
                            <span class="gym-card-detail-value email-value" title="<?= h($gym['email']) ?>"><?= h($gym['email']) ?></span>
                        </div>

                        <div class="gym-card-detail-item">
                            <span class="gym-card-detail-label">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                Sub Status
                            </span>
                            <span class="gym-card-detail-value">
                                <span class="badge <?= $statusBadgeClass ?>"><?= h(ucfirst($subStatus)) ?></span>
                            </span>
                        </div>

                        <div class="gym-card-detail-item">
                            <span class="gym-card-detail-label">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                Renewal
                            </span>
                            <span class="gym-card-detail-value"><?= h($renewal) ?></span>
                        </div>

                        <div class="gym-card-detail-item">
                            <span class="gym-card-detail-label">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                Documents
                            </span>
                            <span class="gym-card-detail-value">
                                <?php if ($gym['doc_count'] === 0): ?>
                                    <span class="badge" style="background: rgba(59, 130, 246, 0.12); color: #60a5fa; font-size: 10px; padding: 2px 6px; border-radius: 4px;">Admin Provisioned</span>
                                <?php else: ?>
                                    <div class="doc-pill-dropdown" id="dropdown-m<?= (int)$gym['gym_id'] ?>">
                                        <button type="button" class="doc-pill-trigger" onclick="toggleDocDropdown('m<?= (int)$gym['gym_id'] ?>', event)" title="View <?= $gym['doc_count'] ?> attached documents">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                                <polyline points="14 2 14 8 20 8"></polyline>
                                            </svg>
                                            <span><?= $gym['doc_count'] ?> Docs</span>
                                            <svg class="doc-pill-chevron" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                                <polyline points="6 9 12 15 18 9"></polyline>
                                            </svg>
                                        </button>
                                        <div class="doc-pill-menu" id="menu-m<?= (int)$gym['gym_id'] ?>">
                                            <div class="doc-menu-header">
                                                <span>Attached Documents (<?= $gym['doc_count'] ?>)</span>
                                            </div>
                                            <?php foreach ($gym['docs'] as $dItem): ?>
                                                <?php if ($dItem['type'] === 'link'): ?>
                                                    <a href="<?= h($dItem['url']) ?>" target="_blank" rel="noopener noreferrer" class="doc-menu-item" onclick="closeAllDocDropdowns()">
                                                        <span class="doc-item-icon link"><?= $dItem['icon'] ?></span>
                                                        <span class="doc-item-title"><?= h($dItem['name']) ?></span>
                                                        <span class="doc-item-badge link">Visit ↗</span>
                                                    </a>
                                                <?php else: ?>
                                                    <button type="button" class="doc-menu-item" onclick="viewDocument('<?= h($dItem['url']) ?>', '<?= h(addslashes($dItem['name'])) ?>'); closeAllDocDropdowns();">
                                                        <span class="doc-item-icon"><?= $dItem['icon'] ?></span>
                                                        <span class="doc-item-title"><?= h($dItem['name']) ?></span>
                                                        <span class="doc-item-badge">Preview</span>
                                                    </button>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>

                    <div class="gym-card-actions">
                        <button type="button" 
                                class="btn-sm btn-primary" 
                                data-gym-id="<?= (int)$gym['gym_id'] ?>"
                                data-gym-name="<?= h($gym['name']) ?>"
                                data-owner-name="<?= h($gym['first_name'] . ' ' . $gym['last_name']) ?>"
                                data-plan="<?= h($gym['subscription_plan'] ?? '') ?>"
                                data-status="<?= h($subStatus) ?>"
                                data-renewal="<?= h($gym['subscription_renewal_date'] ?? '') ?>"
                                data-cycle="<?= h($gym['subscription_billing_cycle'] ?? 'monthly') ?>"
                                onclick="handleEditSubClick(this)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                            Edit Sub
                        </button>

                        <button type="button" 
                                class="btn-sm btn-secondary" 
                                data-gym-id="<?= (int)$gym['gym_id'] ?>"
                                data-gym-name="<?= h($gym['name']) ?>"
                                data-owner-name="<?= h($gym['first_name'] . ' ' . $gym['last_name']) ?>"
                                data-xendit-account="<?= h((string)($gym['xendit_subaccount_id'] ?? '')) ?>"
                                data-payout-channel="<?= h((string)($gym['payout_channel'] ?? 'gcash')) ?>"
                                data-payout-number="<?= h((string)($gym['payout_account_number'] ?? '')) ?>"
                                data-payout-name="<?= h((string)($gym['payout_account_name'] ?? '')) ?>"
                                onclick="handleEditPayoutClick(this)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                            Payout
                        </button>

                        <?php if ($gym['status'] === 'approved'): ?>
                            <form method="post" action="index.php?page=gyms" style="margin:0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="return_url" value="<?= h($currentQueryUrl) ?>">
                                <input type="hidden" name="gym_id" value="<?= (int) $gym['gym_id'] ?>">
                                <input type="hidden" name="action" value="suspend">
                                <button type="submit" class="btn-sm btn-secondary" style="color:var(--danger);" data-confirm="Are you sure you want to suspend this gym? Their members will lose access.">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="10" y1="15" x2="10" y2="9"/><line x1="14" y1="15" x2="14" y2="9"/></svg>
                                    Suspend
                                </button>
                            </form>
                        <?php elseif ($gym['status'] === 'suspended'): ?>
                            <form method="post" action="index.php?page=gyms" style="margin:0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="return_url" value="<?= h($currentQueryUrl) ?>">
                                <input type="hidden" name="gym_id" value="<?= (int) $gym['gym_id'] ?>">
                                <input type="hidden" name="action" value="reactivate">
                                <button type="submit" class="btn-sm btn-primary" data-confirm="Reactivate this gym?">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                    Reactivate
                                </button>
                            </form>
                        <?php endif; ?>

                        <form method="post" action="index.php?page=gyms" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="return_url" value="<?= h($currentQueryUrl) ?>">
                            <input type="hidden" name="gym_id" value="<?= (int) $gym['gym_id'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" class="btn-sm btn-secondary" style="color:var(--danger); border-color:var(--danger);" data-confirm="Are you sure you want to permanently delete this gym? This cannot be undone.">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                Delete
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ── Pagination ── -->
        <?php if ($totalPages > 1): ?>
            <div class="gyms-pagination-wrap" style="margin-top: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; padding: 14px 4px 4px; border-top: 1px solid var(--line);">
                <div style="font-size: 13px; color: var(--muted);">
                    Showing page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong> (<?= $totalGyms ?> total <?= $totalGyms === 1 ? 'gym' : 'gyms' ?>)
                </div>
                <div>
                    <?php render_pagination($page, $totalPages, $paginationBaseUrl, 'p'); ?>
                </div>
            </div>
        <?php endif; ?>

        <?php endif; ?>
    </section>

    <!-- Hidden Form for Edit Subscription Submission -->
    <form id="edit-sub-form" method="post" action="index.php?page=gyms" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="return_url" value="<?= h($currentQueryUrl) ?>">
        <input type="hidden" name="action" value="update_subscription">
        <input type="hidden" name="gym_id" id="modal-sub-gym-id" value="">
        <input type="hidden" name="subscription_plan" id="modal-sub-plan" value="">
        <input type="hidden" name="subscription_billing_cycle" id="modal-sub-cycle" value="monthly">
        <input type="hidden" name="subscription_status" id="modal-sub-status" value="">
        <input type="hidden" name="subscription_renewal_date" id="modal-sub-renewal-date" value="">
        <input type="hidden" name="record_payment" id="modal-sub-record-payment" value="0">
        <input type="hidden" name="amount" id="modal-sub-amount" value="0">
        <input type="hidden" name="payment_method" id="modal-sub-method" value="cash">
    </form>

    <!-- Hidden Form for Edit Payout Settings Submission -->
    <form id="edit-payout-form" method="post" action="index.php?page=gyms" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="return_url" value="<?= h($currentQueryUrl) ?>">
        <input type="hidden" name="action" value="update_payout">
        <input type="hidden" name="gym_id" id="modal-payout-gym-id" value="">
        <input type="hidden" name="xendit_subaccount_id" id="modal-payout-xendit-account" value="">
        <input type="hidden" name="payout_channel" id="modal-payout-channel" value="gcash">
        <input type="hidden" name="payout_account_number" id="modal-payout-number" value="">
        <input type="hidden" name="payout_account_name" id="modal-payout-name" value="">
    </form>

    <script>
    const platformPlans = <?= json_encode(get_platform_subscription_plans()) ?>;
    const planMonthlyPrices = {};
    const planAnnualPrices = {};
    for (const [k, p] of Object.entries(platformPlans)) {
        planMonthlyPrices[p.name] = Number(p.price) || 0;
        planAnnualPrices[p.name] = Number(p.annual_price) || (Number(p.price) * 10);
    }
    planMonthlyPrices['Free Trial'] = 0;
    planAnnualPrices['Free Trial'] = 0;

    function getFutureDate(type) {
        const d = new Date();
        if (type === 'trial' || type === '14days') {
            d.setDate(d.getDate() + 14);
        } else if (type === 'yearly' || type === 'annual') {
            d.setFullYear(d.getFullYear() + 1);
        } else {
            // Default 1 month
            d.setMonth(d.getMonth() + 1);
        }
        const yyyy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        return `${yyyy}-${mm}-${dd}`;
    }

    function handleEditSubClick(btn) {
        if (!btn) return;
        const gymId = parseInt(btn.getAttribute('data-gym-id') || '0', 10);
        const gymName = btn.getAttribute('data-gym-name') || '';
        const ownerName = btn.getAttribute('data-owner-name') || '';
        const currentPlan = btn.getAttribute('data-plan') || '';
        const currentStatus = btn.getAttribute('data-status') || 'inactive';
        const currentRenewal = btn.getAttribute('data-renewal') || '';
        const currentCycle = btn.getAttribute('data-cycle') || 'monthly';
        openEditSubModal(gymId, gymName, ownerName, currentPlan, currentStatus, currentRenewal, currentCycle);
    }

    function openEditSubModal(gymId, gymName, ownerName, currentPlan, currentStatus, currentRenewal, currentCycle) {
        currentPlan = currentPlan || '';
        currentStatus = currentStatus || 'inactive';
        currentCycle = (currentCycle === 'yearly') ? 'yearly' : 'monthly';

        const todayStr = (new Date()).toISOString().split('T')[0];
        let defaultRenewal = currentRenewal;

        // Automatically detect next renewal date if empty OR if the existing date is already in the past
        if (!defaultRenewal || defaultRenewal < todayStr) {
            if (currentPlan === 'Free Trial' || currentStatus === 'trialing') {
                defaultRenewal = getFutureDate('trial');
            } else if (currentCycle === 'yearly') {
                defaultRenewal = getFutureDate('yearly');
            } else {
                defaultRenewal = getFutureDate('monthly');
            }
        }

        const safePlanLower = currentPlan.toLowerCase();
        const prices = (currentCycle === 'yearly') ? planAnnualPrices : planMonthlyPrices;
        const currentPrice = prices[currentPlan] !== undefined 
            ? prices[currentPlan] 
            : (platformPlans['professional'] ? (currentCycle === 'yearly' ? platformPlans['professional'].annual_price : platformPlans['professional'].price) : 999);

        let planOptions = '';
        for (const [k, p] of Object.entries(platformPlans)) {
            const isSelected = (safePlanLower === p.name.toLowerCase() || safePlanLower === k.toLowerCase());
            const popularTag = p.popular ? ' - Most Popular' : '';
            planOptions += `<option value="${p.name}" ${isSelected ? 'selected' : ''}>${p.name} Plan (${p.price_label}/mo · ${p.annual_price_label}/yr${popularTag})</option>`;
        }
        if (safePlanLower.includes('trial')) {
            planOptions = `<option value="Free Trial" selected>Free Trial (14 Days)</option>` + planOptions;
        }
        planOptions += `<option value="None" ${!currentPlan || safePlanLower === 'none' ? 'selected' : ''}>None / Unsubscribed</option>`;

        Swal.fire({
            title: 'Edit Gym Subscription',
            html: `
                <div style="text-align: left; display: flex; flex-direction: column; gap: 13px; margin-top: 10px;">
                    <div style="padding: 10px 14px; border-radius: 8px; background: color-mix(in srgb, var(--panel-soft) 50%, transparent); border: 1px solid var(--line);">
                        <div style="font-size: 14px; font-weight: 700; color: var(--ink);">${gymName}</div>
                        <div style="font-size: 12px; color: var(--muted);">Owner: ${ownerName}</div>
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <label style="flex: 1.5; display:block; font-size: 13px; color: var(--muted);">Subscription Plan *
                            <select id="swal-sub-plan" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;" onchange="onPlanOrCycleChange()">
                                ${planOptions}
                            </select>
                        </label>

                        <label style="flex: 1; display:block; font-size: 13px; color: var(--muted);">Billing Cycle *
                            <select id="swal-sub-cycle" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;" onchange="onPlanOrCycleChange()">
                                <option value="monthly" ${currentCycle === 'monthly' ? 'selected' : ''}>Monthly</option>
                                <option value="yearly" ${currentCycle === 'yearly' ? 'selected' : ''}>Yearly (Annual)</option>
                            </select>
                        </label>
                    </div>

                    <div style="display: flex; gap: 12px;">
                        <label style="flex: 1; display:block; font-size: 13px; color: var(--muted);">Subscription Status *
                            <select id="swal-sub-status" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;" onchange="onStatusChange(this.value)">
                                <option value="active" ${currentStatus === 'active' ? 'selected' : ''}>Active (Paid)</option>
                                <option value="trialing" ${currentStatus === 'trialing' ? 'selected' : ''}>Trialing (Free Trial)</option>
                                <option value="free" ${currentStatus === 'free' ? 'selected' : ''}>Free Tier (Limited)</option>
                                <option value="inactive" ${currentStatus === 'inactive' ? 'selected' : ''}>Inactive</option>
                                <option value="expired" ${currentStatus === 'expired' ? 'selected' : ''}>Expired</option>
                            </select>
                        </label>

                        <label style="flex: 1; display:block; font-size: 13px; color: var(--muted);">Renewal / Expiration Date
                            <input type="date" id="swal-sub-renewal" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;" value="${defaultRenewal}">
                        </label>
                    </div>

                    <div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center; margin-top: -6px;">
                        <span style="font-size: 11px; color: var(--muted); margin-right: auto;">Quick auto-detect:</span>
                        <button type="button" style="background: rgba(255,255,255,0.06); border: 1px solid var(--line); color: var(--muted); padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer;" onclick="calcRenewalDate('monthly')">+1 Month</button>
                        <button type="button" style="background: rgba(255,255,255,0.06); border: 1px solid var(--line); color: var(--muted); padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer;" onclick="calcRenewalDate('yearly')">+1 Year</button>
                        <button type="button" style="background: rgba(255,255,255,0.06); border: 1px solid var(--line); color: var(--muted); padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer;" onclick="calcRenewalDate('trial')">+14 Days</button>
                    </div>

                    <div style="padding: 12px; border-radius: 8px; background: rgba(34, 197, 94, 0.04); border: 1px dashed rgba(34, 197, 94, 0.25);">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 600; color: var(--ink);">
                            <input type="checkbox" id="swal-record-payment" style="accent-color: var(--lime);" onchange="togglePaymentFields(this.checked)">
                            <span>Record as Paid Subscription in Ledger</span>
                        </label>

                        <div id="swal-payment-fields" style="display: none; margin-top: 12px; flex-direction: column; gap: 10px;">
                            <div style="display: flex; gap: 10px;">
                                <label style="flex: 1; font-size: 12px; color: var(--muted);">Amount (₱)
                                     <input type="number" step="0.01" id="swal-sub-amount" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 2px;" value="${currentPrice}">
                                </label>

                                <label style="flex: 1; font-size: 12px; color: var(--muted);">Payment Method
                                    <select id="swal-sub-method" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 2px;">
                                        <option value="cash">Cash / Offline</option>
                                        <option value="gcash">GCash</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                        <option value="card">Card</option>
                                    </select>
                                </label>
                            </div>
                            <small style="color: var(--muted); font-size: 11px;">This transaction will appear in Platform Revenue and Recent Subscriptions.</small>
                        </div>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Save Subscription',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            width: '510px',
            preConfirm: () => {
                const plan = document.getElementById('swal-sub-plan').value;
                const cycle = document.getElementById('swal-sub-cycle').value;
                const status = document.getElementById('swal-sub-status').value;
                const renewal = document.getElementById('swal-sub-renewal').value;
                const recordPay = document.getElementById('swal-record-payment').checked;
                const amount = document.getElementById('swal-sub-amount').value;
                const method = document.getElementById('swal-sub-method').value;

                document.getElementById('modal-sub-gym-id').value = gymId;
                document.getElementById('modal-sub-plan').value = plan;
                document.getElementById('modal-sub-cycle').value = cycle;
                document.getElementById('modal-sub-status').value = status;
                document.getElementById('modal-sub-renewal-date').value = renewal;
                document.getElementById('modal-sub-record-payment').value = recordPay ? '1' : '0';
                document.getElementById('modal-sub-amount').value = amount || '0';
                document.getElementById('modal-sub-method').value = method;

                document.getElementById('edit-sub-form').submit();
            }
        });
    }

    function handleEditPayoutClick(btn) {
        if (!btn) return;
        const gymId = parseInt(btn.getAttribute('data-gym-id') || '0', 10);
        const gymName = btn.getAttribute('data-gym-name') || '';
        const ownerName = btn.getAttribute('data-owner-name') || '';
        const currentXenditAccount = btn.getAttribute('data-xendit-account') || '';
        const currentPayoutChannel = btn.getAttribute('data-payout-channel') || 'gcash';
        const currentPayoutNumber = btn.getAttribute('data-payout-number') || '';
        const currentPayoutName = btn.getAttribute('data-payout-name') || '';
        openEditPayoutModal(gymId, gymName, ownerName, currentXenditAccount, currentPayoutChannel, currentPayoutNumber, currentPayoutName);
    }

    function openEditPayoutModal(gymId, gymName, ownerName, currentXenditAccount, currentPayoutChannel, currentPayoutNumber, currentPayoutName) {
        currentPayoutChannel = currentPayoutChannel || 'gcash';

        const safeXenditAccount = String(currentXenditAccount || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const safePayoutChannel = String(currentPayoutChannel || 'gcash').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const safePayoutNumber = String(currentPayoutNumber || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const safePayoutName = String(currentPayoutName || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

        Swal.fire({
            title: 'Payout Destination Settings',
            html: `
                <div style="text-align: left; display: flex; flex-direction: column; gap: 14px; margin-top: 10px;">
                    <div style="padding: 10px 14px; border-radius: 8px; background: color-mix(in srgb, var(--panel-soft) 50%, transparent); border: 1px solid var(--line);">
                        <div style="font-size: 14px; font-weight: 700; color: var(--ink);">${gymName}</div>
                        <div style="font-size: 12px; color: var(--muted);">Owner: ${ownerName}</div>
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <label style="flex: 1; display:block; font-size: 12px; font-weight: 600; color: var(--muted);">Payout Channel
                            <select id="swal-payout-channel" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;">
                                <option value="gcash" ${safePayoutChannel.toLowerCase() === 'gcash' ? 'selected' : ''}>GCash</option>
                                <option value="maya" ${safePayoutChannel.toLowerCase() === 'maya' ? 'selected' : ''}>Maya</option>
                                <option value="bank_transfer" ${safePayoutChannel.toLowerCase() === 'bank_transfer' ? 'selected' : ''}>Bank Transfer</option>
                            </select>
                        </label>
                        <label style="flex: 1.5; display:block; font-size: 12px; font-weight: 600; color: var(--muted);">Payout Account / GCash #
                            <input id="swal-payout-number" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;" value="${safePayoutNumber}" placeholder="09171234567">
                        </label>
                    </div>

                    <label style="display:block; font-size: 12px; font-weight: 600; color: var(--muted);">Payout Account Holder Full Name
                        <input id="swal-payout-name" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;" value="${safePayoutName}" placeholder="Exact Name on GCash/Bank">
                    </label>

                    <label style="display:block; font-size: 12px; font-weight: 600; color: var(--muted);">XenPlatform Sub-Account User ID
                        <input id="swal-xendit-account" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px;" value="${safeXenditAccount}" placeholder="e.g. user_64a... (or leave blank if unlinked)">
                        <small style="display:block; margin-top: 4px; font-size: 11px; color: var(--muted); font-weight: normal;">Members can pay online via payment gateway only after this gym has a verified XenPlatform sub-account.</small>
                    </label>

                    <div style="padding: 10px 12px; border-radius: 8px; background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.2); font-size: 11.5px; color: var(--muted); line-height: 1.45;">
                        <strong style="color: #60a5fa;">Disbursement Note:</strong> These settlement details receive disbursed member dues, walk-in fees, and coach session payouts.
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Save Payout Details',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            width: '490px',
            preConfirm: () => {
                const payoutChan = document.getElementById('swal-payout-channel').value;
                const payoutNum = document.getElementById('swal-payout-number').value.trim();
                const payoutName = document.getElementById('swal-payout-name').value.trim();
                const xenditAccount = document.getElementById('swal-xendit-account').value.trim();

                document.getElementById('modal-payout-gym-id').value = gymId;
                document.getElementById('modal-payout-channel').value = payoutChan;
                document.getElementById('modal-payout-number').value = payoutNum;
                document.getElementById('modal-payout-name').value = payoutName;
                document.getElementById('modal-payout-xendit-account').value = xenditAccount;

                document.getElementById('edit-payout-form').submit();
            }
        });
    }

    function onPlanOrCycleChange() {
        const planEl = document.getElementById('swal-sub-plan');
        const cycleEl = document.getElementById('swal-sub-cycle');
        const statusEl = document.getElementById('swal-sub-status');
        const renewalEl = document.getElementById('swal-sub-renewal');
        const amountEl = document.getElementById('swal-sub-amount');

        if (!planEl || !cycleEl) return;
        const plan = planEl.value;
        const cycle = cycleEl.value;

        if (plan === 'Free Trial') {
            cycleEl.disabled = true;
            if (statusEl) statusEl.value = 'trialing';
            if (renewalEl) renewalEl.value = getFutureDate('trial');
            if (amountEl) amountEl.value = '0';
        } else if (plan === 'None' || plan === 'free') {
            cycleEl.disabled = true;
            if (statusEl) statusEl.value = 'inactive';
            if (renewalEl) renewalEl.value = '';
            if (amountEl) amountEl.value = '0';
        } else {
            cycleEl.disabled = false;
            if (statusEl && (statusEl.value === 'inactive' || statusEl.value === 'expired' || statusEl.value === 'trialing')) {
                statusEl.value = 'active';
            }
            if (renewalEl) {
                renewalEl.value = getFutureDate(cycle === 'yearly' ? 'yearly' : 'monthly');
            }
            if (amountEl) {
                const prices = (cycle === 'yearly') ? planAnnualPrices : planMonthlyPrices;
                if (prices[plan] !== undefined) {
                    amountEl.value = prices[plan];
                }
            }
        }
    }

    function onStatusChange(status) {
        const renewalEl = document.getElementById('swal-sub-renewal');
        const cycleEl = document.getElementById('swal-sub-cycle');
        const todayStr = (new Date()).toISOString().split('T')[0];

        if (status === 'active') {
            if (renewalEl && (!renewalEl.value || renewalEl.value < todayStr)) {
                const cycle = cycleEl ? cycleEl.value : 'monthly';
                renewalEl.value = getFutureDate(cycle === 'yearly' ? 'yearly' : 'monthly');
            }
        } else if (status === 'trialing') {
            if (renewalEl && (!renewalEl.value || renewalEl.value < todayStr)) {
                renewalEl.value = getFutureDate('trial');
            }
        }
    }

    function calcRenewalDate(type) {
        const renewalEl = document.getElementById('swal-sub-renewal');
        if (renewalEl) {
            renewalEl.value = getFutureDate(type);
        }
        const cycleEl = document.getElementById('swal-sub-cycle');
        if (cycleEl && (type === 'monthly' || type === 'yearly')) {
            cycleEl.value = type;
            const planEl = document.getElementById('swal-sub-plan');
            const amountEl = document.getElementById('swal-sub-amount');
            if (planEl && amountEl) {
                const prices = (type === 'yearly') ? planAnnualPrices : planMonthlyPrices;
                if (prices[planEl.value] !== undefined) {
                    amountEl.value = prices[planEl.value];
                }
            }
        }
    }

    function togglePaymentFields(show) {
        const container = document.getElementById('swal-payment-fields');
        if (container) {
            container.style.display = show ? 'flex' : 'none';
        }
    }

    // Action Dropdown Menu Handlers
    function toggleActionDropdown(gymId, event) {
        if (event) {
            event.stopPropagation();
        }
        const dropdown = document.getElementById('action-dropdown-' + gymId);
        if (!dropdown) return;

        const isOpen = dropdown.classList.contains('is-open');
        closeAllActionDropdowns();
        closeAllDocDropdowns();

        if (!isOpen) {
            dropdown.classList.remove('drop-up');
            dropdown.classList.add('is-open');

            const trigger = dropdown.querySelector('.action-kebab-btn');
            if (trigger) {
                const triggerRect = trigger.getBoundingClientRect();
                const container = dropdown.closest('.table-container') || document.body;
                const containerRect = container.getBoundingClientRect();

                const spaceBelow = Math.min(window.innerHeight - triggerRect.bottom, containerRect.bottom - triggerRect.bottom);
                const spaceAbove = triggerRect.top - Math.max(0, containerRect.top);

                if (spaceBelow < 210 && spaceAbove > spaceBelow) {
                    dropdown.classList.add('drop-up');
                }
            }
        }
    }

    function closeAllActionDropdowns() {
        document.querySelectorAll('.action-kebab-dropdown.is-open').forEach(el => el.classList.remove('is-open'));
    }

    // Document Dropdown & SweetAlert Viewer
    function toggleDocDropdown(gymId, event) {
        if (event) {
            event.stopPropagation();
        }
        const dropdown = document.getElementById('dropdown-' + gymId);
        if (!dropdown) return;

        const isOpen = dropdown.classList.contains('is-open');
        closeAllDocDropdowns();
        closeAllActionDropdowns();

        if (!isOpen) {
            dropdown.classList.remove('drop-up');
            dropdown.classList.add('is-open');

            const trigger = dropdown.querySelector('.doc-pill-trigger');
            if (trigger) {
                const triggerRect = trigger.getBoundingClientRect();
                const container = dropdown.closest('.table-container') || document.body;
                const containerRect = container.getBoundingClientRect();

                const spaceBelow = Math.min(window.innerHeight - triggerRect.bottom, containerRect.bottom - triggerRect.bottom);
                const spaceAbove = triggerRect.top - Math.max(0, containerRect.top);

                if (spaceBelow < 230 && spaceAbove > spaceBelow) {
                    dropdown.classList.add('drop-up');
                }

                const menu = dropdown.querySelector('.doc-pill-menu');
                if (menu) {
                    if (triggerRect.left + 280 > containerRect.right) {
                        menu.style.left = 'auto';
                        menu.style.right = '0';
                    } else {
                        menu.style.left = '0';
                        menu.style.right = 'auto';
                    }
                }
            }
        }
    }

    function closeAllDocDropdowns() {
        document.querySelectorAll('.doc-pill-dropdown.is-open').forEach(el => el.classList.remove('is-open'));
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.doc-pill-dropdown')) {
            closeAllDocDropdowns();
        }
        if (!e.target.closest('.action-kebab-dropdown')) {
            closeAllActionDropdowns();
        }
    });

    function viewDocument(url, docTitle = 'Document') {
        let clean = url.split('#')[0];
        if (clean.includes('file=')) {
            clean = decodeURIComponent(clean.split('file=').pop().split('&')[0]);
        }
        const ext = clean.split('.').pop().toLowerCase().split('?')[0].split('&')[0];
        
        if (ext === 'pdf') {
            Swal.fire({
                title: docTitle,
                html: `<iframe src="${url}" style="width:100%; height:70vh; border:none;"></iframe>`,
                width: '80%',
                showCloseButton: true,
                showConfirmButton: false,
                background: 'var(--surface-color, #18251eff)',
                color: 'var(--text-color, #ffffff)'
            });
        } else {
            Swal.fire({
                title: docTitle,
                html: `<div style="display: flex; justify-content: center; align-items: center; width: 100%; height: 70vh;"><img src="${url}" style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 4px;" alt="${docTitle}"></div>`,
                width: '80%',
                showCloseButton: true,
                showConfirmButton: false,
                background: 'var(--surface, #18251eff)',
                color: 'var(--text-color, #ffffff)'
            });
        }
    }
    </script>
<?php
    render_footer();
}
