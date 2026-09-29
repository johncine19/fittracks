<?php
declare(strict_types=1);

// Helper: count active training plans created by a trainer.
function trainer_plan_count(int $coachId): int
{
    return (int) scalar('SELECT COUNT(*) FROM training_plans WHERE trainer_id = ? AND status = "active"', [$coachId]);
}

// Helper: ensure a trainer_profiles row exists for the given user and return trainer_id.

function trainer_members_page(): void
{
    $user = require_roles(['trainer']);
    $coachId = ensure_coach_profile((int) $user['user_id']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (post('action') === 'workout') {
            $memberUserId = (int) post('member_user_id');
            generate_workout_plan($memberUserId, $coachId);
            $trainerName = $user['first_name'] . ' ' . $user['last_name'];
            notify_user($memberUserId, 'system', 'New workout plan', $trainerName . ' generated a personalised workout plan for you.');
            flash('Workout plan generated for client.');
        } elseif (post('action') === 'accept_appointment') {
            $assignmentId = (int) post('assignment_id');
            db()->prepare('UPDATE trainer_assignments SET status = "active" WHERE assignment_id = ?')->execute([$assignmentId]);
            $stmt = db()->prepare('SELECT member_user_id, assigned_by FROM trainer_assignments WHERE assignment_id = ?');
            $stmt->execute([$assignmentId]);
            $assignment = $stmt->fetch();
            if ($assignment) {
                notify_user((int)$assignment['member_user_id'], 'system', 'Appointment Accepted', 'Your trainer appointment request was accepted by the trainer.');
                if ($assignment['assigned_by']) {
                    notify_user((int)$assignment['assigned_by'], 'system', 'Appointment Accepted', 'Trainer ' . $user['first_name'] . ' accepted the appointment request.');
                }
                grant_retroactive_commission((int)$assignment['member_user_id']);
            }
            flash('Appointment accepted.');
        } elseif (post('action') === 'reject_appointment') {
            $assignmentId = (int) post('assignment_id');
            $reason = post('rejection_reason');
            db()->prepare('UPDATE trainer_assignments SET status = "rejected", rejection_reason = ? WHERE assignment_id = ?')->execute([$reason, $assignmentId]);
            $stmt = db()->prepare('SELECT member_user_id, assigned_by FROM trainer_assignments WHERE assignment_id = ?');
            $stmt->execute([$assignmentId]);
            $assignment = $stmt->fetch();
            if ($assignment) {
                $reasonText = $reason ? ' Reason: ' . $reason : '';
                notify_user((int)$assignment['member_user_id'], 'system', 'Appointment Rejected', 'Your trainer appointment request was rejected by the trainer.' . $reasonText);
                
                // Notify all admins (or just the one who assigned if available)
                $admins = query_all('SELECT user_id FROM users WHERE role = "admin" AND status = "active"');
                foreach ($admins as $admin) {
                    notify_user((int)$admin['user_id'], 'system', 'Appointment Rejected', 'Trainer ' . $user['first_name'] . ' rejected the appointment request.' . $reasonText);
                }
            }
            flash('Appointment rejected.');
        }
        redirect('trainer_members');
    }
    $members = query_all('SELECT ca.*, u.first_name, u.last_name, u.email, u.profile_picture, 
        mp.weight_kg, mp.height_cm, mp.primary_goal, mp.target_weight_kg,
        (SELECT title FROM training_plans WHERE member_user_id = ca.member_user_id AND status = "active" ORDER BY plan_id DESC LIMIT 1) AS active_plan_title,
        (SELECT plan_id FROM training_plans WHERE member_user_id = ca.member_user_id AND status = "active" ORDER BY plan_id DESC LIMIT 1) AS active_plan_id,
        (SELECT COUNT(*) FROM progress_logs WHERE user_id = ca.member_user_id) AS logs_count
    FROM trainer_assignments ca 
    JOIN users u ON u.user_id = ca.member_user_id 
    LEFT JOIN member_profiles mp ON mp.user_id = u.user_id 
    WHERE ca.trainer_id = ? AND ca.status = "active" 
    ORDER BY ca.assignment_id DESC', [$coachId]);

    $pending_requests = query_all('SELECT ca.*, u.first_name, u.last_name, u.email, u.profile_picture, 
        mp.weight_kg, mp.height_cm, mp.primary_goal 
    FROM trainer_assignments ca 
    JOIN users u ON u.user_id = ca.member_user_id 
    LEFT JOIN member_profiles mp ON mp.user_id = u.user_id 
    WHERE ca.trainer_id = ? AND ca.status = "pending_trainer" 
    ORDER BY ca.assignment_id DESC', [$coachId]);
    
    render_header('Clients', $user);
    
    // KPI Stats calculation
    $totalClients = count($members);
    $withPlanCount = count(array_filter($members, fn($m) => !empty($m['active_plan_title'])));
    $needsPlanCount = $totalClients - $withPlanCount;
    $pendingCount = count($pending_requests);
    $totalLogs = array_sum(array_column($members, 'logs_count'));
    ?>

    <style>
    /* Scoped Trainer Clients Styling - Cohesive & Mature Palette */
    .tm-container {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    /* Page Header */
    .tm-header-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }
    .tm-header-title {
        margin: 0;
        font-size: 1.55rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: var(--ink);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .tm-header-subtitle {
        margin: 4px 0 0 0;
        font-size: 0.92rem;
        color: var(--muted);
    }

    /* KPI Metrics Bar - Neutral & Unified */
    .tm-kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 1rem;
    }
    .tm-kpi-card {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 14px;
        padding: 1.15rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 14px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
        transition: transform 0.2s ease, border-color 0.2s ease;
    }
    .tm-kpi-card:hover {
        transform: translateY(-2px);
        border-color: var(--muted);
    }
    .tm-kpi-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        background: color-mix(in srgb, var(--surface) 60%, var(--bg));
        border: 1px solid var(--line);
        color: var(--muted);
        transition: color 0.2s ease, border-color 0.2s ease;
    }
    .tm-kpi-card:hover .tm-kpi-icon {
        color: var(--ink);
        border-color: var(--muted);
    }
    .tm-kpi-num {
        font-size: 1.6rem;
        font-weight: 800;
        line-height: 1.1;
        color: var(--ink);
    }
    .tm-kpi-label {
        font-size: 0.78rem;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 600;
        margin-top: 3px;
    }

    /* Search & Filter Toolbar */
    .tm-toolbar {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 14px;
        padding: 0.85rem 1.2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }
    .tm-search-box {
        position: relative;
        flex: 1;
        min-width: 240px;
        max-width: 450px;
    }
    .tm-search-box svg {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--muted);
        pointer-events: none;
    }
    .tm-search-input {
        width: 100%;
        padding: 9px 12px 9px 38px;
        background: color-mix(in srgb, var(--surface) 60%, var(--bg));
        border: 1px solid var(--line);
        border-radius: 9px;
        color: var(--ink);
        font-size: 0.9rem;
        transition: all 0.2s ease;
        box-sizing: border-box;
    }
    .tm-search-input:focus {
        outline: none;
        border-color: var(--muted);
        box-shadow: 0 0 0 3px rgba(100, 116, 139, 0.12);
    }
    .tm-filters {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }
    .tm-filter-btn {
        background: color-mix(in srgb, var(--surface) 50%, var(--bg));
        border: 1px solid var(--line);
        color: var(--muted);
        font-size: 0.82rem;
        font-weight: 600;
        padding: 7px 14px;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .tm-filter-btn:hover {
        color: var(--ink);
        border-color: var(--muted);
    }
    .tm-filter-btn.active {
        background: var(--ink);
        border-color: var(--ink);
        color: var(--bg);
        font-weight: 700;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }
    .tm-count-badge {
        font-size: 0.82rem;
        color: var(--muted);
        font-weight: 500;
    }

    /* Pending Appointments Banner */
    .tm-pending-section {
        background: color-mix(in srgb, var(--surface) 80%, var(--bg));
        border: 1px solid var(--line);
        border-left: 4px solid var(--muted);
        border-radius: 14px;
        padding: 1.25rem;
    }
    .tm-pending-title {
        margin: 0 0 1rem 0;
        font-size: 1.05rem;
        font-weight: 700;
        color: var(--ink);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* Cards Grid */
    .tm-clients-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
        gap: 1.25rem;
    }
    @media (max-width: 480px) {
        .tm-clients-grid {
            grid-template-columns: 1fr;
        }
    }

    /* Client Card */
    .tm-card {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 16px;
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        gap: 1rem;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.03);
        transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        position: relative;
    }
    .tm-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
        border-color: var(--muted);
    }

    /* Card Top */
    .tm-card-top {
        display: flex;
        gap: 14px;
        align-items: center;
    }
    .tm-avatar-wrapper {
        position: relative;
        flex-shrink: 0;
    }
    .tm-status-dot {
        position: absolute;
        bottom: 0;
        right: 0;
        width: 10px;
        height: 10px;
        background: #10b981;
        border: 2px solid var(--surface);
        border-radius: 50%;
    }
    .tm-client-name {
        margin: 0;
        font-size: 1.15rem;
        font-weight: 700;
        color: var(--ink);
        line-height: 1.2;
    }
    .tm-client-email {
        margin: 3px 0 0 0;
        font-size: 0.85rem;
        color: var(--muted);
        word-break: break-all;
    }

    /* Badges Row - Clean & Neutral */
    .tm-badges-row {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
    }
    .tm-badge-goal {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: color-mix(in srgb, var(--surface) 60%, var(--bg));
        color: var(--ink);
        border: 1px solid var(--line);
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 600;
    }
    .tm-badge-activity {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: color-mix(in srgb, var(--surface) 60%, var(--bg));
        color: var(--muted);
        border: 1px solid var(--line);
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 500;
    }

    /* Vitals Strip (3 columns) */
    .tm-vitals-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
        background: color-mix(in srgb, var(--surface) 55%, var(--bg));
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 10px;
    }
    .tm-vital-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    .tm-vital-item:not(:last-child) {
        border-right: 1px solid var(--line);
    }
    .tm-vital-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--muted);
        font-weight: 600;
        margin-bottom: 2px;
    }
    .tm-vital-val {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--ink);
    }
    .tm-vital-sub {
        font-size: 0.72rem;
        color: var(--muted);
        margin-top: 2px;
    }

    /* Assignment Date Strip */
    .tm-date-strip {
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 0.8rem;
        color: var(--muted);
        padding: 6px 10px;
        background: color-mix(in srgb, var(--surface) 40%, var(--bg));
        border-radius: 8px;
    }

    /* Action Buttons - Distinct Primary & Clean Secondary */
    .tm-action-primary-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    .tm-btn-builder {
        background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
        color: #ffffff !important;
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 10px;
        padding: 10px 12px;
        font-size: 0.88rem;
        font-weight: 700;
        letter-spacing: -0.01em;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        text-decoration: none;
        box-shadow: 0 4px 14px rgba(37, 99, 235, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.2);
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        cursor: pointer;
    }
    .tm-btn-builder:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(37, 99, 235, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.25);
        filter: brightness(1.06);
    }
    .tm-btn-builder:active {
        transform: translateY(0);
        box-shadow: 0 2px 8px rgba(37, 99, 235, 0.2);
    }
    .tm-btn-builder .tm-btn-arrow {
        transition: transform 0.2s ease;
        margin-left: 1px;
    }
    .tm-btn-builder:hover .tm-btn-arrow {
        transform: translateX(3px);
    }

    .tm-btn-diet {
        background: color-mix(in srgb, var(--ink) 9%, var(--surface));
        border: 1px solid color-mix(in srgb, var(--ink) 24%, transparent);
        color: var(--ink) !important;
        border-radius: 10px;
        padding: 10px 14px;
        font-size: 0.88rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        text-decoration: none;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
        transition: all 0.2s ease;
        cursor: pointer;
    }
    .tm-btn-diet:hover {
        transform: translateY(-2px);
        background: color-mix(in srgb, var(--ink) 16%, var(--surface));
        border-color: color-mix(in srgb, var(--ink) 45%, transparent);
        color: #ffffff !important;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.22);
    }

    .tm-action-tools-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 6px;
    }
    .tm-btn-tool {
        background: color-mix(in srgb, var(--ink) 7%, var(--surface));
        border: 1px solid color-mix(in srgb, var(--ink) 20%, transparent);
        color: var(--ink) !important;
        border-radius: 8px;
        padding: 8px 6px;
        font-size: 0.78rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        text-decoration: none;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
        transition: all 0.18s ease;
        cursor: pointer;
        text-align: center;
    }
    .tm-btn-tool:hover {
        background: color-mix(in srgb, var(--ink) 16%, var(--surface));
        border-color: color-mix(in srgb, var(--ink) 42%, transparent);
        color: #ffffff !important;
        transform: translateY(-1.5px);
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.16);
    }

    .tm-btn-chat {
        background: color-mix(in srgb, var(--ink) 5%, var(--surface));
        border: 1px solid color-mix(in srgb, var(--ink) 18%, transparent);
        color: var(--ink) !important;
        border-radius: 8px;
        padding: 9px 12px;
        font-size: 0.82rem;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        text-decoration: none;
        transition: all 0.18s ease;
    }
    .tm-btn-chat:hover {
        background: color-mix(in srgb, var(--ink) 12%, var(--surface));
        border-color: color-mix(in srgb, var(--ink) 38%, transparent);
        color: #ffffff !important;
    }

    /* Pagination Bar */
    .tm-pagination-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
        padding: 0.9rem 1.25rem;
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 14px;
        margin-top: 1.5rem;
    }
    .tm-pagination-info {
        font-size: 0.85rem;
        color: var(--muted);
        font-weight: 500;
    }
    .tm-pagination-controls {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .tm-page-btn {
        background: color-mix(in srgb, var(--ink) 7%, var(--surface));
        border: 1px solid color-mix(in srgb, var(--ink) 20%, transparent);
        color: var(--ink);
        font-size: 0.82rem;
        font-weight: 600;
        padding: 6px 12px;
        border-radius: 8px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: all 0.15s ease;
    }
    .tm-page-btn:hover:not(:disabled) {
        background: color-mix(in srgb, var(--ink) 14%, var(--surface));
        border-color: color-mix(in srgb, var(--ink) 40%, transparent);
    }
    .tm-page-btn:disabled {
        opacity: 0.35;
        cursor: not-allowed;
    }
    .tm-page-numbers {
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .tm-page-num {
        min-width: 32px;
        height: 32px;
        padding: 0 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        border: 1px solid color-mix(in srgb, var(--ink) 18%, transparent);
        background: color-mix(in srgb, var(--ink) 6%, var(--surface));
        color: var(--muted);
        font-size: 0.82rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .tm-page-num:hover {
        color: var(--ink);
        border-color: color-mix(in srgb, var(--ink) 40%, transparent);
    }
    .tm-page-num.active {
        background: var(--ink);
        color: var(--bg);
        border-color: var(--ink);
        font-weight: 700;
    }

    /* Empty States */
    .tm-empty-box {
        background: var(--surface);
        border: 1px dashed var(--line);
        border-radius: 16px;
        padding: 3rem 1.5rem;
        text-align: center;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 12px;
    }
    .tm-empty-icon {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        background: color-mix(in srgb, var(--surface) 50%, var(--bg));
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--muted);
    }
    </style>

    <div class="tm-container">
        <!-- Top Header & Subtitle -->
        <div class="tm-header-row">
            <div>
                <h1 class="tm-header-title">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                    Assigned Clients
                </h1>
                <p class="tm-header-subtitle">Monitor client progress, build personalized training & diet plans, and update assessments.</p>
            </div>
        </div>

        <!-- KPI Metric Summary Bar - Cohesive & Neutral -->
        <div class="tm-kpi-grid">
            <div class="tm-kpi-card">
                <div class="tm-kpi-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </div>
                <div>
                    <div class="tm-kpi-num"><?= $totalClients ?></div>
                    <div class="tm-kpi-label">Active Clients</div>
                </div>
            </div>

            <div class="tm-kpi-card">
                <div class="tm-kpi-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M6 5h12M6 19h12M6 12h12M4 7v10M20 7v10"/>
                    </svg>
                </div>
                <div>
                    <div class="tm-kpi-num"><?= $withPlanCount ?></div>
                    <div class="tm-kpi-label">Active Workout Plans</div>
                </div>
            </div>

            <div class="tm-kpi-card">
                <div class="tm-kpi-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                </div>
                <div>
                    <div class="tm-kpi-num"><?= $pendingCount ?></div>
                    <div class="tm-kpi-label">Pending Requests</div>
                </div>
            </div>

            <div class="tm-kpi-card">
                <div class="tm-kpi-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                    </svg>
                </div>
                <div>
                    <div class="tm-kpi-num"><?= $totalLogs ?></div>
                    <div class="tm-kpi-label">Progress Check-ins</div>
                </div>
            </div>
        </div>

        <?php if ($pending_requests): ?>
            <!-- Pending Appointments Section -->
            <section class="tm-pending-section">
                <h3 class="tm-pending-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    Pending Appointment Requests (<?= count($pending_requests) ?>)
                </h3>
                
                <div class="tm-clients-grid">
                <?php foreach ($pending_requests as $req):
                    $name = h($req['first_name'] . ' ' . $req['last_name']);
                    $email = h($req['email']);
                    $goal = h(ucwords(str_replace('_', ' ', $req['primary_goal'] ?? 'General Fitness')));
                    $weight = h($req['weight_kg'] ?? '-');
                    $assignmentId = (int) $req['assignment_id'];
                    $avatarHtml = render_avatar($req, 'large');
                    $csrf = csrf_field();
                    
                    $dateTimeText = 'Immediate (Ongoing)';
                    if ($req['assigned_date'] && $req['ended_date']) {
                        $dateTimeText = date('M j, Y g:i A', strtotime($req['assigned_date']));
                    } elseif ($req['assigned_date']) {
                        $dateTimeText = date('M j, Y', strtotime($req['assigned_date'])) . ' (Ongoing)';
                    }
                ?>
                    <article class="tm-card">
                        <div class="tm-card-top">
                            <div class="tm-avatar-wrapper">
                                <?= $avatarHtml ?>
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <h3 class="tm-client-name"><?= $name ?></h3>
                                <p class="tm-client-email"><?= $email ?></p>
                            </div>
                        </div>

                        <div class="tm-badges-row">
                            <span class="tm-badge-goal">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                                <?= $goal ?>
                            </span>
                            <span style="font-size: 0.8rem; color: var(--muted);">Weight: <?= $weight ?> kg</span>
                        </div>

                        <div class="tm-date-strip">
                            <span>Requested Appointment:</span>
                            <strong style="color: var(--ink);"><?= $dateTimeText ?></strong>
                        </div>

                        <div style="display: flex; gap: 8px; margin-top: 4px;">
                            <form method="post" style="flex: 1;">
                                <?= $csrf ?>
                                <input type="hidden" name="action" value="accept_appointment">
                                <input type="hidden" name="assignment_id" value="<?= $assignmentId ?>">
                                <button type="submit" class="tm-btn-builder" style="width: 100%;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                    Accept
                                </button>
                            </form>
                            <button type="button" class="tm-btn-tool" style="flex: 1; color: var(--danger) !important;" onclick="rejectAppointment(<?= $assignmentId ?>)">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                Reject
                            </button>
                        </div>
                    </article>
                <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Search & Filter Controls -->
        <div class="tm-toolbar">
            <div class="tm-search-box">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input type="text" id="tmSearchInput" class="tm-search-input" placeholder="Search clients by name, email, or goal..." oninput="filterClients()">
            </div>

            <div class="tm-filters">
                <button type="button" class="tm-filter-btn active" data-filter="all" onclick="setFilter('all', this)">
                    All (<?= $totalClients ?>)
                </button>
                <button type="button" class="tm-filter-btn" data-filter="has_plan" onclick="setFilter('has_plan', this)">
                    With Plan (<?= $withPlanCount ?>)
                </button>
                <button type="button" class="tm-filter-btn" data-filter="needs_plan" onclick="setFilter('needs_plan', this)">
                    Needs Plan (<?= $needsPlanCount ?>)
                </button>
            </div>

            <div id="tmResultsCount" class="tm-count-badge">
                Showing <?= $totalClients ?> client<?= $totalClients === 1 ? '' : 's' ?>
            </div>
        </div>

        <!-- Assigned Clients Grid -->
        <?php if ($members): ?>
            <div class="tm-clients-grid" id="tmClientsGrid">
            <?php foreach ($members as $member):
                $name = h($member['first_name'] . ' ' . $member['last_name']);
                $firstName = h($member['first_name']);
                $email = h($member['email']);
                $rawGoal = $member['primary_goal'] ?? '';
                $goal = h(!empty($rawGoal) ? ucwords(str_replace('_', ' ', $rawGoal)) : 'General Fitness');
                $weight = !empty($member['weight_kg']) ? number_format((float)$member['weight_kg'], 1) : '-';
                $targetWeight = !empty($member['target_weight_kg']) ? number_format((float)$member['target_weight_kg'], 1) : null;
                $height = !empty($member['height_cm']) ? number_format((float)$member['height_cm'], 0) : '-';
                $memberId = (int) $member['member_user_id'];
                $avatarHtml = render_avatar($member, 'large');
                $activityTitle = $member['activity_title'] ?? '';
                
                // Active Plan
                $activePlanTitle = $member['active_plan_title'] ?? null;
                $hasPlan = !empty($activePlanTitle);
                $logsCount = (int) ($member['logs_count'] ?? 0);

                // BMI Calculation
                $bmiText = '-';
                if (!empty($member['weight_kg']) && !empty($member['height_cm']) && (float)$member['height_cm'] > 0) {
                    $hM = (float)$member['height_cm'] / 100.0;
                    $bmiVal = round((float)$member['weight_kg'] / ($hM * $hM), 1);
                    $bmiText = $bmiVal . ' BMI';
                }

                $dateTimeText = 'Immediate (Ongoing)';
                if ($member['assigned_date'] && $member['ended_date']) {
                    $dateTimeText = date('M j, Y', strtotime($member['assigned_date']));
                } elseif ($member['assigned_date']) {
                    $dateTimeText = date('M j, Y', strtotime($member['assigned_date'])) . ' (Ongoing)';
                }
            ?>
                <article class="tm-card tm-client-item" 
                    data-name="<?= strtolower($name) ?>" 
                    data-email="<?= strtolower($email) ?>" 
                    data-goal="<?= strtolower($goal) ?>"
                    data-has-plan="<?= $hasPlan ? '1' : '0' ?>">
                    
                    <!-- Card Top: Avatar & Info -->
                    <div class="tm-card-top">
                        <div class="tm-avatar-wrapper">
                            <?= $avatarHtml ?>
                            <span class="tm-status-dot" title="Active Client"></span>
                        </div>
                        <div style="flex: 1; min-width: 0;">
                            <h3 class="tm-client-name"><?= $name ?></h3>
                            <p class="tm-client-email"><?= $email ?></p>
                        </div>
                    </div>

                    <!-- Badges Row -->
                    <div class="tm-badges-row">
                        <span class="tm-badge-goal">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                            <?= $goal ?>
                        </span>
                        <?php if ($activityTitle): ?>
                            <span class="tm-badge-activity">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                                <?= h($activityTitle) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Vitals & Metrics Strip -->
                    <div class="tm-vitals-grid">
                        <div class="tm-vital-item">
                            <span class="tm-vital-label">Weight</span>
                            <span class="tm-vital-val"><?= $weight ?> <small style="font-size:0.75rem;font-weight:normal;color:var(--muted);">kg</small></span>
                            <span class="tm-vital-sub"><?= $targetWeight ? "Goal: {$targetWeight}kg" : "Target: -" ?></span>
                        </div>
                        <div class="tm-vital-item">
                            <span class="tm-vital-label">Height</span>
                            <span class="tm-vital-val"><?= $height ?> <small style="font-size:0.75rem;font-weight:normal;color:var(--muted);">cm</small></span>
                            <span class="tm-vital-sub"><?= $bmiText ?></span>
                        </div>
                        <div class="tm-vital-item">
                            <span class="tm-vital-label">Workout Plan</span>
                            <?php if ($hasPlan): ?>
                                <span class="tm-vital-val" style="font-size: 0.82rem; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; max-width: 90px;" title="<?= h($activePlanTitle) ?>">
                                    <?= h($activePlanTitle) ?>
                                </span>
                                <span class="tm-vital-sub">Active</span>
                            <?php else: ?>
                                <span class="tm-vital-val" style="font-size: 0.82rem; color: var(--muted);">None</span>
                                <span class="tm-vital-sub">Not assigned</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Assignment Date Strip -->
                    <div class="tm-date-strip">
                        <span style="display:flex; align-items:center; gap:5px;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            Assignment:
                        </span>
                        <span style="font-weight: 600; color: var(--ink);"><?= $dateTimeText ?></span>
                    </div>

                    <!-- Primary Plan Builder Buttons -->
                    <div class="tm-action-primary-grid">
                        <a href="index.php?page=workout_builder&member_user_id=<?= $memberId ?>" class="tm-btn-builder" title="Design custom workout routines">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                <path d="M6 5h12M6 19h12M6 12h12M4 7v10M20 7v10"/>
                            </svg>
                            <span>Workout Builder</span>
                            <svg class="tm-btn-arrow" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </a>
                        <a href="index.php?page=diet_builder&member_user_id=<?= $memberId ?>" class="tm-btn-diet" title="Build customized meal and nutrition plans">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                            </svg>
                            Diet Plan
                        </a>
                    </div>

                    <!-- Secondary Management Row -->
                    <div class="tm-action-tools-grid">
                        <a href="index.php?page=trainer_assessment&member_user_id=<?= $memberId ?>" class="tm-btn-tool" title="Update health and physical assessment">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>
                            Assessment
                        </a>
                        <a href="index.php?page=training&member_user_id=<?= $memberId ?>" class="tm-btn-tool" title="View assigned training plan">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                            Routines
                        </a>
                        <a href="index.php?page=progress&member_user_id=<?= $memberId ?>" class="tm-btn-tool" title="View weight and fitness logs">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                            Progress <?= $logsCount > 0 ? "({$logsCount})" : "" ?>
                        </a>
                    </div>

                    <!-- Direct Client Chat -->
                    <a href="index.php?page=messages&chat=<?= $memberId ?>" class="tm-btn-chat" title="Send direct message to <?= $firstName ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                        </svg>
                        Message <?= $firstName ?>
                    </a>
                </article>
            <?php endforeach; ?>
            </div>

            <!-- Pagination Bar -->
            <div class="tm-pagination-bar" id="tmPaginationBar">
                <div class="tm-pagination-info" id="tmPaginationInfo">
                    Showing 1 to <?= min(6, count($members)) ?> of <?= count($members) ?> client<?= count($members) === 1 ? '' : 's' ?>
                </div>
                <div class="tm-pagination-controls" id="tmPaginationControls">
                    <button type="button" class="tm-page-btn" id="tmPrevBtn" onclick="changePage(currentPage - 1)">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                        Prev
                    </button>
                    <div class="tm-page-numbers" id="tmPageNumbers"></div>
                    <button type="button" class="tm-page-btn" id="tmNextBtn" onclick="changePage(currentPage + 1)">
                        Next
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                    </button>
                </div>
            </div>

            <!-- Empty Filter Search State -->
            <div id="tmNoResults" class="tm-empty-box" style="display: none;">
                <div class="tm-empty-icon">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </div>
                <h3 style="margin: 0; font-size: 1.15rem; color: var(--ink);">No matching clients found</h3>
                <p style="margin: 0; color: var(--muted); font-size: 0.9rem;">Try searching with different keywords or reset your filter.</p>
                <button type="button" class="tm-filter-btn active" style="margin-top: 8px;" onclick="resetFilters()">Clear Filters</button>
            </div>
        <?php else: ?>
            <!-- No Clients Assigned State -->
            <div class="tm-empty-box">
                <div class="tm-empty-icon">
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
                        <line x1="23" y1="11" x2="17" y2="11"/>
                    </svg>
                </div>
                <h3 style="margin: 0; font-size: 1.2rem; color: var(--ink);">No Assigned Clients Yet</h3>
                <p style="margin: 0; color: var(--muted); font-size: 0.92rem; max-width: 420px;">
                    When gym administrators assign members to you or members book personal training appointments, they will appear right here.
                </p>
            </div>
        <?php endif; ?>
    </div>

    <script>
    const ITEMS_PER_PAGE = 6;
    let currentPage = 1;
    let currentFilter = 'all';

    function getMatchingCards() {
        const query = (document.getElementById('tmSearchInput')?.value || '').toLowerCase().trim();
        const cards = Array.from(document.querySelectorAll('.tm-client-item'));
        return cards.filter(card => {
            const name = card.dataset.name || '';
            const email = card.dataset.email || '';
            const goal = card.dataset.goal || '';
            const hasPlan = card.dataset.hasPlan === '1';

            const matchesQuery = !query || name.includes(query) || email.includes(query) || goal.includes(query);
            let matchesFilter = true;
            if (currentFilter === 'has_plan') matchesFilter = hasPlan;
            else if (currentFilter === 'needs_plan') matchesFilter = !hasPlan;

            return matchesQuery && matchesFilter;
        });
    }

    function renderPagination(totalItems) {
        const totalPages = Math.ceil(totalItems / ITEMS_PER_PAGE) || 1;
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        const startIdx = (currentPage - 1) * ITEMS_PER_PAGE;
        const endIdx = Math.min(startIdx + ITEMS_PER_PAGE, totalItems);

        const info = document.getElementById('tmPaginationInfo');
        if (info) {
            if (totalItems === 0) {
                info.textContent = 'Showing 0 clients';
            } else {
                info.textContent = `Showing ${startIdx + 1} - ${endIdx} of ${totalItems} client${totalItems === 1 ? '' : 's'}`;
            }
        }

        const prevBtn = document.getElementById('tmPrevBtn');
        if (prevBtn) prevBtn.disabled = (currentPage <= 1);

        const nextBtn = document.getElementById('tmNextBtn');
        if (nextBtn) nextBtn.disabled = (currentPage >= totalPages);

        const pageNumbers = document.getElementById('tmPageNumbers');
        if (pageNumbers) {
            pageNumbers.innerHTML = '';
            for (let i = 1; i <= totalPages; i++) {
                const pageBtn = document.createElement('button');
                pageBtn.type = 'button';
                pageBtn.className = 'tm-page-num' + (i === currentPage ? ' active' : '');
                pageBtn.textContent = i;
                pageBtn.onclick = () => changePage(i);
                pageNumbers.appendChild(pageBtn);
            }
        }

        const paginationBar = document.getElementById('tmPaginationBar');
        if (paginationBar) {
            paginationBar.style.display = totalItems > 0 ? 'flex' : 'none';
        }
    }

    function changePage(page) {
        currentPage = page;
        filterClients(false);
        document.getElementById('tmClientsGrid')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function setFilter(filterType, btn) {
        currentFilter = filterType;
        document.querySelectorAll('.tm-filter-btn').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
        filterClients(true);
    }

    function resetFilters() {
        const input = document.getElementById('tmSearchInput');
        if (input) input.value = '';
        currentFilter = 'all';
        document.querySelectorAll('.tm-filter-btn').forEach(b => {
            if (b.dataset.filter === 'all') b.classList.add('active');
            else b.classList.remove('active');
        });
        filterClients(true);
    }

    function filterClients(resetPage = true) {
        if (resetPage) currentPage = 1;
        const allCards = document.querySelectorAll('.tm-client-item');
        const matching = getMatchingCards();
        const totalMatching = matching.length;

        const startIdx = (currentPage - 1) * ITEMS_PER_PAGE;
        const endIdx = startIdx + ITEMS_PER_PAGE;

        allCards.forEach(card => {
            card.style.display = 'none';
        });

        matching.slice(startIdx, endIdx).forEach(card => {
            card.style.display = 'flex';
        });

        const counter = document.getElementById('tmResultsCount');
        if (counter) {
            counter.textContent = `Showing ${totalMatching} client${totalMatching === 1 ? '' : 's'}`;
        }

        const noResults = document.getElementById('tmNoResults');
        if (noResults) {
            noResults.style.display = (totalMatching === 0 && allCards.length > 0) ? 'flex' : 'none';
        }

        renderPagination(totalMatching);
    }

    function rejectAppointment(assignmentId) {
        Swal.fire({
            title: 'Reject Appointment',
            input: 'textarea',
            inputLabel: 'Reason for rejection (optional)',
            inputPlaceholder: 'Please state your reason...',
            showCancelButton: true,
            confirmButtonText: 'Reject',
            confirmButtonColor: 'var(--danger)',
            background: 'var(--surface)',
            color: 'var(--ink)'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject_appointment">
                    <input type="hidden" name="assignment_id" value="${assignmentId}">
                    <input type="hidden" name="rejection_reason" value="${result.value || ''}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    // Initialize pagination and filtering on load
    document.addEventListener('DOMContentLoaded', () => {
        filterClients(true);
    });
    </script>
    <?php
    render_footer();
}
