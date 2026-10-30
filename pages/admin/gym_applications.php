<?php

declare(strict_types=1);

function gym_applications_page(): void
{
    $user = require_roles(['platform_admin']);
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');

        if ($action === 'update_id_policy') {
            verify_csrf();
            $idTypeId = (int) post('id_type_id');
            $category = post('category') === 'secondary' ? 'secondary' : 'primary';
            $isActive = post('is_active') ? 1 : 0;
            update_id_type_policy($idTypeId, $category, $isActive);
            flash('ID classification updated successfully.', 'success');
            redirect('gym_applications');
        } elseif ($action === 'add_id_type') {
            verify_csrf();
            $name = trim((string) post('new_id_name'));
            $category = post('new_category') === 'secondary' ? 'secondary' : 'primary';
            if ($name !== '') {
                $code = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $name)));
                $check = $pdo->prepare("SELECT COUNT(*) FROM gym_verification_id_types WHERE id_name = ? OR id_code = ?");
                $check->execute([$name, $code]);
                if ((int)$check->fetchColumn() === 0) {
                    $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(display_order), 0) FROM gym_verification_id_types")->fetchColumn();
                    $stmt = $pdo->prepare("INSERT INTO gym_verification_id_types (id_code, id_name, category, requires_supporting_id, display_order, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                    $stmt->execute([$code, $name, $category, $category === 'secondary' ? 1 : 0, $maxOrder + 10]);
                    flash("Added '{$name}' to accepted ID policy.", 'success');
                } else {
                    flash("ID type '{$name}' already exists in policy.", 'danger');
                }
            }
            redirect('gym_applications');
        }

        $gymId = (int) post('gym_id');

        $gymStmt = $pdo->prepare('SELECT g.name AS gym_name, g.owner_user_id, u.email, u.first_name, u.last_name FROM gyms g JOIN users u ON g.owner_user_id = u.user_id WHERE g.gym_id = ?');
        $gymStmt->execute([$gymId]);
        $gym = $gymStmt->fetch();

        if ($gym) {
            $ownerName = $gym['first_name'] . ' ' . $gym['last_name'];
            
            if ($action === 'approve') {
                $pdo->prepare('
                    UPDATE gyms 
                    SET status = "approved", 
                        subscription_plan = "Free Trial", 
                        subscription_status = "trialing", 
                        subscription_renewal_date = DATE_ADD(CURDATE(), INTERVAL 14 DAY) 
                    WHERE gym_id = ?
                ')->execute([$gymId]);
                require_once __DIR__ . '/../../core/seeds.php';
                seed_reference_exercises();
                
                notify_user((int)$gym['owner_user_id'], 'system', 'Application Approved - 14-Day Free Trial Activated', "Your gym application for {$gym['gym_name']} has been approved! A 14-day Free Trial has been activated with full platform access.");
                Emails::sendGymApplicationApproved($gym['email'], $ownerName, $gym['gym_name']);
                
                flash('Gym application approved successfully. 14-day Free Trial activated and default exercises seeded.', 'success');
            } elseif ($action === 'reject') {
                $pdo->prepare('UPDATE gyms SET status = "rejected" WHERE gym_id = ?')->execute([$gymId]);
                
                notify_user((int)$gym['owner_user_id'], 'system', 'Application Rejected', "Your gym application for {$gym['gym_name']} has been rejected.");
                Emails::sendGymApplicationRejected($gym['email'], $ownerName, $gym['gym_name']);
                
                flash('Gym application rejected.', 'success');
            }
        }
        redirect('gym_applications');
    }

    $currentStatus = strtolower(trim((string) ($_GET['status'] ?? 'pending')));
    if (!in_array($currentStatus, ['pending', 'approved', 'rejected', 'all'], true)) {
        $currentStatus = 'pending';
    }

    $statusCounts = [
        'pending'  => (int) scalar("SELECT COUNT(*) FROM gyms WHERE status = 'pending'"),
        'approved' => (int) scalar("SELECT COUNT(*) FROM gyms WHERE status = 'approved'"),
        'rejected' => (int) scalar("SELECT COUNT(*) FROM gyms WHERE status = 'rejected'"),
    ];
    $statusCounts['all'] = (int) scalar("SELECT COUNT(*) FROM gyms WHERE status IN ('pending', 'approved', 'rejected')");

    $sql = 'SELECT g.*, u.first_name, u.last_name, u.email FROM gyms g JOIN users u ON u.user_id = g.owner_user_id';
    $params = [];
    if ($currentStatus !== 'all') {
        $sql .= ' WHERE g.status = ?';
        $params[] = $currentStatus;
    } else {
        $sql .= " WHERE g.status IN ('pending', 'approved', 'rejected')";
    }
    $sql .= ' ORDER BY g.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (function_exists('hydrate_gym_rows')) {
        $applications = hydrate_gym_rows($applications);
    }

    foreach ($applications as &$app) {
        $docs = [];
        if (!empty($app['business_permit_url'])) {
            $docs[] = [
                'name' => 'Business Permit',
                'url' => upload_url($app['business_permit_url'], 'permits'),
                'icon' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
                'type' => 'doc'
            ];
        }
        if (!empty($app['barangay_clearance_url'])) {
            $docs[] = [
                'name' => 'Barangay Clearance',
                'url' => upload_url($app['barangay_clearance_url'], 'permits'),
                'icon' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>',
                'type' => 'doc'
            ];
        }
        if (!empty($app['valid_id_url'])) {
            $idLabel = !empty($app['valid_id_type']) ? $app['valid_id_type'] : 'Valid ID';
            $isSec = is_secondary_id($idLabel);
            $badge = $isSec ? 'Secondary' : 'Primary';
            $docs[] = [
                'name' => "Valid ID ({$idLabel}) - {$badge}",
                'url' => upload_url($app['valid_id_url'], 'permits'),
                'icon' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><line x1="15" y1="8" x2="17" y2="8"/><line x1="15" y1="12" x2="17" y2="12"/><line x1="7" y1="16" x2="17" y2="16"/></svg>',
                'type' => 'doc'
            ];
        }
        if (!empty($app['secondary_valid_id_url'])) {
            $secIdLabel = !empty($app['secondary_valid_id_type']) ? $app['secondary_valid_id_type'] : 'Supporting ID';
            $docs[] = [
                'name' => "Supporting ID ({$secIdLabel})",
                'url' => upload_url($app['secondary_valid_id_url'], 'permits'),
                'icon' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><line x1="15" y1="8" x2="17" y2="8"/><line x1="15" y1="12" x2="17" y2="12"/><line x1="7" y1="16" x2="17" y2="16"/></svg>',
                'type' => 'doc'
            ];
        }
        if (!empty($app['fire_safety_cert_url'])) {
            $docs[] = [
                'name' => 'Fire Safety Cert. (FSIC)',
                'url' => upload_url($app['fire_safety_cert_url'], 'permits'),
                'icon' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
                'type' => 'doc'
            ];
        }
        if (!empty($app['fb_page_url'])) {
            $docs[] = [
                'name' => 'Facebook Page',
                'url' => $app['fb_page_url'],
                'icon' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>',
                'type' => 'link'
            ];
        }
        $app['docs'] = $docs;
        $app['doc_count'] = count($docs);
        $app['has_permit'] = !empty($app['business_permit_url']) ? 1 : 0;
        $app['has_id'] = !empty($app['valid_id_url']) ? 1 : 0;
        $app['has_fire'] = !empty($app['fire_safety_cert_url']) ? 1 : 0;
        $app['has_brgy'] = !empty($app['barangay_clearance_url']) ? 1 : 0;
        $app['doc_complete'] = ($app['has_permit'] && $app['has_id']) ? 1 : 0;
        $app['payout_chan'] = strtolower(trim((string)($app['payout_channel'] ?? 'gcash')));
        $docNames = implode(' ', array_column($docs, 'name'));
        $app['search_text'] = strtolower($app['name'] . ' ' . $app['first_name'] . ' ' . $app['last_name'] . ' ' . $app['email'] . ' ' . $app['address'] . ' ' . ($app['contact_info'] ?? '') . ' ' . ($app['payout_account_number'] ?? '') . ' ' . ($app['payout_account_name'] ?? '') . ' ' . $app['payout_chan'] . ' ' . $app['status'] . ' ' . $docNames);
    }
    unset($app);

    render_header('Gym Applications', $user);
?>
    <style>
    /* Desktop vs Mobile Toggle */
    .app-desktop-table {
        display: block;
    }
    .app-mobile-cards {
        display: none;
    }

    @media (max-width: 768px) {
        .app-desktop-table {
            display: none !important;
        }
        .app-mobile-cards {
            display: flex !important;
            flex-direction: column;
            gap: 14px;
        }
    }

    /* Gym Applications Compact Table & Dropdown Styles */
    .table-container {
        min-height: 380px;
        padding-bottom: 60px;
    }

    .table-container table td {
        vertical-align: middle !important;
        padding: 10px 12px !important;
    }

    .pending-count-badge {
        font-size: 12px;
        font-weight: 700;
        background: rgba(132, 204, 22, 0.16);
        color: #65a30d;
        border: 1px solid rgba(132, 204, 22, 0.35);
        padding: 2px 10px;
        border-radius: 99px;
        margin-left: 8px;
        vertical-align: middle;
        display: inline-block;
    }

    .app-address-cell {
        max-width: 220px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        font-size: 13px;
        cursor: default;
    }

    /* Live Search Toolbar */
    .app-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 18px;
    }

    .app-search-wrap {
        position: relative;
        flex: 1 1 auto;
        min-width: 200px;
        max-width: 360px;
        height: 38px;
    }

    .app-search-input {
        width: 100%;
        height: 38px;
        box-sizing: border-box;
        padding: 0 36px 0 36px;
        border-radius: 8px;
        border: 1px solid var(--line);
        background: var(--panel-soft, rgba(255, 255, 255, 0.05));
        color: var(--ink);
        font-size: 13px;
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .app-search-input:focus {
        border-color: var(--lime);
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--lime) 20%, transparent);
    }

    .app-search-icon {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--muted);
        pointer-events: none;
        width: 15px;
        height: 15px;
        z-index: 2;
    }

    .app-search-clear {
        display: none;
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: var(--muted);
        cursor: pointer;
        padding: 4px;
        border-radius: 50%;
        font-size: 14px;
        line-height: 1;
        z-index: 2;
    }

    .app-search-clear:hover {
        color: var(--ink);
    }

    .app-toolbar-right {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
    }

    .app-filter-select,
    .app-per-page-select {
        width: auto !important;
        max-width: 100%;
        height: 38px;
        padding: 0 30px 0 11px;
        border-radius: 8px;
        border: 1px solid var(--line);
        background-color: var(--panel-soft, rgba(255, 255, 255, 0.05));
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='rgba(255,255,255,0.45)' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 10px center;
        color: var(--ink);
        font-size: 12.5px;
        font-weight: 500;
        outline: none;
        cursor: pointer;
        appearance: none;
        -webkit-appearance: none;
        -moz-appearance: none;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    html[data-theme="light"] .app-filter-select,
    html[data-theme="light"] .app-per-page-select {
        background-color: #ffffff;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='rgba(0,0,0,0.5)' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        border-color: #cbd5e1;
        color: #0f172a;
    }

    .app-filter-select:focus,
    .app-per-page-select:focus {
        border-color: var(--lime);
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--lime) 20%, transparent);
    }

    .app-reset-filters-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        height: 38px;
        padding: 0 12px;
        border-radius: 8px;
        border: 1px solid color-mix(in srgb, var(--danger, #ef4444) 30%, transparent);
        background: color-mix(in srgb, var(--danger, #ef4444) 10%, transparent);
        color: #f87171;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s ease;
        white-space: nowrap;
    }

    .app-reset-filters-btn:hover {
        background: color-mix(in srgb, var(--danger, #ef4444) 20%, transparent);
        color: #fca5a5;
    }

    /* Status Navigation Tabs */
    .users-tabs-pills {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 20px;
        padding-bottom: 2px;
    }

    .users-nav-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 7px 14px;
        font-size: 13px;
        font-weight: 600;
        color: var(--muted);
        text-decoration: none;
        border-radius: 8px;
        background: var(--panel-soft, rgba(255, 255, 255, 0.04));
        border: 1px solid var(--line);
        transition: all 0.18s ease;
        white-space: nowrap;
    }

    .users-nav-pill:hover {
        color: var(--ink);
        border-color: color-mix(in srgb, var(--lime) 40%, transparent);
        background: var(--panel);
    }

    .users-nav-pill.is-active {
        color: #000000 !important;
        background: var(--lime) !important;
        border-color: var(--lime) !important;
        font-weight: 700 !important;
        box-shadow: 0 2px 8px color-mix(in srgb, var(--lime) 30%, transparent);
    }

    .pill-title {
        font-size: 12.5px;
    }

    .pill-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 18px;
        height: 18px;
        padding: 0 6px;
        font-size: 10.5px;
        font-weight: 700;
        border-radius: 999px;
        background: color-mix(in srgb, var(--line) 90%, transparent);
        color: var(--ink);
        line-height: 1;
    }

    .users-nav-pill.is-active .pill-chip {
        background: rgba(0, 0, 0, 0.25);
        color: #000000;
    }

    @media (max-width: 960px) {
        .app-toolbar {
            flex-direction: column;
            align-items: stretch;
            gap: 10px;
        }
        .app-search-wrap {
            flex: none;
            max-width: 100%;
            min-width: 0;
            width: 100%;
            height: 38px;
        }
        .app-toolbar-right {
            width: 100%;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .app-filter-select,
        .app-per-page-select {
            flex: 1 1 calc(50% - 6px);
            min-width: 130px;
            width: auto !important;
        }
    }

    @media (max-width: 520px) {
        .app-filter-select,
        .app-per-page-select {
            flex: 1 1 100%;
            width: 100% !important;
        }
    }

    /* Pagination Bar */
    .app-pagination-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 14px;
        margin-top: 16px;
        padding-top: 14px;
        border-top: 1px solid var(--line);
    }

    .app-pagination-info {
        font-size: 13px;
        color: var(--muted);
    }

    .app-pagination-info strong {
        color: var(--ink);
        font-weight: 600;
    }

    .app-pagination-controls {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        user-select: none;
    }

    .app-page-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 34px;
        height: 34px;
        padding: 0 10px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
        color: var(--ink);
        border: 1px solid var(--line);
        background: var(--panel-soft, rgba(255, 255, 255, 0.05));
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .app-page-btn:hover:not(.disabled):not(.active) {
        background: var(--panel);
        border-color: var(--lime);
        color: var(--lime);
    }

    .app-page-btn.active {
        background: var(--lime);
        color: #000;
        border-color: var(--lime);
        font-weight: 700;
        cursor: default;
    }

    .app-page-btn.disabled {
        opacity: 0.35;
        cursor: not-allowed;
        pointer-events: none;
    }

    .app-page-ellipsis {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 24px;
        height: 34px;
        color: var(--muted);
        font-size: 13px;
    }

    /* Compact Document Dropdown Pill */
    .doc-pill-dropdown {
        position: relative;
        display: inline-block;
    }

    .doc-pill-dropdown.is-open {
        z-index: 1050;
    }

    .doc-pill-trigger {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 11px;
        background: var(--panel-soft, rgba(255, 255, 255, 0.05));
        border: 1px solid var(--line, rgba(255, 255, 255, 0.1));
        border-radius: 99px;
        color: var(--ink, #f8fafc);
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s ease;
        white-space: nowrap;
        line-height: 1.2;
    }

    .doc-pill-trigger:hover,
    .doc-pill-dropdown.is-open .doc-pill-trigger {
        background: color-mix(in srgb, var(--accent, #c7ff22) 15%, transparent);
        border-color: color-mix(in srgb, var(--accent, #c7ff22) 50%, transparent);
        color: var(--accent, #c7ff22);
        box-shadow: 0 0 12px color-mix(in srgb, var(--accent, #c7ff22) 20%, transparent);
    }

    .doc-pill-chevron {
        transition: transform 0.2s ease;
        color: var(--muted, #8792ad);
    }

    .doc-pill-dropdown.is-open .doc-pill-chevron {
        transform: rotate(180deg);
        color: var(--accent, #c7ff22);
    }

    .doc-pill-menu {
        display: none;
        position: absolute;
        top: calc(100% + 6px);
        left: 0;
        z-index: 1000;
        min-width: 240px;
        max-width: 320px;
        max-height: 220px;
        overflow-y: auto;
        overscroll-behavior: contain;
        background: var(--surface, #10131b);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border: 1px solid var(--line, rgba(255, 255, 255, 0.12));
        border-radius: 12px;
        box-shadow: 0 16px 36px rgba(0, 0, 0, 0.4), 0 0 0 1px var(--line, rgba(255, 255, 255, 0.05));
        padding: 6px;
        animation: pillMenuFade 0.15s ease-out;
        scrollbar-width: thin;
        scrollbar-color: var(--line, rgba(255, 255, 255, 0.2)) transparent;
    }

    .doc-pill-dropdown.drop-up .doc-pill-menu {
        top: auto;
        bottom: calc(100% + 6px);
        animation: pillMenuFadeUp 0.15s ease-out;
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

    .doc-pill-menu::-webkit-scrollbar-thumb:hover {
        background: var(--muted, #8792ad);
    }

    .doc-pill-dropdown.is-open .doc-pill-menu {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    @keyframes pillMenuFade {
        from { opacity: 0; transform: translateY(-4px) scale(0.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }

    @keyframes pillMenuFadeUp {
        from { opacity: 0; transform: translateY(4px) scale(0.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }

    .doc-menu-header {
        position: sticky;
        top: -6px;
        z-index: 2;
        background: var(--surface, #10131b);
        padding: 6px 9px 4px;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: var(--muted, #8792ad);
        border-bottom: 1px solid var(--line, rgba(255, 255, 255, 0.08));
        margin-bottom: 3px;
    }

    .doc-menu-item {
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
        padding: 7px 9px;
        border-radius: 8px;
        background: transparent;
        border: none;
        color: var(--ink, #f8fafc);
        font-size: 12px;
        font-weight: 500;
        text-align: left;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.12s ease;
        box-sizing: border-box;
    }

    .doc-menu-item:hover {
        background: color-mix(in srgb, var(--accent, #c7ff22) 14%, transparent);
        color: var(--ink, #ffffff);
    }

    .doc-item-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        border-radius: 6px;
        background: color-mix(in srgb, var(--accent, #c7ff22) 18%, transparent);
        color: var(--accent, #c7ff22);
        flex-shrink: 0;
    }

    .doc-item-icon.link {
        background: rgba(59, 130, 246, 0.18);
        color: #3b82f6;
    }

    .doc-item-title {
        flex: 1;
        min-width: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .doc-item-badge {
        font-size: 10px;
        font-weight: 700;
        color: var(--muted, #8792ad);
        padding: 2px 7px;
        border-radius: 4px;
        background: var(--panel-soft, rgba(255, 255, 255, 0.06));
        flex-shrink: 0;
    }

    .doc-item-badge.link {
        color: #3b82f6;
        background: rgba(59, 130, 246, 0.12);
    }

    /* Payout Dropdown Details */
    .payout-detail-card {
        display: flex;
        flex-direction: column;
        gap: 6px;
        padding: 2px;
    }

    .payout-detail-item {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 7px 9px;
        background: color-mix(in srgb, var(--panel-soft, rgba(255, 255, 255, 0.05)) 50%, transparent);
        border: 1px solid var(--line, rgba(255, 255, 255, 0.08));
        border-radius: 8px;
    }

    .payout-detail-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        border-radius: 6px;
        background: color-mix(in srgb, var(--accent, #c7ff22) 16%, transparent);
        color: var(--accent, #c7ff22);
        flex-shrink: 0;
    }

    .payout-detail-content {
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 1px;
    }

    .payout-detail-label {
        font-size: 9.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--muted, #8792ad);
    }

    .payout-detail-val {
        font-size: 12.5px;
        color: var(--ink, #f8fafc);
        word-break: normal;
        overflow-wrap: break-word;
    }

    .payout-copy-btn {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 8px;
        font-size: 10.5px;
        font-weight: 600;
        background: var(--panel-soft, rgba(255, 255, 255, 0.08));
        color: var(--ink, #f8fafc);
        border: 1px solid var(--line, rgba(255, 255, 255, 0.12));
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.15s ease;
        margin-left: auto;
        flex-shrink: 0;
    }

    .payout-copy-btn:hover {
        background: color-mix(in srgb, var(--accent, #c7ff22) 20%, transparent);
        border-color: var(--accent, #c7ff22);
        color: var(--accent, #c7ff22);
    }

    .payout-copy-btn.copied {
        background: rgba(132, 204, 22, 0.2) !important;
        border-color: #84cc16 !important;
        color: #84cc16 !important;
    }

    .payout-detail-footer {
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 10px;
        color: var(--muted, #8792ad);
        padding: 4px 6px 2px;
        line-height: 1.25;
    }

    .payout-detail-footer svg {
        color: var(--accent, #c7ff22);
        flex-shrink: 0;
    }

    /* Mobile Gym Application Card Styles */
    .app-card-item {
        background: color-mix(in srgb, var(--panel-soft) 45%, transparent);
        border: 1px solid var(--line);
        border-radius: 14px;
        padding: 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    html[data-theme="light"] .app-card-item,
    [data-theme="light"] .app-card-item {
        background: #ffffff;
        border-color: #e2e8f0;
        box-shadow: 0 1px 6px rgba(0, 0, 0, 0.04);
    }

    .app-card-item:hover {
        border-color: color-mix(in srgb, var(--lime) 30%, transparent);
    }

    .app-card-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        padding-bottom: 12px;
        border-bottom: 1px solid color-mix(in srgb, var(--line) 60%, transparent);
    }

    .app-card-identity {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 0;
    }

    .app-card-avatar {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: color-mix(in srgb, var(--lime) 15%, transparent);
        color: var(--lime);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
    }

    html[data-theme="light"] .app-card-avatar,
    [data-theme="light"] .app-card-avatar {
        background: rgba(132, 204, 22, 0.15);
        color: #4d7c0f;
        border-color: rgba(132, 204, 22, 0.3);
    }

    .app-card-names {
        min-width: 0;
    }

    .app-card-title {
        font-weight: 700;
        font-size: 15px;
        color: var(--ink);
        line-height: 1.3;
        word-break: break-word;
    }

    .app-card-date {
        font-size: 11px;
        color: var(--muted);
        margin-top: 2px;
    }

    .app-card-body {
        display: flex;
        flex-direction: column;
        gap: 8px;
        font-size: 13px;
    }

    .app-card-info-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
    }

    .app-card-label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: var(--muted);
        font-size: 12px;
        font-weight: 500;
        flex-shrink: 0;
    }

    .app-card-value {
        color: var(--ink);
        text-align: right;
        word-break: break-word;
        font-size: 13px;
    }

    .app-card-docs {
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding: 10px 12px;
        border-radius: 10px;
        background: var(--panel-soft, rgba(255, 255, 255, 0.04));
        border: 1px solid var(--line);
    }

    .app-card-docs-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--muted);
    }

    .app-card-docs-list {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .app-card-doc-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 9px;
        background: var(--panel, #10131b);
        border: 1px solid var(--line);
        border-radius: 6px;
        color: var(--ink);
        font-size: 11px;
        font-weight: 500;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .app-card-doc-pill:hover {
        border-color: var(--lime);
        color: var(--lime);
    }

    .app-card-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        padding-top: 10px;
        border-top: 1px solid color-mix(in srgb, var(--line) 60%, transparent);
    }

    .app-card-actions form {
        margin: 0;
        display: flex;
    }

    .app-card-actions .btn-sm {
        width: 100%;
        justify-content: center;
        padding: 9px 12px;
        font-size: 13px;
        font-weight: 600;
        border-radius: 8px;
    }
    </style>

    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Gym Applications <?php if ($statusCounts['pending'] > 0): ?><span class="pending-count-badge"><?= $statusCounts['pending'] ?> Pending</span><?php else: ?><span class="badge" style="background: rgba(34, 197, 94, 0.15); color: var(--lime); font-size: 11px; margin-left: 8px;">All Caught Up</span><?php endif; ?></h1>
                <p>Review, search, and manage registered gym applications and facility accounts.</p>
            </div>
        </div>

        <div class="users-tabs-pills" role="tablist">
            <a href="?page=gym_applications&status=pending" class="users-nav-pill <?= $currentStatus === 'pending' ? 'is-active' : '' ?>">
                <span class="pill-title">Pending</span>
                <span class="pill-chip"><?= $statusCounts['pending'] ?></span>
            </a>
            <a href="?page=gym_applications&status=approved" class="users-nav-pill <?= $currentStatus === 'approved' ? 'is-active' : '' ?>">
                <span class="pill-title">Approved</span>
                <span class="pill-chip"><?= $statusCounts['approved'] ?></span>
            </a>
            <a href="?page=gym_applications&status=rejected" class="users-nav-pill <?= $currentStatus === 'rejected' ? 'is-active' : '' ?>">
                <span class="pill-title">Rejected</span>
                <span class="pill-chip"><?= $statusCounts['rejected'] ?></span>
            </a>
            <a href="?page=gym_applications&status=all" class="users-nav-pill <?= $currentStatus === 'all' ? 'is-active' : '' ?>">
                <span class="pill-title">All Applications</span>
                <span class="pill-chip"><?= $statusCounts['all'] ?></span>
            </a>
        </div>

        <div class="app-toolbar">
            <div class="app-search-wrap">
                <svg class="app-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text"
                       id="appSearchInput"
                       class="app-search-input"
                       placeholder="Search by gym, owner, email, address, phone..."
                       autocomplete="off">
                <button type="button"
                        id="appSearchClear"
                        class="app-search-clear"
                        onclick="clearAppSearch()"
                        title="Clear search">✕</button>
            </div>
            <div class="app-toolbar-right">
                <select id="appPayoutFilter" class="app-filter-select" title="Filter by payout channel">
                    <option value="all">All Payouts</option>
                    <option value="gcash">GCash</option>
                    <option value="maya">Maya</option>
                    <option value="bank_transfer">Bank Transfer</option>
                </select>

                <select id="appDocFilter" class="app-filter-select" title="Filter by document status">
                    <option value="all">All Documents</option>
                    <option value="complete">Complete (Permit + ID)</option>
                    <option value="has_permit">With Business Permit</option>
                    <option value="has_id">With Valid ID</option>
                    <option value="has_fire">With Fire Safety Cert</option>
                    <option value="has_brgy">With Brgy Clearance</option>
                    <option value="missing">Missing Required Docs</option>
                </select>

                <select id="appSortSelect" class="app-filter-select" title="Sort order">
                    <option value="newest">Newest First</option>
                    <option value="oldest">Oldest First</option>
                    <option value="name_asc">Gym Name (A → Z)</option>
                    <option value="name_desc">Gym Name (Z → A)</option>
                </select>

                <select id="appPerPageSelect" class="app-per-page-select" title="Applications per page">
                    <option value="5">5 / page</option>
                    <option value="10" selected>10 / page</option>
                    <option value="25">25 / page</option>
                    <option value="50">50 / page</option>
                </select>

                <button type="button" id="appResetFiltersBtn" class="app-reset-filters-btn" onclick="resetAllAppFilters()" title="Reset all filters" style="display: none;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                    <span>Reset</span>
                </button>
            </div>
        </div>

        <?php 
        $hasActionable = ($statusCounts['pending'] > 0 || $statusCounts['rejected'] > 0);
        $showActionsColumn = ($currentStatus === 'pending' || $currentStatus === 'rejected' || ($currentStatus === 'all' && $hasActionable));
        $tableColspan = $showActionsColumn ? 9 : 8;
        ?>
        <div class="app-desktop-table table-container">
            <table>
                <thead>
                    <tr>
                        <th>Gym Name</th>
                        <th>Owner</th>
                        <th>Email</th>
                        <th>Address</th>
                        <th>Contact Info</th>
                        <th>Payout Account</th>
                        <th>Documents</th>
                        <th>Status</th>
                        <?php if ($showActionsColumn): ?>
                            <th style="width: 170px;">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$applications): ?>
                        <?php table_empty($tableColspan, 'No ' . ($currentStatus === 'all' ? '' : htmlspecialchars($currentStatus) . ' ') . 'applications found.'); ?>
                    <?php else: foreach ($applications as $app): 
                        $docs = $app['docs'];
                        $docCount = $app['doc_count'];
                    ?>
                        <tr class="app-row" 
                            data-search="<?= h($app['search_text']) ?>"
                            data-name="<?= h(strtolower($app['name'])) ?>"
                            data-created="<?= strtotime($app['created_at'] ?? 'now') ?>"
                            data-payout="<?= h($app['payout_chan']) ?>"
                            data-status="<?= h($app['status']) ?>"
                            data-has-permit="<?= $app['has_permit'] ?>"
                            data-has-id="<?= $app['has_id'] ?>"
                            data-has-fire="<?= $app['has_fire'] ?>"
                            data-has-brgy="<?= $app['has_brgy'] ?>"
                            data-doc-complete="<?= $app['doc_complete'] ?>">
                            <td>
                                <strong><?= h($app['name']) ?></strong>
                                <?php if (!empty($app['created_at'])): ?>
                                    <div class="muted" style="font-size:11px; margin-top:2px;"><?= date('M j, Y', strtotime($app['created_at'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= h($app['first_name'] . ' ' . $app['last_name']) ?></td>
                            <td><?= h($app['email']) ?></td>
                            <td>
                                <div class="app-address-cell" title="<?= h($app['address']) ?>">
                                    <?= h($app['address']) ?>
                                </div>
                            </td>
                            <td><?= h($app['contact_info'] ?? 'N/A') ?></td>
                            <td>
                                <?php if (!empty($app['payout_account_number'])): ?>
                                    <div class="doc-pill-dropdown payout-pill-dropdown" id="payout-dropdown-<?= (int)$app['gym_id'] ?>">
                                        <button type="button" class="doc-pill-trigger payout-pill-trigger" onclick="togglePayoutDropdown(<?= (int)$app['gym_id'] ?>, event)" title="View payout destination details">
                                            <span style="width: 7px; height: 7px; border-radius: 50%; background: #84cc16; display: inline-block; flex-shrink: 0;"></span>
                                            <span style="font-weight: 700; color: var(--accent, #c7ff22);"><?= strtoupper(h($app['payout_channel'] ?? 'GCash')) ?></span>
                                            <span style="font-size: 11.5px; opacity: 0.92; font-family: monospace; letter-spacing: -0.2px;"><?= h($app['payout_account_number']) ?></span>
                                            <svg class="doc-pill-chevron" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                                <polyline points="6 9 12 15 18 9"></polyline>
                                            </svg>
                                        </button>
                                        <div class="doc-pill-menu payout-pill-menu" id="payout-menu-<?= (int)$app['gym_id'] ?>" style="min-width: 275px;">
                                            <div class="doc-menu-header">
                                                <span>Payout Destination</span>
                                            </div>
                                            <div class="payout-detail-card">
                                                <div class="payout-detail-item">
                                                    <div class="payout-detail-icon">
                                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
                                                    </div>
                                                    <div class="payout-detail-content">
                                                        <div class="payout-detail-label">Payment Channel</div>
                                                        <div class="payout-detail-val">
                                                            <span class="badge" style="padding: 2px 7px; font-size: 10.5px; font-weight: 700; background: rgba(132, 204, 22, 0.18); color: var(--accent, #c7ff22); border-radius: 4px;">
                                                                <?= strtoupper(h($app['payout_channel'] ?? 'GCash')) ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="payout-detail-item">
                                                    <div class="payout-detail-icon">
                                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                                                    </div>
                                                    <div class="payout-detail-content" style="flex: 1; min-width: 0;">
                                                        <div class="payout-detail-label">Account Number</div>
                                                        <div class="payout-detail-val" style="font-family: monospace; font-size: 13px; font-weight: 700; color: #ffffff;">
                                                            <?= h($app['payout_account_number']) ?>
                                                        </div>
                                                    </div>
                                                    <button type="button" class="payout-copy-btn" onclick="copyPayoutText('<?= h($app['payout_account_number']) ?>', this, event)" title="Copy account number">
                                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                                        <span>Copy</span>
                                                    </button>
                                                </div>

                                                <?php if (!empty($app['payout_account_name'])): ?>
                                                <div class="payout-detail-item">
                                                    <div class="payout-detail-icon">
                                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                                    </div>
                                                    <div class="payout-detail-content">
                                                        <div class="payout-detail-label">Account Holder Name</div>
                                                        <div class="payout-detail-val" style="font-weight: 600; color: #ffffff;">
                                                            <?= h($app['payout_account_name']) ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php endif; ?>

                                                <div class="payout-detail-footer">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Verified destination for member payouts</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="muted" style="font-size:12px;">Not provided</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($docCount === 0): ?>
                                    <?php if ($app['status'] === 'approved'): ?>
                                        <span class="badge" style="background: rgba(59, 130, 246, 0.12); color: #60a5fa; font-size: 11px; padding: 2px 7px; border-radius: 4px;" title="Directly provisioned by Platform Admin. Documents can be uploaded via Gym Profile.">Admin Provisioned</span>
                                    <?php else: ?>
                                        <span class="muted" style="font-size:12px;">No docs</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="doc-pill-dropdown" id="dropdown-<?= (int)$app['gym_id'] ?>">
                                        <button type="button" class="doc-pill-trigger" onclick="toggleDocDropdown(<?= (int)$app['gym_id'] ?>, event)" title="View <?= $docCount ?> attached documents">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                                <polyline points="14 2 14 8 20 8"></polyline>
                                            </svg>
                                            <span><?= $docCount ?> <?= $docCount === 1 ? 'Doc' : 'Docs' ?></span>
                                            <svg class="doc-pill-chevron" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                                <polyline points="6 9 12 15 18 9"></polyline>
                                            </svg>
                                        </button>
                                        <div class="doc-pill-menu" id="menu-<?= (int)$app['gym_id'] ?>">
                                            <div class="doc-menu-header">
                                                <span>Attached Documents (<?= $docCount ?>)</span>
                                            </div>
                                            <?php foreach ($docs as $doc): ?>
                                                <?php if ($doc['type'] === 'link'): ?>
                                                    <a href="<?= h($doc['url']) ?>" target="_blank" rel="noopener noreferrer" class="doc-menu-item" onclick="closeAllDocDropdowns()">
                                                        <span class="doc-item-icon link"><?= $doc['icon'] ?></span>
                                                        <span class="doc-item-title"><?= h($doc['name']) ?></span>
                                                        <span class="doc-item-badge link">Visit ↗</span>
                                                    </a>
                                                <?php else: ?>
                                                    <button type="button" class="doc-menu-item" onclick="viewDocument('<?= h($doc['url']) ?>', '<?= h(addslashes($doc['name'])) ?>'); closeAllDocDropdowns();">
                                                        <span class="doc-item-icon"><?= $doc['icon'] ?></span>
                                                        <span class="doc-item-title"><?= h($doc['name']) ?></span>
                                                        <span class="doc-item-badge">Preview</span>
                                                    </button>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($app['status'] === 'approved'): ?>
                                    <span class="badge" style="background: rgba(34, 197, 94, 0.15); color: var(--lime); font-weight: 700; border: 1px solid rgba(34, 197, 94, 0.3);">Approved</span>
                                <?php elseif ($app['status'] === 'rejected'): ?>
                                    <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #f87171; font-weight: 700; border: 1px solid rgba(239, 68, 68, 0.3);">Rejected</span>
                                <?php elseif ($app['status'] === 'suspended'): ?>
                                    <span class="badge" style="background: rgba(234, 179, 8, 0.15); color: #fbbf24; font-weight: 700; border: 1px solid rgba(234, 179, 8, 0.3);">Suspended</span>
                                <?php else: ?>
                                    <span class="pending-count-badge" style="margin: 0; font-size: 11px;">Pending</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($showActionsColumn): ?>
                            <td>
                                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                    <?php if ($app['status'] === 'pending'): ?>
                                        <form method="post" action="index.php?page=gym_applications<?= $currentStatus !== 'pending' ? '&status=' . urlencode($currentStatus) : '' ?>" style="margin:0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="gym_id" value="<?= (int) $app['gym_id'] ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="btn-sm btn-primary" data-confirm="Approve this gym?">Approve</button>
                                        </form>
                                        <form method="post" action="index.php?page=gym_applications<?= $currentStatus !== 'pending' ? '&status=' . urlencode($currentStatus) : '' ?>" style="margin:0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="gym_id" value="<?= (int) $app['gym_id'] ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" class="btn-sm btn-secondary" style="color:var(--danger)" data-confirm="Reject this gym?">Reject</button>
                                        </form>
                                    <?php elseif ($app['status'] === 'rejected'): ?>
                                        <form method="post" action="index.php?page=gym_applications<?= $currentStatus !== 'pending' ? '&status=' . urlencode($currentStatus) : '' ?>" style="margin:0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="gym_id" value="<?= (int) $app['gym_id'] ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="btn-sm btn-primary" data-confirm="Re-approve this previously rejected gym?">Re-Approve</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted" style="font-size: 12px;">—</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                        <tr id="appNoResultsRow" style="display: none;">
                            <td colspan="<?= $tableColspan ?>" style="text-align: center; padding: 48px 16px; color: var(--muted);">
                                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin: 0 auto 10px; display: block; opacity: 0.5;">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                </svg>
                                <div style="font-size: 15px; font-weight: 600; color: var(--ink); margin-bottom: 4px;">No applications found</div>
                                <div style="font-size: 13px; color: var(--muted); margin-bottom: 14px;">No applications match your current search and filter criteria.</div>
                                <button type="button" class="btn-sm btn-secondary" onclick="resetAllAppFilters()">Reset Filters</button>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Card View (<= 768px) -->
        <div class="app-mobile-cards">
            <?php if (empty($applications)): ?>
                <div style="text-align: center; padding: 40px 16px; background: var(--panel-soft); border-radius: 12px; border: 1px dashed var(--line); color: var(--muted);">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin: 0 auto 10px; display: block; opacity: 0.5;">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <div style="font-size: 15px; font-weight: 600; color: var(--ink); margin-bottom: 4px;">No <?= $currentStatus === 'all' ? '' : h($currentStatus) . ' ' ?>applications found</div>
                    <div style="font-size: 13px; color: var(--muted);">There are no <?= $currentStatus === 'all' ? '' : h($currentStatus) . ' ' ?>gym registration requests at this time.</div>
                </div>
            <?php else: ?>
                <?php foreach ($applications as $app): 
                    $docs = $app['docs'];
                    $docCount = $app['doc_count'];
                    $appStatus = strtolower($app['status'] ?? 'pending');
                    $isApproved = $appStatus === 'approved';
                    $isRejected = $appStatus === 'rejected';
                    $isPending = !$isApproved && !$isRejected;
                    $statusColor = $isApproved ? 'var(--accent, #a3e635)' : ($isRejected ? 'var(--danger, #ef4444)' : '#f59e0b');
                    $statusBg = $isApproved ? 'rgba(163, 230, 53, 0.12)' : ($isRejected ? 'rgba(239, 68, 68, 0.12)' : 'rgba(245, 158, 11, 0.12)');
                ?>
                    <div class="app-card-item" 
                         data-search="<?= h($app['search_text']) ?>"
                         data-gym-id="<?= (int) $app['gym_id'] ?>"
                         data-name="<?= h(strtolower($app['name'])) ?>"
                         data-created="<?= strtotime($app['created_at'] ?? 'now') ?>"
                         data-status="<?= h($appStatus) ?>"
                         data-payout="<?= h(strtolower($app['payout_chan'] ?? 'none')) ?>"
                         data-doc-complete="<?= !empty($app['doc_complete']) ? '1' : '0' ?>"
                         data-has-permit="<?= !empty($app['has_permit']) ? '1' : '0' ?>"
                         data-has-id="<?= !empty($app['has_id']) ? '1' : '0' ?>"
                         data-has-fire="<?= !empty($app['has_fire']) ? '1' : '0' ?>"
                         data-has-brgy="<?= !empty($app['has_brgy']) ? '1' : '0' ?>">
                        <div class="app-card-header">
                            <div class="app-card-identity">
                                <div class="app-card-avatar">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 21h18M3 7v14M21 7v14M6 11h4M6 15h4M14 11h4M14 15h4M9 21v-4h6v4M3 7l9-4 9 4"/>
                                    </svg>
                                </div>
                                <div class="app-card-names">
                                    <div class="app-card-title"><?= h($app['name']) ?></div>
                                    <?php if (!empty($app['created_at'])): ?>
                                        <div class="app-card-date"><?= date('M j, Y', strtotime($app['created_at'])) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="badge" style="margin:0; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; padding:3px 8px; border-radius:999px; background:<?= $statusBg ?>; color:<?= $statusColor ?>; border:1px solid <?= $statusColor ?>40;">
                                <?= h(ucfirst($appStatus)) ?>
                            </span>
                        </div>

                        <div class="app-card-body">
                            <div class="app-card-info-row">
                                <span class="app-card-label">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    Owner
                                </span>
                                <span class="app-card-value" style="font-weight:600;"><?= h($app['first_name'] . ' ' . $app['last_name']) ?></span>
                            </div>

                            <div class="app-card-info-row">
                                <span class="app-card-label">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                    Email
                                </span>
                                <span class="app-card-value"><a href="mailto:<?= h($app['email']) ?>" style="color:var(--ink); text-decoration:none;"><?= h($app['email']) ?></a></span>
                            </div>

                            <div class="app-card-info-row">
                                <span class="app-card-label">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                    Contact
                                </span>
                                <span class="app-card-value"><?= h($app['contact_info'] ?? 'N/A') ?></span>
                            </div>

                            <div class="app-card-info-row">
                                <span class="app-card-label">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                    Address
                                </span>
                                <span class="app-card-value" style="font-size:12px;"><?= h($app['address']) ?></span>
                            </div>

                            <div class="app-card-info-row">
                                <span class="app-card-label">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                                    Payout Dest.
                                </span>
                                <span class="app-card-value">
                                    <?php if (!empty($app['payout_account_number'])): ?>
                                        <div style="display: flex; align-items: center; justify-content: flex-end; gap: 6px; flex-wrap: wrap;">
                                            <span class="badge" style="padding: 2px 6px; font-size: 10px; font-weight: 700; background: rgba(132, 204, 22, 0.18); color: var(--accent, #c7ff22); border-radius: 4px;">
                                                <?= strtoupper(h($app['payout_channel'] ?? 'GCash')) ?>
                                            </span>
                                            <span style="font-family: monospace; font-size: 12px; font-weight: 600; color: var(--ink, #ffffff);"><?= h($app['payout_account_number']) ?></span>
                                            <button type="button" class="payout-copy-btn" style="padding: 2px 7px; font-size: 9.5px;" onclick="copyPayoutText('<?= h($app['payout_account_number']) ?>', this, event)" title="Copy account number">Copy</button>
                                        </div>
                                        <?php if (!empty($app['payout_account_name'])): ?>
                                            <div style="font-size: 11px; color: var(--muted); margin-top: 3px; text-align: right;"><?= h($app['payout_account_name']) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="muted" style="font-size: 12px;">Not provided</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>

                        <?php if ($docCount > 0): ?>
                            <div class="app-card-docs">
                                <div class="app-card-docs-header">
                                    <span>Attached Documents (<?= $docCount ?>)</span>
                                </div>
                                <div class="app-card-docs-list">
                                    <?php foreach ($docs as $doc): ?>
                                        <?php if ($doc['type'] === 'link'): ?>
                                            <a href="<?= h($doc['url']) ?>" target="_blank" rel="noopener noreferrer" class="app-card-doc-pill">
                                                <?= $doc['icon'] ?>
                                                <span><?= h($doc['name']) ?> ↗</span>
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="app-card-doc-pill" onclick="viewDocument('<?= h($doc['url']) ?>', '<?= h(addslashes($doc['name'])) ?>')">
                                                <?= $doc['icon'] ?>
                                                <span><?= h($doc['name']) ?></span>
                                            </button>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php elseif ($app['status'] === 'approved'): ?>
                            <div class="app-card-docs" style="padding-top: 6px;">
                                <span class="badge" style="background: rgba(59, 130, 246, 0.12); color: #60a5fa; font-size: 11px; padding: 2px 7px; border-radius: 4px;">Admin Provisioned (No docs uploaded)</span>
                            </div>
                        <?php endif; ?>

                        <?php if (!$isApproved): ?>
                        <div class="app-card-actions">
                            <?php if ($isRejected): ?>
                                <form method="post" action="index.php?page=gym_applications<?= $currentStatus !== 'pending' ? '&status=' . urlencode($currentStatus) : '' ?>" style="flex: 1;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="gym_id" value="<?= (int) $app['gym_id'] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button type="submit" class="btn-sm btn-primary" style="width: 100%;" data-confirm="Re-approve this gym application?">Re-Approve</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="index.php?page=gym_applications<?= $currentStatus !== 'pending' ? '&status=' . urlencode($currentStatus) : '' ?>" style="flex: 1;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="gym_id" value="<?= (int) $app['gym_id'] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button type="submit" class="btn-sm btn-primary" style="width: 100%;" data-confirm="Approve this gym?">Approve</button>
                                </form>
                                <form method="post" action="index.php?page=gym_applications<?= $currentStatus !== 'pending' ? '&status=' . urlencode($currentStatus) : '' ?>" style="flex: 1;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="gym_id" value="<?= (int) $app['gym_id'] ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <button type="submit" class="btn-sm btn-secondary" style="width: 100%; color:var(--danger);" data-confirm="Reject this gym?">Reject</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div id="appMobileNoResults" style="display: none; text-align: center; padding: 36px 16px; background: var(--panel-soft); border-radius: 12px; border: 1px dashed var(--line); color: var(--muted);">
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin: 0 auto 10px; display: block; opacity: 0.5;">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <div style="font-size: 15px; font-weight: 600; color: var(--ink); margin-bottom: 4px;">No applications found</div>
                <div style="font-size: 13px; color: var(--muted); margin-bottom: 14px;">No applications match your current search and filter criteria.</div>
                <button type="button" class="btn-sm btn-secondary" onclick="resetAllAppFilters()">Reset Filters</button>
            </div>
        </div>

        <div id="appPaginationBar" class="app-pagination-bar" style="<?= empty($applications) ? 'display: none;' : '' ?>">
            <div id="appPaginationInfo" class="app-pagination-info">
                Showing <strong>1</strong> to <strong><?= min(10, count($applications)) ?></strong> of <strong><?= count($applications) ?></strong> applications
            </div>
            <div id="appPaginationControls" class="app-pagination-controls"></div>
        </div>
    </section>

    <script>
    function toggleDocDropdown(gymId, event) {
        if (event) {
            event.stopPropagation();
        }
        const dropdown = document.getElementById('dropdown-' + gymId);
        if (!dropdown) return;
        const isOpen = dropdown.classList.contains('is-open');
        closeAllDocDropdowns();
        if (!isOpen) {
            dropdown.classList.remove('drop-up');
            dropdown.classList.add('is-open');

            // Auto-flip upward if there isn't enough space below in the container or viewport
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
            }
        }
    }

    function togglePayoutDropdown(gymId, event) {
        if (event) {
            event.stopPropagation();
        }
        const dropdown = document.getElementById('payout-dropdown-' + gymId);
        if (!dropdown) return;
        const isOpen = dropdown.classList.contains('is-open');
        closeAllDocDropdowns();
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

                if (spaceBelow < 250 && spaceAbove > spaceBelow) {
                    dropdown.classList.add('drop-up');
                }
            }
        }
    }

    function copyPayoutText(text, btn, event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        if (!text) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
        } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        }
        const origHtml = btn.innerHTML;
        btn.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> <span>Copied!</span>';
        btn.classList.add('copied');
        setTimeout(() => {
            btn.innerHTML = origHtml;
            btn.classList.remove('copied');
        }, 1500);
    }

    function closeAllDocDropdowns() {
        document.querySelectorAll('.doc-pill-dropdown.is-open').forEach(el => {
            el.classList.remove('is-open');
            el.classList.remove('drop-up');
        });
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.doc-pill-dropdown')) {
            closeAllDocDropdowns();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAllDocDropdowns();
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

    // Live Search, Multi-Filter, Sorting & Responsive Pagination for Gym Applications
    let appCurrentPage = 1;
    let appPageSize = 10;
    let appAllRows = [];
    let appAllCards = [];
    let appVisibleRows = [];
    let appVisibleCards = [];

    function initAppPagination() {
        appAllRows = Array.from(document.querySelectorAll('.app-row'));
        appAllCards = Array.from(document.querySelectorAll('.app-card-item'));
        
        const searchInput = document.getElementById('appSearchInput');
        const searchClear = document.getElementById('appSearchClear');
        const payoutFilter = document.getElementById('appPayoutFilter');
        const docFilter = document.getElementById('appDocFilter');
        const sortSelect = document.getElementById('appSortSelect');
        const perPageSelect = document.getElementById('appPerPageSelect');

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                if (searchClear) {
                    searchClear.style.display = this.value.trim() !== '' ? 'block' : 'none';
                }
                appCurrentPage = 1;
                applyAppFiltersAndSort();
            });

            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    clearAppSearch();
                }
            });
        }

        if (payoutFilter) {
            payoutFilter.addEventListener('change', function() {
                appCurrentPage = 1;
                applyAppFiltersAndSort();
            });
        }

        if (docFilter) {
            docFilter.addEventListener('change', function() {
                appCurrentPage = 1;
                applyAppFiltersAndSort();
            });
        }

        if (sortSelect) {
            sortSelect.addEventListener('change', function() {
                appCurrentPage = 1;
                applyAppFiltersAndSort();
            });
        }

        if (perPageSelect) {
            perPageSelect.addEventListener('change', function() {
                const val = this.value;
                appPageSize = val === 'all' ? 999999 : (parseInt(val, 10) || 10);
                appCurrentPage = 1;
                renderAppPage();
            });
            const initialVal = perPageSelect.value;
            appPageSize = initialVal === 'all' ? 999999 : (parseInt(initialVal, 10) || 10);
        }

        applyAppFiltersAndSort();
    }

    function applyAppFiltersAndSort() {
        const searchInput = document.getElementById('appSearchInput');
        const payoutFilter = document.getElementById('appPayoutFilter');
        const docFilter = document.getElementById('appDocFilter');
        const sortSelect = document.getElementById('appSortSelect');

        const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const searchTerms = query.split(/\s+/).filter(Boolean);
        const payoutVal = payoutFilter ? payoutFilter.value.toLowerCase() : 'all';
        const docVal = docFilter ? docFilter.value.toLowerCase() : 'all';
        const sortVal = sortSelect ? sortSelect.value : 'newest';

        const resetBtn = document.getElementById('appResetFiltersBtn');
        const isFiltered = query !== '' || payoutVal !== 'all' || docVal !== 'all' || sortVal !== 'newest';
        if (resetBtn) {
            resetBtn.style.display = isFiltered ? 'inline-flex' : 'none';
        }

        function matchesFilter(el) {
            if (!el) return false;
            // Search text
            if (searchTerms.length > 0) {
                const text = el.getAttribute('data-search') || '';
                if (!searchTerms.every(term => text.includes(term))) {
                    return false;
                }
            }
            // Payout filter
            if (payoutVal !== 'all') {
                const payout = (el.getAttribute('data-payout') || 'none').toLowerCase();
                if (payoutVal === 'bank_transfer') {
                    if (payout !== 'bank_transfer' && payout !== 'bank') return false;
                } else if (payout !== payoutVal) {
                    return false;
                }
            }
            // Document filter
            if (docVal === 'complete') {
                if (el.getAttribute('data-doc-complete') !== '1') return false;
            } else if (docVal === 'has_permit') {
                if (el.getAttribute('data-has-permit') !== '1') return false;
            } else if (docVal === 'has_id') {
                if (el.getAttribute('data-has-id') !== '1') return false;
            } else if (docVal === 'has_fire') {
                if (el.getAttribute('data-has-fire') !== '1') return false;
            } else if (docVal === 'has_brgy') {
                if (el.getAttribute('data-has-brgy') !== '1') return false;
            } else if (docVal === 'missing') {
                if (el.getAttribute('data-doc-complete') === '1') return false;
            }
            return true;
        }

        // Filter
        appVisibleRows = appAllRows.filter(matchesFilter);
        appVisibleCards = appAllCards.filter(matchesFilter);

        // Sort comparator
        function sortCompare(a, b) {
            if (sortVal === 'newest') {
                return (parseInt(b.getAttribute('data-created') || '0', 10)) - (parseInt(a.getAttribute('data-created') || '0', 10));
            } else if (sortVal === 'oldest') {
                return (parseInt(a.getAttribute('data-created') || '0', 10)) - (parseInt(b.getAttribute('data-created') || '0', 10));
            } else if (sortVal === 'name_asc') {
                return (a.getAttribute('data-name') || '').localeCompare(b.getAttribute('data-name') || '');
            } else if (sortVal === 'name_desc') {
                return (b.getAttribute('data-name') || '').localeCompare(a.getAttribute('data-name') || '');
            }
            return 0;
        }

        appVisibleRows.sort(sortCompare);
        appVisibleCards.sort(sortCompare);

        // Reorder elements in DOM container to respect sort order
        if (appVisibleRows.length > 0) {
            const tableBody = appVisibleRows[0].parentElement;
            if (tableBody) {
                const noResults = document.getElementById('appNoResultsRow');
                appVisibleRows.forEach(row => tableBody.insertBefore(row, noResults));
            }
        }
        if (appVisibleCards.length > 0) {
            const cardsContainer = appVisibleCards[0].parentElement;
            if (cardsContainer) {
                const noResultsMobile = document.getElementById('appMobileNoResults');
                appVisibleCards.forEach(card => cardsContainer.insertBefore(card, noResultsMobile));
            }
        }

        renderAppPage();
    }

    function resetAllAppFilters() {
        const searchInput = document.getElementById('appSearchInput');
        const searchClear = document.getElementById('appSearchClear');
        const payoutFilter = document.getElementById('appPayoutFilter');
        const docFilter = document.getElementById('appDocFilter');
        const sortSelect = document.getElementById('appSortSelect');

        if (searchInput) searchInput.value = '';
        if (searchClear) searchClear.style.display = 'none';
        if (payoutFilter) payoutFilter.value = 'all';
        if (docFilter) docFilter.value = 'all';
        if (sortSelect) sortSelect.value = 'newest';

        appCurrentPage = 1;
        applyAppFiltersAndSort();
    }

    function clearAppSearch() {
        const searchInput = document.getElementById('appSearchInput');
        const searchClear = document.getElementById('appSearchClear');
        if (searchInput) {
            searchInput.value = '';
            if (searchClear) searchClear.style.display = 'none';
            appCurrentPage = 1;
            applyAppFiltersAndSort();
            searchInput.focus();
        }
    }

    function renderAppPage() {
        closeAllDocDropdowns();
        const totalItems = Math.max(appVisibleRows.length, appVisibleCards.length);
        const totalPages = Math.max(1, Math.ceil(totalItems / appPageSize));

        if (appCurrentPage > totalPages) appCurrentPage = totalPages;
        if (appCurrentPage < 1) appCurrentPage = 1;

        const startIndex = (appCurrentPage - 1) * appPageSize;
        const endIndex = Math.min(startIndex + appPageSize, totalItems);

        // Toggle Desktop Table Rows
        const visibleRowSet = new Set(appVisibleRows.slice(startIndex, endIndex));
        appAllRows.forEach(row => {
            row.style.display = visibleRowSet.has(row) ? '' : 'none';
        });

        // Toggle Mobile Cards
        const visibleCardSet = new Set(appVisibleCards.slice(startIndex, endIndex));
        appAllCards.forEach(card => {
            card.style.display = visibleCardSet.has(card) ? '' : 'none';
        });

        // Empty results state
        const maxTotal = Math.max(appAllRows.length, appAllCards.length);
        const noResultsRow = document.getElementById('appNoResultsRow');
        const noResultsMobile = document.getElementById('appMobileNoResults');

        if (totalItems === 0 && maxTotal > 0) {
            if (noResultsRow) noResultsRow.style.display = '';
            if (noResultsMobile) noResultsMobile.style.display = 'block';
        } else {
            if (noResultsRow) noResultsRow.style.display = 'none';
            if (noResultsMobile) noResultsMobile.style.display = 'none';
        }

        updateAppPaginationUI(totalItems, totalPages, startIndex, endIndex);
    }

    function updateAppPaginationUI(totalItems, totalPages, startIndex, endIndex) {
        const bar = document.getElementById('appPaginationBar');
        const info = document.getElementById('appPaginationInfo');
        const controls = document.getElementById('appPaginationControls');
        if (!bar || !info || !controls) return;

        const maxTotal = Math.max(appAllRows.length, appAllCards.length);
        if (maxTotal === 0) {
            bar.style.display = 'none';
            return;
        }

        bar.style.display = 'flex';

        if (totalItems === 0) {
            info.innerHTML = 'Showing <strong>0</strong> applications';
            controls.innerHTML = '';
            return;
        }

        const startDisplay = startIndex + 1;
        info.innerHTML = `Showing <strong>${startDisplay}</strong> to <strong>${endIndex}</strong> of <strong>${totalItems}</strong> application${totalItems === 1 ? '' : 's'}`;

        if (totalPages <= 1) {
            controls.innerHTML = '';
            return;
        }

        let html = '';

        // Prev Button
        const prevDisabled = appCurrentPage === 1 ? ' disabled' : '';
        html += `<button type="button" class="app-page-btn${prevDisabled}" onclick="goToAppPage(${appCurrentPage - 1})" aria-label="Previous page">← Prev</button>`;

        // Numbered buttons
        const startPage = Math.max(1, appCurrentPage - 2);
        const endPage = Math.min(totalPages, appCurrentPage + 2);

        if (startPage > 1) {
            html += `<button type="button" class="app-page-btn" onclick="goToAppPage(1)">1</button>`;
            if (startPage > 2) {
                html += `<span class="app-page-ellipsis">…</span>`;
            }
        }

        for (let p = startPage; p <= endPage; p++) {
            if (p === appCurrentPage) {
                html += `<button type="button" class="app-page-btn active">${p}</button>`;
            } else {
                html += `<button type="button" class="app-page-btn" onclick="goToAppPage(${p})">${p}</button>`;
            }
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<span class="app-page-ellipsis">…</span>`;
            }
            html += `<button type="button" class="app-page-btn" onclick="goToAppPage(${totalPages})">${totalPages}</button>`;
        }

        // Next Button
        const nextDisabled = appCurrentPage === totalPages ? ' disabled' : '';
        html += `<button type="button" class="app-page-btn${nextDisabled}" onclick="goToAppPage(${appCurrentPage + 1})" aria-label="Next page">Next →</button>`;

        controls.innerHTML = html;
    }

    function goToAppPage(page) {
        appCurrentPage = page;
        renderAppPage();
        const targetView = window.innerWidth <= 768 
            ? document.querySelector('.app-mobile-cards') 
            : document.querySelector('.table-container');
        if (targetView) {
            const rect = targetView.getBoundingClientRect();
            if (rect.top < 0) {
                targetView.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }
    
    document.addEventListener('DOMContentLoaded', initAppPagination);
    if (document.readyState === 'interactive' || document.readyState === 'complete') {
        initAppPagination();
    }
    </script>
<?php
    render_footer();
}
