<?php
declare(strict_types=1);

/**
 * Renders the Floating Rating Modal in the lower right for logged-in gym members.
 * Includes close (✕) button, "Don't show this again today" option, and direct rating/review action.
 */
function render_member_floating_rating_modal(array $user): void
{
    if (($user['role'] ?? '') !== 'member') {
        return;
    }

    $currentPage = $_GET['page'] ?? 'dashboard';
    // Do not show on the full view_gym page where the review form is already prominent
    if ($currentPage === 'view_gym') {
        return;
    }

    $userId = (int) ($user['user_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    // Resolve member's primary gym
    $gym = get_user_gym($user);
    if (!$gym || empty($gym['gym_id'])) {
        return;
    }

    $gymId = (int) $gym['gym_id'];
    $gymName = htmlspecialchars($gym['name'] ?? 'Your Gym');

    $myRating = get_user_gym_review($userId, $gymId);
    $starScore = $myRating ? (float)$myRating['rating'] : 0.0;
    ?>
    <link rel="stylesheet" href="<?= h(asset_url('css/components/floating_rating_modal.css')) ?>">

    <div id="ft-floating-rating-modal"
         data-user-id="<?= $userId ?>"
         data-gym-id="<?= $gymId ?>"
         class="<?= $myRating ? 'is-rated' : 'is-unrated' ?>"
         role="dialog"
         aria-labelledby="ft-floating-rating-title">
        <!-- Close (✕) button -->
        <button type="button" id="ft-close-rating-modal" aria-label="Close rating modal" title="Close">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>

        <div style="padding-right:26px;">
            <div style="min-width:0;">
                <div class="ft-modal-title" id="ft-floating-rating-title">
                    <?= $myRating ? 'Your Rating for ' . $gymName : 'Rate ' . $gymName ?>
                </div>

                <div class="ft-modal-subtext">
                    <?php if ($myRating): ?>
                        <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-top:2px;">
                            <span>You rated</span>
                            <span style="color:#fbbf24; font-weight:700;"><?= render_star_rating($starScore, 13, true) ?></span>
                        </div>
                        <span style="display:block; font-size:11px; opacity:0.85; margin-top:2px;">Contributes to your gym's score</span>
                    <?php else: ?>
                        <span>How is your experience? Help your gym grow with a quick rating!</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!$myRating): ?>
        <!-- Quick 1-5 Star Selector for unrated members -->
        <div class="ft-modal-quick-box" style="margin-top:10px; padding:8px 12px; background:rgba(255,255,255,0.04); border-radius:10px; display:flex; align-items:center; justify-content:space-between;">
            <span class="ft-modal-quick-label" style="font-size:11px; color:#94a3b8; font-weight:600;">Tap to Rate:</span>
            <div style="display:flex; align-items:center; gap:3px;" id="ft-quick-stars-picker">
                <?php for ($s = 1; $s <= 5; $s++): ?>
                    <button type="button" class="ft-modal-quick-star" data-rating="<?= $s ?>" title="<?= $s ?> Stars" aria-label="<?= $s ?> Stars">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    </button>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Bottom Row: "Don't show again today" & Action Button -->
        <div class="ft-modal-footer">
            <label class="ft-modal-label">
                <input type="checkbox" id="ft-dont-show-rating-today" style="cursor:pointer; accent-color:var(--lime); width:13.5px; height:13.5px; margin:0;">
                <span>Don't show this again today</span>
            </label>

            <a href="index.php?page=view_gym&gym_id=<?= $gymId ?>#gym-ratings-section" id="ft-btn-open-gym-review" class="btn <?= $myRating ? 'btn-secondary' : 'btn-lime' ?>" style="font-size:11.5px; padding:5px 13px; text-decoration:none; display:inline-flex; align-items:center; gap:5px; font-weight:700; border-radius:8px;">
                <span><?= $myRating ? 'Edit Review' : 'Rate Gym ★' ?></span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
        </div>
    </div>

    <script src="<?= h(asset_url('js/components/floating_rating_modal.js')) ?>"></script>
    <?php
}

/**
 * Renders the Floating Rating Modal in the lower right for gym owners.
 * Prompts them to leave a review / rating for the FitTrack Platform.
 */
function render_owner_floating_rating_modal(array $user): void
{
    if (!in_array(($user['role'] ?? ''), ['gym_owner', 'admin'], true)) {
        return;
    }

    $userId = (int) ($user['user_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    $myReview = get_owner_platform_review($userId);
    $starScore = $myReview ? (float)$myReview['rating'] : 0.0;
    ?>
    <link rel="stylesheet" href="<?= h(asset_url('css/components/floating_rating_modal.css')) ?>">

    <div id="ft-owner-floating-rating-modal"
         data-user-id="<?= $userId ?>"
         class="<?= $myReview ? 'is-rated' : 'is-unrated' ?>"
         role="dialog"
         aria-labelledby="ft-owner-floating-rating-title">
        <!-- Close (✕) button -->
        <button type="button" id="ft-close-owner-rating-modal" aria-label="Close rating modal" title="Close">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>

        <div style="padding-right:26px;">
            <div style="min-width:0;">
                <div class="ft-owner-modal-title" id="ft-owner-floating-rating-title">
                    <?= $myReview ? 'FitTrack Platform Review' : 'Rate FitTrack Platform' ?>
                </div>

                <div class="ft-owner-modal-subtext">
                    <?php if ($myReview): ?>
                        <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-top:2px;">
                            <span>You rated</span>
                            <span style="color:#fbbf24; font-weight:700;"><?= render_star_rating($starScore, 13, true) ?></span>
                        </div>
                        <span style="display:block; font-size:11px; opacity:0.85; margin-top:2px;">Featured on the public landing page</span>
                    <?php else: ?>
                        <span>How is your gym software experience? Share feedback to feature on our landing page!</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!$myReview): ?>
        <!-- Quick 1-5 Star Selector for unrated gym owners -->
        <div class="ft-owner-modal-quick-box" style="margin-top:10px; padding:8px 12px; background:rgba(255,255,255,0.04); border-radius:10px; display:flex; align-items:center; justify-content:space-between;">
            <span class="ft-owner-modal-quick-label" style="font-size:11px; color:#94a3b8; font-weight:600;">Tap to Rate:</span>
            <div style="display:flex; align-items:center; gap:3px;" id="ft-owner-quick-stars-picker">
                <?php for ($s = 1; $s <= 5; $s++): ?>
                    <button type="button" class="ft-owner-modal-quick-star" data-rating="<?= $s ?>" title="<?= $s ?> Stars" aria-label="<?= $s ?> Stars">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    </button>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Bottom Row: "Don't show again today" & Action Button -->
        <div class="ft-owner-modal-footer">
            <label class="ft-owner-modal-label">
                <input type="checkbox" id="ft-owner-dont-show-rating-today" style="cursor:pointer; accent-color:var(--lime); width:13.5px; height:13.5px; margin:0;">
                <span>Don't show this again today</span>
            </label>

            <a href="index.php?page=profile&tab=ratings_feedback#platform-feedback-card" id="ft-btn-open-owner-review" class="btn <?= $myReview ? 'btn-secondary' : 'btn-lime' ?>" style="font-size:11.5px; padding:5px 13px; text-decoration:none; display:inline-flex; align-items:center; gap:5px; font-weight:700; border-radius:8px;">
                <span><?= $myReview ? 'Edit Feedback' : 'Rate FitTrack ★' ?></span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
        </div>
    </div>

    <script src="<?= h(asset_url('js/components/floating_rating_modal.js')) ?>"></script>
    <?php
}
