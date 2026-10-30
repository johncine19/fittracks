<?php
declare(strict_types=1);

function gym_equipment_page(): void
{
    $user = require_roles(['gym_owner', 'admin', 'platform_admin', 'staff']);
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
    $totalDurationSecs = (int)scalar("SELECT COALESCE(SUM(GREATEST(0, TIMESTAMPDIFF(SECOND, start_time, COALESCE(end_time, NOW())))), 0) FROM equipment_sessions WHERE gym_id = $gymId AND session_status = 'completed'");
    $avgDurationMins = $totalSessions > 0 ? (int)round(($totalDurationSecs / $totalSessions) / 60) : 0;
    $totalUsageHours = round($totalDurationSecs / 3600, 1);

    // Most used equipment
    $topUsed = $pdo->query("
        SELECT e.name, e.unit_number, e.category, COUNT(s.session_id) as session_count,
               COALESCE(SUM(GREATEST(0, TIMESTAMPDIFF(SECOND, s.start_time, COALESCE(s.end_time, NOW())))), 0) as total_seconds
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

    <link rel="stylesheet" href="<?= h(asset_url('css/pages/admin_equipment.css')) ?>">

    <div class="equipment-admin-container">
        <!-- Top Title Bar -->
        <div class="equip-header-row">
            <div>
                <p style="margin: 0; color: var(--muted); font-size: 14px;">Manage machines, monitor live occupancy & queues, log maintenance, and review usage metrics.</p>
            </div>

            <div class="equip-header-actions">
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

                <button type="button" onclick="openAddModal()" class="btn btn-add-equip" style="background: var(--lime); color: #fff; font-weight: 700; padding: 10px 22px; border-radius: 8px; border: none; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.15);">
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
            <div class="equip-admin-card equip-analytics-card">
                <div class="equip-analytics-header">
                    <div>
                        <h3 class="equip-analytics-title">Equipment Usage & Analytics</h3>
                        <p class="equip-analytics-sub">Descriptive metrics derived from recorded equipment sessions and queues.</p>
                    </div>
                    <div class="equip-analytics-meta-pills">
                        <span class="equip-meta-pill">
                            Avg. Session: <strong><?= $avgDurationMins ?> mins</strong>
                        </span>
                        <span class="equip-meta-pill equip-meta-pill-accent">
                            Total Time: <strong><?= $totalUsageHours ?> hrs</strong>
                        </span>
                        <span class="equip-meta-pill">
                            Completed: <strong><?= $totalSessions ?> sessions</strong>
                        </span>
                    </div>
                </div>

                <div class="analytics-grid">
                    <!-- Top Used Equipment -->
                    <div class="analytics-subcard">
                        <h4 class="analytics-subcard-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                            Top Used Equipment
                        </h4>
                        <?php if (empty($topUsed)): ?>
                            <div class="analytics-empty">
                                No completed equipment sessions recorded yet.
                            </div>
                        <?php else: ?>
                            <ul class="top-used-list">
                                <?php foreach ($topUsed as $idx => $t): ?>
                                    <li class="top-used-item">
                                        <div class="top-used-info">
                                            <span class="top-used-rank"><?= $idx + 1 ?></span>
                                            <strong class="top-used-name"><?= h($t['name']) ?></strong>
                                            <span class="unit-pill"><?= h($t['unit_number']) ?></span>
                                            <span class="top-used-cat">(<?= h($t['category']) ?>)</span>
                                        </div>
                                        <div class="top-used-stats">
                                            <strong><?= $t['session_count'] ?></strong> <span class="top-used-stat-label">sessions</span>
                                            <span class="top-used-hours">(<?= round((float)$t['total_seconds'] / 3600, 1) ?> hrs)</span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <!-- Queue Demand Summary -->
                    <div class="analytics-subcard">
                        <h4 class="analytics-subcard-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                            Queue Demand & Turnaround
                        </h4>
                        <div class="queue-demand-list">
                            <div class="queue-demand-row">
                                <span>Total Queue Requests Logged:</span>
                                <strong><?= $totalQueueRequests ?></strong>
                            </div>
                            <div class="queue-demand-row">
                                <span>Successfully Claimed via Queue:</span>
                                <strong style="color: #059669;"><?= $claimedQueues ?></strong>
                            </div>
                            <div class="queue-demand-row">
                                <span>Cancelled / Passed Queues:</span>
                                <strong style="color: #dc2626;"><?= $cancelledQueues ?></strong>
                            </div>
                            <div class="queue-demand-row queue-demand-row-total">
                                <span>Claim Conversion Rate:</span>
                                <strong>
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
            <div class="modal-header-wrap" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 id="modal-title" style="margin: 0; font-size: 18px; color: var(--ink);">Add Equipment</h3>
                <button type="button" onclick="closeModal()" style="background: none; border: none; color: var(--muted); font-size: 22px; cursor: pointer; line-height: 1;">&times;</button>
            </div>

            <form id="equipment-form">
                <input type="hidden" name="equipment_id" id="form-equip-id" value="0">

                <div class="equip-form-group">
                    <label class="equip-form-label">Equipment Name *</label>
                    <input type="text" name="name" id="form-name" placeholder="e.g. Treadmill, Bench Press, Leg Press" required
                           class="equip-form-input">
                </div>

                <div class="modal-two-col">
                    <div>
                        <label class="equip-form-label">Category</label>
                        <select name="category" id="form-category" class="equip-form-input">
                            <option value="Machines">Machines</option>
                            <option value="Cardio">Cardio</option>
                            <option value="Strength">Strength</option>
                            <option value="Free Weights">Free Weights</option>
                            <option value="Functional Training">Functional Training</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div>
                        <label class="equip-form-label">Condition</label>
                        <select name="equipment_condition" id="form-condition" class="equip-form-input">
                            <option value="Excellent">Excellent</option>
                            <option value="Good" selected>Good</option>
                            <option value="Fair">Fair</option>
                            <option value="Poor">Poor</option>
                        </select>
                    </div>
                </div>

                <!-- Unit Identifier vs Batch Quantity -->
                <div id="unit-group" class="modal-two-col">
                    <div id="unit-num-container">
                        <label class="equip-form-label">Unit Identifier</label>
                        <input type="text" name="unit_number" id="form-unit-number" placeholder="e.g. #1, Unit A" value="#1"
                               class="equip-form-input">
                    </div>

                    <div id="batch-qty-container">
                        <label class="equip-form-label">Quantity to Create</label>
                        <input type="number" name="quantity" id="form-quantity" min="1" max="50" value="1"
                               class="equip-form-input">
                        <span class="equip-form-hint">Auto-names #1, #2...</span>
                    </div>
                </div>

                <div class="modal-two-col">
                    <div>
                        <label class="equip-form-label">Location / Area</label>
                        <input type="text" name="location_area" id="form-location" placeholder="e.g. Cardio Deck, Floor 2" value="Main Gym Floor"
                               class="equip-form-input">
                    </div>

                    <div>
                        <label class="equip-form-label">Next Maintenance</label>
                        <input type="date" name="next_maintenance_date" id="form-next-maint"
                               class="equip-form-input">
                    </div>
                </div>

                <!-- Image Upload (ImageKit 5MB) and Direct URL -->
                <div class="modal-two-col">
                    <div>
                        <label class="equip-form-label">
                            Upload Image <span class="equip-sub-label">(Max 5MB • ImageKit)</span>
                        </label>
                        <input type="file" name="image_file" id="form-image-file" accept="image/png, image/jpeg, image/webp, image/gif"
                               class="equip-form-input equip-file-input">
                    </div>
                    <div>
                        <label class="equip-form-label">
                            Or Image URL <span class="equip-sub-label">(Direct link)</span>
                        </label>
                        <input type="url" name="image_url" id="form-image-url" placeholder="https://ik.imagekit.io/... or https://..."
                               class="equip-form-input">
                    </div>
                </div>

                <!-- Live Thumbnail Preview -->
                <div id="image-preview-container" style="display: none; align-items: center; gap: 12px; margin-bottom: 12px; padding: 8px 12px; background: var(--panel-soft); border-radius: 8px; border: 1px solid var(--line);">
                    <img id="form-image-preview" src="" alt="Equipment preview" style="width: 44px; height: 44px; object-fit: cover; border-radius: 6px; border: 1px solid var(--line); background: var(--bg);">
                    <div style="flex: 1; min-width: 0;">
                        <div id="image-preview-name" style="font-size: 12px; font-weight: 600; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Equipment Image</div>
                        <div id="image-preview-meta" style="font-size: 11px; color: var(--muted);">Ready for upload</div>
                    </div>
                    <button type="button" onclick="clearImageSelection()" style="background: none; border: none; color: var(--danger); cursor: pointer; font-size: 11px; font-weight: 600; padding: 4px 6px;">Clear</button>
                </div>

                <div class="equip-form-group">
                    <label class="equip-form-label">Description (optional)</label>
                    <textarea name="description" id="form-description" rows="2" placeholder="Notes, brand model, specifications..."
                              class="equip-form-input equip-form-textarea"></textarea>
                </div>

                <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px;">
                    <button type="button" onclick="closeModal()" class="btn" style="background: transparent; color: var(--muted); border: 1px solid var(--line); padding: 8px 18px; border-radius: 8px; cursor: pointer;">
                        Cancel
                    </button>
                    <button type="submit" class="btn" style="background: var(--lime); color: #fff; font-weight: 700; padding: 8px 22px; border-radius: 8px; border: none; cursor: pointer;">
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

    <!-- Admin Equipment Configuration & Script -->
    <script>
    window.ADMIN_EQUIPMENT_CONFIG = {
        gymId: <?= $gymId ?>,
        csrfToken: <?= json_encode(csrf_token()) ?>
    };
    </script>
    <script src="<?= h(asset_url('js/pages/admin_equipment.js')) ?>"></script>
    <?php
    render_footer();
}
