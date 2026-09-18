<?php
declare(strict_types=1);

function view_gym_page(): void
{
    $user = require_roles(['member', 'platform_admin', 'gym_owner']);
    $pdo = db();
    
    $gymId = (int) ($_GET['gym_id'] ?? 0);
    if (!$gymId) {
        flash('Invalid gym ID.', 'danger');
        redirect('dashboard');
    }
    
    $stmt = $pdo->prepare('SELECT * FROM gyms WHERE gym_id = ? AND status = "approved"');
    $stmt->execute([$gymId]);
    $gym = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$gym) {
        flash('Gym not found or not active.', 'danger');
        redirect('dashboard');
    }

    // Affiliation check for members
    $isAffiliatedWithThisGym = false;
    if ($user['role'] === 'member') {
        $stmtAff = $pdo->prepare('SELECT 1 FROM gym_members WHERE user_id = ? AND gym_id = ?');
        $stmtAff->execute([$user['user_id'], $gymId]);
        $isAffiliatedWithThisGym = (bool) $stmtAff->fetchColumn();
    }
    
    // Classes
    $stmtClasses = $pdo->prepare('
        SELECT c.*, 
               COALESCE(CONCAT(u.first_name, " ", u.last_name), "Open Trainer") as instructor_name,
               (SELECT COUNT(*) FROM class_schedules s WHERE s.class_id = c.class_id AND DATE(s.start_datetime) >= CURDATE()) as upcoming_count
        FROM classes c 
        LEFT JOIN users u ON u.user_id = c.instructor_id 
        WHERE c.gym_id = ? 
        ORDER BY c.class_name ASC
    ');
    $stmtClasses->execute([$gymId]);
    $classes = $stmtClasses->fetchAll(PDO::FETCH_ASSOC);

    // Membership Plans
    $stmtPlans = $pdo->prepare('
        SELECT * FROM membership_plans 
        WHERE gym_id = ? AND is_active = 1 
        ORDER BY price ASC
    ');
    $stmtPlans->execute([$gymId]);
    $plans = $stmtPlans->fetchAll(PDO::FETCH_ASSOC);

    // Equipment
    $stmtEquip = $pdo->prepare('
        SELECT * FROM gym_equipment 
        WHERE gym_id = ? 
        ORDER BY category ASC, name ASC
    ');
    $stmtEquip->execute([$gymId]);
    $equipment = $stmtEquip->fetchAll(PDO::FETCH_ASSOC);

    // Trainers
    $stmtTrainers = $pdo->prepare('
        SELECT u.user_id, u.first_name, u.last_name, u.email, u.profile_picture, tp.specialization, tp.bio 
        FROM trainer_profiles tp 
        JOIN users u ON u.user_id = tp.user_id 
        WHERE tp.gym_id = ? AND u.status = "active"
        ORDER BY u.first_name ASC
    ');
    $stmtTrainers->execute([$gymId]);
    $trainers = $stmtTrainers->fetchAll(PDO::FETCH_ASSOC);

    // Gym Images
    $stmtImages = $pdo->prepare('SELECT image_url FROM gym_images WHERE gym_id = ? ORDER BY created_at DESC LIMIT 6');
    $stmtImages->execute([$gymId]);
    $images = $stmtImages->fetchAll(PDO::FETCH_COLUMN);

    $brandColor = !empty($gym['brand_color']) ? $gym['brand_color'] : 'var(--lime)';

    render_header($gym['name'] . ' - Partner Gym', $user);
    ?>

    <style>
    /* Gym Showcase Styles */
    .gym-showcase-container {
        display: flex;
        flex-direction: column;
        gap: 24px;
        margin-bottom: 36px;
    }

    /* Hero Banner */
    .gym-hero-card {
        background: linear-gradient(135deg, rgba(20, 26, 38, 0.95) 0%, rgba(12, 16, 24, 0.98) 100%);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 18px;
        padding: 28px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 6px 24px rgba(0, 0, 0, 0.2);
    }
    /* Subtle 2px gradient glow line instead of harsh thick block */
    .gym-hero-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent, color-mix(in srgb, <?= h($brandColor) ?> 70%, white) 25%, <?= h($brandColor) ?> 50%, color-mix(in srgb, <?= h($brandColor) ?> 70%, white) 75%, transparent);
        opacity: 0.9;
        box-shadow: 0 0 14px color-mix(in srgb, <?= h($brandColor) ?> 45%, transparent);
    }
    .gym-hero-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 20px;
    }
    .gym-hero-profile {
        display: flex;
        align-items: center;
        gap: 18px;
        flex: 1;
        min-width: 0;
    }
    .gym-avatar-box {
        width: 64px;
        height: 64px;
        border-radius: 16px;
        background: color-mix(in srgb, <?= h($brandColor) ?> 10%, rgba(255, 255, 255, 0.03));
        color: <?= h($brandColor) ?>;
        border: 1px solid color-mix(in srgb, <?= h($brandColor) ?> 24%, rgba(255, 255, 255, 0.08));
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        font-weight: 800;
        flex-shrink: 0;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
    }
    .gym-hero-details {
        flex: 1;
        min-width: 0;
    }
    .gym-hero-title-row {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 6px;
        flex-wrap: wrap;
    }
    .gym-hero-title {
        margin: 0;
        font-size: 26px;
        font-weight: 800;
        color: var(--ink);
        line-height: 1.2;
    }
    .gym-verified-badge {
        background: rgba(34, 197, 94, 0.08);
        color: #4ade80;
        border: 1px solid rgba(34, 197, 94, 0.20);
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 6px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .gym-current-badge {
        background: color-mix(in srgb, <?= h($brandColor) ?> 10%, transparent);
        color: <?= h($brandColor) ?>;
        border: 1px solid color-mix(in srgb, <?= h($brandColor) ?> 22%, transparent);
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 6px;
        font-weight: 700;
    }
    .gym-hero-meta-row {
        display: flex;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
        font-size: 13px;
        color: var(--muted);
        margin-top: 6px;
    }
    .gym-meta-item {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .gym-hero-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        flex-shrink: 0;
    }

    /* Section Panels */
    .gym-panel-box {
        background: linear-gradient(135deg, rgba(20, 26, 38, 0.8) 0%, rgba(13, 17, 25, 0.95) 100%);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.15);
        transition: border-color 0.2s ease;
    }
    .gym-panel-box:hover {
        border-color: rgba(255, 255, 255, 0.12);
    }
    .gym-section-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin: 0 0 20px;
        color: var(--ink);
        font-size: 18px;
        font-weight: 700;
        flex-wrap: wrap;
        gap: 12px;
    }
    .gym-section-title-left {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .gym-section-icon-wrap {
        width: 32px;
        height: 32px;
        border-radius: 9px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .gym-section-icon-wrap.icon-classes {
        background: rgba(163, 230, 53, 0.08);
        color: #bef264;
        border: 1px solid rgba(163, 230, 53, 0.18);
    }
    .gym-section-icon-wrap.icon-plans {
        background: rgba(168, 85, 247, 0.08);
        color: #d8b4fe;
        border: 1px solid rgba(168, 85, 247, 0.18);
    }
    .gym-section-icon-wrap.icon-equipment {
        background: rgba(14, 165, 233, 0.08);
        color: #38bdf8;
        border: 1px solid rgba(14, 165, 233, 0.18);
    }
    .gym-section-icon-wrap.icon-trainers {
        background: rgba(245, 158, 11, 0.08);
        color: #fbbf24;
        border: 1px solid rgba(245, 158, 11, 0.18);
    }
    .gym-section-icon-wrap.icon-gallery {
        background: rgba(255, 255, 255, 0.05);
        color: var(--ink);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    /* Metric Highlights Strip */
    .gym-metrics-strip {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 200px), 1fr));
        gap: 16px;
    }
    .gym-metric-card {
        background: linear-gradient(135deg, rgba(20, 26, 38, 0.65) 0%, rgba(13, 17, 25, 0.85) 100%);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 14px;
        padding: 16px 20px;
        display: flex;
        align-items: center;
        gap: 14px;
        transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .gym-metric-card:hover {
        transform: translateY(-2px);
        border-color: rgba(255, 255, 255, 0.15);
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.15);
    }
    .gym-metric-icon {
        width: 40px;
        height: 40px;
        border-radius: 11px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    /* Controlled muted category icon backgrounds */
    .metric-icon-lime {
        background: rgba(163, 230, 53, 0.10);
        color: #bef264;
        border: 1px solid rgba(163, 230, 53, 0.20);
    }
    .metric-icon-purple {
        background: rgba(168, 85, 247, 0.10);
        color: #d8b4fe;
        border: 1px solid rgba(168, 85, 247, 0.20);
    }
    .metric-icon-blue {
        background: rgba(14, 165, 233, 0.10);
        color: #38bdf8;
        border: 1px solid rgba(14, 165, 233, 0.20);
    }
    .metric-icon-amber {
        background: rgba(245, 158, 11, 0.10);
        color: #fbbf24;
        border: 1px solid rgba(245, 158, 11, 0.20);
    }

    .gym-metric-val {
        font-size: 22px;
        font-weight: 800;
        color: var(--ink);
        line-height: 1.1;
    }
    .gym-metric-lbl {
        font-size: 12px;
        color: var(--muted);
        font-weight: 600;
        margin-top: 2px;
    }

    /* Classes Offered */
    .gym-classes-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr));
        gap: 14px;
    }
    .explore-card-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        background: linear-gradient(135deg, rgba(20, 26, 38, 0.6) 0%, rgba(13, 17, 25, 0.75) 100%);
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 14px;
        padding: 16px 18px;
        position: relative;
        overflow: hidden;
        transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    }
    .explore-card-item:hover {
        transform: translateY(-2px);
        border-color: color-mix(in srgb, <?= h($brandColor) ?> 30%, rgba(255, 255, 255, 0.12));
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.18);
    }
    .explore-card-item::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        bottom: 0;
        width: 2.5px;
        background: <?= h($brandColor) ?>;
        opacity: 0;
        transition: opacity 0.25s ease;
    }
    .explore-card-item:hover::before {
        opacity: 1;
    }
    .explore-card-main {
        display: flex;
        gap: 14px;
        align-items: flex-start;
        flex: 1;
        min-width: 0;
    }
    .explore-card-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: rgba(163, 230, 53, 0.08);
        color: #a3e635;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        border: 1px solid rgba(163, 230, 53, 0.18);
    }
    .explore-card-info {
        flex: 1;
        min-width: 0;
    }
    .explore-card-title {
        font-weight: 700;
        font-size: 15px;
        color: var(--ink);
        margin-bottom: 3px;
        line-height: 1.3;
    }
    .explore-card-meta {
        font-size: 12px;
        color: var(--muted);
        margin-bottom: 6px;
    }
    .explore-card-desc {
        font-size: 12.5px;
        color: var(--muted);
        line-height: 1.45;
    }
    .explore-action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 8px 18px;
        font-size: 13px;
        font-weight: 700;
        border-radius: 9px;
        text-decoration: none !important;
        flex-shrink: 0;
        align-self: center;
        transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        cursor: pointer;
    }
    .explore-action-btn.btn-book {
        background: color-mix(in srgb, var(--lime) 14%, transparent);
        color: var(--lime) !important;
        border: 1px solid color-mix(in srgb, var(--lime) 35%, transparent);
    }
    .explore-action-btn.btn-book:hover {
        background: var(--lime);
        color: #080b0d !important;
        border-color: var(--lime);
        box-shadow: 0 4px 14px color-mix(in srgb, var(--lime) 30%, transparent);
        transform: translateY(-2px);
        text-decoration: none !important;
    }
    .explore-action-btn svg {
        transition: transform 0.2s ease;
    }
    .explore-action-btn:hover svg {
        transform: translateX(2px);
    }

    /* Plan Pricing Cards */
    .gym-plans-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
        gap: 20px;
    }
    .gym-plan-card {
        background: linear-gradient(135deg, rgba(20, 26, 38, 0.7) 0%, rgba(13, 17, 25, 0.85) 100%);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 14px;
        padding: 22px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        position: relative;
        overflow: hidden;
        transition: all 0.25s ease;
    }
    .gym-plan-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 28px rgba(0, 0, 0, 0.24);
        border-color: rgba(255, 255, 255, 0.16);
    }
    .gym-plan-card.is-popular {
        border-color: color-mix(in srgb, <?= h($brandColor) ?> 40%, rgba(255, 255, 255, 0.1));
        box-shadow: 0 4px 20px color-mix(in srgb, <?= h($brandColor) ?> 10%, transparent);
    }
    .gym-plan-badge {
        position: absolute;
        top: 16px;
        right: 16px;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 0.04em;
        padding: 3px 9px;
        border-radius: 999px;
        background: color-mix(in srgb, <?= h($brandColor) ?> 12%, transparent);
        color: <?= h($brandColor) ?>;
        border: 1px solid color-mix(in srgb, <?= h($brandColor) ?> 28%, transparent);
        text-transform: uppercase;
    }
    .gym-plan-features {
        border-top: 1px solid rgba(255, 255, 255, 0.07);
        padding-top: 12px;
        margin-bottom: 18px;
    }
    .btn-plan-secondary {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        width: 100%;
        box-sizing: border-box;
        margin-top: 10px;
        padding: 9px 18px;
        border-radius: 9px;
        background: rgba(255, 255, 255, 0.05);
        color: var(--ink);
        border: 1px solid rgba(255, 255, 255, 0.1);
        font-size: 13px;
        font-weight: 600;
        text-decoration: none !important;
        transition: all 0.2s ease;
    }
    .btn-plan-secondary:hover {
        background: rgba(255, 255, 255, 0.09);
        border-color: color-mix(in srgb, <?= h($brandColor) ?> 35%, rgba(255, 255, 255, 0.15));
        color: var(--ink);
        transform: translateY(-1px);
    }

    /* Equipment Tags Grid */
    .gym-equip-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 210px), 1fr));
        gap: 12px;
    }
    .gym-equip-card {
        background: color-mix(in srgb, var(--panel-soft) 45%, transparent);
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 12px;
        padding: 12px 14px;
        display: flex;
        align-items: center;
        gap: 12px;
        transition: all 0.2s ease;
    }
    .gym-equip-card:hover {
        background: color-mix(in srgb, var(--panel-soft) 70%, transparent);
        border-color: rgba(255, 255, 255, 0.12);
        transform: translateY(-1px);
    }
    .gym-equip-icon {
        width: 34px;
        height: 34px;
        border-radius: 8px;
        background: rgba(14, 165, 233, 0.08);
        color: #38bdf8;
        border: 1px solid rgba(14, 165, 233, 0.16);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .gym-status-badge {
        color: #4ade80;
        background: rgba(34, 197, 94, 0.08);
        border: 1px solid rgba(34, 197, 94, 0.16);
        font-size: 10px;
        padding: 1px 6px;
        border-radius: 4px;
        font-weight: 600;
    }

    /* Trainer Cards */
    .gym-trainers-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 260px), 1fr));
        gap: 16px;
    }
    .gym-trainer-card {
        background: color-mix(in srgb, var(--panel-soft) 40%, transparent);
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 14px;
        padding: 16px;
        display: flex;
        align-items: center;
        gap: 14px;
        transition: all 0.2s ease;
    }
    .gym-trainer-card:hover {
        border-color: rgba(255, 255, 255, 0.14);
        transform: translateY(-2px);
    }
    .gym-trainer-avatar {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: rgba(245, 158, 11, 0.08);
        color: #fbbf24;
        border: 1px solid rgba(245, 158, 11, 0.18);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 15px;
        flex-shrink: 0;
        overflow: hidden;
    }

    /* Photo Gallery */
    .gym-gallery-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 180px), 1fr));
        gap: 12px;
    }
    .gym-gallery-item {
        aspect-ratio: 16 / 10;
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid rgba(255, 255, 255, 0.08);
        background: rgba(0, 0, 0, 0.2);
    }
    .gym-gallery-item img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s ease;
    }
    .gym-gallery-item:hover img {
        transform: scale(1.05);
    }

    /* Buttons */
    .btn-action-back {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        border-radius: 9px;
        background: rgba(255, 255, 255, 0.08);
        color: var(--ink);
        border: 1px solid rgba(255, 255, 255, 0.1);
        font-size: 13px;
        font-weight: 600;
        text-decoration: none !important;
        transition: all 0.2s ease;
    }
    .btn-action-back:hover {
        background: rgba(255, 255, 255, 0.14);
        color: var(--ink);
        transform: translateX(-2px);
    }
    .btn-action-primary {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 18px;
        border-radius: 9px;
        background: <?= h($brandColor) ?>;
        color: #080b0d !important;
        font-size: 13px;
        font-weight: 700;
        text-decoration: none !important;
        border: none;
        box-shadow: 0 4px 14px color-mix(in srgb, <?= h($brandColor) ?> 28%, transparent);
        transition: all 0.2s ease;
        cursor: pointer;
    }
    .btn-action-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px color-mix(in srgb, <?= h($brandColor) ?> 40%, transparent);
        filter: brightness(1.06);
    }

    /* Light Theme Parity */
    html[data-theme="light"] .gym-hero-card,
    [data-theme="light"] .gym-hero-card,
    html[data-theme="light"] .gym-panel-box,
    [data-theme="light"] .gym-panel-box,
    html[data-theme="light"] .gym-metric-card,
    [data-theme="light"] .gym-metric-card,
    html[data-theme="light"] .gym-plan-card,
    [data-theme="light"] .gym-plan-card {
        background: #ffffff !important;
        border: 1px solid #cbd5e1 !important;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04) !important;
    }
    html[data-theme="light"] .explore-card-item,
    [data-theme="light"] .explore-card-item {
        background: #ffffff !important;
        border: 1px solid #e2e8f0 !important;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.03) !important;
    }
    html[data-theme="light"] .explore-card-item:hover,
    [data-theme="light"] .explore-card-item:hover {
        background: #ffffff !important;
        border-color: #84cc16 !important;
        box-shadow: 0 4px 14px rgba(101, 163, 13, 0.1) !important;
    }
    html[data-theme="light"] .explore-action-btn.btn-book,
    [data-theme="light"] .explore-action-btn.btn-book {
        background: #f7fee7 !important;
        color: #4d7c0f !important;
        border-color: #bef264 !important;
    }
    html[data-theme="light"] .explore-action-btn.btn-book:hover,
    [data-theme="light"] .explore-action-btn.btn-book:hover {
        background: #84cc16 !important;
        color: #ffffff !important;
        border-color: #84cc16 !important;
        box-shadow: 0 3px 10px rgba(132, 204, 22, 0.25) !important;
    }
    html[data-theme="light"] .gym-plan-features,
    [data-theme="light"] .gym-plan-features {
        border-top-color: #e2e8f0 !important;
    }
    html[data-theme="light"] .btn-plan-secondary,
    [data-theme="light"] .btn-plan-secondary {
        background: #f1f5f9 !important;
        border-color: #cbd5e1 !important;
        color: #1e293b !important;
    }
    html[data-theme="light"] .btn-plan-secondary:hover,
    [data-theme="light"] .btn-plan-secondary:hover {
        background: #e2e8f0 !important;
    }
    html[data-theme="light"] .gym-equip-card,
    [data-theme="light"] .gym-equip-card,
    html[data-theme="light"] .gym-trainer-card,
    [data-theme="light"] .gym-trainer-card {
        background: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
    }
    html[data-theme="light"] .btn-action-back,
    [data-theme="light"] .btn-action-back {
        background: #f1f5f9 !important;
        border-color: #cbd5e1 !important;
        color: #1e293b !important;
    }
    html[data-theme="light"] .metric-icon-lime,
    [data-theme="light"] .metric-icon-lime {
        background: #f7fee7 !important;
        color: #4d7c0f !important;
        border-color: #bef264 !important;
    }
    html[data-theme="light"] .metric-icon-purple,
    [data-theme="light"] .metric-icon-purple {
        background: #faf5ff !important;
        color: #7e22ce !important;
        border-color: #e9d5ff !important;
    }
    html[data-theme="light"] .metric-icon-blue,
    [data-theme="light"] .metric-icon-blue {
        background: #f0f9ff !important;
        color: #0369a1 !important;
        border-color: #bae6fd !important;
    }
    html[data-theme="light"] .metric-icon-amber,
    [data-theme="light"] .metric-icon-amber {
        background: #fffbeb !important;
        color: #b45309 !important;
        border-color: #fde68a !important;
    }
    html[data-theme="light"] .gym-section-icon-wrap.icon-classes,
    [data-theme="light"] .gym-section-icon-wrap.icon-classes {
        background: #f7fee7 !important;
        color: #4d7c0f !important;
        border-color: #bef264 !important;
    }
    html[data-theme="light"] .gym-section-icon-wrap.icon-plans,
    [data-theme="light"] .gym-section-icon-wrap.icon-plans {
        background: #faf5ff !important;
        color: #7e22ce !important;
        border-color: #e9d5ff !important;
    }
    html[data-theme="light"] .gym-section-icon-wrap.icon-equipment,
    [data-theme="light"] .gym-section-icon-wrap.icon-equipment {
        background: #f0f9ff !important;
        color: #0369a1 !important;
        border-color: #bae6fd !important;
    }
    html[data-theme="light"] .gym-section-icon-wrap.icon-trainers,
    [data-theme="light"] .gym-section-icon-wrap.icon-trainers {
        background: #fffbeb !important;
        color: #b45309 !important;
        border-color: #fde68a !important;
    }
    html[data-theme="light"] .gym-section-icon-wrap.icon-gallery,
    [data-theme="light"] .gym-section-icon-wrap.icon-gallery {
        background: #f1f5f9 !important;
        color: #334155 !important;
        border-color: #cbd5e1 !important;
    }

    /* =========================================================
       Mobile & Tablet Responsive UX Enhancements
       ========================================================= */
    @media (max-width: 768px) {
        .gym-showcase-container {
            gap: 16px;
            margin-bottom: 24px;
        }
        .gym-hero-card {
            padding: 20px 16px;
            border-radius: 16px;
        }
        .gym-hero-top {
            flex-direction: column;
            align-items: stretch;
            gap: 16px;
        }
        .gym-hero-profile {
            align-items: flex-start;
            gap: 14px;
        }
        .gym-avatar-box {
            width: 54px;
            height: 54px;
            font-size: 20px;
            border-radius: 14px;
        }
        .gym-hero-title {
            font-size: 22px;
        }
        .gym-hero-meta-row {
            gap: 12px;
            font-size: 12.5px;
        }
        .gym-hero-actions {
            width: 100%;
            flex-direction: column;
            gap: 10px;
        }
        .gym-hero-actions .btn-action-back,
        .gym-hero-actions .btn-action-primary,
        .gym-hero-actions form {
            width: 100%;
        }
        .gym-hero-actions .btn-action-back,
        .gym-hero-actions .btn-action-primary {
            justify-content: center;
            padding: 10px 16px;
            font-size: 13.5px;
            box-sizing: border-box;
        }

        /* 2x2 Metric Strip */
        .gym-metrics-strip {
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        .gym-metric-card {
            padding: 12px 14px;
            gap: 10px;
        }
        .gym-metric-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
        }
        .gym-metric-icon svg {
            width: 18px;
            height: 18px;
        }
        .gym-metric-val {
            font-size: 18px;
        }
        .gym-metric-lbl {
            font-size: 11px;
        }

        .gym-panel-box {
            padding: 18px 16px;
            border-radius: 14px;
        }
        .gym-section-title {
            margin-bottom: 16px;
        }
    }

    @media (max-width: 540px) {
        .gym-hero-title-row {
            gap: 6px;
        }
        .gym-hero-title {
            font-size: 20px;
            width: 100%;
        }
        .gym-verified-badge,
        .gym-current-badge {
            font-size: 10.5px;
            padding: 2px 7px;
        }
        .gym-hero-meta-row {
            flex-direction: column;
            align-items: flex-start;
            gap: 6px;
        }

        /* Section Titles on Small Mobile */
        .gym-section-title {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        .gym-section-title a.btn-action-back {
            width: 100%;
            justify-content: center;
            box-sizing: border-box;
        }

        /* Classes Cards: Stack smoothly with prominent touch target button */
        .gym-classes-grid {
            grid-template-columns: 1fr;
            gap: 12px;
        }
        .explore-card-item {
            flex-direction: column;
            align-items: stretch;
            gap: 14px;
            padding: 14px;
        }
        .explore-action-btn.btn-book {
            width: 100%;
            justify-content: center;
            padding: 10px 16px;
            font-size: 13.5px;
            box-sizing: border-box;
        }

        /* Plans Grid */
        .gym-plans-grid {
            grid-template-columns: 1fr;
            gap: 14px;
        }
        .gym-plan-card {
            padding: 18px 16px;
        }

        /* Equipment Grid */
        .gym-equip-grid {
            grid-template-columns: 1fr;
            gap: 8px;
        }

        /* Trainers Grid */
        .gym-trainers-grid {
            grid-template-columns: 1fr;
            gap: 10px;
        }

        /* Photo Gallery */
        .gym-gallery-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
        }
    }

    @media (max-width: 380px) {
        .gym-metrics-strip {
            grid-template-columns: 1fr;
        }
        .gym-hero-profile {
            flex-direction: column;
            align-items: flex-start;
        }
    }
    </style>

    <div class="gym-showcase-container animate-fade-in">
        <!-- Hero Banner Card -->
        <div class="gym-hero-card">
            <div class="gym-hero-top">
                <div class="gym-hero-profile">
                    <div class="gym-avatar-box">
                        <?php if (!empty($gym['logo_url'])): ?>
                            <img src="<?= h($gym['logo_url']) ?>" alt="<?= h($gym['name']) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">
                        <?php else: ?>
                            <?= h(strtoupper(substr($gym['name'], 0, 2))) ?>
                        <?php endif; ?>
                    </div>
                    <div class="gym-hero-details">
                        <div class="gym-hero-title-row">
                            <h1 class="gym-hero-title"><?= h($gym['name']) ?></h1>
                            <span class="gym-verified-badge">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                Verified Partner Gym
                            </span>
                            <?php if ($isAffiliatedWithThisGym): ?>
                                <span class="gym-current-badge">
                                    ★ Your Current Gym
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="gym-hero-meta-row">
                            <span class="gym-meta-item">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                <span><?= h($gym['address'] ?? 'Partner Gym Location') ?></span>
                            </span>
                            <?php if (!empty($gym['contact_info'])): ?>
                                <span class="gym-meta-item">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                                    <span><?= h($gym['contact_info']) ?></span>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="gym-hero-actions">
                    <a href="index.php?page=dashboard#explore" class="btn-action-back">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                        <span>Back to Dashboard</span>
                    </a>
                    <?php if ($user['role'] === 'member' && !$isAffiliatedWithThisGym): ?>
                        <form method="post" action="index.php?page=gym_selection" style="margin: 0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="select_gym">
                            <input type="hidden" name="gym_id" value="<?= $gymId ?>">
                            <button type="submit" class="btn-action-primary" onclick="return confirm('Switch your affiliated gym to <?= h($gym['name']) ?>?')">
                                <span>Affiliate with Gym</span>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Metrics Strip with muted semantic icon containers -->
        <div class="gym-metrics-strip">
            <div class="gym-metric-card">
                <div class="gym-metric-icon metric-icon-lime">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                </div>
                <div>
                    <div class="gym-metric-val"><?= count($classes) ?></div>
                    <div class="gym-metric-lbl">Classes Offered</div>
                </div>
            </div>

            <div class="gym-metric-card">
                <div class="gym-metric-icon metric-icon-purple">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                </div>
                <div>
                    <div class="gym-metric-val"><?= count($plans) ?></div>
                    <div class="gym-metric-lbl">Membership Tiers</div>
                </div>
            </div>

            <div class="gym-metric-card">
                <div class="gym-metric-icon metric-icon-blue">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/><path d="m3 10 7-7"/><path d="m14 21 7-7"/></svg>
                </div>
                <div>
                    <div class="gym-metric-val"><?= count($equipment) ?></div>
                    <div class="gym-metric-lbl">Equipments & Stations</div>
                </div>
            </div>

            <div class="gym-metric-card">
                <div class="gym-metric-icon metric-icon-amber">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div>
                    <div class="gym-metric-val"><?= count($trainers) ?></div>
                    <div class="gym-metric-lbl">Trainers & Coaches</div>
                </div>
            </div>
        </div>

        <!-- Section: Classes Offered -->
        <div class="gym-panel-box">
            <div class="gym-section-title">
                <div class="gym-section-title-left">
                    <div class="gym-section-icon-wrap icon-classes">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    </div>
                    <span>Classes & Sessions</span>
                </div>
                <a href="index.php?page=book_classes" class="btn-action-back" style="font-size: 12px; padding: 6px 14px;">
                    <span>Schedule & Booking</span>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
            </div>

            <?php if ($classes): ?>
                <div class="gym-classes-grid">
                    <?php foreach ($classes as $c): ?>
                        <div class="explore-card-item">
                            <div class="explore-card-main">
                                <div class="explore-card-icon">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                                </div>
                                <div class="explore-card-info">
                                    <div class="explore-card-title"><?= h($c['class_name']) ?></div>
                                    <div class="explore-card-meta">
                                        Instructor: <strong style="color: var(--ink);"><?= h($c['instructor_name']) ?></strong> • Capacity: <?= (int)$c['capacity'] ?>
                                    </div>
                                    <?php if (!empty($c['description'])): ?>
                                        <div class="explore-card-desc"><?= h($c['description']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="index.php?page=book_classes" class="explore-action-btn btn-book">
                                <span>Book</span>
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 32px; border: 1px dashed var(--line); border-radius: 12px; color: var(--muted); font-size: 13.5px;">
                    No group classes currently scheduled at this partner location.
                </div>
            <?php endif; ?>
        </div>

        <!-- Section: Membership Plans -->
        <?php if ($plans): ?>
            <div class="gym-panel-box">
                <div class="gym-section-title">
                    <div class="gym-section-title-left">
                        <div class="gym-section-icon-wrap icon-plans">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                        </div>
                        <span>Membership Plans</span>
                    </div>
                    <span style="font-size: 13px; color: var(--muted);">Flexible access options</span>
                </div>

                <div class="gym-plans-grid">
                    <?php foreach ($plans as $p): ?>
                        <?php $isPopular = !empty($p['is_popular']); ?>
                        <div class="gym-plan-card <?= $isPopular ? 'is-popular' : '' ?>">
                            <?php if ($isPopular): ?>
                                <span class="gym-plan-badge">Most Popular</span>
                            <?php endif; ?>
                            <div>
                                <div style="font-size: 17px; font-weight: 800; color: var(--ink); margin-bottom: 6px;"><?= h($p['plan_name']) ?></div>
                                <div style="display: flex; align-items: baseline; gap: 4px; margin-bottom: 14px;">
                                    <span style="font-size: 26px; font-weight: 800; color: var(--ink);">₱<?= number_format((float)$p['price'], 2) ?></span>
                                    <span style="font-size: 12px; color: var(--muted); font-weight: 600;">/ <?= (int)$p['duration_days'] ?> days</span>
                                </div>
                                
                                <?php if (!empty($p['description'])): ?>
                                    <div class="gym-plan-features">
                                        <?php 
                                        $lines = array_filter(array_map('trim', explode("\n", $p['description'])));
                                        foreach ($lines as $line): 
                                        ?>
                                            <div style="display: flex; align-items: flex-start; gap: 8px; font-size: 12.5px; color: var(--muted); margin-bottom: 6px;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="color: var(--lime); flex-shrink: 0; margin-top: 2px;"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span><?= h($line) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($isPopular): ?>
                                <a href="index.php?page=memberships" class="btn-action-primary" style="justify-content: center; width: 100%; box-sizing: border-box; margin-top: 10px;">
                                    <span>Select Plan</span>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                                </a>
                            <?php else: ?>
                                <a href="index.php?page=memberships" class="btn-plan-secondary">
                                    <span>Select Plan</span>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Section: Equipment & Facilities -->
        <?php if ($equipment): ?>
            <div class="gym-panel-box">
                <div class="gym-section-title">
                    <div class="gym-section-title-left">
                        <div class="gym-section-icon-wrap icon-equipment">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/><path d="m3 10 7-7"/><path d="m14 21 7-7"/></svg>
                        </div>
                        <span>Gym Equipment & Stations</span>
                    </div>
                    <span style="font-size: 13px; color: var(--muted);"><?= count($equipment) ?> items available</span>
                </div>

                <div class="gym-equip-grid">
                    <?php foreach ($equipment as $eq): ?>
                        <div class="gym-equip-card">
                            <div class="gym-equip-icon">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-weight: 700; font-size: 13.5px; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    <?= h($eq['name']) ?> <?= !empty($eq['unit_number']) ? '<small style="color:var(--muted)">'.h($eq['unit_number']).'</small>' : '' ?>
                                </div>
                                <div style="font-size: 11px; color: var(--muted); display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                                    <span><?= h($eq['category'] ?? 'General') ?></span>
                                    •
                                    <span class="gym-status-badge"><?= h($eq['equipment_condition'] ?? 'Good') ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Section: Coaching Staff -->
        <?php if ($trainers): ?>
            <div class="gym-panel-box">
                <div class="gym-section-title">
                    <div class="gym-section-title-left">
                        <div class="gym-section-icon-wrap icon-trainers">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </div>
                        <span>Coaching Staff</span>
                    </div>
                    <span style="font-size: 13px; color: var(--muted);">Certified fitness professionals</span>
                </div>

                <div class="gym-trainers-grid">
                    <?php foreach ($trainers as $t): ?>
                        <div class="gym-trainer-card">
                            <div class="gym-trainer-avatar">
                                <?php if (!empty($t['profile_picture'])): ?>
                                    <img src="<?= h($t['profile_picture']) ?>" alt="<?= h($t['first_name']) ?>" style="width:100%;height:100%;object-fit:cover;">
                                <?php else: ?>
                                    <?= h(strtoupper(substr($t['first_name'], 0, 1) . substr($t['last_name'], 0, 1))) ?>
                                <?php endif; ?>
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-weight: 700; font-size: 14.5px; color: var(--ink);"><?= h($t['first_name'] . ' ' . $t['last_name']) ?></div>
                                <div style="font-size: 12px; color: var(--muted); margin-top: 1px;">
                                    <?= h($t['specialization'] ?: 'Personal Fitness Coach') ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Section: Gym Gallery (if any photos uploaded) -->
        <?php if (!empty($images)): ?>
            <div class="gym-panel-box">
                <div class="gym-section-title">
                    <div class="gym-section-title-left">
                        <div class="gym-section-icon-wrap icon-gallery">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                        </div>
                        <span>Gym Gallery</span>
                    </div>
                    <span style="font-size: 13px; color: var(--muted);"><?= count($images) ?> photos</span>
                </div>

                <div class="gym-gallery-grid">
                    <?php foreach ($images as $img): ?>
                        <div class="gym-gallery-item">
                            <img src="<?= h($img) ?>" alt="<?= h($gym['name']) ?>" loading="lazy">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php
    render_footer();
}
