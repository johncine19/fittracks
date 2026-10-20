<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/gym_selection_service.php';

function gym_map_page(): void
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
                redirect('index.php?page=gym_map');
                return;
            }
            // Set active gym affiliation (preserves multi-gym affiliations)
            db()->prepare('INSERT IGNORE INTO gym_members (user_id, gym_id) VALUES (?, ?)')
                ->execute([$user['user_id'], $gymId]);
            $_SESSION['current_gym_id'] = $gymId;
            flash('Successfully switched active gym to ' . $targetGym['name'] . '.', 'success');
            redirect('index.php?page=dashboard');
            return;
        }
    }

    // Display approved gyms on platform
    $gyms = db()->query("
        SELECT * FROM gyms 
        WHERE status = 'approved'
    ")->fetchAll();

    $gymData = load_gym_selection_data($gyms);

    $profile = member_profile((int) $user['user_id']);
    $userGoal = strtolower($profile['primary_goal'] ?? '');

    render_header('Gym Locations Map', $user);
    ?>
    <link rel="stylesheet" href="<?= h(asset_url('css/pages/gym_selection.css')) ?>">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <div class="panel gym-select">
        <div class="gym-hero" id="gym-header">
            <div class="eyebrow"><span class="dot"></span> Interactive Map</div>
            <h1>
                <?php if ($userGoal): ?>
                    Find Gyms for <mark><?= h(str_replace(['-', '_'], ' ', $userGoal)) ?></mark>
                <?php else: ?>
                    Explore <mark>Gym Locations</mark>
                <?php endif; ?>
            </h1>
            <p class="sub">
                Explore gym locations on the map. Click any pin to see distance, classes, and preview details.
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
                    <button type="button" class="btn-gym-tool" id="btn-near-me" title="Locate nearest gyms with GPS">
                        <svg class="geo-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                            <polygon points="3 11 22 2 13 21 11 13 3 11"/>
                        </svg>
                        <span class="btn-text">Near Me</span>
                    </button>
                    <div class="gym-view-switcher" role="tablist" aria-label="View switch">
                        <a href="index.php?page=gym_selection" class="view-btn" id="btn-view-cards" aria-label="Cards View" title="Switch to Cards carousel">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                            <span>Cards</span>
                        </a>
                        <a href="index.php?page=gym_map" class="view-btn is-active" id="btn-view-map" aria-label="Map View" title="Show interactive map">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
                            <span>Map</span>
                        </a>
                    </div>
                </div>
            </div>

            <div class="gym-map-wrapper" id="gym-map-wrapper" style="display: block;">
                <div class="gym-map-header">
                    <div class="map-status-info">
                        <span class="map-status-dot"></span>
                        <span id="map-status-text">Interactive Gym Map • Click a pin to preview</span>
                    </div>
                </div>
                <div id="gym-map" style="height: 600px;"></div>
            </div>

            <div class="gym-no-results" id="gym-no-results" style="display: none;">No gyms match your search.</div>
        <?php endif; ?>

        <div class="skip-link">
            <a href="index.php?page=gym_selection">&larr; Back to Cards View</a>
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
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <!-- Gym Map Configuration & Script -->
    <script>
    window.GYM_SELECTION_CONFIG = {
        gymData: <?= json_encode($gymData) ?>,
        csrfToken: <?= json_encode(csrf_token()) ?>
    };
    </script>
    <script src="<?= h(asset_url('js/pages/gym_map.js')) ?>"></script>
    <?php
    render_footer();
}
