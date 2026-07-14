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
        $validator = new Validator();
        $valid = $validator->validate($_POST, [
            'height_cm' => 'numeric|min_num:100|max_num:250',
            'weight_kg' => 'numeric|min_num:20|max_num:300',
            'age'       => 'numeric|min_num:16|max_num:120',
            'neck_cm'   => 'numeric|min_num:20|max_num:100',
            'waist_cm'  => 'numeric|min_num:30|max_num:200',
            'hip_cm'    => 'numeric|min_num:30|max_num:200',
        ]);
        
        if (!$valid) {
            flash($validator->firstError() ?? 'Invalid measurements provided.', 'danger');
            redirect('setup_profile');
        }

        save_member_profile((int) $user['user_id']);
        generate_workout_plan((int) $user['user_id']); // Keep existing plan generation if needed, or we can rely on the lookup

        // Lookup engine for starter workout and diet
        $pdo = db();
        $stmtProfile = $pdo->prepare('SELECT * FROM member_profiles WHERE user_id = ?');
        $stmtProfile->execute([$user['user_id']]);
        $profile = $stmtProfile->fetch();

        $tier = $profile['fitness_tier'] ?? 1;
        $sex = $profile['biological_sex'];
        $goal = $profile['primary_goal'];
        $activity = $profile['activity_level'];

        // Workout rule lookup
        $wRule = $pdo->prepare('SELECT recommended_workout_structure FROM workout_rules WHERE experience_level = ? AND (biological_sex = ? OR biological_sex = "any") AND primary_goal = ? AND (activity_level = ? OR activity_level = "any") LIMIT 1');
        $wRule->execute([$tier, $sex, $goal, $activity]);
        $workoutStruct = $wRule->fetchColumn();
        if (!$workoutStruct) {
            $wRule->execute([1, 'any', $goal, 'any']);
            $workoutStruct = $wRule->fetchColumn() ?: 'General full body workout 3 times a week.';
        }

        // Diet rule lookup
        $dRule = $pdo->prepare('SELECT macro_split, notes FROM diet_rules WHERE experience_level = ? AND (biological_sex = ? OR biological_sex = "any") AND primary_goal = ? AND (activity_level = ? OR activity_level = "any") LIMIT 1');
        $dRule->execute([$tier, $sex, $goal, $activity]);
        $dietInfo = $dRule->fetch();
        if (!$dietInfo) {
            $dRule->execute([1, 'any', $goal, 'any']);
            $dietInfo = $dRule->fetch();
        }
        $dietStruct = $dietInfo ? ($dietInfo['macro_split'] . ' - ' . $dietInfo['notes']) : 'Balanced diet.';

        $msgBody = "Based on your profile, here is your starter guide!\n\n**Workout Structure:**\n$workoutStruct\n\n**Diet & Macros:**\n$dietStruct";
        notify_user((int) $user['user_id'], 'system', 'Your Starter Plan is Ready!', $msgBody);

        if (empty(post('height_cm')) || empty(post('weight_kg'))) {
            flash('Welcome to FITTRACKS! Please add your measurements later.', 'success');
        } else {
            flash('Physical profile saved! Check your notifications for your starter plan.', 'success');
        }
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
                <label>Height (cm) <small style="color:var(--muted);font-weight:normal;">(Optional)</small>
                    <input name="height_cm" type="number" step="0.01" min="1" placeholder="Leave blank if unknown">
                </label>
                <label>Weight (kg) <small style="color:var(--muted);font-weight:normal;">(Optional)</small>
                    <input name="weight_kg" type="number" step="0.01" min="1" placeholder="Leave blank if unknown">
                </label>
                <label>Age <small style="color:var(--muted);font-weight:normal;">(Optional)</small>
                    <input name="age" type="number" min="16" max="120" placeholder="e.g. 30">
                </label>
                <label>Neck (cm) <small style="color:var(--muted);font-weight:normal;">(Optional)</small>
                    <input name="neck_cm" type="number" step="0.01" min="1" placeholder="Leave blank if unknown">
                </label>
                <label>Waist (cm) <small style="color:var(--muted);font-weight:normal;">(Optional)</small>
                    <input name="waist_cm" type="number" step="0.01" min="1" placeholder="Leave blank if unknown">
                </label>
                <label id="hipContainer" style="display: none;">Hip (cm) <small style="color:var(--muted);font-weight:normal;">(Optional)</small>
                    <input name="hip_cm" type="number" step="0.01" min="1" placeholder="Widest part (required for females)">
                </label>
                <label>Biological sex
                    <select name="biological_sex" id="biological_sex" required>
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
        
        // Handle biological sex change to show/hide Hip measurement
        const sexSelect = document.getElementById('biological_sex');
        const hipContainer = document.getElementById('hipContainer');
        const hipInput = document.querySelector('input[name="hip_cm"]');
        
        sexSelect.addEventListener('change', function() {
            if (this.value === 'female') {
                hipContainer.style.display = 'block';
                hipInput.required = true;
            } else {
                hipContainer.style.display = 'none';
                hipInput.required = false;
                hipInput.value = '';
            }
        });
        
        // Trigger change event on page load to set initial state
        sexSelect.dispatchEvent(new Event('change'));
    })();
    </script>
    <?php
    render_footer();
}
