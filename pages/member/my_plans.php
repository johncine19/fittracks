<?php
declare(strict_types=1);

if (!function_exists('plan_icon')) {
    function plan_icon(string $name, int $size = 18, string $extraClass = ''): string
    {
        $c = $extraClass !== '' ? ' ' . $extraClass : '';
        return match ($name) {
            'dumbbell' => '<svg class="plan-svg-icon' . $c . '" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v16"/><path d="M10 4v16"/><path d="M6 12h4"/><path d="M14 4v16"/><path d="M18 4v16"/><path d="M14 12h4"/></svg>',
            'diet', 'nutrition' => '<svg class="plan-svg-icon' . $c . '" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>',
            'facility', 'gym' => '<svg class="plan-svg-icon' . $c . '" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><line x1="9" y1="22" x2="9" y2="22"/><line x1="15" y1="22" x2="15" y2="22"/><line x1="9" y1="6" x2="9" y2="6"/><line x1="15" y1="6" x2="15" y2="6"/><line x1="9" y1="10" x2="9" y2="10"/><line x1="15" y1="10" x2="15" y2="10"/><line x1="9" y1="14" x2="9" y2="14"/><line x1="15" y1="14" x2="15" y2="14"/><line x1="9" y1="18" x2="9" y2="18"/><line x1="15" y1="18" x2="15" y2="18"/></svg>',
            'check', 'success' => '<svg class="plan-svg-icon' . $c . '" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
            'alert', 'warning' => '<svg class="plan-svg-icon' . $c . '" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
            'flame', 'workload' => '<svg class="plan-svg-icon' . $c . '" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 3z"/></svg>',
            'trending', 'volume' => '<svg class="plan-svg-icon' . $c . '" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>',
            'recovery', 'rest' => '<svg class="plan-svg-icon' . $c . '" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>',
            'calendar' => '<svg class="plan-svg-icon' . $c . '" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
            'coach', 'user' => '<svg class="plan-svg-icon' . $c . '" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
            'goal', 'target' => '<svg class="plan-svg-icon' . $c . '" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
            'pause' => '<svg class="plan-svg-icon' . $c . '" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>',
            'play', 'resume' => '<svg class="plan-svg-icon' . $c . '" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="currentColor" stroke="none"><polygon points="6 4 20 12 6 20 6 4"/></svg>',
            'star' => '<svg class="plan-svg-icon' . $c . '" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="currentColor" stroke="currentColor" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
            'arrow-right' => '<svg class="plan-svg-icon' . $c . '" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>',
            default => ''
        };
    }
}

if (!function_exists('plan_initials')) {
    function plan_initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name));
        $initials = '';
        if (!empty($words)) {
            foreach (array_slice($words, 0, 2) as $w) {
                if ($w !== '') {
                    $initials .= strtoupper(mb_substr($w, 0, 1));
                }
            }
        }
        return $initials ?: 'PT';
    }
}

function my_plans_page(): void
{
    $user = require_roles(['member']);
    $userId = (int) $user['user_id'];
    $pdo = db();

    // ----------------------------------------------------
    // POST ACTIONS (Set Preferred Diet, Pause/Resume Plan)
    // ----------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = post('action', '');

        if ($action === 'set_preferred_diet') {
            $planId = (int) post('plan_id', 0);
            if ($planId > 0 && function_exists('set_preferred_dietary_plan')) {
                if (set_preferred_dietary_plan($userId, $planId)) {
                    flash('Marked as your preferred nutrition plan!', 'success');
                } else {
                    flash('Could not update preferred nutrition plan.', 'error');
                }
            }
            redirect('my_plans&tab=diet');
        }

        if ($action === 'toggle_plan_status') {
            $planType = post('plan_type', 'workout');
            $planId = (int) post('plan_id', 0);
            $newStatus = post('new_status', 'active');
            
            // Validate ownership
            $table = ($planType === 'workout') ? 'training_plans' : 'dietary_plans';
            $ownerCheck = (int) scalar("SELECT member_user_id FROM {$table} WHERE plan_id = ?", [$planId]);

            // Members may only pause/resume; lifecycle states are controlled by gym staff.
            if (!in_array($newStatus, ['active', 'paused'], true)) {
                flash('Invalid plan status.', 'error');
                redirect('my_plans&tab=' . ($planType === 'workout' ? 'workouts' : 'diet'));
            }
            // Cannot resume a gym-issued plan once that gym membership has ended.
            if ($newStatus === 'active' && $ownerCheck === $userId) {
                $planGymId = (int) scalar("SELECT gym_id FROM {$table} WHERE plan_id = ? AND trainer_id IS NOT NULL", [$planId]);
                if ($planGymId > 0) {
                    $hadMembership = (bool) scalar('SELECT 1 FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = ? AND mp.gym_id = ? LIMIT 1', [$userId, $planGymId]);
                    $hasActive = (bool) scalar('SELECT 1 FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = ? AND mp.gym_id = ? AND m.status IN ("active","pending") AND m.end_date >= CURDATE() LIMIT 1', [$userId, $planGymId]);
                    if ($hadMembership && !$hasActive) {
                        flash('This plan belongs to a gym where your membership has ended. Renew your membership to resume it.', 'warning');
                        redirect('my_plans&tab=' . ($planType === 'workout' ? 'workouts' : 'diet'));
                    }
                }
            }

            if ($ownerCheck === $userId && function_exists('update_plan_status')) {
                if (update_plan_status($planType, $planId, $newStatus, $userId, 'Member status toggle via My Plans')) {
                    flash('Plan status successfully updated to ' . ucfirst($newStatus) . '.', 'success');
                } else {
                    flash('Unable to update plan status.', 'error');
                }
            } else {
                flash('Unauthorized plan modification.', 'error');
            }
            redirect('my_plans&tab=' . ($planType === 'workout' ? 'workouts' : 'diet'));
        }
    }

    // ----------------------------------------------------
    // GET FILTERS & TAB
    // ----------------------------------------------------
    $activeTab = trim((string)($_GET['tab'] ?? 'workouts'));
    if (!in_array($activeTab, ['workouts', 'diet', 'calendar'], true)) {
        $activeTab = 'workouts';
    }

    $gymFilter = isset($_GET['gym_id']) && (int)$_GET['gym_id'] > 0 ? (int)$_GET['gym_id'] : null;
    $statusFilter = trim((string)($_GET['status'] ?? 'all'));
    if (!in_array($statusFilter, ['all', 'active', 'paused', 'upcoming', 'completed', 'archived'], true)) {
        $statusFilter = 'all';
    }

    // Load data
    $affiliatedGyms = function_exists('get_member_affiliated_gyms') ? get_member_affiliated_gyms($userId) : [];
    $workoutPlans = function_exists('get_member_workout_plans') ? get_member_workout_plans($userId, $gymFilter, $statusFilter) : [];
    $dietaryPlans = function_exists('get_member_dietary_plans') ? get_member_dietary_plans($userId, $gymFilter, $statusFilter) : [];

    // Member-facing: strictly exclude draft plans
    $workoutPlans = array_values(array_filter($workoutPlans, fn($p) => ($p['status'] ?? '') !== 'draft'));
    $dietaryPlans = array_values(array_filter($dietaryPlans, fn($p) => ($p['status'] ?? '') !== 'draft'));

    $workoutConflicts = function_exists('detect_workout_schedule_conflicts') ? detect_workout_schedule_conflicts($userId) : [];
    $dietConflicts = function_exists('detect_dietary_plan_conflicts') ? detect_dietary_plan_conflicts($userId) : ['has_conflict' => false];
    $calendar = function_exists('get_consolidated_workout_calendar') ? get_consolidated_workout_calendar($userId) : [];
    $workloadWarnings = function_exists('detect_workload_warnings') ? detect_workload_warnings($userId) : [];

    // Quick Stats
    $activeWorkoutCount = count(array_filter($workoutPlans, fn($p) => ($p['status'] ?? '') === 'active'));
    $activeDietCount = count(array_filter($dietaryPlans, fn($p) => ($p['status'] ?? '') === 'active'));
    $activeGymCount = count($affiliatedGyms);

    render_header('My Plans', $user);
    ?>
    <link rel="stylesheet" href="assets/css/pages/my_plans.css?v=<?= filemtime(__DIR__ . '/../../assets/css/pages/my_plans.css') ?>">

    <div class="my-plans-container">
        <!-- Hero Header -->
        <div class="plans-hero-header">
            <div class="plans-hero-title-row">
                <div class="plans-hero-title-group">
                    <h1>
                        <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect width="18" height="18" x="3" y="3" rx="2" />
                            <path d="m9 12 2 2 4-4" />
                        </svg>
                        My Training &amp; Nutrition Plans
                    </h1>
                    <p class="plans-hero-subtitle">Unified multi-facility dashboard: manage independent workout routines and diet protocols across all your gym memberships.</p>
                </div>
            </div>

            <!-- Stats Bar -->
            <div class="plans-stat-grid">
                <div class="plans-stat-card">
                    <div class="plans-stat-icon">
                        <?= plan_icon('dumbbell', 20) ?>
                    </div>
                    <div class="plans-stat-info">
                        <span class="plans-stat-value"><?= count($workoutPlans) ?></span>
                        <span class="plans-stat-label">Workout Plans (<?= $activeWorkoutCount ?> Active)</span>
                    </div>
                </div>
                <div class="plans-stat-card">
                    <div class="plans-stat-icon">
                        <?= plan_icon('diet', 20) ?>
                    </div>
                    <div class="plans-stat-info">
                        <span class="plans-stat-value"><?= count($dietaryPlans) ?></span>
                        <span class="plans-stat-label">Diet Plans (<?= $activeDietCount ?> Active)</span>
                    </div>
                </div>
                <div class="plans-stat-card">
                    <div class="plans-stat-icon">
                        <?= plan_icon('facility', 20) ?>
                    </div>
                    <div class="plans-stat-info">
                        <span class="plans-stat-value"><?= $activeGymCount ?></span>
                        <span class="plans-stat-label">Affiliated Facilities</span>
                    </div>
                </div>
                <div class="plans-stat-card">
                    <div class="plans-stat-icon <?= !empty($workoutConflicts) ? 'stat-warn' : 'stat-ok' ?>">
                        <?= !empty($workoutConflicts) ? plan_icon('alert', 20) : plan_icon('check', 20) ?>
                    </div>
                    <div class="plans-stat-info">
                        <span class="plans-stat-value"><?= count($workoutConflicts) ?></span>
                        <span class="plans-stat-label">Schedule Overlaps</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Schedule Conflict Warnings -->
        <?php if (!empty($workoutConflicts)): ?>
            <div class="conflict-alert-card">
                <div class="conflict-alert-icon">
                    <?= plan_icon('alert', 24) ?>
                </div>
                <div class="conflict-alert-content">
                    <h3>Multi-Gym Schedule Overlap Detected</h3>
                    <p>You have active workout routines assigned from different gyms on the same day. Please review the days below to prevent overtraining or double-booking:</p>
                    <div class="conflict-badge-list">
                        <?php foreach ($workoutConflicts as $c): ?>
                            <span class="conflict-badge-pill">
                                <strong><?= h($c['day_name']) ?>:</strong> 
                                <?= implode(' &amp; ', array_map(fn($p) => h($p['gym_name']) . ' (' . h($p['plan_title']) . ')', $c['plans'])) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Diet Conflict Warning -->
        <?php if (!empty($dietConflicts['has_conflict'])): ?>
            <div class="conflict-alert-card" style="border-left-color: #3b82f6; background: color-mix(in srgb, #3b82f6 10%, var(--panel)); border-color: color-mix(in srgb, #3b82f6 30%, transparent);">
                <div class="conflict-alert-icon" style="color: #3b82f6;">
                    <?= plan_icon('diet', 24) ?>
                </div>
                <div class="conflict-alert-content">
                    <h3>Multiple Active Diet Protocols</h3>
                    <p><?= h($dietConflicts['message']) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Workload / Recovery Warnings -->
        <?php if (!empty($workloadWarnings)): ?>
            <div class="conflict-alert-card" id="workload-warning-card" style="border-left-color: #f59e0b; background: color-mix(in srgb, #f59e0b 10%, var(--panel)); border-color: color-mix(in srgb, #f59e0b 30%, transparent);">
                <div class="conflict-alert-icon" style="color: #f59e0b;">
                    <?= plan_icon('flame', 24) ?>
                </div>
                <div class="conflict-alert-content">
                    <h3>Combined Training Load Warning</h3>
                    <p>Your plans from multiple gyms add up. Review these items with your trainers:</p>
                    <div class="conflict-badge-list">
                        <?php foreach ($workloadWarnings as $w): ?>
                            <span class="conflict-badge-pill">
                                <?= $w['type'] === 'volume' ? plan_icon('trending', 14) : plan_icon('recovery', 14) ?> 
                                <?= h($w['message']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Navigation Tabs & Filter Controls -->
        <div class="plans-nav-bar">
            <!-- Desktop Tab List (Hidden on Mobile <= 600px) -->
            <div class="plans-tab-list plans-tab-desktop">
                <a href="index.php?page=my_plans&tab=workouts<?= $gymFilter ? '&gym_id=' . $gymFilter : '' ?><?= $statusFilter !== 'all' ? '&status=' . $statusFilter : '' ?>" 
                   class="plans-tab-btn <?= $activeTab === 'workouts' ? 'active' : '' ?>">
                    <?= plan_icon('dumbbell', 16) ?>
                    <span>Workout Plans</span>
                    <span class="plans-tab-badge"><?= count($workoutPlans) ?></span>
                </a>
                <a href="index.php?page=my_plans&tab=diet<?= $gymFilter ? '&gym_id=' . $gymFilter : '' ?><?= $statusFilter !== 'all' ? '&status=' . $statusFilter : '' ?>" 
                   class="plans-tab-btn <?= $activeTab === 'diet' ? 'active' : '' ?>">
                    <?= plan_icon('diet', 16) ?>
                    <span>Diet Plans</span>
                    <span class="plans-tab-badge"><?= count($dietaryPlans) ?></span>
                </a>
                <a href="index.php?page=my_plans&tab=calendar" 
                   class="plans-tab-btn <?= $activeTab === 'calendar' ? 'active' : '' ?>">
                    <?= plan_icon('calendar', 16) ?>
                    <span>Consolidated Schedule</span>
                    <?php if (!empty($workoutConflicts)): ?>
                        <span class="plans-tab-badge" style="background:#ef4444; color:#fff;"><?= count($workoutConflicts) ?></span>
                    <?php endif; ?>
                </a>
            </div>

            <!-- Mobile Tab Dropdown Selector (Visible on Mobile <= 600px) -->
            <div class="plans-tab-mobile-selector">
                <div class="plans-tab-mobile-select-wrap">
                    <div class="plans-tab-mobile-icon">
                        <?php if ($activeTab === 'workouts'): ?>
                            <?= plan_icon('dumbbell', 18) ?>
                        <?php elseif ($activeTab === 'diet'): ?>
                            <?= plan_icon('diet', 18) ?>
                        <?php else: ?>
                            <?= plan_icon('calendar', 18) ?>
                        <?php endif; ?>
                    </div>
                    <select id="plans-mobile-tab-select" class="plans-tab-mobile-select" aria-label="Select Plan View" onchange="window.location.href=this.value">
                        <option value="index.php?page=my_plans&tab=workouts<?= $gymFilter ? '&gym_id=' . $gymFilter : '' ?><?= $statusFilter !== 'all' ? '&status=' . $statusFilter : '' ?>" <?= $activeTab === 'workouts' ? 'selected' : '' ?>>
                            Workout Plans (<?= count($workoutPlans) ?>)
                        </option>
                        <option value="index.php?page=my_plans&tab=diet<?= $gymFilter ? '&gym_id=' . $gymFilter : '' ?><?= $statusFilter !== 'all' ? '&status=' . $statusFilter : '' ?>" <?= $activeTab === 'diet' ? 'selected' : '' ?>>
                            Diet Plans (<?= count($dietaryPlans) ?>)
                        </option>
                        <option value="index.php?page=my_plans&tab=calendar" <?= $activeTab === 'calendar' ? 'selected' : '' ?>>
                            Consolidated Schedule<?= !empty($workoutConflicts) ? ' (' . count($workoutConflicts) . ' Overlaps)' : '' ?>
                        </option>
                    </select>
                </div>
            </div>

            <?php if ($activeTab !== 'calendar'): ?>
                <div class="plans-filter-group">
                    <form method="get" action="index.php" class="plans-filter-form">
                        <input type="hidden" name="page" value="my_plans">
                        <input type="hidden" name="tab" value="<?= h($activeTab) ?>">

                        <!-- Gym Filter -->
                        <select name="gym_id" class="plans-filter-select" onchange="this.form.submit()">
                            <option value="">All Facilities</option>
                            <?php foreach ($affiliatedGyms as $g): ?>
                                <option value="<?= (int)$g['gym_id'] ?>" <?= $gymFilter === (int)$g['gym_id'] ? 'selected' : '' ?>>
                                    <?= h($g['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Status Filter -->
                        <select name="status" class="plans-filter-select" onchange="this.form.submit()">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Only</option>
                            <option value="paused" <?= $statusFilter === 'paused' ? 'selected' : '' ?>>Paused</option>
                            <option value="upcoming" <?= $statusFilter === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                            <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>Archived</option>
                        </select>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB CONTENT: WORKOUT PLANS -->
        <?php if ($activeTab === 'workouts'): ?>
            <?php if (empty($workoutPlans)): ?>
                <div class="plans-empty-state">
                    <div class="plans-empty-icon">
                        <?= plan_icon('dumbbell', 32) ?>
                    </div>
                    <h3>No Workout Plans Found</h3>
                    <p>There are no workout plans matching your current filter. Your trainers or gym owners can assign routines, or you can start a self-guided routine.</p>
                    <a href="index.php?page=my_workout" class="plan-btn-primary">
                        <span>Go to Workout Tracker</span>
                        <?= plan_icon('arrow-right', 14) ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="plans-grid">
                    <?php foreach ($workoutPlans as $plan): ?>
                        <?php 
                            $status = $plan['status'] ?? 'active';
                            $isActive = ($status === 'active');
                            $gymName = !empty($plan['gym_name']) ? (string)$plan['gym_name'] : 'Personal / Independent';
                            $trainerDisplay = !empty($plan['trainer_name']) ? (string)$plan['trainer_name'] : 'Personal Trainer';
                            $brandColor = !empty($plan['gym_brand_color']) ? (string)$plan['gym_brand_color'] : 'var(--lime)';
                            $daysCount = (int)($plan['workout_days_count'] ?? 0);
                            $exerciseCount = (int)($plan['exercise_count'] ?? 0);
                            $goalDisplay = !empty($plan['goal']) ? ucwords(str_replace('_', ' ', (string)$plan['goal'])) : '';
                        ?>
                        <div class="plan-card <?= $isActive ? 'is-active-plan' : '' ?> plan-card-status-<?= h($status) ?>">
                            <div class="plan-card-body">
                                <div class="plan-card-header">
                                    <div class="plan-gym-tag" title="<?= h($gymName) ?>">
                                        <span class="plan-gym-dot" style="background: <?= h($brandColor) ?>; box-shadow: 0 0 8px <?= h($brandColor) ?>;"></span>
                                        <span class="plan-gym-name"><?= h($gymName) ?></span>
                                    </div>
                                    <div class="plan-badge-cluster">
                                        <span class="plan-status-badge status-<?= h($status) ?>">
                                            <?php if ($isActive): ?>
                                                <span class="plan-pulse-dot"></span>
                                            <?php endif; ?>
                                            <?= h($status) ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="plan-title-block">
                                    <h2><?= h(ucwords((string)$plan['title'])) ?></h2>
                                    <div class="plan-meta-row">
                                        <div class="plan-coach-chip" title="Assigned Coach">
                                            <span class="plan-coach-avatar"><?= plan_initials($trainerDisplay) ?></span>
                                            <span class="plan-coach-name">Coach <?= h($trainerDisplay) ?></span>
                                        </div>
                                        <?php if ($goalDisplay !== ''): ?>
                                            <div class="plan-goal-chip">
                                                <?= plan_icon('goal', 13) ?>
                                                <span><?= h($goalDisplay) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="plan-hud-grid">
                                    <div class="plan-hud-stat">
                                        <div class="plan-hud-stat-header">
                                            <span class="plan-hud-stat-icon"><?= plan_icon('calendar', 14) ?></span>
                                            <span class="plan-hud-stat-lbl">Frequency</span>
                                        </div>
                                        <div class="plan-hud-stat-num">
                                            <?= $daysCount ?>
                                            <span class="plan-hud-stat-unit">Days/Wk</span>
                                        </div>
                                        <div class="plan-hud-dow-pills">
                                            <?php for ($d = 1; $d <= 7; $d++): ?>
                                                <span class="plan-dow-dot <?= $d <= $daysCount ? 'is-filled' : '' ?>" title="Day <?= $d ?>"></span>
                                            <?php endfor; ?>
                                        </div>
                                    </div>

                                    <div class="plan-hud-stat">
                                        <div class="plan-hud-stat-header">
                                            <span class="plan-hud-stat-icon"><?= plan_icon('dumbbell', 14) ?></span>
                                            <span class="plan-hud-stat-lbl">Volume</span>
                                        </div>
                                        <div class="plan-hud-stat-num">
                                            <?= $exerciseCount ?>
                                            <span class="plan-hud-stat-unit">Exercises</span>
                                        </div>
                                        <div class="plan-hud-subtext">Total program volume</div>
                                    </div>
                                </div>

                                <?php if (!empty($plan['start_date']) && !empty($plan['end_date'])): ?>
                                    <div class="plan-timeline-strip">
                                        <span class="plan-timeline-icon"><?= plan_icon('calendar', 13) ?></span>
                                        <span class="plan-timeline-dates">
                                            <?= date('M d, Y', strtotime((string)$plan['start_date'])) ?> – <?= date('M d, Y', strtotime((string)$plan['end_date'])) ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="plan-timeline-strip plan-timeline-ongoing">
                                        <span class="plan-timeline-icon"><?= plan_icon('calendar', 13) ?></span>
                                        <span class="plan-timeline-dates">Ongoing / Self-Paced Schedule</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="plan-card-footer">
                                <?php if ($status === 'completed'): ?>
                                    <div class="plan-completed-state-pill" title="This training program has concluded">
                                        <?= plan_icon('check', 14) ?>
                                        <span>Routine Completed</span>
                                    </div>
                                <?php else: ?>
                                    <a href="index.php?page=my_workout&plan_id=<?= (int)$plan['plan_id'] ?>" class="plan-btn-primary">
                                        <span>Open Routine</span>
                                        <?= plan_icon('arrow-right', 14) ?>
                                    </a>
                                <?php endif; ?>

                                <div class="plan-card-actions">
                                    <form method="post" action="index.php?page=my_plans" style="margin: 0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_plan_status">
                                        <input type="hidden" name="plan_type" value="workout">
                                        <input type="hidden" name="plan_id" value="<?= (int)$plan['plan_id'] ?>">
                                        <?php if ($status === 'active'): ?>
                                            <input type="hidden" name="new_status" value="paused">
                                            <button type="submit" class="plan-btn-secondary" title="Pause this routine">
                                                <?= plan_icon('pause', 13) ?>
                                                <span>Pause</span>
                                            </button>
                                        <?php elseif ($status === 'paused'): ?>
                                            <input type="hidden" name="new_status" value="active">
                                            <button type="submit" class="plan-btn-secondary" title="Resume routine">
                                                <?= plan_icon('play', 13) ?>
                                                <span>Resume</span>
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <!-- TAB CONTENT: DIET PLANS -->
        <?php elseif ($activeTab === 'diet'): ?>
            <?php if (empty($dietaryPlans)): ?>
                <div class="plans-empty-state">
                    <div class="plans-empty-icon">
                        <?= plan_icon('diet', 32) ?>
                    </div>
                    <h3>No Dietary Plans Found</h3>
                    <p>There are no diet plans matching your current filter. You can auto-generate a tailored plan or ask your coach for a nutritional protocol.</p>
                    <a href="index.php?page=diet" class="plan-btn-primary">
                        <span>Go to Diet Tracker</span>
                        <?= plan_icon('arrow-right', 14) ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="plans-grid">
                    <?php foreach ($dietaryPlans as $plan): ?>
                        <?php 
                            $status = $plan['status'] ?? 'active';
                            $isActive = ($status === 'active');
                            $isPreferred = !empty($plan['is_preferred']);
                            $gymName = !empty($plan['gym_name']) ? (string)$plan['gym_name'] : 'Personal / Independent';
                            $trainerDisplay = !empty($plan['trainer_name']) ? (string)$plan['trainer_name'] : 'Nutrition Coach / System';
                            $brandColor = !empty($plan['gym_brand_color']) ? (string)$plan['gym_brand_color'] : '#10b981';
                            $calories = (int)($plan['avg_daily_calories'] ?? 0);
                            $mealDays = (int)($plan['meal_days_count'] ?? 0);
                            $totalMeals = (int)($plan['total_meals_count'] ?? 0);
                            $goalDisplay = !empty($plan['goal']) ? ucwords(str_replace('_', ' ', (string)$plan['goal'])) : '';
                        ?>
                        <div class="plan-card <?= $isActive ? 'is-active-plan' : '' ?> plan-card-status-<?= h($status) ?>">
                            <div class="plan-card-body">
                                <div class="plan-card-header">
                                    <div class="plan-gym-tag" title="<?= h($gymName) ?>">
                                        <span class="plan-gym-dot" style="background: <?= h($brandColor) ?>; box-shadow: 0 0 8px <?= h($brandColor) ?>;"></span>
                                        <span class="plan-gym-name"><?= h($gymName) ?></span>
                                    </div>
                                    <div class="plan-badge-cluster">
                                        <?php if ($isPreferred): ?>
                                            <span class="plan-preferred-badge">
                                                <?= plan_icon('star', 12) ?>
                                                <span>Preferred</span>
                                            </span>
                                        <?php endif; ?>
                                        <span class="plan-status-badge status-<?= h($status) ?>">
                                            <?php if ($isActive): ?>
                                                <span class="plan-pulse-dot" style="background: #10b981;"></span>
                                            <?php endif; ?>
                                            <?= h($status) ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="plan-title-block">
                                    <h2><?= h(ucwords((string)$plan['title'])) ?></h2>
                                    <div class="plan-meta-row">
                                        <div class="plan-coach-chip" title="Nutrition Specialist">
                                            <span class="plan-coach-avatar" style="background: color-mix(in srgb, #10b981 18%, transparent); color: #10b981;"><?= plan_initials($trainerDisplay) ?></span>
                                            <span class="plan-coach-name"><?= h($trainerDisplay) ?></span>
                                        </div>
                                        <?php if ($goalDisplay !== ''): ?>
                                            <div class="plan-goal-chip">
                                                <?= plan_icon('goal', 13) ?>
                                                <span><?= h($goalDisplay) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="plan-hud-grid">
                                    <div class="plan-hud-stat">
                                        <div class="plan-hud-stat-header">
                                            <span class="plan-hud-stat-icon" style="color: #f59e0b;"><?= plan_icon('flame', 14) ?></span>
                                            <span class="plan-hud-stat-lbl">Caloric Target</span>
                                        </div>
                                        <div class="plan-hud-stat-num">
                                            <?= $calories > 0 ? number_format($calories) : 'Custom' ?>
                                            <span class="plan-hud-stat-unit">kcal/day</span>
                                        </div>
                                        <div class="plan-hud-subtext">Estimated energy intake</div>
                                    </div>

                                    <div class="plan-hud-stat">
                                        <div class="plan-hud-stat-header">
                                            <span class="plan-hud-stat-icon" style="color: #10b981;"><?= plan_icon('diet', 14) ?></span>
                                            <span class="plan-hud-stat-lbl">Meal Protocol</span>
                                        </div>
                                        <div class="plan-hud-stat-num">
                                            <?= $totalMeals ?>
                                            <span class="plan-hud-stat-unit">Meals</span>
                                        </div>
                                        <div class="plan-hud-subtext"><?= $mealDays ?> Days Programmed</div>
                                    </div>
                                </div>

                                <?php if (!empty($plan['start_date']) && !empty($plan['end_date'])): ?>
                                    <div class="plan-timeline-strip">
                                        <span class="plan-timeline-icon"><?= plan_icon('calendar', 13) ?></span>
                                        <span class="plan-timeline-dates">
                                            <?= date('M d, Y', strtotime((string)$plan['start_date'])) ?> – <?= date('M d, Y', strtotime((string)$plan['end_date'])) ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="plan-timeline-strip plan-timeline-ongoing">
                                        <span class="plan-timeline-icon"><?= plan_icon('diet', 13) ?></span>
                                        <span class="plan-timeline-dates">Continuous Nutrition Protocol</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="plan-card-footer">
                                <?php if ($status === 'completed'): ?>
                                    <div class="plan-completed-state-pill" title="This nutrition protocol has concluded">
                                        <?= plan_icon('check', 14) ?>
                                        <span>Plan Completed</span>
                                    </div>
                                <?php else: ?>
                                    <a href="index.php?page=diet&plan_id=<?= (int)$plan['plan_id'] ?>" class="plan-btn-primary">
                                        <span>Open Diet</span>
                                        <?= plan_icon('arrow-right', 14) ?>
                                    </a>
                                <?php endif; ?>

                                <div class="plan-card-actions">
                                    <?php if ($isActive && !$isPreferred): ?>
                                        <form method="post" action="index.php?page=my_plans" style="margin: 0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="set_preferred_diet">
                                            <input type="hidden" name="plan_id" value="<?= (int)$plan['plan_id'] ?>">
                                            <button type="submit" class="plan-btn-preferred" title="Set this as your primary daily nutrition guide">
                                                <?= plan_icon('star', 12) ?>
                                                <span>Set Preferred</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="post" action="index.php?page=my_plans" style="margin: 0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_plan_status">
                                        <input type="hidden" name="plan_type" value="diet">
                                        <input type="hidden" name="plan_id" value="<?= (int)$plan['plan_id'] ?>">
                                        <?php if ($status === 'active'): ?>
                                            <input type="hidden" name="new_status" value="paused">
                                            <button type="submit" class="plan-btn-secondary" title="Pause nutrition plan">
                                                <?= plan_icon('pause', 13) ?>
                                                <span>Pause</span>
                                            </button>
                                        <?php elseif ($status === 'paused'): ?>
                                            <input type="hidden" name="new_status" value="active">
                                            <button type="submit" class="plan-btn-secondary" title="Resume nutrition plan">
                                                <?= plan_icon('play', 13) ?>
                                                <span>Resume</span>
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <!-- TAB CONTENT: CONSOLIDATED CALENDAR -->
        <?php elseif ($activeTab === 'calendar'): ?>
            <?php
                $conflictDayNumbers = array_column($workoutConflicts, 'day_of_week');
                $currentDow = (int)date('N'); // 1 = Monday ... 7 = Sunday
                $totalWeeklyWorkouts = 0;
                $totalWeeklyExercises = 0;
                $activeDaysCount = 0;
                foreach ($calendar as $d) {
                    $pCount = count($d['plans'] ?? []);
                    if ($pCount > 0) {
                        $activeDaysCount++;
                        $totalWeeklyWorkouts += $pCount;
                        foreach ($d['plans'] as $p) {
                            $totalWeeklyExercises += count($p['exercises'] ?? []);
                        }
                    }
                }
                $restDaysCount = 7 - $activeDaysCount;
            ?>
            <div class="calendar-view-panel">
                <div class="calendar-header-row">
                    <div class="calendar-header-titles">
                        <h2>
                            <?= plan_icon('calendar', 22) ?>
                            <span>Consolidated Multi-Gym Weekly Schedule</span>
                        </h2>
                        <p class="calendar-header-subtitle">
                            <?php if ($activeGymCount > 0): ?>
                                Aggregating active routines across your <?= $activeGymCount ?> affiliated <?= $activeGymCount === 1 ? 'facility' : 'facilities' ?><?= $activeDaysCount > 0 ? ' &amp; personal workouts' : '' ?>.
                            <?php else: ?>
                                Aggregating all scheduled personal &amp; independent training routines.
                            <?php endif; ?>
                        </p>
                    </div>

                    <!-- Weekly Split Summary Pills -->
                    <div class="calendar-summary-pills">
                        <div class="calendar-summary-pill" title="Training Days">
                            <span class="calendar-summary-dot" style="background: var(--lime);"></span>
                            <strong><?= $activeDaysCount ?></strong> Training Days
                        </div>
                        <div class="calendar-summary-pill" title="Rest & Recovery Days">
                            <span class="calendar-summary-dot" style="background: #94a3b8;"></span>
                            <strong><?= $restDaysCount ?></strong> Rest Days
                        </div>
                        <div class="calendar-summary-pill" title="Total Weekly Programmed Movements">
                            <?= plan_icon('dumbbell', 13) ?>
                            <strong><?= $totalWeeklyExercises ?></strong> Exercises
                        </div>
                        <?php if (!empty($workoutConflicts)): ?>
                            <div class="calendar-summary-pill is-conflict" title="Schedule Conflicts Detected">
                                <?= plan_icon('alert', 13) ?>
                                <strong><?= count($workoutConflicts) ?></strong> Overlap<?= count($workoutConflicts) === 1 ? '' : 's' ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 7-Day Athletic Grid with Scroll Wrapper -->
                <div class="calendar-grid-wrap">
                    <div class="calendar-grid">
                        <?php foreach ($calendar as $dow => $dayData): ?>
                            <?php 
                                $hasConflict = in_array($dow, $conflictDayNumbers, true);
                                $isToday = ($dow === $currentDow);
                                $plansForDay = $dayData['plans'] ?? [];
                                $isRest = empty($plansForDay);
                                $shortName = $dayData['short'] ?? substr($dayData['name'], 0, 3);
                            ?>
                            <div class="calendar-day-col <?= $isToday ? 'is-today' : '' ?> <?= $hasConflict ? 'has-conflict' : '' ?> <?= $isRest ? 'is-rest-day' : '' ?>">
                                <div class="calendar-day-header">
                                    <div class="calendar-day-title-group">
                                        <span class="calendar-day-short"><?= h($shortName) ?></span>
                                        <span class="calendar-day-fullname"><?= h($dayData['name']) ?></span>
                                    </div>
                                    <div class="calendar-day-badges">
                                        <?php if ($isToday): ?>
                                            <span class="calendar-today-badge">Today</span>
                                        <?php endif; ?>
                                        <?php if ($hasConflict): ?>
                                            <span class="calendar-day-conflict-badge" title="Multiple gym routines scheduled on this day">
                                                <?= plan_icon('alert', 11) ?>
                                                <span>Overlap</span>
                                            </span>
                                        <?php elseif (!$isRest): ?>
                                            <span class="calendar-routine-count-badge">
                                                <?= count($plansForDay) ?> <?= count($plansForDay) === 1 ? 'routine' : 'routines' ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="calendar-day-body">
                                    <?php if ($isRest): ?>
                                        <div class="calendar-rest-card">
                                            <div class="calendar-rest-icon">
                                                <?= plan_icon('recovery', 22) ?>
                                            </div>
                                            <div class="calendar-rest-title">Rest &amp; Recovery</div>
                                            <div class="calendar-rest-sub">No workout scheduled</div>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($plansForDay as $p): ?>
                                            <?php 
                                                $brandColor = !empty($p['gym_brand_color']) ? (string)$p['gym_brand_color'] : 'var(--lime)';
                                                $exercises = $p['exercises'] ?? [];
                                                $previewExercises = array_slice($exercises, 0, 4);
                                                $moreCount = count($exercises) - count($previewExercises);
                                            ?>
                                            <div class="calendar-routine-card" style="--gym-accent: <?= h($brandColor) ?>;">
                                                <div class="calendar-routine-meta">
                                                    <span class="calendar-routine-gym-dot"></span>
                                                    <span class="calendar-routine-gym-name" title="<?= h($p['gym_name']) ?>"><?= h($p['gym_name']) ?></span>
                                                </div>

                                                <h4 class="calendar-routine-title"><?= h(ucwords((string)$p['plan_title'])) ?></h4>

                                                <div class="calendar-exercise-list">
                                                    <?php foreach ($previewExercises as $ex): ?>
                                                        <div class="calendar-exercise-row">
                                                            <span class="calendar-exercise-name" title="<?= h($ex['name']) ?>">
                                                                <?= h($ex['name']) ?>
                                                            </span>
                                                            <span class="calendar-exercise-reps">
                                                                <?= h($ex['sets']) ?>×<?= h($ex['reps']) ?>
                                                            </span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <?php if ($moreCount > 0): ?>
                                                        <div class="calendar-exercise-more">
                                                            +<?= $moreCount ?> more exercise<?= $moreCount === 1 ? '' : 's' ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
    render_footer();
}
