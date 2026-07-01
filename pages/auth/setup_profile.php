<?php
declare(strict_types=1);

function setup_profile_page(): void
{
    define('AUTH_PAGE', true);

    // Need a user, but we don't want to use require_login() since it redirects here.
    $user = current_user();
    if (!$user) {
        redirect('login');
    }

    // Only for members
    if ($user['role'] !== 'member') {
        redirect('dashboard');
    }

    // If they already have a profile, send them to the dashboard
    if (member_profile((int) $user['user_id']) !== null) {
        redirect('dashboard');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        save_member_profile((int) $user['user_id']);
        generate_workout_plan((int) $user['user_id']);
        notify_user((int) $user['user_id'], 'system', 'Welcome to FITTRACKS', 'Your profile is set up and your personalised workout plan is ready.');
        flash('Physical profile saved! Welcome to FITTRACKS.', 'success');
        redirect('dashboard');
    }

    // We pass null for $user to render_header so that the sidebar/topbar are NOT rendered.
    // This makes it a standalone page.
    render_header('Complete your profile', null);
    ?>
    <section style="padding: 40px 0; min-height: 80vh; display:flex; align-items:center; justify-content:center;">
        <div class="auth-card" style="max-width:700px; width:100%;">
            <div class="auth-card-header">
                <h1 class="auth-title" style="font-size:1.6rem;">One more step</h1>
                <p class="auth-subtitle">Fill in your physical details so FITTRACKS can build your personalised workout plan.</p>
            </div>
            <form method="post" action="index.php?page=setup_profile" class="form grid-form" style="padding:0 0 8px;">
                <?= csrf_field() ?>
                <label>Height (cm)
                    <input name="height_cm" type="number" step="0.01" min="1" required placeholder="e.g. 170">
                </label>
                <label>Weight (kg)
                    <input name="weight_kg" type="number" step="0.01" min="1" required placeholder="e.g. 65">
                </label>
                <label>Age
                    <input name="age" type="number" min="16" max="120" required>
                </label>
                <label>Biological sex
                    <select name="biological_sex" required>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </label>
                <label>Activity level
                    <select name="activity_level" required>
                        <?php foreach (['sedentary', 'lightly_active', 'moderately_active', 'very_active', 'extra_active'] as $level): ?>
                            <option value="<?= h($level) ?>"><?= h(ucwords(str_replace('_', ' ', $level))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Primary goal
                    <select name="primary_goal" required>
                        <?php foreach (['fat_loss', 'muscle_gain', 'maintenance', 'general_health'] as $goal): ?>
                            <option value="<?= h($goal) ?>"><?= h(ucwords(str_replace('_', ' ', $goal))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="auth-submit-btn full-width" style="grid-column:1/-1;margin-top:8px;">
                    SAVE &amp; CONTINUE TO DASHBOARD
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg>
                </button>
            </form>
            <div class="corner corner-tl"></div>
            <div class="corner corner-tr"></div>
            <div class="corner corner-bl"></div>
            <div class="corner corner-br"></div>
        </div>
    </section>
    <script>
    // Prevent accidental navigation away before the form is submitted
    (function() {
        let submitted = false;
        document.querySelector('form').addEventListener('submit', function() { submitted = true; });
        window.addEventListener('beforeunload', function(e) {
            if (!submitted) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    })();
    </script>
    <?php
    render_footer();
}
