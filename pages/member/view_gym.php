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

    // Ratings & Reviews for this Gym
    $ratingStats = get_gym_rating_stats($gymId);
    $gymReviews = get_gym_reviews($gymId, 50);
    $canReview = ($user['role'] === 'member') && can_user_review_gym((int)$user['user_id'], $gymId);
    $myReview = ($user['role'] === 'member') ? get_user_gym_review((int)$user['user_id'], $gymId) : null;
    
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
    .gym-section-icon-wrap.icon-rating {
        background: rgba(245, 158, 11, 0.12);
        color: #fbbf24;
        border: 1px solid rgba(245, 158, 11, 0.25);
    }

    .gym-section-header-link {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 12.5px;
        font-weight: 600;
        color: var(--muted);
        text-decoration: none !important;
        transition: all 0.2s ease;
    }
    .gym-section-header-link:hover {
        color: var(--ink);
        transform: translateX(2px);
    }
    .btn-toggle-equip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 16px;
        border-radius: 9px;
        background: rgba(255, 255, 255, 0.05);
        color: var(--ink);
        border: 1px solid rgba(255, 255, 255, 0.1);
        font-size: 12.5px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .btn-toggle-equip:hover {
        background: rgba(255, 255, 255, 0.1);
        border-color: color-mix(in srgb, <?= h($brandColor) ?> 35%, rgba(255, 255, 255, 0.15));
        color: var(--ink);
        transform: translateY(-1px);
    }
    .gym-empty-compact {
        padding: 14px 18px;
        border: 1px dashed var(--line);
        border-radius: 10px;
        color: var(--muted);
        font-size: 12.5px;
        text-align: center;
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
        font-size: 10.5px;
        padding: 1.5px 7px;
        border-radius: 999px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .gym-status-badge.status-available {
        color: #4ade80;
        background: rgba(34, 197, 94, 0.10);
        border: 1px solid rgba(34, 197, 94, 0.22);
    }
    .gym-status-badge.status-in_use {
        color: #fbbf24;
        background: rgba(245, 158, 11, 0.10);
        border: 1px solid rgba(245, 158, 11, 0.22);
    }
    .gym-status-badge.status-maintenance,
    .gym-status-badge.status-out_of_service {
        color: #f87171;
        background: rgba(239, 68, 68, 0.10);
        border: 1px solid rgba(239, 68, 68, 0.22);
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

    /* Ratings & Reviews Section */
    .gym-rating-hero-grid {
        display: grid;
        grid-template-columns: 320px 1fr;
        gap: 20px;
        margin-bottom: 24px;
    }
    .gym-rating-summary-card {
        background: color-mix(in srgb, var(--panel-soft) 40%, transparent);
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 14px;
        padding: 20px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 16px;
    }
    .gym-rating-big-score {
        display: flex;
        align-items: center;
        gap: 16px;
    }
    .big-score-val {
        font-size: 44px;
        font-weight: 900;
        color: #fbbf24;
        line-height: 1;
        letter-spacing: -1px;
    }
    .big-score-stars {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .big-score-count {
        font-size: 11.5px;
        color: var(--muted);
        font-weight: 500;
    }
    .gym-rating-bars-list {
        display: flex;
        flex-direction: column;
        gap: 7px;
    }
    .rating-bar-row {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 11.5px;
    }
    .bar-star-label {
        width: 28px;
        color: var(--muted);
        font-weight: 600;
        flex-shrink: 0;
    }
    .rating-bar-track {
        flex: 1;
        height: 6px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.08);
        overflow: hidden;
    }
    .rating-bar-fill {
        height: 100%;
        background: #fbbf24;
        border-radius: 999px;
        transition: width 0.3s ease;
    }
    .bar-count-label {
        width: 22px;
        text-align: right;
        color: var(--muted);
        font-size: 11px;
        flex-shrink: 0;
    }

    .gym-rating-action-card {
        background: color-mix(in srgb, var(--panel-soft) 40%, transparent);
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 14px;
        padding: 20px;
    }
    .form-header-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
    }
    .user-reviewed-badge {
        font-size: 11px;
        font-weight: 700;
        color: #4ade80;
        background: rgba(34, 197, 94, 0.1);
        border: 1px solid rgba(34, 197, 94, 0.22);
        padding: 2px 8px;
        border-radius: 999px;
    }
    .interactive-star-picker {
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 10px 0;
    }
    .star-picker-stars {
        display: inline-flex;
        gap: 4px;
    }
    .star-btn {
        background: none;
        border: none;
        padding: 2px;
        cursor: pointer;
        color: rgba(255, 255, 255, 0.22);
        transition: transform 0.15s ease, color 0.15s ease;
    }
    .star-btn:hover,
    .star-btn.hovered,
    .star-btn.active {
        color: #fbbf24;
        transform: scale(1.15);
    }
    .star-picker-text {
        font-size: 13px;
        font-weight: 700;
        color: #fbbf24;
    }
    .form-textarea-review {
        width: 100%;
        box-sizing: border-box;
        border-radius: 10px;
        border: 1px solid rgba(255, 255, 255, 0.12);
        background: rgba(0, 0, 0, 0.2);
        color: var(--ink);
        padding: 10px 12px;
        font-family: inherit;
        font-size: 13px;
        resize: vertical;
        min-height: 70px;
        line-height: 1.4;
    }
    .form-textarea-review:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2);
    }
    .rating-not-eligible-box {
        text-align: center;
        padding: 16px 20px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 140px;
    }
    .not-eligible-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: rgba(245, 158, 11, 0.1);
        color: #fbbf24;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 10px;
    }
    .gym-reviews-filter-bar {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 16px;
        flex-wrap: wrap;
        border-top: 1px solid rgba(255, 255, 255, 0.06);
        padding-top: 16px;
    }
    .review-filter-btn {
        background: color-mix(in srgb, var(--panel-soft) 50%, transparent);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 20px;
        padding: 4px 12px;
        font-size: 11.5px;
        font-weight: 600;
        color: var(--muted);
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .review-filter-btn:hover {
        color: var(--ink);
        border-color: rgba(255, 255, 255, 0.16);
    }
    .review-filter-btn.active {
        background: #fbbf24;
        color: #080b0d;
        border-color: #fbbf24;
        font-weight: 700;
    }
    .gym-reviews-feed {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .gym-review-item {
        background: color-mix(in srgb, var(--panel-soft) 35%, transparent);
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 12px;
        padding: 14px 16px;
        transition: all 0.2s ease;
    }
    .gym-review-item:hover {
        border-color: rgba(255, 255, 255, 0.12);
        background: color-mix(in srgb, var(--panel-soft) 55%, transparent);
    }
    .review-item-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 10px;
    }
    .review-user-info {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .review-user-avatar {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        background: color-mix(in srgb, var(--lime) 15%, transparent);
        color: var(--lime);
        font-weight: 800;
        font-size: 13px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        overflow: hidden;
    }
    .review-user-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .review-user-name {
        font-size: 13.5px;
        font-weight: 700;
        color: var(--ink);
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }
    .review-you-pill {
        font-size: 10px;
        padding: 1px 6px;
        border-radius: 4px;
        background: rgba(132, 204, 22, 0.15);
        color: var(--lime);
        font-weight: 700;
    }
    .review-verified-pill {
        font-size: 10px;
        padding: 1px 6px;
        border-radius: 4px;
        background: rgba(255, 255, 255, 0.06);
        color: var(--muted);
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 3px;
    }
    .review-date-str {
        font-size: 11px;
        color: var(--muted);
        margin-top: 1px;
    }
    .review-comment-body {
        font-size: 13px;
        color: var(--ink);
        line-height: 1.5;
        opacity: 0.92;
    }
    .review-comment-empty {
        font-size: 12px;
        font-style: italic;
        color: var(--muted);
    }

    @media (max-width: 768px) {
        .gym-rating-hero-grid {
            grid-template-columns: 1fr;
        }
    }

    /* Light Theme Parity */
    html[data-theme="light"] .gym-hero-card,
    [data-theme="light"] .gym-hero-card,
    html[data-theme="light"] .gym-panel-box,
    [data-theme="light"] .gym-panel-box,
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
    html[data-theme="light"] .btn-toggle-equip,
    [data-theme="light"] .btn-toggle-equip {
        background: #f1f5f9 !important;
        border-color: #cbd5e1 !important;
        color: #1e293b !important;
    }
    html[data-theme="light"] .btn-toggle-equip:hover,
    [data-theme="light"] .btn-toggle-equip:hover {
        background: #e2e8f0 !important;
    }
    html[data-theme="light"] .gym-status-badge.status-available {
        background: #f0fdf4 !important;
        color: #15803d !important;
        border-color: #bbf7d0 !important;
    }
    html[data-theme="light"] .gym-status-badge.status-in_use {
        background: #fffbeb !important;
        color: #b45309 !important;
        border-color: #fde68a !important;
    }
    html[data-theme="light"] .gym-status-badge.status-maintenance,
    html[data-theme="light"] .gym-status-badge.status-out_of_service {
        background: #fef2f2 !important;
        color: #b91c1c !important;
        border-color: #fecaca !important;
    }
    html[data-theme="light"] .gym-empty-compact {
        border-color: #cbd5e1 !important;
        background: #f8fafc !important;
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
    html[data-theme="light"] .gym-section-icon-wrap.icon-rating,
    [data-theme="light"] .gym-section-icon-wrap.icon-rating {
        background: #fffbeb !important;
        color: #b45309 !important;
        border-color: #fde68a !important;
    }
    html[data-theme="light"] .gym-rating-summary-card,
    [data-theme="light"] .gym-rating-summary-card,
    html[data-theme="light"] .gym-rating-action-card,
    [data-theme="light"] .gym-rating-action-card,
    html[data-theme="light"] .gym-review-item,
    [data-theme="light"] .gym-review-item {
        background: #ffffff !important;
        border: 1px solid #e2e8f0 !important;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.03) !important;
    }
    html[data-theme="light"] .form-textarea-review {
        background: #f8fafc !important;
        border-color: #cbd5e1 !important;
        color: #0f172a !important;
    }
    html[data-theme="light"] .rating-bar-track {
        background: #e2e8f0 !important;
    }
    html[data-theme="light"] .star-btn {
        color: #cbd5e1;
    }
    html[data-theme="light"] .star-btn:hover,
    html[data-theme="light"] .star-btn.hovered,
    html[data-theme="light"] .star-btn.active {
        color: #f59e0b !important;
    }
    html[data-theme="light"] .review-filter-btn {
        background: #f1f5f9 !important;
        border-color: #cbd5e1 !important;
        color: #475569 !important;
    }
    html[data-theme="light"] .review-filter-btn.active {
        background: #f59e0b !important;
        color: #ffffff !important;
        border-color: #f59e0b !important;
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

                        <div class="gym-hero-rating-row" style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap;">
                            <?= render_star_rating($ratingStats['avg_rating'], 'sm', true, $ratingStats['total_reviews']) ?>
                            <?php if ($ratingStats['total_reviews'] > 0): ?>
                                <a href="#gym-ratings-section" style="font-size: 12px; color: var(--lime); font-weight: 600; text-decoration: none;">View <?= $ratingStats['total_reviews'] ?> <?= $ratingStats['total_reviews'] === 1 ? 'review' : 'reviews' ?> ↓</a>
                            <?php else: ?>
                                <span style="font-size: 12px; color: var(--muted);">No member reviews yet</span>
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

        <!-- Section: Membership Plans -->
        <?php if (!empty($plans)): ?>
            <div class="gym-panel-box">
                <div class="gym-section-title">
                    <div class="gym-section-title-left">
                        <div class="gym-section-icon-wrap icon-plans">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                        </div>
                        <span>Membership Plans</span>
                    </div>
                    <a href="index.php?page=memberships" class="gym-section-header-link">
                        <span>View all plans</span>
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                    </a>
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

        <!-- Section: Classes & Sessions -->
        <div class="gym-panel-box">
            <div class="gym-section-title">
                <div class="gym-section-title-left">
                    <div class="gym-section-icon-wrap icon-classes">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    </div>
                    <span>Classes & Sessions</span>
                </div>
                <a href="index.php?page=book_classes" class="gym-section-header-link">
                    <span>Schedule & Booking</span>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
            </div>

            <?php if (!empty($classes)): ?>
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
                <div class="gym-empty-compact">
                    No group classes currently scheduled at this partner location.
                </div>
            <?php endif; ?>
        </div>

        <!-- Section: Equipment & Stations -->
        <?php if (!empty($equipment)): ?>
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
                    <?php foreach ($equipment as $idx => $eq): ?>
                        <div class="gym-equip-card <?= $idx >= 4 ? 'gym-equip-extra' : '' ?>" <?= $idx >= 4 ? 'style="display:none;"' : '' ?>>
                            <div class="gym-equip-icon">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-weight: 700; font-size: 13.5px; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    <?= h($eq['name']) ?> <?= !empty($eq['unit_number']) ? '<small style="color:var(--muted); font-weight:500;">'.h($eq['unit_number']).'</small>' : '' ?>
                                </div>
                                <div style="font-size: 11px; color: var(--muted); display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                                    <span><?= h($eq['category'] ?? 'General') ?></span>
                                    <span>•</span>
                                    <?php 
                                        $eqStatus = $eq['status'] ?? 'available';
                                        $statusClass = 'status-' . strtolower(str_replace(' ', '_', $eqStatus));
                                        $statusLabel = ucfirst(str_replace('_', ' ', $eqStatus));
                                    ?>
                                    <span class="gym-status-badge <?= $statusClass ?>">
                                        <?= h($statusLabel) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (count($equipment) > 4): ?>
                    <div style="text-align: center; margin-top: 14px;">
                        <button type="button" class="btn-toggle-equip" id="btnToggleEquip" data-expanded="false" onclick="toggleAllEquipment(<?= count($equipment) ?>)">
                            <span>View all <?= count($equipment) ?> equipment</span>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Section: Coaching Staff -->
        <?php if (!empty($trainers)): ?>
            <div class="gym-panel-box">
                <div class="gym-section-title">
                    <div class="gym-section-title-left">
                        <div class="gym-section-icon-wrap icon-trainers">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </div>
                        <span>Trainers & Coaches</span>
                    </div>
                    <span style="font-size: 13px; color: var(--muted);"><?= count($trainers) ?> available</span>
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
                            <img src="<?= h(upload_url($img)) ?>" alt="<?= h($gym['name']) ?>" loading="lazy">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Section: Member Ratings & Reviews -->
        <div class="gym-panel-box" id="gym-ratings-section">
            <div class="gym-section-title">
                <div class="gym-section-title-left">
                    <div class="gym-section-icon-wrap icon-rating">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="#fbbf24" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    </div>
                    <span>Ratings & Member Reviews</span>
                </div>
                <span style="font-size: 13px; color: var(--muted);"><?= $ratingStats['total_reviews'] ?> <?= $ratingStats['total_reviews'] === 1 ? 'review' : 'reviews' ?></span>
            </div>

            <!-- Rating Overview & Interactive Review Form Grid -->
            <div class="gym-rating-hero-grid">
                <!-- Left: Aggregated Rating Stats & Breakdown -->
                <div class="gym-rating-summary-card">
                    <div class="gym-rating-big-score">
                        <div class="big-score-val"><?= number_format($ratingStats['avg_rating'], 1) ?></div>
                        <div class="big-score-stars">
                            <?= render_star_rating($ratingStats['avg_rating'], 'md') ?>
                            <div class="big-score-count">Based on <?= $ratingStats['total_reviews'] ?> <?= $ratingStats['total_reviews'] === 1 ? 'member review' : 'member reviews' ?></div>
                        </div>
                    </div>

                    <div class="gym-rating-bars-list">
                        <?php for ($s = 5; $s >= 1; $s--): 
                            $cnt = $ratingStats['breakdown'][$s] ?? 0;
                            $pct = $ratingStats['breakdown_pct'][$s] ?? 0;
                        ?>
                            <div class="rating-bar-row">
                                <span class="bar-star-label"><?= $s ?> ★</span>
                                <div class="rating-bar-track">
                                    <div class="rating-bar-fill" style="width: <?= $pct ?>%;"></div>
                                </div>
                                <span class="bar-count-label"><?= $cnt ?></span>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- Right: Submit / Edit Review or Status -->
                <div class="gym-rating-action-card">
                    <?php if ($canReview): 
                        $initialRating = isset($_GET['rating']) && (int)$_GET['rating'] >= 1 && (int)$_GET['rating'] <= 5 
                            ? (int)$_GET['rating'] 
                            : (int) ($myReview['rating'] ?? 5);
                    ?>
                        <form method="POST" action="index.php" id="formGymRating" class="rating-submit-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="submit_gym_rating" value="1">
                            <input type="hidden" name="gym_id" value="<?= $gymId ?>">
                            <input type="hidden" name="rating" id="selectedGymRating" value="<?= $initialRating ?>">

                            <div class="form-header-row">
                                <h4 style="margin: 0; font-size: 16px; font-weight: 700; color: var(--ink);">
                                    <?= $myReview ? 'Update Your Rating & Review' : 'Rate & Review ' . h($gym['name']) ?>
                                </h4>
                                <?php if ($myReview): ?>
                                    <span class="user-reviewed-badge">Reviewed <?= date('M j', strtotime($myReview['created_at'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <p style="margin: 4px 0 14px; font-size: 12.5px; color: var(--muted);">
                                Share your personal experience with equipment, coaches, cleanliness, and overall facility.
                            </p>

                            <!-- Interactive Star Picker -->
                            <div class="interactive-star-picker" id="starPickerContainer" role="radiogroup" aria-label="Rating from 1 to 5 stars">
                                <div class="star-picker-stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <button type="button" class="star-btn <?= ($i <= $initialRating) ? 'active' : '' ?>" data-val="<?= $i ?>" aria-label="<?= $i ?> stars" title="<?= $i ?> Stars">
                                            <svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                        </button>
                                    <?php endfor; ?>
                                </div>
                                <span class="star-picker-text" id="starPickerLabel"><?= $initialRating ?> / 5 Stars</span>
                            </div>

                            <!-- Optional Written Review -->
                            <div style="margin-top: 14px;">
                                <label for="gymReviewText" style="display: block; font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 6px;">
                                    Written Review <span style="font-weight: 400; opacity: 0.8;">(optional)</span>
                                </label>
                                <textarea name="review" id="gymReviewText" rows="3" class="form-textarea-review" placeholder="How was your workout experience? Equipment availability, trainers, hygiene..."><?= h($myReview['review'] ?? '') ?></textarea>
                            </div>

                            <div style="margin-top: 14px; display: flex; justify-content: flex-end;">
                                <button type="submit" class="btn btn-lime btn-sm" id="btnSubmitGymReview">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                    <span><?= $myReview ? 'Update Review' : 'Post Review' ?></span>
                                </button>
                            </div>
                        </form>
                    <?php elseif ($user['role'] === 'member'): ?>
                        <div class="rating-not-eligible-box">
                            <div class="not-eligible-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            </div>
                            <h4 style="margin: 0 0 6px; font-size: 15px; font-weight: 700; color: var(--ink);">Member Review Eligibility</h4>
                            <p style="margin: 0; font-size: 13px; color: var(--muted); line-height: 1.5;">
                                Only members enrolled in <strong><?= h($gym['name']) ?></strong> or members who have checked in / visited can leave a rating and review.
                            </p>
                            <?php if (!$isAffiliatedWithThisGym): ?>
                                <div style="margin-top: 14px;">
                                    <a href="index.php?page=gym_selection" class="btn btn-lime btn-sm">Affiliate With This Gym</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="rating-not-eligible-box">
                            <div class="not-eligible-icon" style="color: var(--lime); background: rgba(132, 204, 22, 0.1);">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            </div>
                            <h4 style="margin: 0 0 6px; font-size: 15px; font-weight: 700; color: var(--ink);">Verified Community Ratings</h4>
                            <p style="margin: 0; font-size: 13px; color: var(--muted); line-height: 1.5;">
                                All member ratings shown here are verified check-ins and enrolled members of <?= h($gym['name']) ?>.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Review Filter Pills -->
            <?php if (!empty($gymReviews)): ?>
                <div class="gym-reviews-filter-bar">
                    <span style="font-size: 13px; font-weight: 600; color: var(--muted);">Filter:</span>
                    <button type="button" class="review-filter-btn active" data-star="all" onclick="filterReviews('all', this)">All (<?= count($gymReviews) ?>)</button>
                    <?php for ($s = 5; $s >= 1; $s--): 
                        $sCount = $ratingStats['breakdown'][$s] ?? 0;
                        if ($sCount > 0): ?>
                            <button type="button" class="review-filter-btn" data-star="<?= $s ?>" onclick="filterReviews(<?= $s ?>, this)"><?= $s ?> ★ (<?= $sCount ?>)</button>
                        <?php endif; 
                    endfor; ?>
                </div>

                <!-- Reviews Feed List -->
                <div class="gym-reviews-feed" id="gymReviewsFeed">
                    <?php foreach ($gymReviews as $rev): ?>
                        <div class="gym-review-item" data-rating="<?= (int) $rev['rating'] ?>">
                            <div class="review-item-header">
                                <div class="review-user-info">
                                    <div class="review-user-avatar">
                                        <?php if (!empty($rev['profile_picture'])): ?>
                                            <img src="<?= h($rev['profile_picture']) ?>" alt="<?= h($rev['first_name']) ?>">
                                        <?php else: ?>
                                            <?= h(strtoupper(substr($rev['first_name'], 0, 1) . substr($rev['last_name'], 0, 1))) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="review-user-name">
                                            <?= h($rev['first_name'] . ' ' . $rev['last_name']) ?>
                                            <?php if ((int)$rev['user_id'] === (int)$user['user_id']): ?>
                                                <span class="review-you-pill">You</span>
                                            <?php endif; ?>
                                            <span class="review-verified-pill">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <?= $rev['is_enrolled'] ? 'Enrolled Member' : 'Verified Visitor' ?>
                                            </span>
                                        </div>
                                        <div class="review-date-str"><?= date('M j, Y', strtotime($rev['updated_at'] ?: $rev['created_at'])) ?></div>
                                    </div>
                                </div>
                                <div class="review-item-stars">
                                    <?= render_star_rating((float) $rev['rating'], 'sm') ?>
                                </div>
                            </div>
                            <?php if (!empty($rev['review'])): ?>
                                <div class="review-comment-body">
                                    <?= nl2br(h($rev['review'])) ?>
                                </div>
                            <?php else: ?>
                                <div class="review-comment-empty">
                                    Rated <?= (int) $rev['rating'] ?> out of 5 stars
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="gym-empty-compact" style="margin-top: 20px; text-align: center; padding: 32px 20px;">
                    <div style="font-size: 28px; margin-bottom: 8px;">★</div>
                    <h4 style="margin: 0 0 4px; color: var(--ink);">No member reviews yet</h4>
                    <p style="margin: 0; color: var(--muted); font-size: 13px;">Be the first member to rate and review <?= h($gym['name']) ?>!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function toggleAllEquipment(totalCount) {
        const extraItems = document.querySelectorAll('.gym-equip-extra');
        const btn = document.getElementById('btnToggleEquip');
        if (!btn || !extraItems.length) return;

        const isExpanded = btn.getAttribute('data-expanded') === 'true';
        if (isExpanded) {
            extraItems.forEach(el => el.style.display = 'none');
            btn.setAttribute('data-expanded', 'false');
            btn.innerHTML = '<span>View all ' + totalCount + ' equipment</span> <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>';
        } else {
            extraItems.forEach(el => el.style.display = 'flex');
            btn.setAttribute('data-expanded', 'true');
            btn.innerHTML = '<span>Show less</span> <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg>';
        }
    }

    // Star Picker Handler
    (function initStarPicker() {
        const starPicker = document.getElementById('starPickerContainer');
        if (!starPicker) return;
        const starBtns = starPicker.querySelectorAll('.star-btn');
        const hiddenInput = document.getElementById('selectedGymRating');
        const pickerLabel = document.getElementById('starPickerLabel');

        function updateStarDisplay(val) {
            starBtns.forEach(btn => {
                const bVal = parseInt(btn.dataset.val, 10);
                btn.classList.toggle('active', bVal <= val);
            });
            if (pickerLabel) {
                pickerLabel.textContent = val + ' / 5 Stars';
            }
        }

        starBtns.forEach(btn => {
            btn.addEventListener('mouseenter', () => {
                const hoverVal = parseInt(btn.dataset.val, 10);
                starBtns.forEach(b => {
                    const bVal = parseInt(b.dataset.val, 10);
                    b.classList.toggle('hovered', bVal <= hoverVal);
                });
            });

            btn.addEventListener('mouseleave', () => {
                starBtns.forEach(b => b.classList.remove('hovered'));
            });

            btn.addEventListener('click', () => {
                const clickVal = parseInt(btn.dataset.val, 10);
                if (hiddenInput) hiddenInput.value = clickVal;
                updateStarDisplay(clickVal);
            });
        });
    })();

    // Filter Reviews
    function filterReviews(star, btn) {
        document.querySelectorAll('.review-filter-btn').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');

        const items = document.querySelectorAll('.gym-review-item');
        items.forEach(item => {
            const itemRating = parseInt(item.dataset.rating, 10);
            if (star === 'all' || itemRating === parseInt(star, 10)) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    }
    </script>

    <?php
    render_footer();
}
