<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/gym_selection_service.php';

function gym_selection_page(): void
{
    define('AUTH_PAGE', true);
    $user = require_roles(['member']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'select_gym') {
        verify_csrf();
        $gymId = (int) ($_POST['gym_id'] ?? 0);
        if ($gymId > 0) {
            $targetGym = db()->query("SELECT * FROM gyms WHERE gym_id = " . $gymId)->fetch(PDO::FETCH_ASSOC);
            if (!$targetGym || gym_subscription_tier($targetGym) === 'none' || !gym_can_add_member($gymId, $targetGym)) {
                $limit = gym_member_limit($targetGym);
                $current = gym_active_member_count($gymId);
                if ($limit > 0 && $current >= $limit) {
                    flash("This gym has reached its maximum active member capacity ({$current}/{$limit} members). Please select another gym or contact the gym owner.", 'warning');
                } else {
                    flash('This gym is currently not accepting new members. Please select another gym or contact the gym owner.', 'warning');
                }
                redirect('index.php?page=gym_selection');
                return;
            }
            // Set active gym affiliation (preserves multi-gym affiliations)
            db()->prepare('INSERT IGNORE INTO gym_members (user_id, gym_id) VALUES (?, ?)')
                ->execute([$user['user_id'], $gymId]);
            $_SESSION['current_gym_id'] = $gymId;
            flash('Successfully switched active gym to ' . $targetGym['name'] . '.', 'success');
            redirect('index.php?page=dashboard');
        }
    }

    // Display gyms that are approved and accessible (active subscription, free trial, or free tier)
    $gyms = db()->query("
        SELECT * FROM gyms 
        WHERE status = 'approved'
    ")->fetchAll();

    $gymData = load_gym_selection_data($gyms);

    $profile = member_profile((int) $user['user_id']);
    $userGoal = strtolower($profile['primary_goal'] ?? '');

    render_header('Select Your Gym', $user);
    ?>
    <link rel="stylesheet" href="<?= h(asset_url('css/pages/gym_selection.css')) ?>">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script>
        document.documentElement.setAttribute('data-theme', 'dark');
    </script>

    <div class="panel gym-select">
        <div class="gym-hero" id="gym-header">
            <div class="eyebrow"><span class="dot"></span> Membership</div>
            <h1>
                <?php if ($userGoal): ?>
                    Gyms matched to your <mark><?= h(str_replace(['-', '_'], ' ', $userGoal)) ?></mark> goal
                <?php else: ?>
                    Find your <mark>perfect</mark> gym
                <?php endif; ?>
            </h1>
            <p class="sub">
                <?php if ($userGoal): ?>
                    We've flagged the gyms whose classes line up with what you're training for.
                <?php else: ?>
                    Browse classes, schedules, and membership plans from every gym on the platform.
                <?php endif; ?>
            </p>
            <svg class="pulse-line" viewBox="0 0 460 30" preserveAspectRatio="none" aria-hidden="true">
                <path d="M0,15 L150,15 L168,3 L184,27 L200,15 L215,15 L228,8 L240,22 L252,15 L460,15" />
            </svg>
            <div style="margin-top: 14px;">
                <a href="index.php?page=dashboard" style="display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; border-radius: 999px; background: rgba(255, 255, 255, 0.06); border: 1px solid rgba(255, 255, 255, 0.15); color: #94a3b8; font-size: 12.5px; font-weight: 600; text-decoration: none; transition: all 0.2s;" onmouseover="this.style.color='#f8fafc'; this.style.borderColor='rgba(199,255,34,0.4)'; this.style.background='rgba(199,255,34,0.08)';" onmouseout="this.style.color='#94a3b8'; this.style.borderColor='rgba(255,255,255,0.15)'; this.style.background='rgba(255,255,255,0.06)';">
                    <span>Skip gym selection for now &amp; go to Dashboard</span>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
            </div>
        </div>

        <?php if (empty($gymData)): ?>
            <p style="text-align: center; color: var(--muted);">No gyms are currently available on the platform.</p>
        <?php else: ?>
            <div class="gym-toolbar">
                <div class="gym-search-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="7"></circle>
                        <path d="M21 21l-3.8-3.8"></path>
                    </svg>
                    <input type="text" id="gym-search" placeholder="Search gyms by name or location..." autocomplete="off">
                </div>
                <div class="gym-toolbar-actions">
                    <button type="button" class="btn-gym-tool" id="btn-near-me" title="Sort gyms nearest to your location">
                        <svg class="geo-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                            <polygon points="3 11 22 2 13 21 11 13 3 11"/>
                        </svg>
                        <span class="btn-text">Near Me</span>
                    </button>
                    <div class="gym-view-switcher" role="tablist" aria-label="View switch">
                        <a href="index.php?page=gym_selection" class="view-btn is-active" id="btn-view-cards" aria-label="Cards View" title="Show cards carousel">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                            <span>Cards</span>
                        </a>
                        <a href="index.php?page=gym_map" class="view-btn" id="btn-view-map" aria-label="Map View" title="Open Interactive Gym Map">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
                            <span>Map</span>
                        </a>
                    </div>
                </div>
            </div>

            <div class="carousel-wrap" id="gym-carousel-wrap">
                <button type="button" class="carousel-nav prev" id="carousel-prev" aria-label="Previous gym">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"></path></svg>
                </button>
                <div class="carousel-container" id="carousel">
                    <?php foreach ($gymData as $index => $gym):
                        $isMatch = false;
                        if ($userGoal) {
                            $goalKeywords = explode('-', $userGoal);
                            foreach ($gym['classes'] as $c) {
                                $text = strtolower($c['class_name'] . ' ' . $c['description']);
                                foreach ($goalKeywords as $kw) {
                                    if (strlen($kw) > 3 && strpos($text, $kw) !== false) {
                                        $isMatch = true;
                                        break 2;
                                    }
                                }
                            }
                        }
                    ?>
                        <div class="gym-card" tabindex="0" role="button" style="--lime: <?= !empty($gym['brand_color']) ? h($gym['brand_color']) : '#c7ff22' ?>;" onclick="openGymModal(<?= (int)$index ?>)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openGymModal(<?= (int)$index ?>);}" data-index="<?= (int)$index ?>" data-gym-id="<?= (int)$gym['gym_id'] ?>" data-lat="<?= $gym['latitude'] !== null ? (float)$gym['latitude'] : '' ?>" data-lng="<?= $gym['longitude'] !== null ? (float)$gym['longitude'] : '' ?>" data-search="<?= h(mb_strtolower($gym['name'] . ' ' . $gym['address'])) ?>">
                            <?php if ($isMatch): ?>
                                <div class="gym-badge"><span class="dot"></span> Recommended</div>
                            <?php endif; ?>
                            <div class="gym-card-top">
                                <?php if (!empty($gym['logo_url'])): ?>
                                    <div class="gym-logo-tile"><img src="<?= h(upload_url($gym['logo_url'])) ?>" alt="<?= h($gym['name']) ?> logo" loading="lazy" decoding="async" onerror="this.onerror=null; this.outerHTML='<?= htmlspecialchars(substr(h($gym['name']), 0, 1), ENT_QUOTES) ?>';"></div>
                                <?php else: ?>
                                    <div class="gym-logo-tile"><?= substr(h($gym['name']), 0, 1) ?></div>
                                <?php endif; ?>
                                <h3><?= h($gym['name']) ?></h3>
                            </div>
                            <p class="addr-row">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                <span><?= h($gym['address']) ?></span>
                                <span class="gym-distance-badge" id="dist-badge-<?= (int)$gym['gym_id'] ?>" style="display: none;"></span>
                            </p>
                            <div class="gym-chip-row">
                                <?php $gStats = $gym['rating_stats'] ?? ['avg_rating' => 0, 'total_reviews' => 0]; ?>
                                <span class="gym-chip" style="color: #fbbf24; border-color: rgba(251, 191, 36, 0.3); background: rgba(251, 191, 36, 0.08);">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="#fbbf24" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                    <?= number_format((float)$gStats['avg_rating'], 1) ?> (<?= (int)$gStats['total_reviews'] ?>)
                                </span>
                                <span class="gym-chip">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                    <?= count($gym['classes']) ?> Classes
                                </span>
                                <span class="gym-chip">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1"/></svg>
                                    <?= count($gym['plans']) ?> Plans
                                </span>
                            </div>
                            <div class="view-hint">
                                View details
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="carousel-nav next" id="carousel-next" aria-label="Next gym">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"></path></svg>
                </button>
            </div>

            <div class="carousel-dots" id="carousel-dots"></div>

            <div class="gym-no-results" id="gym-no-results">No gyms match your search.</div>
        <?php endif; ?>

        <div class="skip-link">
            <a href="index.php?page=dashboard">Skip for now</a>
        </div>
    </div>

    <!-- The Modal -->
    <div id="gymDetailsModal">
        <div class="modal-backdrop" id="modal-backdrop"></div>
        <div class="gym-details-content" id="modal-content">
            <button class="close-btn" id="modal-close-btn" aria-label="Close">&times;</button>
            <div id="modalBody"></div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
    <!-- Gym Selection Configuration & Script -->
    <script>
    window.GYM_SELECTION_CONFIG = {
        gymData: <?= json_encode($gymData) ?>,
        csrfToken: <?= json_encode(csrf_token()) ?>
    };
    </script>
    <script src="<?= h(asset_url('js/pages/gym_selection.js')) ?>"></script>
    <?php
    render_footer();
}
