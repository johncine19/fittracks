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
    $isCheckedIn = (bool)scalar('SELECT attendance_id FROM attendance WHERE user_id = ? AND gym_id = ? AND check_out_time IS NULL AND check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY LIMIT 1', [$userId, $gymId]);

    $lastLogStmt = $pdo->prepare('SELECT weight_kg, body_fat_percent, waist_cm, chest_cm, arm_cm FROM progress_logs WHERE user_id = ? ORDER BY log_date DESC, log_id DESC LIMIT 1');
    $lastLogStmt->execute([$userId]);
    $lastLog = $lastLogStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $recentWeight = $lastLog['weight_kg'] ?? scalar('SELECT weight_kg FROM member_profiles WHERE user_id = ?', [$userId]);
    $recentBf = $lastLog['body_fat_percent'] ?? null;
    $recentWaist = $lastLog['waist_cm'] ?? scalar('SELECT waist_cm FROM member_profiles WHERE user_id = ?', [$userId]);
    $recentChest = $lastLog['chest_cm'] ?? null;
    $recentArm = $lastLog['arm_cm'] ?? null;
?>

    <link rel="stylesheet" href="<?= h(asset_url('css/pages/member_equipment.css')) ?>">

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

        <!-- CHECK-IN STATUS BANNER (shown when not checked into the gym) -->
        <div id="checkin-gate-alert" style="<?= $isCheckedIn ? 'display: none;' : 'display: flex;' ?> align-items: center; justify-content: space-between; gap: 14px; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; background: rgba(245, 158, 11, 0.2); color: #d97706; flex-shrink: 0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </span>
                <div>
                    <h4 style="margin: 0 0 2px; font-size: 14px; color: var(--ink);">Browsing in Remote Mode</h4>
                    <p style="margin: 0; font-size: 12.5px; color: var(--muted); line-height: 1.4;">
                        You are viewing live availability from outside <strong><?= h($gymName) ?></strong>. Check in with your QR code at the entrance to use machines or join waitlists.
                    </p>
                </div>
            </div>
            <a href="index.php?page=qr_attendance" class="btn btn-lime" style="padding: 8px 16px; border-radius: 8px; font-size: 13px; text-decoration: none; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Check In via QR
            </a>
        </div>

        <!-- ACTIVE SESSION BANNER (Rendered dynamically) -->
        <div id="active-session-wrapper" style="display: none; margin-bottom: 24px;"></div>

        <!-- MY QUEUES SECTION (Rendered dynamically) -->
        <div id="my-queues-wrapper" style="display: none; margin-bottom: 24px;"></div>

        <!-- SEARCH & FILTER TOOLBAR -->
        <div class="panel equip-filter-panel" style="padding: 16px 20px; border-radius: 12px; margin-bottom: 24px; background: var(--panel); border: 1px solid var(--line); box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
            <div class="equip-filter-row" style="display: flex; flex-wrap: wrap; gap: 14px; align-items: center; justify-content: space-between;">
                <!-- Search Input -->
                <div class="equip-search-wrap" style="flex: 1 1 260px; max-width: 400px; position: relative;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); pointer-events: none;">
                        <circle cx="11" cy="11" r="8" />
                        <line x1="21" y1="21" x2="16.65" y2="16.65" />
                    </svg>
                    <input type="text" id="equipment-search" placeholder="Search by name, category, or area..."
                        style="width: 100%; padding: 10px 12px 10px 38px; border-radius: 8px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 13px; box-sizing: border-box; outline: none;">
                </div>

                <!-- Status Filter Pills (Desktop/Tablet) -->
                <div class="status-filters-pills" id="status-filters">
                    <button type="button" class="filter-btn active" data-status="all">All</button>
                    <button type="button" class="filter-btn" data-status="available">Available</button>
                    <button type="button" class="filter-btn" data-status="in_use">In Use</button>
                    <button type="button" class="filter-btn" data-status="maintenance">Maintenance</button>
                </div>

                <!-- Status Filter Dropdown (Mobile) -->
                <div class="status-filters-select-wrap">
                    <select id="status-filter-select" style="width: 100%; padding: 9px 12px; border-radius: 8px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 13px; box-sizing: border-box; cursor: pointer;">
                        <option value="all">Status: All</option>
                        <option value="available">Status: Available</option>
                        <option value="in_use">Status: In Use</option>
                        <option value="maintenance">Status: Maintenance</option>
                    </select>
                </div>

                <!-- Category Filter -->
                <div class="equip-category-wrap" style="flex: 0 0 auto;">
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
                <div class="equip-sound-wrap" style="flex: 0 0 auto;">
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
                <circle cx="11" cy="11" r="8" />
                <line x1="21" y1="21" x2="16.65" y2="16.65" />
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

    <!-- Member Equipment Configuration & Script -->
    <script>
    window.MEMBER_EQUIPMENT_CONFIG = {
        userId: <?= $userId ?>,
        gymId: <?= $gymId ?>,
        csrfToken: <?= json_encode(csrf_token()) ?>,
        recentWeight: <?= json_encode($recentWeight !== null && $recentWeight !== false ? (float)$recentWeight : null) ?>,
        recentBf: <?= json_encode($recentBf !== null && $recentBf !== false ? (float)$recentBf : null) ?>,
        recentWaist: <?= json_encode($recentWaist !== null && $recentWaist !== false ? (float)$recentWaist : null) ?>,
        recentChest: <?= json_encode($recentChest !== null && $recentChest !== false ? (float)$recentChest : null) ?>,
        recentArm: <?= json_encode($recentArm !== null && $recentArm !== false ? (float)$recentArm : null) ?>,
        isCheckedIn: <?= json_encode($isCheckedIn) ?>,
        gymName: <?= json_encode($gymName) ?>
    };
    </script>
    <script src="<?= h(asset_url('js/pages/member_equipment.js')) ?>"></script>
<?php
    render_footer();
}
