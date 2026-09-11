<?php
declare(strict_types=1);

function member_equipment_page(): void
{
    $user = require_roles(['member']);
    $pdo = db();
    $gymId = get_user_gym_id($user);

    render_header('Gym Equipment', $user);

    if (!$gymId) {
        echo '<div class="panel" style="max-width: 600px; margin: 40px auto; text-align: center; padding: 40px 24px;">';
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 16px;"><rect x="2" y="6" width="4" height="12" rx="1"/><rect x="18" y="6" width="4" height="12" rx="1"/><path d="M6 12h12"/></svg>';
        echo '<h2 style="margin-top:0;">No Gym Selected</h2>';
        echo '<p style="color: var(--muted); margin-bottom: 24px;">To view live equipment availability and join equipment queues, please select and register with a gym first.</p>';
        echo '<a href="index.php?page=gym_selection" class="btn btn-lime" style="background: var(--lime); color: var(--lime-btn-text, #090b10); font-weight: 800; padding: 10px 24px; text-decoration: none; border-radius: 8px;">Browse Gyms</a>';
        echo '</div>';
        render_footer();
        return;
    }

    $gymName = scalar('SELECT name FROM gyms WHERE gym_id = ?', [$gymId]) ?: 'Your Gym';
    $userId = (int)$user['user_id'];
    $recentWeight = scalar('SELECT weight_kg FROM progress_logs WHERE user_id = ? ORDER BY log_date DESC, log_id DESC LIMIT 1', [$userId]);
    if (!$recentWeight) {
        $recentWeight = scalar('SELECT weight_kg FROM member_profiles WHERE user_id = ?', [$userId]);
    }
    $recentBf = scalar('SELECT body_fat_percent FROM progress_logs WHERE user_id = ? AND body_fat_percent IS NOT NULL ORDER BY log_date DESC, log_id DESC LIMIT 1', [$userId]) ?: null;
    ?>

    <style>
        /* Contrast fix for neon lime buttons across themes */
        :root {
            --lime-btn-text: #090b10;
        }
        [data-theme="light"] {
            --lime-btn-text: #ffffff;
        }

        .btn-lime {
            background: var(--lime) !important;
            color: var(--lime-btn-text) !important;
            font-weight: 800 !important;
        }
        .btn-lime:hover {
            opacity: 0.92;
        }

        .filter-btn {
            padding: 8px 16px;
            border-radius: 20px;
            border: 1px solid var(--line);
            background: var(--bg);
            color: var(--muted);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .filter-btn:hover {
            color: var(--ink);
            border-color: var(--ink);
        }
        .filter-btn.active {
            background: var(--lime);
            color: var(--lime-btn-text);
            border-color: var(--lime);
            font-weight: 800;
            box-shadow: 0 2px 10px rgba(0,0,0,0.12);
        }

        /* Ensure all lime action buttons have crisp dark text in darkmode */
        .btn[style*="var(--lime)"],
        button[style*="var(--lime)"],
        a[style*="var(--lime)"] {
            color: var(--lime-btn-text) !important;
            font-weight: 800 !important;
        }

        /* SweetAlert confirm buttons contrast */
        .swal2-styled.swal2-confirm:not([style*="239, 68, 68"]):not([style*="ef4444"]) {
            color: var(--lime-btn-text) !important;
            font-weight: 800 !important;
        }
        .equip-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
            position: relative;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }
        .equip-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
            border-color: color-mix(in srgb, var(--lime) 40%, var(--line));
        }

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

        .equip-badge {
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
        .badge-available {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
            border: 1px solid rgba(16, 185, 129, 0.22);
        }
        [data-theme="dark"] .badge-available {
            background: rgba(16, 185, 129, 0.14);
            color: #34d399;
            border-color: rgba(16, 185, 129, 0.25);
        }

        .badge-in_use {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
            border: 1px solid rgba(245, 158, 11, 0.22);
        }
        [data-theme="dark"] .badge-in_use {
            background: rgba(245, 158, 11, 0.14);
            color: #fbbf24;
            border-color: rgba(245, 158, 11, 0.25);
        }

        .badge-maintenance {
            background: rgba(100, 116, 139, 0.1);
            color: #475569;
            border: 1px solid rgba(100, 116, 139, 0.2);
        }
        [data-theme="dark"] .badge-maintenance {
            background: rgba(148, 163, 184, 0.12);
            color: #94a3b8;
            border-color: rgba(148, 163, 184, 0.22);
        }

        .badge-out_of_service {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.22);
        }
        [data-theme="dark"] .badge-out_of_service {
            background: rgba(239, 68, 68, 0.14);
            color: #f87171;
            border-color: rgba(239, 68, 68, 0.25);
        }

        .claim-pulse {
            animation: borderPulse 1.8s infinite;
        }
        @keyframes borderPulse {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
        @keyframes pulse {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.85); }
            100% { opacity: 1; transform: scale(1); }
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Member Equipment Inventory Pagination */
        .equip-pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
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
            .equipment-member-container {
                padding-bottom: 40px;
            }
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
            #equipment-grid {
                grid-template-columns: 1fr !important;
                gap: 16px !important;
            }
            .equip-card {
                padding: 16px !important;
            }
        }
        @media (max-width: 480px) {
            .filter-btn {
                padding: 6px 12px;
                font-size: 12px;
            }
        }

        .equip-action-strip {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            margin-bottom: 22px;
            background: var(--panel);
            border: 1px solid var(--line);
            padding: 12px 18px;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }
        .equip-strip-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            flex-wrap: wrap;
        }
        .equip-gym-pill {
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
            white-space: nowrap;
            max-width: 100%;
            min-width: 0;
        }
        .equip-gym-pill .gym-val {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .equip-strip-desc {
            color: var(--muted);
            font-size: 13px;
        }
        .equip-strip-status {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }
        .equip-live-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--lime);
            background: color-mix(in srgb, var(--lime) 10%, transparent);
            padding: 5px 14px;
            border-radius: 20px;
            border: 1px solid color-mix(in srgb, var(--lime) 25%, transparent);
            font-weight: 600;
            white-space: nowrap;
        }

        @media (max-width: 768px) {
            .equip-action-strip {
                display: grid !important;
                grid-template-columns: 1fr auto !important;
                grid-template-rows: auto auto !important;
                align-items: center !important;
                row-gap: 8px !important;
                column-gap: 10px !important;
                padding: 12px 16px !important;
                margin-bottom: 18px !important;
            }
            .equip-strip-meta {
                display: contents !important;
            }
            .equip-gym-pill {
                grid-column: 1 / 2 !important;
                grid-row: 1 / 2 !important;
                justify-self: start !important;
            }
            .equip-strip-status {
                grid-column: 2 / 3 !important;
                grid-row: 1 / 2 !important;
                justify-self: end !important;
            }
            .equip-strip-desc {
                grid-column: 1 / -1 !important;
                grid-row: 2 / 3 !important;
                font-size: 12px !important;
                margin: 0 !important;
                line-height: 1.4 !important;
            }
        }
        @media (max-width: 420px) {
            .equip-gym-pill {
                font-size: 12px !important;
                padding: 4px 10px !important;
            }
            .equip-live-pill {
                font-size: 11px !important;
                padding: 4px 10px !important;
            }
        }
    </style>

    <div class="equipment-member-container" style="max-width: 1200px; margin: 0 auto; padding-bottom: 60px;">
        <!-- Contextual Action Strip (Gym Info & Live Sync) -->
        <div class="equip-action-strip">
            <div class="equip-strip-meta">
                <span class="equip-gym-pill">
                    <span style="width: 7px; height: 7px; border-radius: 50%; background: var(--lime); display: inline-block; flex-shrink: 0;"></span>
                    <span style="color: var(--muted); font-weight: 500;">Gym:</span>
                    <strong class="gym-val" style="color: var(--ink); font-weight: 700;"><?= h($gymName) ?></strong>
                </span>
                <span class="equip-strip-desc">Live machine availability &amp; queue tracking</span>
            </div>
            <div class="equip-strip-status">
                <span id="live-indicator" class="equip-live-pill">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background: var(--lime); display: inline-block; box-shadow: 0 0 8px var(--lime); animation: pulse 2s infinite; flex-shrink: 0;"></span>
                    Live Syncing
                </span>
            </div>
        </div>

        <!-- ACTIVE SESSION BANNER (Rendered dynamically) -->
        <div id="active-session-wrapper" style="display: none; margin-bottom: 24px;"></div>

        <!-- MY QUEUES SECTION (Rendered dynamically) -->
        <div id="my-queues-wrapper" style="display: none; margin-bottom: 24px;"></div>

        <!-- SEARCH & FILTER TOOLBAR -->
        <div class="panel" style="padding: 16px 20px; border-radius: 12px; margin-bottom: 24px; background: var(--panel); border: 1px solid var(--line); box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
            <div style="display: flex; flex-wrap: wrap; gap: 14px; align-items: center; justify-content: space-between;">
                <!-- Search Input -->
                <div style="flex: 1 1 260px; max-width: 400px; position: relative;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); pointer-events: none;">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="text" id="equipment-search" placeholder="Search by name, category, or area..." 
                           style="width: 100%; padding: 10px 12px 10px 38px; border-radius: 8px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 13px; box-sizing: border-box; outline: none;">
                </div>

                <!-- Status Filter Pills -->
                <div style="display: flex; gap: 8px; flex-wrap: wrap;" id="status-filters">
                    <button type="button" class="filter-btn active" data-status="all">All</button>
                    <button type="button" class="filter-btn" data-status="available">Available</button>
                    <button type="button" class="filter-btn" data-status="in_use">In Use</button>
                    <button type="button" class="filter-btn" data-status="maintenance">Maintenance</button>
                </div>

                <!-- Category Filter -->
                <div style="flex: 0 0 auto;">
                    <select id="category-filter" style="width: auto; min-width: 150px; padding: 9px 14px; border-radius: 8px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 13px; box-sizing: border-box; cursor: pointer;">
                        <option value="all">All Categories</option>
                        <option value="Cardio">Cardio</option>
                        <option value="Strength">Strength</option>
                        <option value="Free Weights">Free Weights</option>
                        <option value="Machines">Machines</option>
                        <option value="Functional Training">Functional Training</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div style="flex: 0 0 auto;">
                    <button type="button" class="btn-sound-toggle filter-btn" style="display: inline-flex; align-items: center; gap: 7px; padding: 9px 14px; border-radius: 8px; font-size: 13px; border: 1px solid var(--line); background: var(--panel-soft); color: var(--ink); cursor: pointer; transition: all 0.2s;" aria-label="Toggle notification sounds" title="Sound: Enabled (Click to mute)">
                        <span class="sound-toggle-icon"></span>
                        <span class="sound-toggle-text">Sound On</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- EQUIPMENT GRID -->
        <div id="equipment-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px;">
            <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: var(--muted);">
                <div class="spinner" style="margin: 0 auto 12px; width: 32px; height: 32px; border: 3px solid var(--line); border-top-color: var(--lime); border-radius: 50%; animation: spin 0.8s linear infinite;"></div>
                Loading gym equipment inventory...
            </div>
        </div>

        <!-- EMPTY SEARCH STATE -->
        <div id="empty-state" style="display: none; text-align: center; padding: 60px 20px; background: var(--panel); border: 1px solid var(--line); border-radius: 12px; margin-top: 20px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 16px;">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <h3 style="margin: 0 0 8px; color: var(--ink);">No equipment matches your filter</h3>
            <p style="margin: 0; color: var(--muted); font-size: 14px;">Try adjusting your search terms or filter selection.</p>
        </div>

        <!-- MEMBER EQUIPMENT PAGINATION -->
        <div id="member-equip-pagination" class="equip-pagination-bar" style="display: none; margin-top: 24px;">
            <div class="equip-page-info" id="member-equip-page-info">
                Showing 0 of 0 units
            </div>
            <div class="equip-page-controls" id="member-equip-page-buttons">
                <!-- Dynamic page buttons -->
            </div>
            <div class="equip-page-size-wrap">
                <label for="member-equip-pagesize" style="font-size: 12px; color: var(--muted); margin-right: 6px;">Per page:</label>
                <select id="member-equip-pagesize" style="padding: 4px 10px; font-size: 12px; height: 34px; border-radius: 8px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); cursor: pointer;">
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

    <script>
    (function() {
        const CURRENT_USER_ID = <?= $userId ?>;
        const GYM_ID = <?= $gymId ?>;
        const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
        let RECENT_WEIGHT = <?= json_encode($recentWeight !== null && $recentWeight !== false ? (float)$recentWeight : null) ?>;
        let RECENT_BF = <?= json_encode($recentBf !== null && $recentBf !== false ? (float)$recentBf : null) ?>;
        let allEquipment = [];
        let activeSession = null;
        let myQueues = [];
        let previousNotifiedQueueIds = new Set();
        let activeFilter = 'all';
        let categoryFilter = 'all';
        let searchQuery = '';
        let currentMemberPage = 1;
        let memberPageSize = 8;
        let timerInterval = null;
        let pollInterval = null;

        async function memberEquipPost(action, formData) {
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

        function formatDuration(totalSeconds) {
            if (totalSeconds < 0) totalSeconds = 0;
            const hrs = Math.floor(totalSeconds / 3600);
            const mins = Math.floor((totalSeconds % 3600) / 60);
            const secs = totalSeconds % 60;
            if (hrs > 0) {
                return String(hrs).padStart(2, '0') + ':' + String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
            }
            return String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
        }

        async function fetchEquipmentData() {
            try {
                const res = await fetch(`index.php?page=equipment_api&action=poll&gym_id=${GYM_ID}`, {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await res.json();
                if (!data.success) {
                    console.warn(data.message);
                    return;
                }

                allEquipment = data.equipment || [];
                activeSession = data.active_session || null;
                myQueues = data.my_queues || [];

                // Audio cue when equipment becomes available for this member
                const currentNotifiedIds = new Set();
                let hasNewNotifiedQueue = false;
                myQueues.forEach(q => {
                    if (q.queue_status === 'notified') {
                        const qId = parseInt(q.queue_id || q.id || 0, 10);
                        currentNotifiedIds.add(qId);
                        if (!previousNotifiedQueueIds.has(qId)) {
                            hasNewNotifiedQueue = true;
                        }
                    }
                });
                if (hasNewNotifiedQueue) {
                    if (window.playNotifSound) window.playNotifSound('ready');
                }
                previousNotifiedQueueIds = currentNotifiedIds;

                renderActiveSessionBanner();
                renderMyQueuesSection();
                renderEquipmentGrid();
            } catch (err) {
                console.error('Error syncing equipment:', err);
            }
        }

        function renderActiveSessionBanner() {
            const wrapper = document.getElementById('active-session-wrapper');
            if (!activeSession) {
                wrapper.style.display = 'none';
                wrapper.innerHTML = '';
                return;
            }

            const elapsed = activeSession.elapsed_seconds || 0;
            const startTimeStr = new Date(activeSession.start_ts * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

            wrapper.style.display = 'block';
            wrapper.innerHTML = `
                <div class="panel" style="background: linear-gradient(135deg, color-mix(in srgb, var(--lime) 14%, var(--panel)) 0%, var(--panel) 100%); border: 2px solid color-mix(in srgb, var(--lime) 40%, transparent); border-radius: 14px; padding: 22px 24px; box-shadow: var(--shadow); display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px;">
                    <div style="flex: 1 1 300px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                            <span style="width: 10px; height: 10px; border-radius: 50%; background: var(--lime); display: inline-block; box-shadow: 0 0 10px var(--lime); animation: pulse 1.5s infinite;"></span>
                            <span style="color: var(--lime); font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;">Active Equipment Session</span>
                        </div>
                        <h2 style="margin: 0 0 4px; font-size: 22px; color: var(--ink);">
                            ${escapeHtml(activeSession.name)} <span class="unit-pill">${escapeHtml(activeSession.unit_number)}</span>
                        </h2>
                        <p style="margin: 0; color: var(--muted); font-size: 13px;">
                            ${escapeHtml(activeSession.category)} • ${escapeHtml(activeSession.location_area || 'Main Floor')} • Started at ${startTimeStr}
                        </p>
                    </div>

                    <div style="text-align: center; background: var(--panel-soft); padding: 12px 24px; border-radius: 12px; border: 1px solid var(--line); min-width: 140px;">
                        <span style="display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); margin-bottom: 2px; font-weight: 700;">Workout Time</span>
                        <strong id="session-timer-display" style="font-size: 26px; font-family: -apple-system, BlinkMacSystemFont, monospace; color: var(--ink); letter-spacing: 0.05em;">
                            ${formatDuration(elapsed)}
                        </strong>
                    </div>

                    <div>
                        <button type="button" id="btn-finish-session" class="btn" style="background: #ef4444; color: #fff; font-weight: 700; padding: 10px 22px; border-radius: 8px; border: none; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: opacity 0.2s;"
                                onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/></svg>
                            Finish Session
                        </button>
                    </div>
                </div>
            `;

            document.getElementById('btn-finish-session').addEventListener('click', () => confirmFinishSession(activeSession.session_id, activeSession.name + ' ' + activeSession.unit_number));
        }

        function renderMyQueuesSection() {
            const wrapper = document.getElementById('my-queues-wrapper');
            if (!myQueues || myQueues.length === 0) {
                wrapper.style.display = 'none';
                wrapper.innerHTML = '';
                return;
            }

            wrapper.style.display = 'block';
            let cardsHtml = '';

            myQueues.forEach(q => {
                const isNotified = q.queue_status === 'notified';
                const remainingSecs = Math.max(0, q.claim_remaining_seconds || 0);

                if (isNotified) {
                    cardsHtml += `
                        <div class="panel claim-pulse" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.15) 0%, var(--panel) 100%); border: 2px solid #10b981; border-radius: 12px; padding: 18px 20px; margin-bottom: 12px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 14px;">
                            <div>
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                    <span style="background: #10b981; color: #fff; font-size: 11px; font-weight: 800; padding: 2px 8px; border-radius: 12px; text-transform: uppercase;">You're Next!</span>
                                    <span style="color: #059669; font-size: 13px; font-weight: 700;">Equipment is now ready for you</span>
                                </div>
                                <h3 style="margin: 0 0 4px; font-size: 18px; color: var(--ink);">
                                    ${escapeHtml(q.name)} <span class="unit-pill">${escapeHtml(q.unit_number)}</span>
                                </h3>
                                <p style="margin: 0; color: var(--muted); font-size: 13px;">
                                    Claim window expires in: <strong class="claim-countdown" data-remain="${remainingSecs}" style="color: #d97706; font-family: monospace; font-size: 14px;">${formatDuration(remainingSecs)}</strong>
                                </p>
                            </div>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <button type="button" onclick="window.claimEquipmentSession(${q.equipment_id})" class="btn btn-lime" style="background: var(--lime); color: var(--lime-btn-text, #090b10); font-weight: 800; padding: 10px 20px; border-radius: 8px; border: none; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.15);">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                    Claim & Start Session
                                </button>
                                <button type="button" onclick="window.leaveEquipmentQueue(${q.queue_id}, '${escapeHtml(q.name)}')" class="btn" style="background: transparent; color: var(--muted); border: 1px solid var(--line); font-size: 13px; padding: 9px 14px; border-radius: 8px; cursor: pointer;">
                                    Pass / Leave
                                </button>
                            </div>
                        </div>
                    `;
                } else {
                    const ahead = q.members_ahead || 0;
                    const aheadText = ahead === 0 ? 'You are next in line' : `${ahead} member${ahead > 1 ? 's' : ''} ahead of you`;

                    cardsHtml += `
                        <div class="panel" style="background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 16px 20px; margin-bottom: 12px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.03);">
                            <div style="display: flex; align-items: center; gap: 14px;">
                                <div style="background: var(--panel-soft); border: 1px solid var(--line); width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 17px; font-weight: 800; color: var(--ink);">
                                    #${q.queue_position}
                                </div>
                                <div>
                                    <h4 style="margin: 0 0 4px; font-size: 16px; color: var(--ink);">
                                        ${escapeHtml(q.name)} <span class="unit-pill">${escapeHtml(q.unit_number)}</span>
                                    </h4>
                                    <p style="margin: 0; color: var(--muted); font-size: 13px;">
                                        Position <strong style="color: var(--ink);">#${q.queue_position}</strong> • ${aheadText}
                                    </p>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <span style="font-size: 12px; background: rgba(245,158,11,0.12); color: #d97706; border: 1px solid rgba(245,158,11,0.25); padding: 4px 10px; border-radius: 12px; font-weight: 700;">
                                    Waiting in Queue
                                </span>
                                <button type="button" onclick="window.leaveEquipmentQueue(${q.queue_id}, '${escapeHtml(q.name)}')" class="btn" style="background: transparent; color: #ef4444; border: 1px solid rgba(239,68,68,0.3); font-size: 12px; padding: 6px 12px; border-radius: 6px; cursor: pointer; transition: all 0.2s;"
                                        onmouseover="this.style.background='rgba(239,68,68,0.1)'" onmouseout="this.style.background='transparent'">
                                    Leave Queue
                                </button>
                            </div>
                        </div>
                    `;
                }
            });

            wrapper.innerHTML = `
                <div style="margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between;">
                    <h3 style="margin: 0; font-size: 16px; color: var(--ink); text-transform: uppercase; letter-spacing: 0.05em;">My Equipment Queues</h3>
                    <span style="font-size: 12px; color: var(--muted);">${myQueues.length} active queue${myQueues.length > 1 ? 's' : ''}</span>
                </div>
                ${cardsHtml}
            `;
        }

        function renderEquipmentGrid() {
            const grid = document.getElementById('equipment-grid');
            const empty = document.getElementById('empty-state');
            const paginationBar = document.getElementById('member-equip-pagination');

            const filtered = allEquipment.filter(item => {
                if (activeFilter !== 'all') {
                    if (activeFilter === 'available' && item.status !== 'available') return false;
                    if (activeFilter === 'in_use' && item.status !== 'in_use') return false;
                    if (activeFilter === 'maintenance' && item.status !== 'maintenance' && item.status !== 'out_of_service') return false;
                }

                if (categoryFilter !== 'all' && item.category !== categoryFilter) return false;

                if (searchQuery) {
                    const haystack = (item.name + ' ' + item.unit_number + ' ' + item.category + ' ' + (item.location_area || '')).toLowerCase();
                    if (!haystack.includes(searchQuery)) return false;
                }

                return true;
            });

            if (filtered.length === 0) {
                grid.innerHTML = '';
                empty.style.display = 'block';
                if (paginationBar) paginationBar.style.display = 'none';
                return;
            }

            empty.style.display = 'none';

            // Pagination calculation
            const totalFiltered = filtered.length;
            const totalPages = Math.max(1, Math.ceil(totalFiltered / memberPageSize));
            if (currentMemberPage > totalPages) {
                currentMemberPage = totalPages;
            }
            if (currentMemberPage < 1) {
                currentMemberPage = 1;
            }

            const startIndex = (currentMemberPage - 1) * memberPageSize;
            const endIndex = Math.min(startIndex + memberPageSize, totalFiltered);
            const pagedItems = filtered.slice(startIndex, endIndex);

            if (paginationBar) {
                paginationBar.style.display = 'flex';
                renderMemberPagination(totalFiltered, totalPages, startIndex, endIndex);
            }

            let html = '';
            pagedItems.forEach(eq => {
                const status = eq.status || 'available';
                const isUserUsing = activeSession && (parseInt(activeSession.equipment_id) === parseInt(eq.equipment_id));
                const userQueue = myQueues.find(q => parseInt(q.equipment_id) === parseInt(eq.equipment_id));
                const isReservedForOther = (status === 'available') && (parseInt(eq.claim_remaining_seconds || 0) > 0) && (parseInt(eq.notified_user_id || 0) !== CURRENT_USER_ID);

                const statusLabel = {
                    'available': isReservedForOther ? 'Reserved' : 'Available',
                    'in_use': 'In Use',
                    'maintenance': 'Maintenance',
                    'out_of_service': 'Out of Service'
                }[status] || 'Available';

                const badgeClass = isReservedForOther ? 'badge-maintenance' : ('badge-' + status);
                const iconSvg = isReservedForOther ?
                    '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>' :
                    (icons[status] || icons.available);

                let actionBtnHtml = '';
                if (isUserUsing) {
                    actionBtnHtml = `
                        <button type="button" onclick="confirmFinishSession(${activeSession.session_id}, '${escapeHtml(eq.name)} ${escapeHtml(eq.unit_number)}')" class="btn" style="width: 100%; background: #ef4444; color: #fff; font-weight: 700; padding: 10px; border-radius: 8px; border: none; cursor: pointer;">
                            Finish My Session
                        </button>
                    `;
                } else if (userQueue) {
                    if (userQueue.queue_status === 'notified') {
                        actionBtnHtml = `
                            <button type="button" onclick="window.claimEquipmentSession(${eq.equipment_id})" class="btn btn-lime" style="width: 100%; background: var(--lime); color: var(--lime-btn-text, #090b10); font-weight: 800; padding: 10px; border-radius: 8px; border: none; cursor: pointer; animation: pulse 1.5s infinite;">
                                Claim Now (Next in Line!)
                            </button>
                        `;
                    } else {
                        actionBtnHtml = `
                            <button type="button" onclick="window.leaveEquipmentQueue(${userQueue.queue_id}, '${escapeHtml(eq.name)}')" class="btn" style="width: 100%; background: rgba(245,158,11,0.12); color: #d97706; border: 1px solid rgba(245,158,11,0.3); font-weight: 700; padding: 10px; border-radius: 8px; cursor: pointer;">
                                In Queue (#${userQueue.queue_position}) • Leave
                            </button>
                        `;
                    }
                } else if (status === 'available') {
                    if (isReservedForOther) {
                        const queueCount = parseInt(eq.waiting_queue_count || 0);
                        const queueText = queueCount > 0 ? `Join Queue (${queueCount} in line)` : 'Join Queue';
                        actionBtnHtml = `
                            <button type="button" onclick="confirmJoinQueue(${eq.equipment_id}, '${escapeHtml(eq.name)}', '${escapeHtml(eq.unit_number)}', ${queueCount})" class="btn" style="width: 100%; background: var(--panel-soft); color: #d97706; border: 1px solid rgba(217,119,6,0.3); font-weight: 600; padding: 10px; border-radius: 8px; cursor: pointer; transition: all 0.2s;"
                                    title="Currently reserved for the next queued member (2m claim window). You can join the queue behind them.">
                                <span style="display: flex; align-items: center; justify-content: center; gap: 6px;">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    ${queueText}
                                </span>
                            </button>
                        `;
                    } else {
                        actionBtnHtml = `
                            <button type="button" onclick="confirmStartSession(${eq.equipment_id}, '${escapeHtml(eq.name)}', '${escapeHtml(eq.unit_number)}')" class="btn btn-lime" style="width: 100%; background: var(--lime); color: var(--lime-btn-text, #090b10); font-weight: 800; padding: 10px; border-radius: 8px; border: none; cursor: pointer; transition: opacity 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.1);"
                                    onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                                Use Equipment
                            </button>
                        `;
                    }
                } else if (status === 'in_use') {
                    const queueCount = parseInt(eq.waiting_queue_count || 0);
                    const queueText = queueCount > 0 ? `Join Queue (${queueCount} waiting)` : 'Join Queue';
                    actionBtnHtml = `
                        <button type="button" onclick="confirmJoinQueue(${eq.equipment_id}, '${escapeHtml(eq.name)}', '${escapeHtml(eq.unit_number)}', ${queueCount})" class="btn" style="width: 100%; background: var(--panel-soft); color: var(--ink); border: 1px solid var(--line); font-weight: 600; padding: 10px; border-radius: 8px; cursor: pointer; transition: all 0.2s;"
                                onmouseover="this.style.borderColor='var(--lime)'; this.style.color='var(--lime)'" onmouseout="this.style.borderColor='var(--line)'; this.style.color='var(--ink)'">
                            ${queueText}
                        </button>
                    `;
                } else {
                    actionBtnHtml = `
                        <button type="button" disabled class="btn" style="width: 100%; background: var(--panel-soft); color: var(--muted); border: 1px solid var(--line); font-weight: 500; padding: 10px; border-radius: 8px; cursor: not-allowed; opacity: 0.6;">
                            Unavailable
                        </button>
                    `;
                }

                let occupancyHtml = '';
                if (status === 'in_use') {
                    const occupant = eq.current_user_display ? escapeHtml(eq.current_user_display) : 'Occupied';
                    occupancyHtml = `
                        <div style="font-size: 12px; color: var(--muted); display: flex; justify-content: space-between; margin-bottom: 6px;">
                            <span>Current user:</span>
                            <strong style="color: var(--ink);">${occupant}</strong>
                        </div>
                    `;
                } else if (status === 'available') {
                    if (isReservedForOther) {
                        const resName = eq.notified_user_display ? escapeHtml(eq.notified_user_display) : 'Queued member';
                        const remSec = parseInt(eq.claim_remaining_seconds || 0);
                        occupancyHtml = `
                            <div style="font-size: 12px; color: var(--muted); display: flex; justify-content: space-between; margin-bottom: 6px;">
                                <span>Availability:</span>
                                <span style="color: #d97706; font-weight: 700;">Claim window: ${resName} (${remSec}s)</span>
                            </div>
                        `;
                    } else {
                        occupancyHtml = `
                            <div style="font-size: 12px; color: var(--muted); display: flex; justify-content: space-between; margin-bottom: 6px;">
                                <span>Availability:</span>
                                <span style="color: #059669; font-weight: 600;">Ready for use</span>
                            </div>
                        `;
                    }
                } else {
                    const reason = eq.maintenance_reason ? escapeHtml(eq.maintenance_reason) : 'Scheduled maintenance';
                    occupancyHtml = `
                        <div style="font-size: 12px; color: var(--muted); display: flex; justify-content: space-between; margin-bottom: 6px;">
                            <span>Status note:</span>
                            <span style="color: #d97706; text-align: right; max-width: 60%; font-weight: 500;">${reason}</span>
                        </div>
                    `;
                }

                const queueCount = parseInt(eq.waiting_queue_count || 0);
                const queueInfoHtml = `
                    <div style="font-size: 12px; color: var(--muted); display: flex; justify-content: space-between; margin-bottom: 14px;">
                        <span>Queue:</span>
                        <span style="color: ${queueCount > 0 ? '#d97706' : 'var(--muted)'}; font-weight: ${queueCount > 0 ? '700' : 'normal'};">
                            ${queueCount > 0 ? `${queueCount} member${queueCount > 1 ? 's' : ''} waiting` : 'No queue'}
                        </span>
                    </div>
                `;

                const imageBanner = eq.image_url ? `
                    <div style="margin: -20px -20px 14px -20px; height: 140px; overflow: hidden; border-radius: 14px 14px 0 0; position: relative; border-bottom: 1px solid var(--line);">
                        <img src="${escapeHtml(eq.image_url)}" alt="${escapeHtml(eq.name)}" style="width: 100%; height: 100%; object-fit: cover;" loading="lazy">
                        <div style="position: absolute; inset: 0; background: linear-gradient(to top, rgba(0,0,0,0.3) 0%, transparent 60%);"></div>
                    </div>
                ` : '';

                html += `
                    <div class="equip-card" style="overflow: hidden;">
                        ${imageBanner}
                        <div>
                            <!-- Header with category and status badge -->
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 12px;">
                                <span style="font-size: 12px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;">
                                    ${escapeHtml(eq.category)}
                                </span>
                                <span class="equip-badge ${badgeClass}">
                                    ${iconSvg} ${statusLabel}
                                </span>
                            </div>

                            <!-- Title & Unit identifier -->
                            <h3 style="margin: 0 0 6px; font-size: 19px; color: var(--ink);">
                                ${escapeHtml(eq.name)} <span class="unit-pill">${escapeHtml(eq.unit_number)}</span>
                            </h3>

                            <!-- Location and condition -->
                            <p style="margin: 0 0 16px; color: var(--muted); font-size: 13px; display: flex; align-items: center; gap: 8px;">
                                <span>${escapeHtml(eq.location_area || 'Main Floor')}</span>
                                <span>•</span>
                                <span style="color: var(--muted); font-size: 12px;">Condition: <strong>${escapeHtml(eq.equipment_condition || 'Good')}</strong></span>
                            </p>

                            <!-- Occupancy & Queue details -->
                            <div style="background: var(--panel-soft); padding: 11px 13px; border-radius: 9px; margin-bottom: 16px; border: 1px solid var(--line);">
                                ${occupancyHtml}
                                ${queueInfoHtml}
                            </div>
                        </div>

                        <!-- Action Button -->
                        <div>
                            ${actionBtnHtml}
                        </div>
                    </div>
                `;
            });

            grid.innerHTML = html;
        }

        function renderMemberPagination(totalItems, totalPages, startIndex, endIndex) {
            const infoEl = document.getElementById('member-equip-page-info');
            const buttonsEl = document.getElementById('member-equip-page-buttons');
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
                if (currentMemberPage <= 3) {
                    pages = [1, 2, 3, '...', totalPages];
                } else if (currentMemberPage >= totalPages - 2) {
                    pages = [1, '...', totalPages - 2, totalPages - 1, totalPages];
                } else {
                    pages = [1, '...', currentMemberPage - 1, currentMemberPage, currentMemberPage + 1, '...', totalPages];
                }
            }

            let btnsHtml = '';
            // Previous button (<)
            const prevDisabled = currentMemberPage <= 1 ? 'disabled' : '';
            btnsHtml += `<button type="button" class="btn-equip-page ${prevDisabled}" onclick="goToMemberPage(${currentMemberPage - 1})" aria-label="Previous page">&lt;</button>`;

            // Page numbers
            pages.forEach(p => {
                if (p === '...') {
                    btnsHtml += `<span class="equip-page-ellipsis">...</span>`;
                } else {
                    const isActive = p === currentMemberPage ? 'active' : '';
                    btnsHtml += `<button type="button" class="btn-equip-page ${isActive}" onclick="goToMemberPage(${p})">${p}</button>`;
                }
            });

            // Next button (>)
            const nextDisabled = currentMemberPage >= totalPages ? 'disabled' : '';
            btnsHtml += `<button type="button" class="btn-equip-page ${nextDisabled}" onclick="goToMemberPage(${currentMemberPage + 1})" aria-label="Next page">&gt;</button>`;

            buttonsEl.innerHTML = btnsHtml;
        }

        window.goToMemberPage = function(page) {
            currentMemberPage = page;
            renderEquipmentGrid();
            const gridEl = document.getElementById('equipment-grid');
            if (gridEl) {
                const rect = gridEl.getBoundingClientRect();
                if (rect.top < 0) {
                    gridEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        };

        window.changeMemberPageSize = function(size) {
            memberPageSize = parseInt(size, 10) || 8;
            currentMemberPage = 1;
            renderEquipmentGrid();
        };

        // CONFIRM START SESSION
        window.confirmStartSession = function(equipmentId, equipName, unitNumber) {
            if (activeSession) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Active Session Exists',
                    text: `You are currently using ${activeSession.name} ${activeSession.unit_number}. Please finish that session first.`,
                    background: 'var(--panel)',
                    color: 'var(--ink)',
                    confirmButtonColor: 'var(--lime)'
                });
                return;
            }

            Swal.fire({
                title: 'Start Equipment Session?',
                html: `<strong>${escapeHtml(equipName)} ${escapeHtml(unitNumber)}</strong> is currently available.<br><span style="font-size:13px; color:var(--muted);">Your usage session and workout timer will start immediately.</span>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Start Session',
                cancelButtonText: 'Cancel',
                confirmButtonColor: 'var(--lime)',
                cancelButtonColor: '#64748b',
                background: 'var(--panel)',
                color: 'var(--ink)'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    try {
                        const formData = new FormData();
                        formData.append('equipment_id', equipmentId);

                        const data = await memberEquipPost('start_session', formData);

                        if (data.success) {
                            if (window.playNotifSound) window.playNotifSound('success');
                            Swal.fire({
                                icon: 'success',
                                title: 'Session Started!',
                                text: data.message,
                                background: 'var(--panel)',
                                color: 'var(--ink)',
                                confirmButtonColor: 'var(--lime)',
                                timer: 2000,
                                showConfirmButton: false
                            });
                            fetchEquipmentData();
                        } else {
                            if (data.can_queue) {
                                Swal.fire({
                                    icon: 'info',
                                    title: 'Equipment Occupied',
                                    text: data.message,
                                    showCancelButton: true,
                                    confirmButtonText: 'Join Queue',
                                    cancelButtonText: 'Cancel',
                                    confirmButtonColor: 'var(--lime)',
                                    background: 'var(--panel)',
                                    color: 'var(--ink)'
                                }).then(qRes => {
                                    if (qRes.isConfirmed) {
                                        confirmJoinQueue(equipmentId, equipName, unitNumber, 0);
                                    }
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Cannot Start Session',
                                    text: data.message || 'Unable to start session.',
                                    background: 'var(--panel)',
                                    color: 'var(--ink)',
                                    confirmButtonColor: 'var(--lime)'
                                });
                            }
                            fetchEquipmentData();
                        }
                    } catch (e) {
                        console.error(e);
                        Swal.fire({
                            icon: 'error',
                            title: 'Network Error',
                            text: 'Failed to communicate with server.',
                            background: 'var(--panel)',
                            color: 'var(--ink)'
                        });
                    }
                }
            });
        };

        // CONFIRM FINISH SESSION
        window.confirmFinishSession = function(sessionId, fullName) {
            Swal.fire({
                title: 'Finish Equipment Session?',
                text: `Are you finished using ${fullName}?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Finish Session',
                cancelButtonText: 'Continue Using',
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                background: 'var(--panel)',
                color: 'var(--ink)'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    try {
                        const formData = new FormData();
                        formData.append('session_id', sessionId);

                        const data = await memberEquipPost('finish_session', formData);

                        if (data.success) {
                            if (window.playNotifSound) window.playNotifSound('success');
                            fetchEquipmentData();

                            // Prompt member to log progress now or later
                            const formattedDur = formatDuration(data.duration_seconds || 0);
                            Swal.fire({
                                icon: 'success',
                                title: 'Session Completed! 🎉',
                                html: `
                                    <div style="text-align: center; margin-top: 6px;">
                                        <p style="font-size: 15px; margin: 0 0 10px; color: var(--ink);">
                                            Great workout on <strong>${escapeHtml(fullName)}</strong>!
                                        </p>
                                        <div style="display: inline-flex; align-items: center; gap: 6px; background: var(--panel-soft); border: 1px solid var(--line); border-radius: 8px; padding: 6px 14px; margin-bottom: 16px; font-size: 13px; color: var(--muted);">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                            Duration: <strong style="color: var(--ink); font-family: monospace;">${formattedDur}</strong>
                                        </div>
                                        <p style="font-size: 14px; color: var(--muted); margin: 0;">
                                            Would you like to log your progress now?
                                        </p>
                                    </div>
                                `,
                                showCancelButton: true,
                                confirmButtonText: '<span style="display:inline-flex;align-items:center;gap:6px;color:var(--lime-btn-text, #090b10);font-weight:800;"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Log Progress Now</span>',
                                cancelButtonText: 'Later',
                                confirmButtonColor: 'var(--lime)',
                                cancelButtonColor: '#64748b',
                                background: 'var(--panel)',
                                color: 'var(--ink)'
                            }).then((promptRes) => {
                                if (promptRes.isConfirmed) {
                                    openQuickProgressModal(fullName, data.duration_seconds || 0);
                                } else {
                                    Swal.fire({
                                        toast: true,
                                        position: 'top-end',
                                        icon: 'info',
                                        title: 'No problem! You can log your progress anytime in the Progress tab.',
                                        showConfirmButton: false,
                                        timer: 3500,
                                        background: 'var(--panel)',
                                        color: 'var(--ink)'
                                    });
                                }
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message || 'Failed to finish session.',
                                background: 'var(--panel)',
                                color: 'var(--ink)'
                            });
                        }
                    } catch (e) {
                        console.error(e);
                        Swal.fire({
                            icon: 'error',
                            title: 'Network Error',
                            text: 'Failed to communicate with server.',
                            background: 'var(--panel)',
                            color: 'var(--ink)'
                        });
                    }
                }
            });
        };

        // QUICK PROGRESS LOGGING MODAL
        window.openQuickProgressModal = function(fullName, durationSeconds) {
            const todayStr = new Date().toISOString().split('T')[0];
            const durationMins = Math.max(1, Math.round((durationSeconds || 0) / 60));
            const formattedDuration = formatDuration(durationSeconds || 0);
            const defaultNotes = `Completed workout on ${fullName} (${durationMins} min${durationMins > 1 ? 's' : ''}, ${formattedDuration})`;
            const weightVal = RECENT_WEIGHT ? Number(RECENT_WEIGHT).toFixed(1) : '';
            const bfVal = RECENT_BF ? Number(RECENT_BF).toFixed(1) : '';

            Swal.fire({
                title: 'Log Your Progress',
                html: `
                    <div style="text-align: left; font-size: 13.5px; color: var(--ink);">
                        <div style="background: var(--panel-soft); border: 1px solid var(--line); border-radius: 10px; padding: 10px 14px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                            <div>
                                <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: var(--lime); letter-spacing: 0.04em;">Equipment Finished</div>
                                <strong style="font-size: 14px; color: var(--ink);">${escapeHtml(fullName)}</strong>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: var(--muted); letter-spacing: 0.04em;">Duration</div>
                                <strong style="font-size: 14px; color: var(--ink); font-family: monospace;">${formattedDuration}</strong>
                            </div>
                        </div>

                        <form id="quickProgressForm" onsubmit="return false;" style="display: flex; flex-direction: column; gap: 12px;">
                            <div style="display: flex; gap: 12px;">
                                <label style="flex: 1; margin: 0; font-size: 13px; color: var(--muted); font-weight: 600;">
                                    Date *
                                    <input type="date" id="qp-date" value="${todayStr}" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--line); background: var(--panel-soft); color: var(--ink); font-size: 13px;" required>
                                </label>
                                <label style="flex: 1; margin: 0; font-size: 13px; color: var(--muted); font-weight: 600;">
                                    Weight (kg) *
                                    <input type="number" id="qp-weight" step="0.1" min="20" max="300" placeholder="e.g. 72.5" value="${weightVal}" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--line); background: var(--panel-soft); color: var(--ink); font-size: 13px;" required>
                                </label>
                            </div>

                            <div>
                                <label style="margin: 0; font-size: 13px; color: var(--muted); font-weight: 600;">
                                    Body Fat % <span style="font-size: 11px; font-weight: 400; color: var(--muted);">(optional)</span>
                                    <input type="number" id="qp-bodyfat" step="0.1" min="1" max="70" placeholder="e.g. 18.5" value="${bfVal}" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--line); background: var(--panel-soft); color: var(--ink); font-size: 13px;">
                                </label>
                            </div>

                            <div>
                                <label style="margin: 0; font-size: 13px; color: var(--muted); font-weight: 600;">
                                    Workout Notes / Sets &amp; Reps <span style="font-size: 11px; font-weight: 400; color: var(--muted);">(optional)</span>
                                    <textarea id="qp-notes" rows="2" placeholder="e.g., 3 sets of 12 reps at 40kg" class="form-control" style="width: 100%; box-sizing: border-box; margin-top: 4px; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--line); background: var(--panel-soft); color: var(--ink); font-size: 13px; resize: vertical;">${escapeHtml(defaultNotes)}</textarea>
                                </label>
                            </div>

                            <div style="text-align: right; margin-top: 2px;">
                                <a href="index.php?page=progress" style="font-size: 12px; color: var(--lime); text-decoration: none; font-weight: 600;">
                                    Full measurement form (Chest, Waist, Arms) &rarr;
                                </a>
                            </div>
                        </form>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: '<span style="color:var(--lime-btn-text, #090b10);font-weight:800;">Save Progress</span>',
                cancelButtonText: 'Cancel',
                confirmButtonColor: 'var(--lime)',
                cancelButtonColor: '#64748b',
                background: 'var(--panel)',
                color: 'var(--ink)',
                focusConfirm: false,
                showLoaderOnConfirm: true,
                preConfirm: async () => {
                    const dateEl = document.getElementById('qp-date');
                    const weightEl = document.getElementById('qp-weight');
                    const bfEl = document.getElementById('qp-bodyfat');
                    const notesEl = document.getElementById('qp-notes');

                    if (!dateEl || !weightEl) return false;

                    const dateVal = dateEl.value.trim();
                    const weightVal = parseFloat(weightEl.value);

                    if (!dateVal) {
                        Swal.showValidationMessage('Please select a date.');
                        return false;
                    }
                    if (isNaN(weightVal) || weightVal < 20 || weightVal > 300) {
                        Swal.showValidationMessage('Please enter a valid weight between 20 and 300 kg.');
                        return false;
                    }

                    const bfVal = bfEl && bfEl.value.trim() ? parseFloat(bfEl.value) : null;
                    if (bfVal !== null && (isNaN(bfVal) || bfVal < 1 || bfVal > 70)) {
                        Swal.showValidationMessage('Body fat percentage must be between 1% and 70%.');
                        return false;
                    }

                    const notesVal = notesEl ? notesEl.value.trim() : '';

                    try {
                        const fd = new FormData();
                        fd.append('log_date', dateVal);
                        fd.append('weight_kg', weightVal);
                        if (bfVal !== null) fd.append('body_fat_percent', bfVal);
                        if (notesVal) fd.append('notes', notesVal);

                        const res = await memberEquipPost('log_progress', fd);
                        if (!res.success) {
                            Swal.showValidationMessage(res.message || 'Failed to save progress.');
                            return false;
                        }
                        return res;
                    } catch (err) {
                        console.error(err);
                        Swal.showValidationMessage('Network error occurred while saving.');
                        return false;
                    }
                }
            }).then((saveResult) => {
                if (saveResult.isConfirmed && saveResult.value && saveResult.value.success) {
                    if (saveResult.value.weight_kg) {
                        RECENT_WEIGHT = saveResult.value.weight_kg;
                    }
                    if (window.playNotifSound) window.playNotifSound('success');
                    Swal.fire({
                        icon: 'success',
                        title: 'Progress Logged!',
                        text: saveResult.value.message || 'Your progress has been saved.',
                        background: 'var(--panel)',
                        color: 'var(--ink)',
                        confirmButtonColor: 'var(--lime)',
                        timer: 2500,
                        showConfirmButton: false
                    });
                }
            });
        };

        // CONFIRM JOIN QUEUE
        window.confirmJoinQueue = function(equipmentId, equipName, unitNumber, currentQueueCount) {
            const nextPos = currentQueueCount + 1;
            const minWait = Math.max(5, (nextPos - 1) * 15);
            const maxWait = Math.max(10, nextPos * 20);

            Swal.fire({
                title: 'Join Equipment Queue?',
                html: `
                    <p style="margin-bottom: 12px;">You will be added to the queue for <strong>${escapeHtml(equipName)} ${escapeHtml(unitNumber)}</strong>.</p>
                    <div style="background: var(--panel-soft); padding: 14px; border-radius: 8px; border: 1px solid var(--line); text-align: left; font-size: 13px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                            <span style="color: var(--muted);">Your estimated position:</span>
                            <strong style="color: var(--lime);">#${nextPos}</strong>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--muted);">Estimated wait:</span>
                            <span style="color: var(--ink); font-weight: 600;">Approx. ${minWait}–${maxWait} mins</span>
                        </div>
                    </div>
                `,
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: 'Join Queue',
                cancelButtonText: 'Cancel',
                confirmButtonColor: 'var(--lime)',
                cancelButtonColor: '#64748b',
                background: 'var(--panel)',
                color: 'var(--ink)'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    try {
                        const formData = new FormData();
                        formData.append('equipment_id', equipmentId);

                        const data = await memberEquipPost('join_queue', formData);

                        if (data.success) {
                            if (window.playNotifSound) window.playNotifSound('chime');
                            Swal.fire({
                                icon: 'success',
                                title: 'Added to Queue!',
                                text: data.message,
                                background: 'var(--panel)',
                                color: 'var(--ink)',
                                confirmButtonColor: 'var(--lime)'
                            });
                            fetchEquipmentData();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Cannot Join Queue',
                                text: data.message || 'Unable to join queue.',
                                background: 'var(--panel)',
                                color: 'var(--ink)'
                            });
                        }
                    } catch (e) {
                        console.error(e);
                        Swal.fire({
                            icon: 'error',
                            title: 'Network Error',
                            text: 'Failed to communicate with server.',
                            background: 'var(--panel)',
                            color: 'var(--ink)'
                        });
                    }
                }
            });
        };

        // LEAVE QUEUE
        window.leaveEquipmentQueue = function(queueId, equipName) {
            Swal.fire({
                title: 'Leave Queue?',
                text: `Are you sure you want to give up your spot in the queue for ${equipName}?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Leave Queue',
                cancelButtonText: 'Stay in Queue',
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                background: 'var(--panel)',
                color: 'var(--ink)'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    try {
                        const formData = new FormData();
                        formData.append('queue_id', queueId);

                        const data = await memberEquipPost('leave_queue', formData);

                        if (data.success) {
                            Swal.fire({
                                icon: 'info',
                                title: 'Left Queue',
                                text: data.message,
                                background: 'var(--panel)',
                                color: 'var(--ink)',
                                timer: 1500,
                                showConfirmButton: false
                            });
                            fetchEquipmentData();
                        }
                    } catch (e) {
                        console.error(e);
                    }
                }
            });
        };

        // CLAIM EQUIPMENT SESSION
        window.claimEquipmentSession = async function(equipmentId) {
            try {
                const formData = new FormData();
                formData.append('equipment_id', equipmentId);

                const data = await memberEquipPost('claim_session', formData);

                if (data.success) {
                    if (window.playNotifSound) window.playNotifSound('ready');
                    Swal.fire({
                        icon: 'success',
                        title: 'Equipment Claimed!',
                        text: 'Your workout session has started. Great timing!',
                        background: 'var(--panel)',
                        color: 'var(--ink)',
                        confirmButtonColor: 'var(--lime)'
                    });
                    fetchEquipmentData();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Claim Window Expired',
                        text: data.message || 'Could not claim session.',
                        background: 'var(--panel)',
                        color: 'var(--ink)'
                    });
                    fetchEquipmentData();
                }
            } catch (e) {
                console.error(e);
            }
        };

        // Timer interval: updates running session timer and claim countdowns every second
        timerInterval = setInterval(() => {
            if (activeSession) {
                activeSession.elapsed_seconds = (activeSession.elapsed_seconds || 0) + 1;
                const timerEl = document.getElementById('session-timer-display');
                if (timerEl) {
                    timerEl.textContent = formatDuration(activeSession.elapsed_seconds);
                }
            }

            document.querySelectorAll('.claim-countdown').forEach(el => {
                let rem = parseInt(el.getAttribute('data-remain') || '0', 10);
                if (rem > 0) {
                    rem--;
                    el.setAttribute('data-remain', rem);
                    el.textContent = formatDuration(rem);
                } else {
                    el.textContent = 'Expired';
                }
            });
        }, 1000);

        // Polling interval: every 5 seconds
        pollInterval = setInterval(fetchEquipmentData, 5000);

        // Event listeners for filters
        document.querySelectorAll('#status-filters .filter-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('#status-filters .filter-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                activeFilter = this.getAttribute('data-status');
                currentMemberPage = 1;
                renderEquipmentGrid();
            });
        });

        document.getElementById('category-filter').addEventListener('change', function() {
            categoryFilter = this.value;
            currentMemberPage = 1;
            renderEquipmentGrid();
        });

        document.getElementById('equipment-search').addEventListener('input', function() {
            searchQuery = this.value.trim().toLowerCase();
            currentMemberPage = 1;
            renderEquipmentGrid();
        });

        const memberPageSizeSelect = document.getElementById('member-equip-pagesize');
        if (memberPageSizeSelect) {
            memberPageSizeSelect.addEventListener('change', function() {
                changeMemberPageSize(this.value);
            });
        }

        function escapeHtml(text) {
            if (!text) return '';
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return String(text).replace(/[&<>"']/g, m => map[m]);
        }

        fetchEquipmentData();
    })();
    </script>
    <?php
    render_footer();
}
