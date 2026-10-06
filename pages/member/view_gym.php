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
        :root {
            --gym-brand: <?= h($brandColor) ?>;
        }
    </style>
    <link rel="stylesheet" href="<?= h(asset_url('css/pages/view_gym.css')) ?>">

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

    <script src="<?= h(asset_url('js/pages/view_gym.js')) ?>"></script>

    <?php
    render_footer();
}
