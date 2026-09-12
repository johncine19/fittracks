<?php
declare(strict_types=1);

function member_dashboard(PDO $pdo, array $user): void
{
    $score    = calculate_engagement_score((int) $user['user_id']);
    $category = get_engagement_category($score);
    $attendance = scalar('SELECT COUNT(*) FROM attendance WHERE user_id = ?', [$user['user_id']]);
    $progressLogs = scalar('SELECT COUNT(*) FROM progress_logs WHERE user_id = ?', [$user['user_id']]);
    $classBookings = scalar('SELECT COUNT(*) FROM class_bookings WHERE user_id = ?', [$user['user_id']]);

    $stmt = db()->prepare('SELECT fitness_tier FROM member_profiles WHERE user_id = ?');
    $stmt->execute([$user['user_id']]);
    $tier = (int) ($stmt->fetchColumn() ?: 1);
    $tierName = get_fitness_tier_name($tier);

    // Skeleton for member dashboard
    render_skeleton_banner();
    render_skeleton_stats(3);
    ?>

    <style>
    /* Member Dashboard Tab Switcher */
    .member-dashboard-nav {
        display: flex;
        gap: 6px;
        margin-bottom: 24px;
        background: rgba(16, 19, 27, 0.85);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 5px;
        position: sticky;
        top: 72px;
        z-index: 15;
        box-shadow: 0 4px 20px rgba(0,0,0,0.18);
        overflow-x: auto;
        scrollbar-width: none;
        -webkit-overflow-scrolling: touch;
    }
    .member-dashboard-nav::-webkit-scrollbar {
        display: none;
    }
    .member-dash-tab {
        flex: 1 1 auto;
        min-width: max-content;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 10px 14px;
        border-radius: 8px;
        border: 1px solid transparent;
        background: transparent;
        color: var(--muted);
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        white-space: nowrap;
        text-decoration: none;
    }
    .member-dash-tab svg {
        flex-shrink: 0;
        transition: stroke 0.2s ease, transform 0.2s ease;
    }
    .member-dash-tab:hover {
        color: var(--ink);
        background: color-mix(in srgb, var(--ink) 6%, transparent);
    }
    .member-dash-tab.active {
        color: var(--lime);
        background: color-mix(in srgb, var(--lime) 14%, var(--panel-soft));
        border-color: color-mix(in srgb, var(--lime) 40%, transparent);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
    }
    .member-dash-tab.active svg {
        stroke: var(--lime);
        transform: scale(1.08);
    }

    /* Responsive tab labels - prevents any truncation on smaller screens */
    .tab-label-full {
        display: inline;
    }
    .tab-label-compact {
        display: none;
    }
    @media (max-width: 860px) {
        .tab-label-full {
            display: none;
        }
        .tab-label-compact {
            display: inline;
        }
        .member-dash-tab {
            padding: 9px 10px;
            font-size: 12.5px;
            gap: 6px;
        }
    }
    @media (max-width: 480px) {
        .member-dashboard-nav {
            top: 58px;
            padding: 4px;
            gap: 4px;
        }
        .member-dash-tab {
            padding: 8px 6px;
            font-size: 12px;
            gap: 5px;
        }
        .member-dash-tab svg {
            width: 14px;
            height: 14px;
        }
    }

    /* Light Theme Styling */
    [data-theme="light"] .member-dashboard-nav {
        background: rgba(255, 255, 255, 0.96);
        border-color: #cbd5e1;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
    }
    [data-theme="light"] .member-dash-tab {
        color: #475569;
    }
    [data-theme="light"] .member-dash-tab:hover {
        color: #0f172a;
        background: #f1f5f9;
    }
    [data-theme="light"] .member-dash-tab.active {
        color: #166534;
        background: #dcfce7;
        border-color: #86efac;
        box-shadow: 0 2px 8px rgba(22, 101, 52, 0.15);
    }
    [data-theme="light"] .member-dash-tab.active svg {
        stroke: #166534;
    }

    .member-tab-panel {
        display: none;
        animation: memberTabFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .member-tab-panel.active {
        display: block;
    }
    @keyframes memberTabFadeIn {
        from { opacity: 0; transform: translateY(6px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Quick Action Strip */
    .member-quick-actions {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 180px), 1fr));
        gap: 12px;
        margin-bottom: 24px;
    }
    .member-action-pill {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 10px;
        color: var(--ink);
        text-decoration: none;
        font-size: 13px;
        font-weight: 700;
        transition: all 0.2s ease;
    }
    .member-action-pill:hover {
        border-color: var(--lime);
        background: color-mix(in srgb, var(--lime) 8%, var(--surface));
        transform: translateY(-2px);
        box-shadow: 0 4px 14px rgba(0,0,0,0.15);
    }
    .member-action-icon {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        background: rgba(199,255,34,0.12);
        border: 1px solid rgba(199,255,34,0.3);
        color: var(--lime);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    [data-theme="light"] .member-action-pill {
        background: #ffffff;
        border-color: #cbd5e1;
        color: #1e293b;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
    }
    [data-theme="light"] .member-action-pill:hover {
        background: #f8fafc;
        border-color: #84cc16;
    }
    [data-theme="light"] .member-action-icon {
        background: #ecfccb;
        border-color: #a3e635;
        color: #4d7c0f;
    }

    /* Member Key Stats Grid */
    body.loaded .member-stats-grid,
    .member-stats-grid {
        display: grid !important;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 200px), 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .member-stat-card {
        background: linear-gradient(135deg, rgba(22, 27, 39, 0.85) 0%, rgba(15, 19, 28, 0.95) 100%);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 14px;
        padding: 18px 20px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        text-decoration: none;
        color: inherit;
        position: relative;
        overflow: hidden;
        transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
    }
    .member-stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 28px rgba(0, 0, 0, 0.28);
    }
    .member-stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        opacity: 0;
        transition: opacity 0.25s ease;
    }
    .member-stat-card:hover::before {
        opacity: 1;
    }
    .stat-card-attendance::before { background: var(--lime); }
    .stat-card-progress::before { background: var(--teal); }
    .stat-card-classes::before { background: #a855f7; }

    .stat-card-attendance:hover { border-color: color-mix(in srgb, var(--lime) 40%, var(--line)); }
    .stat-card-progress:hover { border-color: color-mix(in srgb, var(--teal) 40%, var(--line)); }
    .stat-card-classes:hover { border-color: color-mix(in srgb, #a855f7 40%, var(--line)); }

    .member-stat-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 12px;
    }
    .member-stat-label {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--muted);
    }
    .member-stat-icon-wrap {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: transform 0.25s ease;
    }
    .member-stat-card:hover .member-stat-icon-wrap {
        transform: scale(1.08);
    }

    .icon-accent-attendance {
        background: color-mix(in srgb, var(--lime) 15%, transparent);
        color: var(--lime);
        border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
    }
    .icon-accent-progress {
        background: color-mix(in srgb, var(--teal) 15%, transparent);
        color: var(--teal);
        border: 1px solid color-mix(in srgb, var(--teal) 30%, transparent);
    }
    .icon-accent-classes {
        background: rgba(168, 85, 247, 0.15);
        color: #a855f7;
        border: 1px solid rgba(168, 85, 247, 0.3);
    }

    .member-stat-mid {
        display: flex;
        align-items: baseline;
        gap: 6px;
        margin-bottom: 14px;
    }
    .member-stat-num {
        font-size: 32px;
        font-weight: 800;
        color: var(--ink);
        line-height: 1;
        letter-spacing: -0.02em;
    }
    .member-stat-unit {
        font-size: 13px;
        color: var(--muted);
        font-weight: 600;
    }
    .member-stat-bot {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding-top: 10px;
        border-top: 1px solid rgba(255, 255, 255, 0.05);
        font-size: 11.5px;
    }
    .member-stat-hint {
        color: var(--muted);
        font-weight: 500;
    }
    .member-stat-cta {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        font-weight: 700;
        color: var(--ink);
        transition: gap 0.2s ease, color 0.2s ease;
    }
    .member-stat-card:hover .member-stat-cta {
        gap: 6px;
    }
    .stat-card-attendance:hover .member-stat-cta { color: var(--lime); }
    .stat-card-progress:hover .member-stat-cta { color: var(--teal); }
    .stat-card-classes:hover .member-stat-cta { color: #a855f7; }

    /* Light Theme Styling for Stat Cards */
    html[data-theme="light"] .member-stat-card,
    [data-theme="light"] .member-stat-card {
        background: #ffffff !important;
        border: 1px solid #cbd5e1 !important;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04) !important;
    }
    html[data-theme="light"] .member-stat-card:hover,
    [data-theme="light"] .member-stat-card:hover {
        background: #ffffff !important;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08) !important;
    }
    html[data-theme="light"] .member-stat-label,
    [data-theme="light"] .member-stat-label {
        color: #64748b !important;
    }
    html[data-theme="light"] .member-stat-num,
    [data-theme="light"] .member-stat-num {
        color: #0f172a !important;
    }
    html[data-theme="light"] .member-stat-unit,
    [data-theme="light"] .member-stat-unit {
        color: #64748b !important;
    }
    html[data-theme="light"] .member-stat-bot,
    [data-theme="light"] .member-stat-bot {
        border-top-color: #f1f5f9 !important;
    }
    html[data-theme="light"] .member-stat-hint,
    [data-theme="light"] .member-stat-hint {
        color: #64748b !important;
    }
    html[data-theme="light"] .member-stat-cta,
    [data-theme="light"] .member-stat-cta {
        color: #0f172a !important;
    }
    html[data-theme="light"] .icon-accent-attendance,
    [data-theme="light"] .icon-accent-attendance {
        background: #ecfccb !important;
        color: #365314 !important;
        border-color: #bef264 !important;
    }
    html[data-theme="light"] .icon-accent-progress,
    [data-theme="light"] .icon-accent-progress {
        background: #e0f2fe !important;
        color: #0369a1 !important;
        border-color: #bae6fd !important;
    }
    html[data-theme="light"] .icon-accent-classes,
    [data-theme="light"] .icon-accent-classes {
        background: #f3e8ff !important;
        color: #6b21a8 !important;
        border-color: #e9d5ff !important;
    }
    html[data-theme="light"] .stat-card-attendance:hover,
    [data-theme="light"] .stat-card-attendance:hover { border-color: #84cc16 !important; }
    html[data-theme="light"] .stat-card-attendance:hover .member-stat-cta,
    [data-theme="light"] .stat-card-attendance:hover .member-stat-cta { color: #166534 !important; }
    html[data-theme="light"] .stat-card-progress:hover,
    [data-theme="light"] .stat-card-progress:hover { border-color: #0ea5e9 !important; }
    html[data-theme="light"] .stat-card-progress:hover .member-stat-cta,
    [data-theme="light"] .stat-card-progress:hover .member-stat-cta { color: #0284c7 !important; }
    html[data-theme="light"] .stat-card-classes:hover,
    [data-theme="light"] .stat-card-classes:hover { border-color: #a855f7 !important; }
    html[data-theme="light"] .stat-card-classes:hover .member-stat-cta,
    [data-theme="light"] .stat-card-classes:hover .member-stat-cta { color: #7e22ce !important; }

    /* Member Welcome Banner & Hero Engagement Card */
    body.loaded .member-welcome-banner {
        display: flex !important;
    }
    .member-welcome-banner {
        display: flex !important;
        align-items: center;
        justify-content: space-between;
        flex-wrap: nowrap;
        gap: 24px;
        background: linear-gradient(135deg, rgba(26, 32, 46, 0.88) 0%, rgba(16, 20, 30, 0.96) 100%);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 16px;
        padding: 22px 28px;
        margin-bottom: 24px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 8px 28px rgba(0, 0, 0, 0.25);
    }
    .member-welcome-banner::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 3px;
        background: linear-gradient(90deg, var(--lime) 0%, var(--teal) 60%, transparent 100%);
        pointer-events: none;
    }
    .member-welcome-left {
        flex: 1 1 auto;
        min-width: 0;
    }
    .member-welcome-title-row {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 6px;
        flex-wrap: wrap;
    }
    .member-welcome-title {
        margin: 0;
        font-size: 24px;
        font-weight: 800;
        color: var(--ink);
        letter-spacing: -0.02em;
    }
    .member-tier-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: rgba(124, 92, 252, 0.18);
        color: #c4b5fd;
        border: 1px solid rgba(124, 92, 252, 0.35);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.02em;
    }
    .tier-info-link {
        color: inherit;
        opacity: 0.8;
        display: inline-flex;
        align-items: center;
        text-decoration: none;
        transition: opacity 0.2s;
    }
    .tier-info-link:hover {
        opacity: 1;
    }
    .member-welcome-desc {
        margin: 0;
        color: var(--muted);
        font-size: 13.5px;
        line-height: 1.5;
    }

    /* Score Hero Card */
    .member-hero-score {
        flex: 0 0 auto;
        min-width: 230px;
        background: rgba(13, 16, 23, 0.8);
        border: 1px solid rgba(255, 255, 255, 0.09);
        border-radius: 14px;
        padding: 14px 18px;
        cursor: pointer;
        transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
        text-align: center;
        position: relative;
        text-decoration: none;
    }
    .member-hero-score:hover {
        transform: translateY(-3px);
        border-color: color-mix(in srgb, var(--lime) 45%, var(--line));
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
    }
    .hero-score-header {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        font-size: 10.5px;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--muted);
        margin-bottom: 8px;
        font-weight: 700;
    }
    .hero-info-btn {
        color: inherit;
        opacity: 0.7;
        display: inline-flex;
        align-items: center;
        text-decoration: none;
        transition: opacity 0.2s;
    }
    .hero-info-btn:hover {
        opacity: 1;
        color: var(--lime);
    }
    .hero-score-body {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 14px;
    }
    .hero-score-ring {
        position: relative;
        width: 52px;
        height: 52px;
        flex-shrink: 0;
    }
    .hero-score-ring svg {
        transform: rotate(-90deg);
        width: 100%;
        height: 100%;
    }
    .hero-ring-bg {
        stroke: rgba(255, 255, 255, 0.08);
    }
    .hero-ring-fg {
        transition: stroke-dashoffset 0.8s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .hero-score-ring-val {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 17px;
        font-weight: 800;
        color: var(--ink);
    }
    .hero-score-meta {
        text-align: left;
        min-width: 0;
    }
    .hero-score-badge {
        display: inline-block;
        font-size: 11px;
        padding: 3px 10px;
        border-radius: 12px;
        font-weight: 700;
        letter-spacing: 0.02em;
        white-space: nowrap;
    }
    .hero-score-action {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 11px;
        color: var(--muted);
        font-weight: 600;
        margin-top: 4px;
        white-space: nowrap;
        transition: color 0.2s ease;
    }
    .hero-score-action svg {
        transition: transform 0.2s ease;
    }
    .member-hero-score:hover .hero-score-action {
        color: var(--lime);
    }
    .member-hero-score:hover .hero-score-action svg {
        transform: translateX(3px);
    }

    /* Category Themes (Dark Mode) */
    .score-theme-highly_engaged .hero-ring-fg { stroke: var(--lime); }
    .score-theme-highly_engaged .hero-score-badge {
        background: color-mix(in srgb, var(--lime) 15%, transparent);
        color: var(--lime);
        border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
    }
    .score-theme-moderately_engaged .hero-ring-fg { stroke: #f59e0b; }
    .score-theme-moderately_engaged .hero-score-badge {
        background: rgba(245, 158, 11, 0.15);
        color: #f59e0b;
        border: 1px solid rgba(245, 158, 11, 0.3);
    }
    .score-theme-at_risk .hero-ring-fg { stroke: #ef4444; }
    .score-theme-at_risk .hero-score-badge {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
        border: 1px solid rgba(239, 68, 68, 0.3);
    }

    /* Light Theme Styling */
    html[data-theme="light"] .member-welcome-banner,
    [data-theme="light"] .member-welcome-banner {
        background: #ffffff !important;
        border: 1px solid #cbd5e1 !important;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05) !important;
    }
    html[data-theme="light"] .member-welcome-banner::before,
    [data-theme="light"] .member-welcome-banner::before {
        background: linear-gradient(90deg, #65a30d 0%, #059669 60%, transparent 100%) !important;
    }
    html[data-theme="light"] .member-welcome-title,
    [data-theme="light"] .member-welcome-title {
        color: #0f172a !important;
    }
    html[data-theme="light"] .member-tier-pill,
    [data-theme="light"] .member-tier-pill {
        background: #ede9fe !important;
        color: #6d28d9 !important;
        border-color: #ddd6fe !important;
    }
    html[data-theme="light"] .member-welcome-desc,
    [data-theme="light"] .member-welcome-desc {
        color: #64748b !important;
    }
    html[data-theme="light"] .member-hero-score,
    [data-theme="light"] .member-hero-score {
        background: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03) !important;
    }
    html[data-theme="light"] .member-hero-score:hover,
    [data-theme="light"] .member-hero-score:hover {
        background: #ffffff !important;
        border-color: #84cc16 !important;
        box-shadow: 0 6px 18px rgba(101, 163, 13, 0.12) !important;
    }
    html[data-theme="light"] .hero-score-header,
    [data-theme="light"] .hero-score-header {
        color: #64748b !important;
    }
    html[data-theme="light"] .hero-ring-bg,
    [data-theme="light"] .hero-ring-bg {
        stroke: #e2e8f0 !important;
    }
    html[data-theme="light"] .hero-score-ring-val,
    [data-theme="light"] .hero-score-ring-val {
        color: #0f172a !important;
    }
    html[data-theme="light"] .hero-score-action,
    [data-theme="light"] .hero-score-action {
        color: #64748b !important;
    }
    html[data-theme="light"] .member-hero-score:hover .hero-score-action,
    [data-theme="light"] .member-hero-score:hover .hero-score-action {
        color: #166534 !important;
    }
    html[data-theme="light"] .score-theme-highly_engaged .hero-ring-fg,
    [data-theme="light"] .score-theme-highly_engaged .hero-ring-fg { stroke: #166534 !important; }
    html[data-theme="light"] .score-theme-highly_engaged .hero-score-badge,
    [data-theme="light"] .score-theme-highly_engaged .hero-score-badge {
        background: #dcfce7 !important;
        color: #166534 !important;
        border: 1px solid #86efac !important;
    }
    html[data-theme="light"] .score-theme-moderately_engaged .hero-ring-fg,
    [data-theme="light"] .score-theme-moderately_engaged .hero-ring-fg { stroke: #b45309 !important; }
    html[data-theme="light"] .score-theme-moderately_engaged .hero-score-badge,
    [data-theme="light"] .score-theme-moderately_engaged .hero-score-badge {
        background: #fef3c7 !important;
        color: #92400e !important;
        border: 1px solid #fde68a !important;
    }
    html[data-theme="light"] .score-theme-at_risk .hero-ring-fg,
    [data-theme="light"] .score-theme-at_risk .hero-ring-fg { stroke: #dc2626 !important; }
    html[data-theme="light"] .score-theme-at_risk .hero-score-badge,
    [data-theme="light"] .score-theme-at_risk .hero-score-badge {
        background: #fee2e2 !important;
        color: #b91c1c !important;
        border: 1px solid #fecaca !important;
    }

    @media (max-width: 720px) {
        body.loaded .member-welcome-banner,
        .member-welcome-banner {
            flex-direction: column !important;
            align-items: stretch !important;
            gap: 16px;
            padding: 18px 20px;
        }
        .member-hero-score {
            width: 100%;
            max-width: 100%;
        }
    }
    </style>

    <?php
    $catSlug = strtolower(str_replace(' ', '_', $category));
    $ringCircumference = 113.1;
    $ringOffset = round($ringCircumference - ($ringCircumference * max(0, min(100, (int)$score)) / 100), 1);

    // Welcome Banner
    echo '<div class="member-welcome-banner skeleton-content sk-display-flex animate-fade-in">';
    echo '<div class="member-welcome-left">';
    echo '<div class="member-welcome-title-row">';
    echo '<h2 class="member-welcome-title">Welcome back, ' . h($user['first_name']) . '!</h2>';
    echo '<span class="member-tier-pill">';
    echo '<span>' . h($tierName) . ' Tier</span>';
    echo '<a href="#" onclick="event.preventDefault(); document.getElementById(\'guide-modal\').showModal();" class="tier-info-link" title="How it works">';
    echo '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>';
    echo '</a></span></div>';
    echo '<p class="member-welcome-desc">Let\'s crush today\'s fitness goals. Here\'s your progress at a glance.</p>';
    echo '</div>';
    
    // Engagement Score Widget (clicks to switch to Missions tab)
    echo '<div class="member-hero-score score-theme-' . $catSlug . '" onclick="switchMemberDashboardTab(\'missions\')" title="Click to view missions and gym leaderboard">';
    echo '<div class="hero-score-header">';
    echo '<span>Engagement Score</span>';
    echo '<a href="#" onclick="event.stopPropagation(); event.preventDefault(); document.getElementById(\'guide-modal\').showModal();" class="hero-info-btn" title="How Engagement Score works">';
    echo '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>';
    echo '</a></div>';
    echo '<div class="hero-score-body">';
    echo '<div class="hero-score-ring">';
    echo '<svg viewBox="0 0 44 44">';
    echo '<circle class="hero-ring-bg" cx="22" cy="22" r="18" fill="none" stroke-width="3.5" />';
    echo '<circle class="hero-ring-fg" cx="22" cy="22" r="18" fill="none" stroke-width="3.5" stroke-dasharray="113.1" stroke-dashoffset="' . $ringOffset . '" stroke-linecap="round" />';
    echo '</svg>';
    echo '<span class="hero-score-ring-val">' . $score . '</span>';
    echo '</div>';
    echo '<div class="hero-score-meta">';
    echo '<span class="hero-score-badge">' . h(ucfirst(str_replace('_', ' ', $category))) . '</span>';
    echo '<div class="hero-score-action">';
    echo '<span>Missions & Rank</span>';
    echo '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>';
    echo '</div></div></div></div></div>';
    
    render_announcement_carousel(get_active_announcements('members'));
    ?>

    <!-- Top View Switcher with Vector SVG Icons & Responsive Labels -->
    <nav class="member-dashboard-nav" aria-label="Dashboard Views">
        <button type="button" class="member-dash-tab active" id="tab-btn-today" onclick="switchMemberDashboardTab('today')" title="Today & Workout">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            <span>
                <span class="tab-label-full">Today & Workout</span>
                <span class="tab-label-compact">Workout</span>
            </span>
        </button>
        <button type="button" class="member-dash-tab" id="tab-btn-missions" onclick="switchMemberDashboardTab('missions')" title="Rank & Missions">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
            <span>
                <span class="tab-label-full">Rank & Missions</span>
                <span class="tab-label-compact">Missions</span>
            </span>
        </button>
        <button type="button" class="member-dash-tab" id="tab-btn-explore" onclick="switchMemberDashboardTab('explore')" title="Explore & Classes">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
            <span>
                <span class="tab-label-full">Explore & Classes</span>
                <span class="tab-label-compact">Classes</span>
            </span>
        </button>
    </nav>

    <!-- ========================================== -->
    <!-- TAB 1: TODAY & WORKOUT                     -->
    <!-- ========================================== -->
    <div id="panel-today" class="member-tab-panel active">
        <!-- 1-Tap Quick Action Strip -->
        <div class="member-quick-actions">
            <a href="index.php?page=qr_attendance" class="member-action-pill">
                <div class="member-action-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                </div>
                <span>Scan Attendance QR</span>
            </a>
            <a href="index.php?page=diet" class="member-action-pill">
                <div class="member-action-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
                </div>
                <span>Log Meal & Macros</span>
            </a>
            <a href="index.php?page=my_workout" class="member-action-pill">
                <div class="member-action-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/><path d="m3 10 7-7"/><path d="m14 21 7-7"/></svg>
                </div>
                <span>Full Workout Routine</span>
            </a>
        </div>

        <!-- Key Stats Metrics Grid -->
        <div class="member-stats-grid skeleton-content sk-display-grid animate-fade-in delay-1">
            <!-- Attendance Records Card -->
            <a href="index.php?page=qr_attendance" class="member-stat-card stat-card-attendance" title="View attendance records and scan QR">
                <div>
                    <div class="member-stat-top">
                        <span class="member-stat-label">Attendance Records</span>
                        <div class="member-stat-icon-wrap icon-accent-attendance">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="m9 16 2 2 4-4"/></svg>
                        </div>
                    </div>
                    <div class="member-stat-mid">
                        <span class="member-stat-num"><?= (int)$attendance ?></span>
                        <span class="member-stat-unit">check-ins</span>
                    </div>
                </div>
                <div class="member-stat-bot">
                    <span class="member-stat-hint"><?= (int)$attendance > 0 ? 'Gym visits tracked' : 'No gym visits logged' ?></span>
                    <span class="member-stat-cta"><span>Scan QR</span> <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></span>
                </div>
            </a>

            <!-- Progress Logs Card -->
            <a href="index.php?page=progress" class="member-stat-card stat-card-progress" title="View progress logs and body measurements">
                <div>
                    <div class="member-stat-top">
                        <span class="member-stat-label">Progress Logs</span>
                        <div class="member-stat-icon-wrap icon-accent-progress">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                        </div>
                    </div>
                    <div class="member-stat-mid">
                        <span class="member-stat-num"><?= (int)$progressLogs ?></span>
                        <span class="member-stat-unit">entries</span>
                    </div>
                </div>
                <div class="member-stat-bot">
                    <span class="member-stat-hint"><?= (int)$progressLogs > 0 ? 'Metrics recorded' : 'Track weight & changes' ?></span>
                    <span class="member-stat-cta"><span>Log stats</span> <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></span>
                </div>
            </a>

            <!-- Class Bookings Card -->
            <a href="index.php?page=book_classes" class="member-stat-card stat-card-classes" title="Browse and book fitness classes">
                <div>
                    <div class="member-stat-top">
                        <span class="member-stat-label">Class Bookings</span>
                        <div class="member-stat-icon-wrap icon-accent-classes">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M8 2v4"/><path d="M16 2v4"/><circle cx="12" cy="15" r="2"/></svg>
                        </div>
                    </div>
                    <div class="member-stat-mid">
                        <span class="member-stat-num"><?= (int)$classBookings ?></span>
                        <span class="member-stat-unit">booked</span>
                    </div>
                </div>
                <div class="member-stat-bot">
                    <span class="member-stat-hint"><?= (int)$classBookings > 0 ? 'Active bookings' : 'Join group classes' ?></span>
                    <span class="member-stat-cta"><span>Browse</span> <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></span>
                </div>
            </a>
        </div>

        <!-- Current Scheduled Workout Section -->
        <div class="skeleton-content animate-fade-in delay-2" style="margin-bottom: 24px;">
            <?php
            $profile = db()->query('SELECT height_cm, weight_kg, primary_goal FROM member_profiles WHERE user_id = ' . (int)$user['user_id'])->fetch();
            if ($profile && ((float)$profile['height_cm'] == 0 || (float)$profile['weight_kg'] == 0)) {
                echo '<div style="background: rgba(255,165,0,0.1); border: 1px solid orange; border-radius: 12px; padding: 24px; text-align: center; margin-bottom: 24px;">';
                echo '<h3 style="color: orange; margin: 0 0 12px 0;">We need a little more info!</h3>';
                echo '<p style="color: var(--muted); margin: 0 0 16px 0;">To build your personalized workout plan, we need your accurate height and weight.</p>';
                echo '<a href="index.php?page=profile" class="btn" style="background: orange; color: #111; font-weight: bold; padding: 10px 20px; text-decoration: none; border-radius: 6px; display: inline-block;">Complete Profile</a>';
                echo '</div>';
            } else {
                render_current_workout($user['user_id'], true);
            }
            ?>
        </div>

        <!-- Exercise Recommendations -->
        <div class="skeleton-content animate-fade-in delay-3" style="margin-bottom: 24px;">
            <?php render_exercise_recommendations((int) $user['user_id'], true); ?>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- TAB 2: RANK & MISSIONS                     -->
    <!-- ========================================== -->
    <div id="panel-missions" class="member-tab-panel">
        <!-- Leaderboard & Badges Grid -->
        <div class="skeleton-content animate-fade-in" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr)); gap: 24px; margin-bottom: 24px;">
            <!-- Gym Leaderboard -->
            <section class="panel" style="padding: 20px;">
                <h3 style="margin: 0 0 16px; color: var(--ink); display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--lime);"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                    Gym Leaderboard
                </h3>
                <?php
                $gymId = $user['gym_id'] ?? scalar('SELECT gym_id FROM gym_members WHERE user_id = ? LIMIT 1', [$user['user_id']]);
                $stmtLeaders = $pdo->prepare('SELECT u.first_name, u.last_name, u.engagement_score FROM users u JOIN gym_members gm ON u.user_id = gm.user_id WHERE u.role = "member" AND gm.gym_id = ? AND u.status = "active" ORDER BY u.engagement_score DESC LIMIT 5');
                $stmtLeaders->execute([$gymId]);
                $leaders = $stmtLeaders->fetchAll(PDO::FETCH_ASSOC);
                if ($leaders) {
                    $rank = 1;
                    foreach ($leaders as $leader) {
                        $isMe = ($leader['first_name'] === $user['first_name'] && $leader['last_name'] === $user['last_name']);
                        $bg = $isMe ? 'color-mix(in srgb, var(--lime) 12%, transparent)' : 'transparent';
                        $border = $isMe ? '1px solid color-mix(in srgb, var(--lime) 35%, transparent)' : '1px solid var(--line)';
                        echo '<div style="display: flex; align-items: center; justify-content: space-between; padding: 10px; border-bottom: 1px solid var(--line); background: '.$bg.'; border: '.$border.'; border-radius: 8px; margin-bottom: 4px;">';
                        echo '<div style="display: flex; align-items: center; gap: 12px;">';
                        echo '<span style="font-weight: bold; color: var(--muted); width: 20px;">#'.$rank.'</span>';
                        echo '<span style="font-weight: 600; color: var(--ink);">'.h($leader['first_name'].' '.mb_substr($leader['last_name'], 0, 1)).'.</span>';
                        echo '</div>';
                        echo '<span style="font-weight: 800; color: var(--lime);">'.$leader['engagement_score'].'</span>';
                        echo '</div>';
                        $rank++;
                    }
                } else {
                    echo '<p style="color: var(--muted); font-size: 13px;">No active members found.</p>';
                }
                ?>
            </section>

            <!-- My Badges -->
            <section class="panel" style="padding: 20px;">
                <h3 style="margin: 0 0 16px; color: var(--ink); display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--lime);"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>
                    My Badges
                </h3>
                <?php
                $stmtBadges = $pdo->prepare('SELECT badge_type, unlocked_at FROM member_badges WHERE user_id = ? ORDER BY unlocked_at DESC');
                $stmtBadges->execute([$user['user_id']]);
                $badges = $stmtBadges->fetchAll(PDO::FETCH_ASSOC);

                $badgeDefs = [
                    'early_bird' => [
                        'icon' => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--lime); display:inline-block;"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>',
                        'name' => 'Early Bird',
                        'desc' => 'Checked in before 7 AM'
                    ],
                    'iron_lifter' => [
                        'icon' => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--lime); display:inline-block;"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/><path d="m3 10 7-7"/><path d="m14 21 7-7"/></svg>',
                        'name' => 'Iron Lifter',
                        'desc' => 'Completed 50 exercises'
                    ],
                    'century_club' => [
                        'icon' => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--lime); display:inline-block;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
                        'name' => 'Century Club',
                        'desc' => 'Reached 100 Engagement Score'
                    ]
                ];

                if ($badges) {
                    echo '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 16px;">';
                    foreach ($badges as $b) {
                        $def = $badgeDefs[$b['badge_type']] ?? [
                            'icon' => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--lime); display:inline-block;"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>',
                            'name' => 'Unknown',
                            'desc' => 'Achievement Unlocked'
                        ];
                        echo '<div style="text-align: center; background: var(--panel-soft); padding: 12px; border-radius: 12px; border: 1px solid var(--line);" title="'.h($def['desc']).' - Unlocked '.date('M j', strtotime($b['unlocked_at'])).'">';
                        echo '<div style="margin-bottom: 8px; display: flex; align-items: center; justify-content: center;">'.$def['icon'].'</div>';
                        echo '<div style="font-size: 11px; font-weight: bold; color: var(--ink); line-height: 1.2;">'.$def['name'].'</div>';
                        echo '</div>';
                    }
                    echo '</div>';
                } else {
                    echo '<div style="text-align: center; padding: 20px; border: 1px dashed var(--line); border-radius: 12px;">';
                    echo '<p style="color: var(--muted); font-size: 13px; margin: 0;">No badges unlocked yet. Keep training!</p>';
                    echo '</div>';
                }
                ?>
            </section>
        </div>

        <!-- Engagement Missions -->
        <?php
        $missions = get_engagement_missions((int) $user['user_id']);
        $completedCount = count(array_filter($missions, fn($m) => $m['completed']));
        $totalMissions = count($missions);
        ?>
        <div id="missions-section" class="skeleton-content animate-fade-in delay-1" style="margin-bottom: 24px;">
            <section class="panel" style="padding: 0; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                <div style="padding: 20px 24px 16px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <h2 style="margin: 0; font-size: 18px; color: var(--ink);">Missions</h2>
                        <span style="font-size: 12px; background: color-mix(in srgb, var(--lime) 15%, transparent); color: var(--lime); padding: 3px 10px; border-radius: 12px; font-weight: 600;"><?= $completedCount ?>/<?= $totalMissions ?> Complete</span>
                    </div>
                    <span style="font-size: 13px; color: var(--muted);">Complete missions to boost your Engagement Score!</span>
                </div>
                <div style="padding: 8px 24px 20px;">
                    <?php 
                    $missionIndex = 0;
                    foreach ($missions as $mission): 
                        $missionIndex++;
                        $isHidden = $missionIndex > 2;
                        
                        $pct = $mission['target'] > 0 ? min(100, round(($mission['current'] / $mission['target']) * 100)) : 0;
                        $barColor = $mission['completed'] ? '#22c55e' : 'var(--lime)';
                        $checkColor = $mission['completed'] ? '#22c55e' : 'var(--line)';
                        $checkBg = $mission['completed'] ? 'rgba(34, 197, 94, 0.15)' : 'color-mix(in srgb, var(--ink) 4%, transparent)';
                        ?>
                        <div class="mission-item <?= $isHidden ? 'hidden-mission' : '' ?>" style="display: <?= $isHidden ? 'none' : 'flex' ?>; align-items: center; gap: 16px; padding: 16px 0; border-bottom: 1px solid var(--line); <?= $mission['completed'] ? 'opacity: 0.75;' : '' ?>">
                            <div style="width: 38px; height: 38px; border-radius: 50%; border: 2px solid <?= $checkColor ?>; background: <?= $checkBg ?>; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.3s;">
                                <?php if ($mission['completed']): ?>
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                <?php else: ?>
                                    <span style="font-size: 16px;"><?= $mission['icon'] ?></span>
                                <?php endif; ?>
                            </div>

                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                    <span style="font-weight: 600; color: var(--ink); font-size: 14px; <?= $mission['completed'] ? 'text-decoration: line-through; color: var(--muted);' : '' ?>"><?= h($mission['title']) ?></span>
                                    <span style="font-size: 12px; font-weight: 600; color: <?= $mission['completed'] ? '#22c55e' : 'var(--muted)' ?>; white-space: nowrap; margin-left: 8px;">
                                        +<?= $mission['earnedPoints'] ?>/<?= $mission['maxPoints'] ?> pts
                                    </span>
                                </div>
                                <div style="font-size: 12px; color: var(--muted); margin-bottom: 8px;"><?= h($mission['description']) ?></div>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="flex: 1; height: 6px; background: color-mix(in srgb, var(--ink) 8%, transparent); border-radius: 3px; overflow: hidden;">
                                        <div style="width: <?= $pct ?>%; height: 100%; background: <?= $barColor ?>; border-radius: 3px; transition: width 0.5s ease;"></div>
                                    </div>
                                    <span style="font-size: 11px; color: var(--muted); font-weight: 500; white-space: nowrap;"><?= $mission['current'] ?>/<?= $mission['target'] ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (count($missions) > 2): ?>
                        <div style="text-align: center; margin-top: 16px;">
                            <button id="toggle-missions-btn" onclick="toggleMissions()" style="background: color-mix(in srgb, var(--lime) 12%, transparent); border: 1px solid color-mix(in srgb, var(--lime) 35%, transparent); color: var(--lime); font-size: 13px; font-weight: 600; cursor: pointer; padding: 6px 16px; border-radius: 20px; transition: all 0.2s;" onmouseover="this.style.background='color-mix(in srgb, var(--lime) 20%, transparent)'" onmouseout="this.style.background='color-mix(in srgb, var(--lime) 12%, transparent)'">
                                Show All Missions
                            </button>
                        </div>
                        <script>
                            function toggleMissions() {
                                const hiddenMissions = document.querySelectorAll('.hidden-mission');
                                const btn = document.getElementById('toggle-missions-btn');
                                if (!hiddenMissions.length) return;
                                
                                const isCurrentlyHidden = hiddenMissions[0].style.display === 'none';
                                
                                hiddenMissions.forEach(m => {
                                    m.style.display = isCurrentlyHidden ? 'flex' : 'none';
                                });
                                
                                btn.innerHTML = isCurrentlyHidden ? 'Show Less' : 'Show All Missions';
                            }
                        </script>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- TAB 3: EXPLORE & CLASSES                   -->
    <!-- ========================================== -->
    <div id="panel-explore" class="member-tab-panel">
        <?php
        $profile = db()->query('SELECT height_cm, weight_kg, primary_goal FROM member_profiles WHERE user_id = ' . (int)$user['user_id'])->fetch();
        $recommendations = !empty($profile['primary_goal']) ? get_recommendations_by_goal(db(), $profile['primary_goal']) : ['classes' => [], 'gyms' => []];
        $hasExploreContent = !empty($recommendations['classes']) || !empty($recommendations['gyms']);
        ?>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="margin: 0; font-size: 18px; color: var(--ink);">Classes & Gym Discovery</h3>
                <p style="margin: 4px 0 0; color: var(--muted); font-size: 13px;">Curated classes and partner gym locations based on your fitness goals.</p>
            </div>
            <a href="index.php?page=book_classes" class="btn btn-primary" style="font-size: 12.5px; padding: 8px 16px; border-radius: 8px;">
                Browse All Classes ➔
            </a>
        </div>

        <?php if ($hasExploreContent): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr)); gap: 20px;">
                <!-- Recommended Classes -->
                <?php if (!empty($recommendations['classes'])): ?>
                    <div class="panel" style="background:var(--surface); border:1px solid var(--line); border-radius:12px; padding:20px;">
                        <h4 style="margin:0 0 16px; color:var(--lime); display:flex; align-items:center; gap:8px;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            Recommended Classes
                        </h4>
                        <?php foreach ($recommendations['classes'] as $cls): ?>
                            <div style="margin-bottom: 16px; padding-bottom:16px; border-bottom:1px solid rgba(255,255,255,0.05); display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
                                <div>
                                    <div style="font-weight:bold; font-size:1.05rem; color:var(--ink);"><?= h($cls['class_name']) ?></div>
                                    <div style="font-size:0.85rem; color:var(--lime); margin: 2px 0 6px;">At <?= h($cls['gym_name']) ?></div>
                                    <?php if (!empty($cls['description'])): ?>
                                        <div style="font-size:0.85rem; color:var(--muted); line-height:1.4;"><?= h($cls['description']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <a href="index.php?page=book_classes" class="btn btn-secondary" style="font-size:11.5px; padding:6px 10px; border-radius:6px; flex-shrink:0;">Book</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Recommended Gyms -->
                <?php if (!empty($recommendations['gyms'])): ?>
                    <div class="panel" style="background:var(--surface); border:1px solid var(--line); border-radius:12px; padding:20px;">
                        <h4 style="margin:0 0 16px; color:var(--accent, #7c5cfc); display:flex; align-items:center; gap:8px;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                            Partner Gyms
                        </h4>
                        <?php foreach ($recommendations['gyms'] as $gym): ?>
                            <div style="margin-bottom: 16px; padding-bottom:16px; border-bottom:1px solid rgba(255,255,255,0.05); display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <div style="font-weight:bold; font-size:1.05rem; color:var(--ink);"><?= h($gym['name']) ?></div>
                                    <div style="font-size:0.85rem; color:var(--muted);"><?= h($gym['address']) ?></div>
                                </div>
                                <a href="index.php?page=view_gym&gym_id=<?= (int)$gym['gym_id'] ?>" class="btn btn-primary" style="padding: 6px 12px; font-size:0.85rem;">View</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 48px 20px; background: var(--surface); border: 1px dashed var(--line); border-radius: 12px;">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.5" style="margin-bottom: 12px;"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
                <h4 style="margin: 0 0 6px; font-size: 16px; color: var(--ink);">Set a Primary Goal to Unlock Recommendations</h4>
                <p style="margin: 0 0 16px; font-size: 13px; color: var(--muted);">We personalize classes and workout routines to your target fitness goal.</p>
                <a href="index.php?page=profile" class="btn btn-primary" style="padding: 8px 18px; border-radius: 8px; font-size: 13px;">Update Profile Goal</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tab Switching JavaScript -->
    <script>
    function switchMemberDashboardTab(tabId) {
        const tabs = ['today', 'missions', 'explore'];
        if (!tabs.includes(tabId)) tabId = 'today';

        tabs.forEach(t => {
            const btn = document.getElementById('tab-btn-' + t);
            const panel = document.getElementById('panel-' + t);
            if (btn) {
                if (t === tabId) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            }
            if (panel) {
                if (t === tabId) {
                    panel.classList.add('active');
                } else {
                    panel.classList.remove('active');
                }
            }
        });

        // Save preference in localStorage & URL hash
        try {
            localStorage.setItem('fit_member_dashboard_tab', tabId);
            window.history.replaceState(null, null, '#' + tabId);
        } catch (e) {}
    }

    // Restore tab from hash or localStorage on page load
    document.addEventListener('DOMContentLoaded', function() {
        let initialTab = 'today';
        const hash = window.location.hash.replace('#', '');
        const savedTab = localStorage.getItem('fit_member_dashboard_tab');

        if (['today', 'missions', 'explore'].includes(hash)) {
            initialTab = hash;
        } else if (['today', 'missions', 'explore'].includes(savedTab)) {
            initialTab = savedTab;
        }

        switchMemberDashboardTab(initialTab);
    });
    </script>

    <?php
    $wAtt = (int) get_setting('engagement_weight_attendance', '40');
    $wCls = (int) get_setting('engagement_weight_classes', '20');
    $wCon = (int) get_setting('engagement_weight_consistency', '20');
    $wWrk = (int) get_setting('engagement_weight_workouts', '10');
    $wPrg = (int) get_setting('engagement_weight_progress', '10');
    $high = (int) get_setting('engagement_threshold_high', '75');
    $mod = (int) get_setting('engagement_threshold_moderate', '40');
    $mod_end = $high - 1;
    $risk_end = $mod - 1;

    // Engagement & Tiers Guide Modal
    echo <<<HTML
    <dialog id="guide-modal" class="panel animate-fade-in" style="border:1px solid rgba(255,255,255,0.1); border-radius:16px; background:var(--panel); color:var(--ink); padding:0; max-width:600px; width:100%; box-shadow:0 20px 40px rgba(0,0,0,0.5); margin:auto;">
        <div style="padding:24px 24px 16px; border-bottom:1px solid rgba(255,255,255,0.05); display:flex; justify-content:space-between; align-items:center;">
            <h2 style="margin:0; font-size:20px; color:var(--lime);">How it Works</h2>
            <button type="button" onclick="document.getElementById('guide-modal').close()" style="background:none; border:none; color:var(--muted); font-size:24px; cursor:pointer; line-height:1;">&times;</button>
        </div>
        <div style="padding:24px; max-height:60vh; overflow-y:auto;">
            <h3 style="color:var(--ink); margin:0 0 12px; display:flex; align-items:center; gap:8px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2" style="width:20px;height:20px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                Engagement Score (0 - 100)
            </h3>
            <p style="color:var(--muted); font-size:14px; margin-bottom:16px; line-height:1.6;">
                Your Engagement Score measures how actively you use FITTRACKS over the last 30-60 days. It updates automatically based on your habits!
            </p>
            <ul style="color:var(--muted); font-size:14px; line-height:1.6; margin-bottom:24px; padding-left:20px;">
                <li><strong>{$wAtt}% Attendance:</strong> Check into the gym regularly (up to 7 visits / 30 days).</li>
                <li><strong>{$wCls}% Classes:</strong> Participate in group fitness classes (up to 4 classes / 30 days).</li>
                <li><strong>{$wCon}% Consistency:</strong> Stay active every week. We look at your weekly streaks!</li>
                <li><strong>{$wWrk}% Daily Completed Workout:</strong> Complete your scheduled exercises (up to 8 days / 30 days).</li>
                <li><strong>{$wPrg}% Progress:</strong> Log your workout progress at least once every 60 days.</li>
            </ul>
            
            <p style="color:var(--muted); font-size:14px; margin-bottom:12px; line-height:1.6;">
                <strong>Engagement Categories:</strong>
            </p>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(min(100%, 150px), 1fr)); gap:12px; font-size:13px; margin-bottom: 24px;">
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px; border-left: 3px solid var(--lime);"><strong>{$high} - 100:</strong> Highly Engaged</div>
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px; border-left: 3px solid #f59e0b;"><strong>{$mod} - {$mod_end}:</strong> Moderately Engaged</div>
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px; border-left: 3px solid #ef4444;"><strong>0 - {$risk_end}:</strong> At-Risk</div>
            </div>
            
            <h3 style="color:var(--ink); margin:0 0 12px; display:flex; align-items:center; gap:8px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="var(--accent, #7c5cfc)" stroke-width="2" style="width:20px;height:20px;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                Fitness Tiers
            </h3>
            <p style="color:var(--muted); font-size:14px; margin-bottom:16px; line-height:1.6;">
                Tiers represent your lifetime workout experience! They are completely based on completing your assigned workout plans. Every time you log all exercises in a week's plan, you earn a "Completed Week".
            </p>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:13px;">
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px;"><strong>Tier 1:</strong> Newbie <em>(&lt; 1 week)</em></div>
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px;"><strong>Tier 2:</strong> Iron Recruit <em>(1+ weeks)</em></div>
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px;"><strong>Tier 3:</strong> Bronze Beast <em>(4+ weeks)</em></div>
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px;"><strong>Tier 4:</strong> Silver Spartan <em>(12+ weeks)</em></div>
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px; border:1px solid rgba(199,255,34,0.3);"><strong>Tier 5:</strong> Gold Gladiator <em>(24+ weeks)</em></div>
                <div style="background:rgba(124,92,252,0.1); padding:10px; border-radius:8px; border:1px solid rgba(124,92,252,0.3); color:var(--accent, #7c5cfc); font-weight:bold;"><strong>Tier 6:</strong> Apex Legend</div>
            </div>
        </div>
        <div style="padding:16px 24px; border-top:1px solid rgba(255,255,255,0.05); text-align:right;">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('guide-modal').close()">Got it</button>
        </div>
    </dialog>
HTML;
}
