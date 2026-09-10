<?php
declare(strict_types=1);

function gym_equipment_page(): void
{
    $user = require_roles(['gym_owner', 'admin', 'platform_admin']);
    $pdo = db();
    $gymId = get_user_gym_id($user);

    // Platform admin support
    if ($user['role'] === 'platform_admin') {
        $selectedGymId = (int)($_GET['gym_id'] ?? 0);
        if ($selectedGymId > 0) {
            $gymId = $selectedGymId;
        } elseif (!$gymId) {
            $gymId = (int)scalar('SELECT gym_id FROM gyms ORDER BY gym_id ASC LIMIT 1');
        }
    }

    render_header('Equipment Management', $user);

    if (!$gymId) {
        echo '<div class="panel" style="max-width: 600px; margin: 40px auto; text-align: center; padding: 40px 24px;">';
        echo '<h2>No Gym Profile Configured</h2>';
        echo '<p style="color: var(--muted);">Please ensure your gym profile is registered and approved.</p>';
        echo '</div>';
        render_footer();
        return;
    }

    $gym = $pdo->query("SELECT gym_id, name FROM gyms WHERE gym_id = $gymId")->fetch(PDO::FETCH_ASSOC);
    $gymName = $gym ? $gym['name'] : 'Gym';

    // Overview Stats
    $totalEquip = (int)scalar("SELECT COUNT(*) FROM gym_equipment WHERE gym_id = $gymId");
    $availableCount = (int)scalar("SELECT COUNT(*) FROM gym_equipment WHERE gym_id = $gymId AND status = 'available'");
    $inUseCount = (int)scalar("SELECT COUNT(*) FROM gym_equipment WHERE gym_id = $gymId AND status = 'in_use'");
    $maintenanceCount = (int)scalar("SELECT COUNT(*) FROM gym_equipment WHERE gym_id = $gymId AND status = 'maintenance'");
    $outOfServiceCount = (int)scalar("SELECT COUNT(*) FROM gym_equipment WHERE gym_id = $gymId AND status = 'out_of_service'");
    $activeQueuesCount = (int)scalar("SELECT COUNT(*) FROM equipment_queues WHERE gym_id = $gymId AND queue_status IN ('waiting', 'notified')");
    $activeEquipTab = ($_GET['tab'] ?? '') === 'analytics' ? 'analytics' : 'units';

    // Descriptive Analytics (strictly descriptive queries)
    $totalSessions = (int)scalar("SELECT COUNT(*) FROM equipment_sessions WHERE gym_id = $gymId AND session_status = 'completed'");
    $totalDurationSecs = (int)scalar("SELECT COALESCE(SUM(duration_seconds), 0) FROM equipment_sessions WHERE gym_id = $gymId AND session_status = 'completed'");
    $avgDurationMins = $totalSessions > 0 ? (int)round(($totalDurationSecs / $totalSessions) / 60) : 0;
    $totalUsageHours = round($totalDurationSecs / 3600, 1);

    // Most used equipment
    $topUsed = $pdo->query("
        SELECT e.name, e.unit_number, e.category, COUNT(s.session_id) as session_count,
               COALESCE(SUM(s.duration_seconds), 0) as total_seconds
        FROM gym_equipment e
        LEFT JOIN equipment_sessions s ON s.equipment_id = e.equipment_id AND s.session_status = 'completed'
        WHERE e.gym_id = $gymId
        GROUP BY e.equipment_id, e.name, e.unit_number, e.category
        HAVING session_count > 0
        ORDER BY session_count DESC, total_seconds DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Queue demand stats
    $totalQueueRequests = (int)scalar("SELECT COUNT(*) FROM equipment_queues WHERE gym_id = $gymId");
    $claimedQueues = (int)scalar("SELECT COUNT(*) FROM equipment_queues WHERE gym_id = $gymId AND queue_status = 'claimed'");
    $cancelledQueues = (int)scalar("SELECT COUNT(*) FROM equipment_queues WHERE gym_id = $gymId AND queue_status = 'cancelled'");

    // All gym list for platform admin switcher
    $allGyms = ($user['role'] === 'platform_admin') ? $pdo->query("SELECT gym_id, name FROM gyms ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) : [];
    ?>

    <style>
        /* Equipment Admin Styling & Theme Adaptability */
        .equip-admin-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 14px;
            box-shadow: var(--shadow);
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }

        .stat-card {
            padding: 18px 20px;
            border-radius: 12px;
            background: var(--panel);
            border: 1px solid var(--line);
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--line);
        }
        .stat-card.stat-available::before { background: #10b981; }
        .stat-card.stat-in_use::before { background: #f59e0b; }
        .stat-card.stat-maint::before { background: #94a3b8; }
        .stat-card.stat-out::before { background: #ef4444; }
        .stat-card.stat-queue::before { background: var(--lime); }

        .stat-num {
            font-size: 28px;
            font-weight: 800;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, monospace;
            line-height: 1.1;
            margin: 4px 0;
            color: var(--ink);
        }
        .stat-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Analytics Sub-cards */
        .analytics-subcard {
            background: var(--panel-soft);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 18px 20px;
        }
        [data-theme="light"] .analytics-subcard {
            background: #f8fafc;
            border-color: #e2e8f0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }

        /* Toolbar controls */
        .equip-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 20px;
        }
        .equip-toolbar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .equip-search-box {
            position: relative;
            min-width: 240px;
            flex: 1 1 240px;
            max-width: 380px;
        }
        .equip-search-box input {
            width: 100%;
            padding: 9px 12px 9px 36px;
            border-radius: 8px;
            background: var(--bg);
            border: 1px solid var(--line);
            color: var(--ink);
            font-size: 13px;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.2s;
        }
        .equip-search-box input:focus {
            border-color: var(--lime);
        }
        .equip-select-filter {
            width: auto !important;
            min-width: 150px;
            padding: 9px 14px;
            border-radius: 8px;
            background: var(--bg);
            border: 1px solid var(--line);
            color: var(--ink);
            font-size: 13px;
            cursor: pointer;
            box-sizing: border-box;
        }
        .equip-select-filter:focus {
            border-color: var(--lime);
        }

        /* Table Styling */
        .equip-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .equip-table th {
            padding: 12px 14px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border-bottom: 2px solid var(--line);
            background: transparent;
        }
        .equip-table td {
            padding: 13px 14px;
            border-bottom: 1px solid var(--line);
            color: var(--ink);
            vertical-align: middle;
        }
        .equip-table tbody tr {
            transition: background 0.15s ease;
        }
        .equip-table tbody tr:hover {
            background: color-mix(in srgb, var(--ink) 3%, transparent);
        }

        /* Unit Identifier Tag */
        .unit-pill {
            display: inline-block;
            background: color-mix(in srgb, var(--lime) 14%, transparent);
            color: var(--ink);
            border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
            padding: 2px 7px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 12px;
            margin-left: 6px;
        }
        [data-theme="light"] .unit-pill {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: #0f172a;
        }

        /* Accessible Status Pills */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 7px;
            border-radius: 5px;
            font-size: 10.5px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            white-space: nowrap;
            line-height: 1.2;
            flex-shrink: 0;
            width: fit-content;
        }
        .status-pill.status-available {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
            border: 1px solid rgba(16, 185, 129, 0.22);
        }
        [data-theme="dark"] .status-pill.status-available {
            background: rgba(16, 185, 129, 0.14);
            color: #34d399;
            border-color: rgba(16, 185, 129, 0.25);
        }

        .status-pill.status-in_use {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
            border: 1px solid rgba(245, 158, 11, 0.22);
        }
        [data-theme="dark"] .status-pill.status-in_use {
            background: rgba(245, 158, 11, 0.14);
            color: #fbbf24;
            border-color: rgba(245, 158, 11, 0.25);
        }

        .status-pill.status-maintenance {
            background: rgba(100, 116, 139, 0.1);
            color: #475569;
            border: 1px solid rgba(100, 116, 139, 0.2);
        }
        [data-theme="dark"] .status-pill.status-maintenance {
            background: rgba(148, 163, 184, 0.12);
            color: #94a3b8;
            border-color: rgba(148, 163, 184, 0.22);
        }

        .status-pill.status-out_of_service {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.22);
        }
        [data-theme="dark"] .status-pill.status-out_of_service {
            background: rgba(239, 68, 68, 0.14);
            color: #f87171;
            border-color: rgba(239, 68, 68, 0.25);
        }

        /* Action Buttons */
        .btn-act {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 11px;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s ease;
            text-decoration: none;
            line-height: 1;
        }
        .btn-act-default {
            background: var(--panel-soft);
            color: var(--ink);
            border-color: var(--line);
        }
        .btn-act-default:hover {
            border-color: var(--ink);
            transform: translateY(-1px);
        }
        .btn-act-service {
            background: rgba(245, 158, 11, 0.12);
            color: #d97706;
            border-color: rgba(245, 158, 11, 0.28);
        }
        [data-theme="dark"] .btn-act-service {
            background: rgba(245, 158, 11, 0.18);
            color: #fbbf24;
            border-color: rgba(245, 158, 11, 0.35);
        }
        .btn-act-service:hover {
            background: rgba(245, 158, 11, 0.25);
            transform: translateY(-1px);
        }

        .btn-act-restore {
            background: rgba(16, 185, 129, 0.12);
            color: #059669;
            border-color: rgba(16, 185, 129, 0.28);
        }
        [data-theme="dark"] .btn-act-restore {
            background: rgba(16, 185, 129, 0.18);
            color: #34d399;
            border-color: rgba(16, 185, 129, 0.35);
        }
        .btn-act-restore:hover {
            background: rgba(16, 185, 129, 0.25);
            transform: translateY(-1px);
        }

        .btn-act-delete {
            background: transparent;
            color: #ef4444;
            border-color: rgba(239, 68, 68, 0.25);
            padding: 6px 8px;
        }
        .btn-act-delete:hover {
            background: rgba(239, 68, 68, 0.12);
            border-color: #ef4444;
            transform: translateY(-1px);
        }

        /* Modal Overlays */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-box {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 14px;
            width: 100%;
            padding: 24px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
            max-height: 90vh;
            overflow-y: auto;
        }

        /* ==================================================== */
        /* TOP VIEW SWITCHER SYSTEM STYLING                     */
        /* ==================================================== */
        .equip-main-nav-wrap {
            margin-bottom: 22px;
        }
        .equip-main-nav {
            display: inline-flex;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 4px;
            gap: 6px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            max-width: 100%;
            flex-wrap: wrap;
        }
        .equip-nav-pill {
            background: transparent;
            border: 1px solid transparent;
            color: var(--muted, #8792ad);
            padding: 9px 18px;
            border-radius: 9px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 9px;
            transition: all 0.22s ease;
        }
        .equip-nav-pill:hover {
            color: var(--ink, #ffffff);
            background: color-mix(in srgb, var(--ink, #ffffff) 5%, transparent);
        }
        .equip-nav-pill.active {
            background: var(--panel-soft, rgba(255,255,255,0.06));
            color: var(--ink, #ffffff);
            border-color: color-mix(in srgb, var(--lime, #c7ff22) 40%, var(--line, rgba(255,255,255,0.1)));
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.1);
        }
        [data-theme="light"] .equip-nav-pill.active {
            background: #f1f5f9;
            color: #0f172a;
            border-color: var(--line, #cbd5e1);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }
        .equip-nav-pill-title-row {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .equip-nav-title-full { display: inline; }
        .equip-nav-title-short { display: none; }

        .equip-nav-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
            letter-spacing: 0.2px;
        }
        .equip-nav-pill.active .units-badge {
            background: color-mix(in srgb, var(--lime, #c7ff22) 18%, transparent);
            color: var(--lime, #c7ff22);
            border: 1px solid color-mix(in srgb, var(--lime, #c7ff22) 35%, transparent);
        }
        .equip-nav-pill:not(.active) .units-badge {
            background: color-mix(in srgb, var(--ink, #ffffff) 6%, transparent);
            color: var(--muted, #8792ad);
        }
        .equip-nav-pill.active .analytics-badge {
            background: color-mix(in srgb, #38bdf8 18%, transparent);
            color: #38bdf8;
            border: 1px solid color-mix(in srgb, #38bdf8 35%, transparent);
        }
        .equip-nav-pill:not(.active) .analytics-badge {
            background: color-mix(in srgb, var(--ink, #ffffff) 6%, transparent);
            color: var(--muted, #8792ad);
        }

        .equip-view-panel {
            animation: equipViewFade 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes equipViewFade {
            0% { opacity: 0; transform: translateY(6px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        /* Stat Grid Layout */
        .equip-stat-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        /* Responsive Mobile Cards for Equipment Units */
        .equip-table-wrap {
            display: block;
        }
        .equip-mobile-cards {
            display: none;
        }

        .equip-mobile-card {
            background: var(--panel-soft);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px 15px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: transform 0.2s ease, border-color 0.2s ease;
        }
        [data-theme="light"] .equip-mobile-card {
            background: #ffffff;
            border-color: #e2e8f0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .equip-mcard-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }
        .equip-mcard-title {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1.2;
        }
        .equip-mcard-sub {
            font-size: 12px;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 3px;
        }

        .equip-mcard-details-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            background: color-mix(in srgb, var(--ink) 3%, transparent);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 8px 10px;
        }
        [data-theme="light"] .equip-mcard-details-grid {
            background: #f8fafc;
            border-color: #f1f5f9;
        }
        .equip-mcard-detail-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }
        .equip-mcard-detail-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
        }
        .equip-mcard-detail-val {
            font-size: 12px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .equip-mcard-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            padding-top: 4px;
            border-top: 1px solid var(--line);
        }
        .equip-mcard-actions .btn-act {
            flex: 1 1 0;
            justify-content: center;
            padding: 8px 6px;
            font-size: 12px;
            gap: 4px;
        }
        .equip-mcard-actions .btn-act-delete {
            flex: 0 0 auto;
            padding: 8px 10px;
        }

        /* Responsive Breakpoints & Device Adaptability */
        @media (max-width: 1180px) {
            .equip-stat-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .equip-table-wrap {
                display: none !important;
            }
            .equip-mobile-cards {
                display: flex !important;
                flex-direction: column;
                gap: 12px;
            }
            .equip-toolbar {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }
            .equip-toolbar-actions {
                width: 100%;
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }
            .equip-search-box {
                width: 100%;
                max-width: 100%;
                flex: 1 1 100%;
            }
            .equip-select-filter {
                flex: 1 1 calc(50% - 4px);
                min-width: 0 !important;
                width: auto !important;
            }
            .equip-stat-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            .analytics-grid {
                grid-template-columns: 1fr !important;
            }
            .equip-analytics-meta-pills {
                width: 100%;
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }
            .equip-analytics-meta-pills span {
                flex: 1 1 calc(33.333% - 6px);
                text-align: center;
                min-width: 100px;
                font-size: 12px;
                padding: 6px 8px;
            }
        }

        @media (max-width: 640px) {
            .equip-main-nav {
                display: flex;
                width: 100%;
            }
            .equip-nav-pill {
                flex: 1 1 0;
                justify-content: center;
                padding: 8px 10px;
                font-size: 12.5px;
                gap: 6px;
            }
            .equip-nav-title-full { display: none; }
            .equip-nav-title-short { display: inline; }
            .equip-nav-badge { padding: 1px 6px; font-size: 10px; }

            .equip-header-row {
                flex-direction: column;
                align-items: stretch !important;
            }
            .equip-header-actions {
                width: 100%;
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            .equip-header-actions .btn {
                width: 100%;
                justify-content: center;
            }
            .stat-card {
                padding: 13px 14px;
            }
            .stat-num {
                font-size: 22px;
            }
            .stat-label {
                font-size: 10px;
            }
            .equip-admin-card {
                padding: 16px 14px !important;
            }
            .modal-box {
                padding: 18px 16px;
            }
        }

        @media (max-width: 480px) {
            .modal-two-col {
                grid-template-columns: 1fr !important;
            }
        }

        /* Equipment Inventory Pagination */
        .equip-pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 18px;
            padding: 14px 18px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }
        .equip-page-info {
            font-size: 13px;
            color: var(--muted);
            font-weight: 500;
        }
        .equip-page-info strong {
            color: var(--ink);
            font-weight: 700;
        }
        .equip-page-controls {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            user-select: none;
        }
        .btn-equip-page {
            min-width: 36px;
            height: 36px;
            padding: 0 10px;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--panel-soft);
            color: var(--ink);
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            text-decoration: none;
            line-height: 1;
        }
        .btn-equip-page:hover:not(.disabled):not(.active) {
            border-color: #eab308;
            color: #eab308;
            background: color-mix(in srgb, #eab308 12%, var(--panel-soft));
            transform: translateY(-1px);
        }
        .btn-equip-page.active {
            background: #eab308 !important;
            color: #0b0e14 !important;
            border-color: #eab308 !important;
            font-weight: 800 !important;
            box-shadow: 0 2px 10px rgba(234, 179, 8, 0.35);
            cursor: default;
            transform: none;
        }
        .btn-equip-page.disabled {
            opacity: 0.35;
            cursor: not-allowed;
            pointer-events: none;
        }
        .equip-page-ellipsis {
            min-width: 24px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--muted);
            font-weight: 700;
            font-size: 13px;
            letter-spacing: 0.08em;
        }
        .equip-page-size-wrap {
            display: inline-flex;
            align-items: center;
        }
        @media (max-width: 768px) {
            .equip-pagination-bar {
                justify-content: center;
                flex-direction: column;
                gap: 12px;
                text-align: center;
            }
            .equip-page-controls {
                order: 1;
            }
            .equip-page-info {
                order: 2;
            }
            .equip-page-size-wrap {
                order: 3;
            }
        }
    </style>

    <div class="equipment-admin-container" style="max-width: 1300px; margin: 0 auto; padding-bottom: 60px;">
        <!-- Top Title Bar -->
        <div class="equip-header-row" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
            <div>
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px; flex-wrap: wrap;">
                    <h1 style="margin: 0; font-size: clamp(22px, 5vw, 26px); color: var(--ink);">Equipment Management</h1>
                    <span style="background: color-mix(in srgb, var(--lime) 15%, transparent); color: var(--lime); border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent); font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 20px;">
                        <?= h($gymName) ?>
                    </span>
                </div>
                <p style="margin: 0; color: var(--muted); font-size: 14px;">Manage machines, monitor live occupancy & queues, log maintenance, and review usage metrics.</p>
            </div>

            <div class="equip-header-actions" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <?php if (!empty($allGyms)): ?>
                    <form method="GET" style="display: inline-flex; align-items: center; gap: 6px;">
                        <input type="hidden" name="page" value="gym_equipment">
                        <select name="gym_id" onchange="this.form.submit()" class="equip-select-filter">
                            <?php foreach ($allGyms as $g): ?>
                                <option value="<?= $g['gym_id'] ?>" <?= (int)$g['gym_id'] === $gymId ? 'selected' : '' ?>>
                                    <?= h($g['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>

                <button type="button" onclick="openAddModal()" class="btn" style="background: var(--lime); color: #fff; font-weight: 700; padding: 10px 22px; border-radius: 8px; border: none; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.15);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add Equipment
                </button>
            </div>
        </div>

        <!-- ==================================================== -->
        <!-- TOP VIEW SWITCHER: UNITS VS OVERVIEW & ANALYTICS     -->
        <!-- ==================================================== -->
        <div class="equip-main-nav-wrap">
            <div class="equip-main-nav">
                <button type="button" class="equip-nav-pill <?= $activeEquipTab === 'units' ? 'active' : '' ?>" id="btn-equip-units" onclick="switchEquipView('units')">
                    <div class="equip-nav-pill-title-row">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="6" width="4" height="12" rx="1"/><rect x="18" y="6" width="4" height="12" rx="1"/><path d="M6 12h12"/>
                        </svg>
                        <span class="equip-nav-title-full">Equipment Units</span>
                        <span class="equip-nav-title-short">Units</span>
                    </div>
                    <span class="equip-nav-badge units-badge"><?= $totalEquip ?> Units</span>
                </button>
                <button type="button" class="equip-nav-pill <?= $activeEquipTab === 'analytics' ? 'active' : '' ?>" id="btn-equip-analytics" onclick="switchEquipView('analytics')">
                    <div class="equip-nav-pill-title-row">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>
                        </svg>
                        <span class="equip-nav-title-full">Overview & Analytics</span>
                        <span class="equip-nav-title-short">Analytics</span>
                    </div>
                    <span class="equip-nav-badge analytics-badge">Metrics</span>
                </button>
            </div>
        </div>

        <!-- ==================================================== -->
        <!-- VIEW 1: EQUIPMENT UNITS (INVENTORY & ACTIONS)        -->
        <!-- ==================================================== -->
        <div id="view-equip-units" class="equip-view-panel" style="<?= $activeEquipTab === 'units' ? 'display:block;' : 'display:none;' ?>">
            <!-- EQUIPMENT INVENTORY TABLE -->
            <div class="equip-admin-card" style="padding: 22px 24px;">
                <!-- Toolbar -->
                <div class="equip-toolbar">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <h3 style="margin: 0; font-size: 18px; color: var(--ink);">Equipment Units</h3>
                        <span id="equip-count-pill" style="background: var(--panel-soft); border: 1px solid var(--line); color: var(--muted); font-size: 12px; font-weight: 600; padding: 2px 8px; border-radius: 12px;">
                            <?= $totalEquip ?> units
                        </span>
                    </div>

                    <div class="equip-toolbar-actions">
                        <!-- Live Search -->
                        <div class="equip-search-box">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 11px; top: 50%; transform: translateY(-50%); pointer-events: none;">
                                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                            </svg>
                            <input type="text" id="admin-search" placeholder="Search machines...">
                        </div>

                        <!-- Filter by Category -->
                        <select id="admin-category-filter" class="equip-select-filter">
                            <option value="all">All Categories</option>
                            <option value="Cardio">Cardio</option>
                            <option value="Strength">Strength</option>
                            <option value="Free Weights">Free Weights</option>
                            <option value="Machines">Machines</option>
                            <option value="Functional Training">Functional Training</option>
                            <option value="Other">Other</option>
                        </select>

                        <!-- Filter by Status -->
                        <select id="admin-status-filter" class="equip-select-filter">
                            <option value="all">All Statuses</option>
                            <option value="available">Available</option>
                            <option value="in_use">In Use</option>
                            <option value="maintenance">Under Maintenance</option>
                            <option value="out_of_service">Out of Service</option>
                        </select>

                        <!-- Sound Toggle -->
                        <button type="button" class="btn-sound-toggle equip-select-filter" style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; text-decoration: none;" aria-label="Toggle notification sounds" title="Sound: Enabled (Click to mute)">
                            <span class="sound-toggle-icon"></span>
                            <span class="sound-toggle-text">Sound On</span>
                        </button>
                    </div>
                </div>

                <!-- Table (Desktop / Tablet) -->
                <div class="equip-table-wrap" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                    <table class="equip-table" id="admin-equipment-table" style="min-width: 820px;">
                        <thead>
                            <tr>
                                <th>Equipment</th>
                                <th>Category</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Current User</th>
                                <th>Queue</th>
                                <th>Next Service</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="admin-tbody">
                            <tr>
                                <td colspan="8" style="padding: 40px; text-align: center; color: var(--muted);">Loading equipment data...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Responsive Mobile Cards (Phone Screens <= 768px) -->
                <div id="admin-mobile-cards" class="equip-mobile-cards">
                    <div style="padding: 30px; text-align: center; color: var(--muted);">Loading equipment data...</div>
                </div>

                <!-- Inventory Pagination Bar -->
                <div id="admin-equip-pagination" class="equip-pagination-bar" style="display: none;">
                    <div class="equip-page-info" id="admin-equip-page-info">
                        Showing 0 of 0 units
                    </div>
                    <div class="equip-page-controls" id="admin-equip-page-buttons">
                        <!-- Dynamic page buttons -->
                    </div>
                    <div class="equip-page-size-wrap">
                        <label for="admin-equip-pagesize" style="font-size: 12px; color: var(--muted); margin-right: 6px;">Per page:</label>
                        <select id="admin-equip-pagesize" class="equip-select-filter" style="padding: 4px 10px; font-size: 12px; height: 34px; border-radius: 8px;">
                            <option value="6">6</option>
                            <option value="8" selected>8</option>
                            <option value="12">12</option>
                            <option value="24">24</option>
                            <option value="50">50</option>
                            <option value="100000">All</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- ==================================================== -->
        <!-- VIEW 2: OVERVIEW & ANALYTICS (STATS & METRICS)       -->
        <!-- ==================================================== -->
        <div id="view-equip-analytics" class="equip-view-panel" style="<?= $activeEquipTab === 'analytics' ? 'display:block;' : 'display:none;' ?>">
            <!-- OVERVIEW STATS CARDS -->
            <div class="equip-stat-grid">
                <div class="stat-card">
                    <div class="stat-label">
                        <span>Total Equipment</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="4" height="12" rx="1"/><rect x="18" y="6" width="4" height="12" rx="1"/><path d="M6 12h12"/></svg>
                    </div>
                    <div class="stat-num"><?= $totalEquip ?></div>
                    <span style="font-size: 12px; color: var(--muted);">Tracked units</span>
                </div>

                <div class="stat-card stat-available">
                    <div class="stat-label" style="color: #059669;">
                        <span>Available</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div class="stat-num" style="color: #059669;"><?= $availableCount ?></div>
                    <span style="font-size: 12px; color: var(--muted);">Ready to use</span>
                </div>

                <div class="stat-card stat-in_use">
                    <div class="stat-label" style="color: #d97706;">
                        <span>In Use</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div class="stat-num" style="color: #d97706;"><?= $inUseCount ?></div>
                    <span style="font-size: 12px; color: var(--muted);">Active sessions</span>
                </div>

                <div class="stat-card stat-maint">
                    <div class="stat-label">
                        <span>Maintenance</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                    </div>
                    <div class="stat-num"><?= $maintenanceCount ?></div>
                    <span style="font-size: 12px; color: var(--muted);">Temporarily offline</span>
                </div>

                <div class="stat-card stat-out">
                    <div class="stat-label" style="color: #dc2626;">
                        <span>Out of Service</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                    </div>
                    <div class="stat-num" style="color: #dc2626;"><?= $outOfServiceCount ?></div>
                    <span style="font-size: 12px; color: var(--muted);">Needs repair</span>
                </div>

                <div class="stat-card stat-queue">
                    <div class="stat-label" style="color: var(--lime);">
                        <span>Active Queues</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div class="stat-num" style="color: var(--lime);"><?= $activeQueuesCount ?></div>
                    <span style="font-size: 12px; color: var(--muted);">Members in line</span>
                </div>
            </div>

            <!-- DESCRIPTIVE ANALYTICS SECTION -->
            <div class="equip-admin-card" style="padding: 22px 24px; margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h3 style="margin: 0 0 4px; font-size: 17px; color: var(--ink);">Equipment Usage & Analytics</h3>
                        <p style="margin: 0; font-size: 13px; color: var(--muted);">Descriptive metrics derived from recorded equipment sessions and queues.</p>
                    </div>
                    <div class="equip-analytics-meta-pills" style="display: flex; gap: 12px; font-size: 13px; flex-wrap: wrap;">
                        <span style="background: var(--panel-soft); border: 1px solid var(--line); padding: 5px 12px; border-radius: 8px; color: var(--muted);">
                            Avg. Session: <strong style="color: var(--ink);"><?= $avgDurationMins ?> mins</strong>
                        </span>
                        <span style="background: var(--panel-soft); border: 1px solid var(--line); padding: 5px 12px; border-radius: 8px; color: var(--lime); font-weight: bold;">
                            Total Time: <strong style="color: var(--lime);"><?= $totalUsageHours ?> hrs</strong>
                        </span>
                        <span style="background: var(--panel-soft); border: 1px solid var(--line); padding: 5px 12px; border-radius: 8px; color: var(--muted);">
                            Completed: <strong style="color: var(--ink);"><?= $totalSessions ?> sessions</strong>
                        </span>
                    </div>
                </div>

                <div class="analytics-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 18px;">
                    <!-- Top Used Equipment -->
                    <div class="analytics-subcard">
                        <h4 style="margin: 0 0 14px; font-size: 13px; color: var(--ink); text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 6px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                            Top Used Equipment
                        </h4>
                        <?php if (empty($topUsed)): ?>
                            <div style="text-align: center; padding: 24px 0; color: var(--muted); font-size: 13px;">
                                No completed equipment sessions recorded yet.
                            </div>
                        <?php else: ?>
                            <ul style="list-style: none; padding: 0; margin: 0;">
                                <?php foreach ($topUsed as $idx => $t): ?>
                                    <li style="display: flex; justify-content: space-between; align-items: center; padding: 9px 0; border-bottom: 1px solid var(--line); font-size: 13px; gap: 10px; flex-wrap: wrap;">
                                        <div style="display: flex; align-items: center; gap: 8px; min-width: 0;">
                                            <span style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: var(--line); font-size: 11px; font-weight: 700; color: var(--muted); flex-shrink: 0;">
                                                <?= $idx + 1 ?>
                                            </span>
                                            <strong style="color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= h($t['name']) ?></strong>
                                            <span class="unit-pill" style="font-size: 11px; flex-shrink: 0;"><?= h($t['unit_number']) ?></span>
                                            <span style="font-size: 11px; color: var(--muted); white-space: nowrap;">(<?= h($t['category']) ?>)</span>
                                        </div>
                                        <div style="text-align: right; white-space: nowrap;">
                                            <strong style="color: var(--ink);"><?= $t['session_count'] ?></strong> <span style="color: var(--muted); font-size: 12px;">sessions</span>
                                            <span style="color: var(--muted); font-size: 11px; margin-left: 6px;">(<?= round((float)$t['total_seconds'] / 3600, 1) ?> hrs)</span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <!-- Queue Demand Summary -->
                    <div class="analytics-subcard">
                        <h4 style="margin: 0 0 14px; font-size: 13px; color: var(--ink); text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 6px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                            Queue Demand & Turnaround
                        </h4>
                        <div style="display: flex; flex-direction: column; gap: 12px; font-size: 13px;">
                            <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--line); padding-bottom: 8px;">
                                <span style="color: var(--muted);">Total Queue Requests Logged:</span>
                                <strong style="color: var(--ink);"><?= $totalQueueRequests ?></strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--line); padding-bottom: 8px;">
                                <span style="color: var(--muted);">Successfully Claimed via Queue:</span>
                                <strong style="color: #059669;"><?= $claimedQueues ?></strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--line); padding-bottom: 8px;">
                                <span style="color: var(--muted);">Cancelled / Passed Queues:</span>
                                <strong style="color: #dc2626;"><?= $cancelledQueues ?></strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding-top: 2px;">
                                <span style="color: var(--muted);">Claim Conversion Rate:</span>
                                <strong style="color: var(--ink);">
                                    <?= $totalQueueRequests > 0 ? round(($claimedQueues / $totalQueueRequests) * 100, 1) . '%' : 'N/A' ?>
                                </strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: ADD / EDIT EQUIPMENT -->
    <div id="equipment-modal" class="modal-overlay" style="display: none;">
        <div class="modal-box" style="max-width: 540px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 id="modal-title" style="margin: 0; font-size: 18px; color: var(--ink);">Add Equipment</h3>
                <button type="button" onclick="closeModal()" style="background: none; border: none; color: var(--muted); font-size: 22px; cursor: pointer; line-height: 1;">&times;</button>
            </div>

            <form id="equipment-form">
                <input type="hidden" name="equipment_id" id="form-equip-id" value="0">

                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; font-weight: 600;">Equipment Name *</label>
                    <input type="text" name="name" id="form-name" placeholder="e.g. Treadmill, Bench Press, Leg Press" required
                           style="width: 100%; padding: 10px 12px; border-radius: 8px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 14px; box-sizing: border-box;">
                </div>

                <div class="modal-two-col" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; font-weight: 600;">Category</label>
                        <select name="category" id="form-category" style="width: 100%; padding: 10px 12px; border-radius: 8px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 13px; box-sizing: border-box;">
                            <option value="Machines">Machines</option>
                            <option value="Cardio">Cardio</option>
                            <option value="Strength">Strength</option>
                            <option value="Free Weights">Free Weights</option>
                            <option value="Functional Training">Functional Training</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div>
                        <label style="display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; font-weight: 600;">Condition</label>
                        <select name="equipment_condition" id="form-condition" style="width: 100%; padding: 10px 12px; border-radius: 8px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 13px; box-sizing: border-box;">
                            <option value="Excellent">Excellent</option>
                            <option value="Good" selected>Good</option>
                            <option value="Fair">Fair</option>
                            <option value="Poor">Poor</option>
                        </select>
                    </div>
                </div>

                <!-- Unit Identifier vs Batch Quantity -->
                <div id="unit-group" class="modal-two-col" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div id="unit-num-container">
                        <label style="display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; font-weight: 600;">Unit Identifier</label>
                        <input type="text" name="unit_number" id="form-unit-number" placeholder="e.g. #1, Unit A" value="#1"
                               style="width: 100%; padding: 10px 12px; border-radius: 8px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 14px; box-sizing: border-box;">
                    </div>

                    <div id="batch-qty-container">
                        <label style="display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; font-weight: 600;">Quantity to Create</label>
                        <input type="number" name="quantity" id="form-quantity" min="1" max="50" value="1"
                               style="width: 100%; padding: 10px 12px; border-radius: 8px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 14px; box-sizing: border-box;">
                        <span style="font-size: 11px; color: var(--muted); display: block; margin-top: 3px;">Auto-names #1, #2...</span>
                    </div>
                </div>

                <div class="modal-two-col" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; font-weight: 600;">Location / Area</label>
                        <input type="text" name="location_area" id="form-location" placeholder="e.g. Cardio Deck, Floor 2" value="Main Gym Floor"
                               style="width: 100%; padding: 10px 12px; border-radius: 8px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 14px; box-sizing: border-box;">
                    </div>

                    <div>
                        <label style="display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; font-weight: 600;">Next Maintenance</label>
                        <input type="date" name="next_maintenance_date" id="form-next-maint"
                               style="width: 100%; padding: 10px 12px; border-radius: 8px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 13px; box-sizing: border-box;">
                    </div>
                </div>

                <!-- Image Upload (ImageKit 5MB) and Direct URL -->
                <div class="modal-two-col" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label style="display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; font-weight: 600;">
                            Upload Image <span style="font-size: 10.5px; opacity: 0.8; font-weight: normal;">(Max 5MB • ImageKit)</span>
                        </label>
                        <input type="file" name="image_file" id="form-image-file" accept="image/png, image/jpeg, image/webp, image/gif"
                               style="width: 100%; padding: 7px 9px; border-radius: 8px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 12px; box-sizing: border-box; cursor: pointer;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; font-weight: 600;">
                            Or Image URL <span style="font-size: 10.5px; opacity: 0.8; font-weight: normal;">(Direct link)</span>
                        </label>
                        <input type="url" name="image_url" id="form-image-url" placeholder="https://ik.imagekit.io/... or https://..."
                               style="width: 100%; padding: 8px 12px; border-radius: 8px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 13px; box-sizing: border-box;">
                    </div>
                </div>

                <!-- Live Thumbnail Preview -->
                <div id="image-preview-container" style="display: none; align-items: center; gap: 12px; margin-bottom: 14px; padding: 8px 12px; background: var(--panel-soft); border-radius: 8px; border: 1px solid var(--line);">
                    <img id="form-image-preview" src="" alt="Equipment preview" style="width: 44px; height: 44px; object-fit: cover; border-radius: 6px; border: 1px solid var(--line); background: var(--bg);">
                    <div style="flex: 1; min-width: 0;">
                        <div id="image-preview-name" style="font-size: 12px; font-weight: 600; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Equipment Image</div>
                        <div id="image-preview-meta" style="font-size: 11px; color: var(--muted);">Ready for upload</div>
                    </div>
                    <button type="button" onclick="clearImageSelection()" style="background: none; border: none; color: var(--danger); cursor: pointer; font-size: 11px; font-weight: 600; padding: 4px 6px;">Clear</button>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; font-weight: 600;">Description (optional)</label>
                    <textarea name="description" id="form-description" rows="2" placeholder="Notes, brand model, specifications..."
                              style="width: 100%; padding: 10px 12px; border-radius: 8px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 13px; box-sizing: border-box;"></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="closeModal()" class="btn" style="background: transparent; color: var(--muted); border: 1px solid var(--line); padding: 9px 18px; border-radius: 8px; cursor: pointer;">
                        Cancel
                    </button>
                    <button type="submit" class="btn" style="background: var(--lime); color: #fff; font-weight: 700; padding: 9px 22px; border-radius: 8px; border: none; cursor: pointer;">
                        Save Equipment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: MAINTENANCE -->
    <div id="maint-modal" class="modal-overlay" style="display: none;">
        <div class="modal-box" style="max-width: 480px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 id="maint-modal-title" style="margin: 0; font-size: 18px; color: var(--ink);">Place Under Maintenance</h3>
                <button type="button" onclick="closeMaintModal()" style="background: none; border: none; color: var(--muted); font-size: 22px; cursor: pointer; line-height: 1;">&times;</button>
            </div>

            <div style="background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.3); border-radius: 8px; padding: 12px; margin-bottom: 16px; font-size: 12px; color: #d97706;">
                <strong>Notice:</strong> Placing equipment under maintenance or out of service will automatically notify any queued members and safely cancel waiting queues.
            </div>

            <form id="maint-form">
                <input type="hidden" name="equipment_id" id="maint-equip-id" value="0">

                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; font-weight: 600;">Status</label>
                    <select name="status" id="maint-status" style="width: 100%; padding: 10px 12px; border-radius: 8px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 13px; box-sizing: border-box;">
                        <option value="maintenance">Under Maintenance</option>
                        <option value="out_of_service">Out of Service</option>
                    </select>
                </div>

                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; font-weight: 600;">Reason *</label>
                    <input type="text" name="reason" id="maint-reason" placeholder="e.g. Running belt replacement, cable inspection" required
                           style="width: 100%; padding: 10px 12px; border-radius: 8px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 14px; box-sizing: border-box;">
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; font-weight: 600;">Expected Return Date</label>
                    <input type="date" name="expected_return_date" id="maint-return-date"
                           style="width: 100%; padding: 10px 12px; border-radius: 8px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 13px; box-sizing: border-box;">
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="closeMaintModal()" class="btn" style="background: transparent; color: var(--muted); border: 1px solid var(--line); padding: 9px 18px; border-radius: 8px; cursor: pointer;">
                        Cancel
                    </button>
                    <button type="submit" class="btn" style="background: #f59e0b; color: #fff; font-weight: 700; padding: 9px 22px; border-radius: 8px; border: none; cursor: pointer;">
                        Save Status
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: VIEW DETAILS & QUEUE MANAGEMENT -->
    <div id="queue-modal" class="modal-overlay" style="display: none;">
        <div class="modal-box" style="max-width: 580px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                <h3 id="queue-modal-title" style="margin: 0; font-size: 18px; color: var(--ink);">Equipment Details & Queue</h3>
                <button type="button" onclick="closeQueueModal()" style="background: none; border: none; color: var(--muted); font-size: 22px; cursor: pointer; line-height: 1;">&times;</button>
            </div>

            <!-- Active Session Details -->
            <div id="queue-active-session-box" style="background: var(--panel-soft); border: 1px solid var(--line); border-radius: 10px; padding: 16px; margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 700;">Current Active Session</span>
                    <span id="qmodal-session-badge" style="font-size: 11px; padding: 2px 8px; border-radius: 12px; font-weight: 700;"></span>
                </div>
                <div id="qmodal-session-content" style="font-size: 13px; color: var(--ink);">
                    Loading...
                </div>
            </div>

            <!-- Waiting Queue List -->
            <div>
                <h4 style="margin: 0 0 10px; font-size: 13px; color: var(--ink); text-transform: uppercase; letter-spacing: 0.05em;">
                    Waiting Members in Line
                </h4>
                <div id="qmodal-queue-list">
                    Loading queue list...
                </div>
            </div>

            <div style="margin-top: 24px; text-align: right;">
                <button type="button" onclick="closeQueueModal()" class="btn" style="background: var(--panel-soft); color: var(--ink); border: 1px solid var(--line); padding: 9px 20px; border-radius: 8px; cursor: pointer;">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const GYM_ID = <?= $gymId ?>;
        const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
        let equipmentData = [];
        let searchQuery = '';
        let categoryFilter = 'all';
        let statusFilter = 'all';
        let currentEquipPage = 1;
        let equipPageSize = 8;

        async function adminEquipPost(action, formData) {
            formData.append('csrf_token', CSRF_TOKEN);
            if (!formData.has('gym_id')) {
                formData.append('gym_id', GYM_ID);
            }
            const res = await fetch(`index.php?page=equipment_api&action=${action}&gym_id=${GYM_ID}`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: formData
            });
            return await res.json();
        }

        const icons = {
            available: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
            in_use: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
            maintenance: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
            out_of_service: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>'
        };

        async function fetchAdminEquipment() {
            try {
                const res = await fetch(`index.php?page=equipment_api&action=poll&gym_id=${GYM_ID}`);
                const data = await res.json();
                if (data.success) {
                    equipmentData = data.equipment || [];
                    renderAdminTable();
                }
            } catch (err) {
                console.error(err);
            }
        }

        function renderAdminTable() {
            const tbody = document.getElementById('admin-tbody');
            const mobileCards = document.getElementById('admin-mobile-cards');
            const countPill = document.getElementById('equip-count-pill');
            const unitsBadge = document.querySelector('.units-badge');
            const paginationBar = document.getElementById('admin-equip-pagination');

            const filtered = equipmentData.filter(item => {
                if (categoryFilter !== 'all' && item.category !== categoryFilter) return false;
                if (statusFilter !== 'all') {
                    if (statusFilter === 'available' && item.status !== 'available') return false;
                    if (statusFilter === 'in_use' && item.status !== 'in_use') return false;
                    if (statusFilter === 'maintenance' && item.status !== 'maintenance') return false;
                    if (statusFilter === 'out_of_service' && item.status !== 'out_of_service') return false;
                }
                if (searchQuery) {
                    const haystack = (item.name + ' ' + item.unit_number + ' ' + item.category + ' ' + (item.location_area || '')).toLowerCase();
                    if (!haystack.includes(searchQuery)) return false;
                }
                return true;
            });

            if (countPill) {
                countPill.textContent = `${filtered.length} unit${filtered.length === 1 ? '' : 's'}`;
            }
            if (unitsBadge) {
                unitsBadge.textContent = `${equipmentData.length} Units`;
            }

            if (filtered.length === 0) {
                if (paginationBar) paginationBar.style.display = 'none';
                tbody.innerHTML = `<tr><td colspan="8" style="padding: 40px; text-align: center; color: var(--muted);">No matching equipment units found.</td></tr>`;
                if (mobileCards) {
                    mobileCards.innerHTML = `<div style="padding: 36px 20px; text-align: center; color: var(--muted); background: var(--panel-soft); border-radius: 10px; border: 1px dashed var(--line); font-size: 13px;">No matching equipment units found.</div>`;
                }
                return;
            }

            // Pagination calculation
            const totalFiltered = filtered.length;
            const totalPages = Math.max(1, Math.ceil(totalFiltered / equipPageSize));
            if (currentEquipPage > totalPages) {
                currentEquipPage = totalPages;
            }
            if (currentEquipPage < 1) {
                currentEquipPage = 1;
            }

            const startIndex = (currentEquipPage - 1) * equipPageSize;
            const endIndex = Math.min(startIndex + equipPageSize, totalFiltered);
            const pagedItems = filtered.slice(startIndex, endIndex);

            if (paginationBar) {
                paginationBar.style.display = 'flex';
                renderAdminPagination(totalFiltered, totalPages, startIndex, endIndex);
            }

            let tableHtml = '';
            let cardsHtml = '';

            pagedItems.forEach(eq => {
                const status = eq.status || 'available';
                const statusLabel = {
                    'available': 'Available',
                    'in_use': 'In Use',
                    'maintenance': 'Maintenance',
                    'out_of_service': 'Out of Service'
                }[status] || 'Available';

                const statusClass = 'status-' + status;
                const iconSvg = icons[status] || icons.available;

                const occupant = eq.current_user_display ? escapeHtml(eq.current_user_display) : '—';
                const queueCount = parseInt(eq.waiting_queue_count || 0);
                const nextMaint = eq.next_maintenance_date ? escapeHtml(eq.next_maintenance_date) : '—';

                // Status action button: either Set Maintenance or Restore Available
                let maintActionBtn = '';
                if (status === 'maintenance' || status === 'out_of_service') {
                    maintActionBtn = `<button type="button" onclick="restoreAvailable(${eq.equipment_id})" class="btn-act btn-act-restore" title="Restore unit to available">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Restore
                    </button>`;
                } else {
                    maintActionBtn = `<button type="button" onclick="openMaintModal(${eq.equipment_id}, '${escapeHtml(eq.name)} ${escapeHtml(eq.unit_number)}')" class="btn-act btn-act-service" title="Schedule maintenance">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                        Service
                    </button>`;
                }

                const imageThumb = eq.image_url ? 
                    `<img src="${escapeHtml(eq.image_url)}" alt="${escapeHtml(eq.name)}" style="width: 36px; height: 36px; object-fit: cover; border-radius: 6px; border: 1px solid var(--line); flex-shrink: 0;" loading="lazy">` : 
                    `<div style="width: 36px; height: 36px; border-radius: 6px; background: var(--panel-soft); border: 1px solid var(--line); display: flex; align-items: center; justify-content: center; color: var(--muted); flex-shrink: 0;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="4" height="12" rx="1"/><rect x="18" y="6" width="4" height="12" rx="1"/><path d="M6 12h12"/></svg></div>`;

                // Desktop Table Row
                tableHtml += `
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                ${imageThumb}
                                <div>
                                    <div style="display: flex; align-items: center; gap: 5px;">
                                        <strong style="color: var(--ink); font-size: 14px;">${escapeHtml(eq.name)}</strong>
                                        <span class="unit-pill">${escapeHtml(eq.unit_number)}</span>
                                    </div>
                                    <span style="font-size: 11px; color: var(--muted);">${escapeHtml(eq.equipment_condition || 'Good')} condition</span>
                                </div>
                            </div>
                        </td>
                        <td style="color: var(--muted);">${escapeHtml(eq.category)}</td>
                        <td style="color: var(--muted);">${escapeHtml(eq.location_area || 'Main Floor')}</td>
                        <td>
                            <span class="status-pill ${statusClass}">
                                ${iconSvg} ${statusLabel}
                            </span>
                        </td>
                        <td style="color: var(--ink); font-weight: ${occupant !== '—' ? '600' : 'normal'};">
                            ${occupant}
                        </td>
                        <td>
                            <span style="color: ${queueCount > 0 ? '#d97706' : 'var(--muted)'}; font-weight: ${queueCount > 0 ? '700' : 'normal'};">
                                ${queueCount > 0 ? `${queueCount} waiting` : '0 waiting'}
                            </span>
                        </td>
                        <td style="color: var(--muted); font-size: 12px;">
                            ${nextMaint}
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 6px; align-items: center; justify-content: flex-end;">
                                <button type="button" onclick="openQueueModal(${eq.equipment_id}, '${escapeHtml(eq.name)} ${escapeHtml(eq.unit_number)}')" class="btn-act btn-act-default" title="View session & queue">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                                    Queue
                                </button>
                                <button type="button" onclick='openEditModal(${JSON.stringify(eq)})' class="btn-act btn-act-default" title="Edit details">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    Edit
                                </button>
                                ${maintActionBtn}
                                <button type="button" onclick="confirmDeleteEquipment(${eq.equipment_id}, '${escapeHtml(eq.name)} ${escapeHtml(eq.unit_number)}')" class="btn-act btn-act-delete" title="Delete unit">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;

                // Responsive Mobile Card (No horizontal scrolling!)
                cardsHtml += `
                    <div class="equip-mobile-card">
                        <div class="equip-mcard-header">
                            <div class="equip-mcard-title-group" style="display: flex; align-items: center; gap: 10px;">
                                ${imageThumb}
                                <div>
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <h4 class="equip-mcard-title">${escapeHtml(eq.name)}</h4>
                                        <span class="unit-pill">${escapeHtml(eq.unit_number)}</span>
                                    </div>
                                    <div class="equip-mcard-sub">
                                        <span>${escapeHtml(eq.category)}</span>
                                        <span>•</span>
                                        <span>${escapeHtml(eq.location_area || 'Main Floor')}</span>
                                    </div>
                                </div>
                            </div>
                            <span class="status-pill ${statusClass}">
                                ${iconSvg} ${statusLabel}
                            </span>
                        </div>

                        <div class="equip-mcard-details-grid">
                            <div class="equip-mcard-detail-item">
                                <span class="equip-mcard-detail-label">Current User</span>
                                <span class="equip-mcard-detail-val" style="color: ${occupant !== '—' ? 'var(--ink)' : 'var(--muted)'}; font-weight: ${occupant !== '—' ? '600' : 'normal'};">
                                    ${occupant}
                                </span>
                            </div>
                            <div class="equip-mcard-detail-item">
                                <span class="equip-mcard-detail-label">Queue</span>
                                <span class="equip-mcard-detail-val" style="color: ${queueCount > 0 ? '#d97706' : 'var(--muted)'}; font-weight: ${queueCount > 0 ? '700' : 'normal'};">
                                    ${queueCount > 0 ? `${queueCount} in line` : '0 waiting'}
                                </span>
                            </div>
                            <div class="equip-mcard-detail-item">
                                <span class="equip-mcard-detail-label">Next Service</span>
                                <span class="equip-mcard-detail-val" style="color: var(--muted);">
                                    ${nextMaint}
                                </span>
                            </div>
                        </div>

                        <div class="equip-mcard-actions">
                            <button type="button" onclick="openQueueModal(${eq.equipment_id}, '${escapeHtml(eq.name)} ${escapeHtml(eq.unit_number)}')" class="btn-act btn-act-default">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                                Queue
                            </button>
                            <button type="button" onclick='openEditModal(${JSON.stringify(eq)})' class="btn-act btn-act-default">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Edit
                            </button>
                            ${maintActionBtn}
                            <button type="button" onclick="confirmDeleteEquipment(${eq.equipment_id}, '${escapeHtml(eq.name)} ${escapeHtml(eq.unit_number)}')" class="btn-act btn-act-delete" title="Delete unit">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            </button>
                        </div>
                    </div>
                `;
            });

            tbody.innerHTML = tableHtml;
            if (mobileCards) {
                mobileCards.innerHTML = cardsHtml;
            }
        }

        function renderAdminPagination(totalItems, totalPages, startIndex, endIndex) {
            const infoEl = document.getElementById('admin-equip-page-info');
            const buttonsEl = document.getElementById('admin-equip-page-buttons');
            if (!infoEl || !buttonsEl) return;

            infoEl.innerHTML = `Showing <strong>${startIndex + 1}–${endIndex}</strong> of <strong>${totalItems}</strong> units`;

            if (totalPages <= 1) {
                buttonsEl.innerHTML = `
                    <button type="button" class="btn-equip-page disabled" aria-label="Previous page">&lt;</button>
                    <button type="button" class="btn-equip-page active">1</button>
                    <button type="button" class="btn-equip-page disabled" aria-label="Next page">&gt;</button>
                `;
                return;
            }

            let pages = [];
            if (totalPages <= 5) {
                for (let i = 1; i <= totalPages; i++) pages.push(i);
            } else {
                if (currentEquipPage <= 3) {
                    pages = [1, 2, 3, '...', totalPages];
                } else if (currentEquipPage >= totalPages - 2) {
                    pages = [1, '...', totalPages - 2, totalPages - 1, totalPages];
                } else {
                    pages = [1, '...', currentEquipPage - 1, currentEquipPage, currentEquipPage + 1, '...', totalPages];
                }
            }

            let btnsHtml = '';
            // Previous button (<)
            const prevDisabled = currentEquipPage <= 1 ? 'disabled' : '';
            btnsHtml += `<button type="button" class="btn-equip-page ${prevDisabled}" onclick="goToEquipPage(${currentEquipPage - 1})" aria-label="Previous page">&lt;</button>`;

            // Page numbers
            pages.forEach(p => {
                if (p === '...') {
                    btnsHtml += `<span class="equip-page-ellipsis">...</span>`;
                } else {
                    const isActive = p === currentEquipPage ? 'active' : '';
                    btnsHtml += `<button type="button" class="btn-equip-page ${isActive}" onclick="goToEquipPage(${p})">${p}</button>`;
                }
            });

            // Next button (>)
            const nextDisabled = currentEquipPage >= totalPages ? 'disabled' : '';
            btnsHtml += `<button type="button" class="btn-equip-page ${nextDisabled}" onclick="goToEquipPage(${currentEquipPage + 1})" aria-label="Next page">&gt;</button>`;

            buttonsEl.innerHTML = btnsHtml;
        }

        window.goToEquipPage = function(page) {
            currentEquipPage = page;
            renderAdminTable();
            const tableWrap = document.querySelector('.equip-table-wrap');
            if (tableWrap) {
                const rect = tableWrap.getBoundingClientRect();
                if (rect.top < 0) {
                    tableWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        };

        window.changeEquipPageSize = function(size) {
            equipPageSize = parseInt(size, 10) || 8;
            currentEquipPage = 1;
            renderAdminTable();
        };

        // ADD MODAL
        window.openAddModal = function() {
            document.getElementById('modal-title').textContent = 'Add Equipment';
            document.getElementById('form-equip-id').value = '0';
            document.getElementById('form-name').value = '';
            document.getElementById('form-category').value = 'Machines';
            document.getElementById('form-condition').value = 'Good';
            document.getElementById('form-unit-number').value = '#1';
            document.getElementById('form-quantity').value = '1';
            document.getElementById('batch-qty-container').style.display = 'block';
            document.getElementById('form-location').value = 'Main Gym Floor';
            document.getElementById('form-image-file').value = '';
            document.getElementById('form-image-url').value = '';
            document.getElementById('image-preview-container').style.display = 'none';
            document.getElementById('form-next-maint').value = '';
            document.getElementById('form-description').value = '';
            document.getElementById('equipment-modal').style.display = 'flex';
        };

        // EDIT MODAL
        window.openEditModal = function(eq) {
            document.getElementById('modal-title').textContent = 'Edit Equipment Details';
            document.getElementById('form-equip-id').value = eq.equipment_id;
            document.getElementById('form-name').value = eq.name;
            document.getElementById('form-category').value = eq.category;
            document.getElementById('form-condition').value = eq.equipment_condition || 'Good';
            document.getElementById('form-unit-number').value = eq.unit_number;
            document.getElementById('batch-qty-container').style.display = 'none';
            document.getElementById('form-location').value = eq.location_area || 'Main Gym Floor';
            document.getElementById('form-image-file').value = '';
            document.getElementById('form-image-url').value = eq.image_url || '';
            
            if (eq.image_url) {
                document.getElementById('form-image-preview').src = eq.image_url;
                document.getElementById('image-preview-name').textContent = eq.name;
                document.getElementById('image-preview-meta').textContent = 'Current Equipment Image';
                document.getElementById('image-preview-container').style.display = 'flex';
            } else {
                document.getElementById('image-preview-container').style.display = 'none';
            }

            document.getElementById('form-next-maint').value = eq.next_maintenance_date || '';
            document.getElementById('form-description').value = eq.description || '';
            document.getElementById('equipment-modal').style.display = 'flex';
        };

        window.closeModal = function() {
            document.getElementById('equipment-modal').style.display = 'none';
        };

        window.clearImageSelection = function() {
            document.getElementById('form-image-file').value = '';
            document.getElementById('form-image-url').value = '';
            document.getElementById('image-preview-container').style.display = 'none';
        };

        // Image file selection & 5MB validation
        document.getElementById('form-image-file').addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                if (file.size > 5 * 1024 * 1024) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'File Too Large',
                        text: `The selected image is ${(file.size / (1024 * 1024)).toFixed(2)}MB. Maximum allowed size is 5MB.`,
                        confirmButtonColor: '#ff4d5d'
                    });
                    this.value = '';
                    document.getElementById('image-preview-container').style.display = 'none';
                    return;
                }
                const reader = new FileReader();
                reader.onload = function(evt) {
                    document.getElementById('form-image-preview').src = evt.target.result;
                    document.getElementById('image-preview-name').textContent = file.name;
                    document.getElementById('image-preview-meta').textContent = `${(file.size / 1024).toFixed(0)} KB • Will upload to ImageKit`;
                    document.getElementById('image-preview-container').style.display = 'flex';
                };
                reader.readAsDataURL(file);
            }
        });

        document.getElementById('form-image-url').addEventListener('input', function() {
            const val = this.value.trim();
            if (val && !document.getElementById('form-image-file').files.length) {
                document.getElementById('form-image-preview').src = val;
                document.getElementById('image-preview-name').textContent = 'Image URL';
                document.getElementById('image-preview-meta').textContent = 'Direct link';
                document.getElementById('image-preview-container').style.display = 'flex';
            } else if (!val && !document.getElementById('form-image-file').files.length) {
                document.getElementById('image-preview-container').style.display = 'none';
            }
        });

        // SUBMIT ADD/EDIT FORM
        document.getElementById('equipment-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);

            try {
                const data = await adminEquipPost('admin_save', formData);
                if (data.success) {
                    if (window.playNotifSound) window.playNotifSound('success');
                    Swal.fire({
                        icon: 'success',
                        title: 'Saved',
                        text: data.message,
                        timer: 1500,
                        showConfirmButton: false
                    });
                    closeModal();
                    fetchAdminEquipment();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message || 'Failed to save equipment.'
                    });
                }
            } catch (err) {
                console.error(err);
                Swal.fire({
                    icon: 'error',
                    title: 'Network Error',
                    text: 'Could not communicate with the server.'
                });
            }
        });

        // MAINTENANCE MODAL
        window.openMaintModal = function(equipmentId, fullName) {
            document.getElementById('maint-modal-title').textContent = `Maintenance: ${fullName}`;
            document.getElementById('maint-equip-id').value = equipmentId;
            document.getElementById('maint-status').value = 'maintenance';
            document.getElementById('maint-reason').value = '';
            document.getElementById('maint-return-date').value = '';
            document.getElementById('maint-modal').style.display = 'flex';
        };

        window.closeMaintModal = function() {
            document.getElementById('maint-modal').style.display = 'none';
        };

        document.getElementById('maint-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);

            try {
                const data = await adminEquipPost('admin_set_maintenance', formData);
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Updated',
                        text: data.message,
                        timer: 1500,
                        showConfirmButton: false
                    });
                    closeMaintModal();
                    fetchAdminEquipment();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message || 'Failed to update maintenance.'
                    });
                }
            } catch (err) {
                console.error(err);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while saving maintenance.'
                });
            }
        });

        // RESTORE TO AVAILABLE
        window.restoreAvailable = async function(equipmentId) {
            try {
                const formData = new FormData();
                formData.append('equipment_id', equipmentId);

                const data = await adminEquipPost('admin_restore_available', formData);
                if (data.success) {
                    if (window.playNotifSound) window.playNotifSound('success');
                    Swal.fire({
                        icon: 'success',
                        title: 'Restored',
                        text: data.message,
                        timer: 1500,
                        showConfirmButton: false
                    });
                    fetchAdminEquipment();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Could Not Restore',
                        text: data.message || 'Failed to restore equipment.'
                    });
                }
            } catch (err) {
                console.error(err);
                Swal.fire({
                    icon: 'error',
                    title: 'Network Error',
                    text: 'Could not communicate with the server.'
                });
            }
        };

        // DELETE EQUIPMENT
        window.confirmDeleteEquipment = function(equipmentId, fullName) {
            Swal.fire({
                title: 'Delete Equipment?',
                text: `Are you sure you want to remove ${fullName} from your gym's inventory?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Delete',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    try {
                        const formData = new FormData();
                        formData.append('equipment_id', equipmentId);

                        const data = await adminEquipPost('admin_delete', formData);
                        if (data.success) {
                            if (window.playNotifSound) window.playNotifSound('warning');
                            Swal.fire({
                                icon: 'success',
                                title: 'Removed',
                                text: data.message,
                                timer: 1500,
                                showConfirmButton: false
                            });
                            fetchAdminEquipment();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message || 'Failed to remove equipment.'
                            });
                        }
                    } catch (err) {
                        console.error(err);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred while removing equipment.'
                        });
                    }
                }
            });
        };

        // QUEUE DETAILS MODAL
        window.openQueueModal = async function(equipmentId, fullName) {
            document.getElementById('queue-modal-title').textContent = fullName;
            document.getElementById('queue-modal').style.display = 'flex';
            document.getElementById('qmodal-session-content').innerHTML = 'Loading session info...';
            document.getElementById('qmodal-queue-list').innerHTML = 'Loading queue list...';

            try {
                const res = await fetch(`index.php?page=equipment_api&action=admin_queue_details&equipment_id=${equipmentId}&gym_id=${GYM_ID}`);
                const data = await res.json();

                if (!data.success) {
                    document.getElementById('qmodal-session-content').innerHTML = 'Could not load details.';
                    return;
                }

                // Render active session
                const sess = data.active_session;
                const eq = data.equipment || {};
                const queue = data.queue || [];
                const notifiedMember = queue.find(q => q.queue_status === 'notified');
                const badge = document.getElementById('qmodal-session-badge');

                if (sess) {
                    badge.textContent = 'In Use';
                    badge.className = 'status-pill status-in_use';

                    const elapsedMins = Math.floor((sess.elapsed_seconds || 0) / 60);
                    document.getElementById('qmodal-session-content').innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <strong style="color: var(--ink);">${escapeHtml(sess.first_name)} ${escapeHtml(sess.last_name)}</strong>
                                <span style="color: var(--muted); font-size: 12px; margin-left: 6px;">(${escapeHtml(sess.email)})</span>
                                <div style="color: var(--muted); font-size: 12px; margin-top: 2px;">
                                    Started: ${new Date(sess.start_time).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})} • Elapsed: ${elapsedMins} mins
                                </div>
                            </div>
                            <button type="button" onclick="adminForceFinishSession(${sess.session_id})" class="btn" style="background: #ef4444; color: #fff; font-size: 12px; padding: 6px 14px; border-radius: 6px; border: none; cursor: pointer;">
                                Force Finish
                            </button>
                        </div>
                    `;
                } else if (eq.status === 'maintenance') {
                    badge.textContent = 'Maintenance';
                    badge.className = 'status-pill status-maintenance';
                    document.getElementById('qmodal-session-content').innerHTML = `<span style="color: #d97706; font-size: 13px;">${escapeHtml(eq.maintenance_reason || 'Scheduled maintenance.')}</span>`;
                } else if (eq.status === 'out_of_service') {
                    badge.textContent = 'Out of Service';
                    badge.className = 'status-pill status-out_of_service';
                    document.getElementById('qmodal-session-content').innerHTML = '<span style="color: #ef4444; font-size: 13px;">Unit is out of service.</span>';
                } else if (notifiedMember) {
                    badge.textContent = 'Reserved (Claiming)';
                    badge.className = 'status-pill status-in_use';
                    const remainingSecs = Math.max(0, parseInt(notifiedMember.claim_seconds_left || 120));
                    document.getElementById('qmodal-session-content').innerHTML = `
                        <div style="color: #059669; font-size: 13px; font-weight: 600;">
                            Reserved for <strong>${escapeHtml(notifiedMember.first_name)} ${escapeHtml(notifiedMember.last_name)}</strong>
                            <span style="color: var(--muted); font-weight: 400; margin-left: 6px;">(~${remainingSecs}s claim window active)</span>
                        </div>
                    `;
                } else {
                    badge.textContent = 'Available';
                    badge.className = 'status-pill status-available';
                    document.getElementById('qmodal-session-content').innerHTML = '<span style="color: var(--muted);">No active member session currently. Ready for use.</span>';
                }

                // Render queue list
                if (queue.length === 0) {
                    document.getElementById('qmodal-queue-list').innerHTML = '<p style="color: var(--muted); font-size: 13px; margin: 8px 0;">No members currently waiting in line.</p>';
                } else {
                    let qHtml = '<ul style="list-style: none; padding: 0; margin: 0;">';
                    queue.forEach(q => {
                        const isNotified = q.queue_status === 'notified';
                        const statusNote = isNotified ? '<span style="color: #059669; font-weight: 700; margin-left: 6px;">[Claiming window active]</span>' : '';
                        qHtml += `
                            <li style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--line); font-size: 13px;">
                                <div>
                                    <strong style="color: var(--lime); margin-right: 6px;">#${q.queue_position}</strong>
                                    <span style="color: var(--ink); font-weight: 600;">${escapeHtml(q.first_name)} ${escapeHtml(q.last_name)}</span>
                                    ${statusNote}
                                    <div style="color: var(--muted); font-size: 11px;">Waiting ${q.minutes_waiting} mins</div>
                                </div>
                                <button type="button" onclick="adminRemoveFromQueue(${q.queue_id})" class="btn-act btn-act-delete" style="font-size: 11px; padding: 4px 8px;" title="Remove from queue">
                                    Remove
                                </button>
                            </li>
                        `;
                    });
                    qHtml += '</ul>';
                    document.getElementById('qmodal-queue-list').innerHTML = qHtml;
                }

            } catch (err) {
                console.error(err);
            }
        };

        window.closeQueueModal = function() {
            document.getElementById('queue-modal').style.display = 'none';
            fetchAdminEquipment();
        };

        window.adminForceFinishSession = function(sessionId) {
            Swal.fire({
                title: 'End Session?',
                text: 'Are you sure you want to end this active session? The machine will immediately be offered to the next waiting member.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'End Session',
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b'
            }).then(async (res) => {
                if (res.isConfirmed) {
                    try {
                        const formData = new FormData();
                        formData.append('session_id', sessionId);
                        const d = await adminEquipPost('admin_force_finish', formData);
                        if (d.success) {
                            closeQueueModal();
                            fetchAdminEquipment();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: d.message || 'Could not finish session.'
                            });
                        }
                    } catch (err) {
                        console.error(err);
                    }
                }
            });
        };

        window.adminRemoveFromQueue = async function(queueId) {
            try {
                const formData = new FormData();
                formData.append('queue_id', queueId);
                const d = await adminEquipPost('admin_remove_queue', formData);
                if (d.success) {
                    closeQueueModal();
                    fetchAdminEquipment();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: d.message || 'Could not remove member from queue.'
                    });
                }
            } catch (err) {
                console.error(err);
            }
        };

        // Filtering events
        document.getElementById('admin-search').addEventListener('input', function() {
            searchQuery = this.value.trim().toLowerCase();
            currentEquipPage = 1;
            renderAdminTable();
        });

        document.getElementById('admin-category-filter').addEventListener('change', function() {
            categoryFilter = this.value;
            currentEquipPage = 1;
            renderAdminTable();
        });

        document.getElementById('admin-status-filter').addEventListener('change', function() {
            statusFilter = this.value;
            currentEquipPage = 1;
            renderAdminTable();
        });

        const pageSizeSelect = document.getElementById('admin-equip-pagesize');
        if (pageSizeSelect) {
            pageSizeSelect.addEventListener('change', function() {
                changeEquipPageSize(this.value);
            });
        }

        function escapeHtml(text) {
            if (!text) return '';
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return String(text).replace(/[&<>"']/g, m => map[m]);
        }

        // Tab Switching Controller (Units vs Overview & Analytics)
        window.switchEquipView = function(view) {
            const btnUnits = document.getElementById('btn-equip-units');
            const btnAnalytics = document.getElementById('btn-equip-analytics');
            const viewUnits = document.getElementById('view-equip-units');
            const viewAnalytics = document.getElementById('view-equip-analytics');

            if (view === 'analytics') {
                btnAnalytics?.classList.add('active');
                btnUnits?.classList.remove('active');
                if (viewAnalytics) viewAnalytics.style.display = 'block';
                if (viewUnits) viewUnits.style.display = 'none';
                try { localStorage.setItem('fittracks_equip_active_tab', 'analytics'); } catch (e) {}
                try {
                    const url = new URL(window.location);
                    url.searchParams.set('tab', 'analytics');
                    history.replaceState(null, '', url.toString());
                } catch (e) {}
            } else {
                btnUnits?.classList.add('active');
                btnAnalytics?.classList.remove('active');
                if (viewUnits) viewUnits.style.display = 'block';
                if (viewAnalytics) viewAnalytics.style.display = 'none';
                try { localStorage.setItem('fittracks_equip_active_tab', 'units'); } catch (e) {}
                try {
                    const url = new URL(window.location);
                    url.searchParams.set('tab', 'units');
                    history.replaceState(null, '', url.toString());
                } catch (e) {}
            }
        };

        // Auto-restore tab preference
        const urlParams = new URLSearchParams(window.location.search);
        const forcedTab = urlParams.get('tab');
        if (forcedTab === 'analytics') {
            window.switchEquipView('analytics');
        } else if (forcedTab === 'units') {
            window.switchEquipView('units');
        } else {
            const savedTab = localStorage.getItem('fittracks_equip_active_tab');
            if (savedTab === 'analytics') {
                window.switchEquipView('analytics');
            }
        }

        fetchAdminEquipment();
    })();
    </script>
    <?php
    render_footer();
}
