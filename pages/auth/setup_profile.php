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

    $profile = member_profile((int) $user['user_id']);
    if ($profile !== null) {
        if (!empty($profile['primary_goal'])) {
            redirect('dashboard');
        } else {
            redirect('setup_goal');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $validator = new Validator();
        $rules = [
            'height_cm' => 'required|numeric|min_num:100|max_num:250',
            'weight_kg' => 'required|numeric|min_num:20|max_num:300',
            'age'       => 'required|numeric|min_num:16|max_num:120',
            'neck_cm'   => 'required|numeric|min_num:20|max_num:100',
            'waist_cm'  => 'required|numeric|min_num:30|max_num:200',
            'target_weight_kg'        => 'numeric|min_num:20|max_num:300',
            'target_body_fat_percent' => 'numeric|min_num:1|max_num:70',
        ];

        if (post('biological_sex') === 'female') {
            $rules['hip_cm'] = 'required|numeric|min_num:30|max_num:200';
        } else {
            $rules['hip_cm'] = 'numeric|min_num:30|max_num:200';
        }

        $valid = $validator->validate($_POST, $rules);
        
        if (!$valid) {
            flash($validator->firstError() ?? 'Invalid measurements provided. Please check the fields.', 'danger');
            redirect('setup_profile');
        }

        save_member_profile((int) $user['user_id']);
        
        // Redirect to step 2: Goal selection
        redirect('setup_goal');
    }

    render_header('Complete your profile', null);
    ?>
    <style>
        .profile-bg-decor {
            position: absolute;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 0;
            border-radius: inherit;
        }
        .profile-blob {
            position: absolute;
            width: 320px;
            height: 320px;
            border-radius: 50%;
            filter: blur(85px);
            opacity: 0.12;
        }
        .profile-blob--a { background: var(--lime); top: -100px; left: -80px; }
        .profile-blob--b { background: #38bdf8; bottom: -120px; right: -90px; }

        .profile-card-wrap {
            position: relative;
            z-index: 1;
        }

        /* Stepper header */
        .onboarding-stepper {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 16px;
        }
        .stepper-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .stepper-pill.active {
            background: rgba(199, 255, 34, 0.12);
            color: var(--lime);
            border: 1px solid rgba(199, 255, 34, 0.35);
        }
        .stepper-pill.upcoming {
            background: rgba(255, 255, 255, 0.05);
            color: var(--muted);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .stepper-arrow {
            color: var(--muted);
            opacity: 0.4;
        }

        /* Form Sections */
        .form-section {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.07);
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 18px;
            transition: border-color 0.2s;
        }
        .form-section:focus-within {
            border-color: rgba(199, 255, 34, 0.3);
        }
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }
        .section-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }
        .section-title svg {
            color: var(--lime);
        }
        .section-badge {
            font-size: 0.72rem;
            padding: 2px 8px;
            border-radius: 4px;
            background: rgba(255, 255, 255, 0.06);
            color: var(--muted);
        }

        /* Sex Toggle Cards */
        .sex-toggle-wrap {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }
        .sex-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1.5px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 12px 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.2s ease;
            user-select: none;
        }
        .sex-card:hover {
            border-color: rgba(199, 255, 34, 0.4);
            background: rgba(255, 255, 255, 0.05);
        }
        .sex-card.selected {
            border-color: var(--lime);
            background: rgba(199, 255, 34, 0.08);
            box-shadow: 0 0 14px rgba(199, 255, 34, 0.12);
        }
        .sex-card input[type="radio"] {
            display: none;
        }
        .sex-icon-circle {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.06);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            color: var(--muted);
            transition: all 0.2s;
        }
        .sex-card.selected .sex-icon-circle {
            background: var(--lime);
            color: var(--bg);
            font-weight: bold;
        }

        /* Input Grid & Suffixes */
        .input-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }
        .input-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }

        .input-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .input-label {
            font-size: 0.82rem;
            font-weight: 500;
            color: var(--ink);
            display: flex;
            justify-content: space-between;
        }
        .input-label .sub {
            color: var(--muted);
            font-size: 0.75rem;
            font-weight: normal;
        }

        .input-field-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .input-field-wrap input {
            width: 100%;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 10px 42px 10px 14px;
            color: var(--ink);
            font-size: 0.92rem;
            font-weight: 500;
            outline: none;
            transition: all 0.2s;
        }
        .input-field-wrap input:focus {
            background: rgba(255, 255, 255, 0.07);
            border-color: var(--lime);
            box-shadow: 0 0 0 3px rgba(199, 255, 34, 0.1);
        }
        .input-unit {
            position: absolute;
            right: 12px;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--muted);
            pointer-events: none;
            letter-spacing: 0.3px;
        }

        /* Experience Tier Cards */
        .experience-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 14px;
        }
        .exp-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1.5px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 12px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s ease;
            user-select: none;
        }
        .exp-card:hover {
            border-color: rgba(199, 255, 34, 0.4);
            background: rgba(255, 255, 255, 0.05);
        }
        .exp-card.selected {
            border-color: var(--lime);
            background: rgba(199, 255, 34, 0.08);
            box-shadow: 0 0 14px rgba(199, 255, 34, 0.12);
        }
        .exp-card input[type="radio"] {
            display: none;
        }
        .exp-title {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--ink);
            margin-bottom: 2px;
        }
        .exp-desc {
            font-size: 0.72rem;
            color: var(--muted);
            line-height: 1.3;
        }

        /* Select styling */
        .select-field {
            width: 100%;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 10px 14px;
            color: var(--ink);
            font-size: 0.9rem;
            outline: none;
            transition: all 0.2s;
        }
        .select-field:focus {
            background: rgba(255, 255, 255, 0.07);
            border-color: var(--lime);
            box-shadow: 0 0 0 3px rgba(199, 255, 34, 0.1);
        }
        .select-field option {
            background: #18181b;
            color: #fff;
        }

        @media (max-width: 640px) {
            .input-grid-3, .input-grid-2, .experience-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <section style="padding: 30px 16px; min-height: 85vh; display:flex; align-items:center; justify-content:center;">
        <div class="auth-card profile-card-wrap" style="max-width:760px; width:100%; position:relative; overflow:hidden;">
            <div class="profile-bg-decor" aria-hidden="true">
                <div class="profile-blob profile-blob--a"></div>
                <div class="profile-blob profile-blob--b"></div>
            </div>

            <!-- Stepper -->
            <div class="onboarding-stepper" style="position:relative; z-index:1;">
                <span class="stepper-pill active">
                    <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:var(--lime); margin-right:4px;"></span>
                    Step 1: Measurements
                </span>
                <span class="stepper-arrow">›</span>
                <span class="stepper-pill upcoming">
                    Step 2: Primary Goal
                </span>
            </div>

            <div class="auth-card-header" style="position:relative; z-index:1; text-align: center; margin-bottom: 22px;">
                <h1 class="auth-title" style="font-size:1.75rem; margin-bottom: 6px;">Let's Personalize Your Fitness Journey</h1>
                <p class="auth-subtitle" style="max-width: 520px; margin: 0 auto;">Your body metrics allow FitTracks to compute accurate target calories, baseline metabolic rate, and custom workout volume.</p>
            </div>

            <form method="post" action="index.php?page=setup_profile" id="profile-form" style="position:relative; z-index:1;">
                <?= csrf_field() ?>

                <!-- SECTION 1: Core Physical Stats -->
                <div class="form-section">
                    <div class="section-header">
                        <h2 class="section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            1. Basic Physical Profile
                        </h2>
                        <span class="section-badge">Required</span>
                    </div>

                    <!-- Biological Sex Toggle -->
                    <div class="sex-toggle-wrap">
                        <label class="sex-card selected" id="card-sex-male">
                            <input type="radio" name="biological_sex" value="male" checked>
                            <div class="sex-icon-circle">♂</div>
                            <div>
                                <div style="font-weight:600; font-size:0.92rem; color:var(--ink);">Male</div>
                                <div style="font-size:0.75rem; color:var(--muted);">Standard formula</div>
                            </div>
                        </label>
                        <label class="sex-card" id="card-sex-female">
                            <input type="radio" name="biological_sex" value="female">
                            <div class="sex-icon-circle">♀</div>
                            <div>
                                <div style="font-weight:600; font-size:0.92rem; color:var(--ink);">Female</div>
                                <div style="font-size:0.75rem; color:var(--muted);">Includes hip calculation</div>
                            </div>
                        </label>
                    </div>

                    <!-- Age, Height, Weight -->
                    <div class="input-grid-3">
                        <div class="input-group">
                            <label class="input-label" for="input-age">Age <span class="sub">16-120</span></label>
                            <div class="input-field-wrap">
                                <input id="input-age" name="age" type="number" min="16" max="120" placeholder="e.g. 25" required>
                                <span class="input-unit">yrs</span>
                            </div>
                        </div>

                        <div class="input-group">
                            <label class="input-label" for="input-height">Height <span class="sub">100-250</span></label>
                            <div class="input-field-wrap">
                                <input id="input-height" name="height_cm" type="number" step="0.1" min="100" max="250" placeholder="e.g. 175" required>
                                <span class="input-unit">cm</span>
                            </div>
                        </div>

                        <div class="input-group">
                            <label class="input-label" for="input-weight">Weight <span class="sub">20-300</span></label>
                            <div class="input-field-wrap">
                                <input id="input-weight" name="weight_kg" type="number" step="0.1" min="20" max="300" placeholder="e.g. 70" required>
                                <span class="input-unit">kg</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: Body Circumferences -->
                <div class="form-section">
                    <div class="section-header">
                        <h2 class="section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                            2. Body Circumference (US Navy Formula)
                        </h2>
                        <span class="section-badge">Body Fat Estimation</span>
                    </div>

                    <div class="input-grid-3" id="circumference-grid">
                        <div class="input-group">
                            <label class="input-label" for="input-neck">Neck <span class="sub">Narrowest part</span></label>
                            <div class="input-field-wrap">
                                <input id="input-neck" name="neck_cm" type="number" step="0.1" min="20" max="100" placeholder="e.g. 38" required>
                                <span class="input-unit">cm</span>
                            </div>
                        </div>

                        <div class="input-group">
                            <label class="input-label" for="input-waist">Waist <span class="sub">At belly button</span></label>
                            <div class="input-field-wrap">
                                <input id="input-waist" name="waist_cm" type="number" step="0.1" min="30" max="200" placeholder="e.g. 82" required>
                                <span class="input-unit">cm</span>
                            </div>
                        </div>

                        <div class="input-group" id="hipContainer" style="display: none;">
                            <label class="input-label" for="input-hip" style="color: var(--lime);">Hip <span class="sub">Widest glute part</span></label>
                            <div class="input-field-wrap">
                                <input id="input-hip" name="hip_cm" type="number" step="0.1" min="30" max="200" placeholder="e.g. 95">
                                <span class="input-unit">cm</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: Fitness & Lifestyle Profile -->
                <div class="form-section">
                    <div class="section-header">
                        <h2 class="section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8zM6 1v3M10 1v3M14 1v3"/></svg>
                            3. Fitness Level & Habits
                        </h2>
                        <span class="section-badge">Custom Splits</span>
                    </div>

                    <!-- Experience Level Cards -->
                    <div class="input-label" style="margin-bottom: 8px;">Experience Level</div>
                    <div class="experience-grid">
                        <label class="exp-card selected" id="exp-1">
                            <input type="radio" name="experience_level" value="1" checked>
                            <div class="exp-title">🟢 Starter</div>
                            <div class="exp-desc">Under 6 months, building habit & form</div>
                        </label>
                        <label class="exp-card" id="exp-2">
                            <input type="radio" name="experience_level" value="2">
                            <div class="exp-title">🔵 Intermediate</div>
                            <div class="exp-desc">6mo – 2 years regular gym workouts</div>
                        </label>
                        <label class="exp-card" id="exp-3">
                            <input type="radio" name="experience_level" value="3">
                            <div class="exp-title">🟣 Advanced</div>
                            <div class="exp-desc">2+ years consistent heavy lifting</div>
                        </label>
                    </div>

                    <div class="input-grid-2">
                        <div class="input-group">
                            <label class="input-label" for="select-activity">Daily Activity Level</label>
                            <select name="activity_level" id="select-activity" class="select-field" required>
                                <option value="sedentary">Sedentary (Desk job, little exercise)</option>
                                <option value="lightly_active" selected>Lightly Active (1-3 days light exercise)</option>
                                <option value="moderately_active">Moderately Active (3-5 days moderate workout)</option>
                                <option value="very_active">Very Active (6-7 days hard training)</option>
                                <option value="extra_active">Extra Active (Intense training / physical job)</option>
                            </select>
                        </div>

                        <div class="input-group">
                            <label class="input-label" for="select-diet">Dietary Preference</label>
                            <select name="dietary_restrictions" id="select-diet" class="select-field" required>
                                <option value="none" selected>No Restrictions (Standard)</option>
                                <option value="vegetarian">Vegetarian</option>
                                <option value="vegan">Vegan</option>
                                <option value="pescatarian">Pescatarian</option>
                                <option value="halal">Halal</option>
                                <option value="gluten-free">Gluten-Free</option>
                                <option value="keto">Keto</option>
                                <option value="paleo">Paleo</option>
                                <option value="nut-allergy">Nut Allergy</option>
                                <option value="dairy-free">Dairy-Free</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="auth-submit-btn full-width" style="margin-top: 10px; padding: 14px 20px; font-weight: 700; letter-spacing: 0.5px;">
                    NEXT STEP: SELECT YOUR GOAL
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-left:8px;"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg>
                </button>
            </form>
        </div>
    </section>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script>
    (function() {
        let submitted = false;
        document.getElementById('profile-form').addEventListener('submit', function() { submitted = true; });
        window.addEventListener('beforeunload', function(e) {
            if (!submitted) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        const hasGSAP = typeof gsap !== 'undefined';
        if (hasGSAP) {
            gsap.fromTo('.auth-card', { opacity: 0, y: 18 }, { opacity: 1, y: 0, duration: 0.45, ease: 'power3.out' });
            gsap.fromTo('.form-section', { opacity: 0, y: 12 }, { opacity: 1, y: 0, duration: 0.4, stagger: 0.08, ease: 'power2.out', delay: 0.1 });
        }

        // Biological Sex selector
        const sexRadios = document.querySelectorAll('input[name="biological_sex"]');
        const hipContainer = document.getElementById('hipContainer');
        const hipInput = document.getElementById('input-hip');
        const sexCards = document.querySelectorAll('.sex-card');

        sexRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                sexCards.forEach(c => c.classList.remove('selected'));
                this.closest('.sex-card').classList.add('selected');

                if (this.value === 'female') {
                    hipContainer.style.display = 'flex';
                    hipInput.required = true;
                    if (hasGSAP) {
                        gsap.fromTo(hipContainer, { opacity: 0, scale: 0.95 }, { opacity: 1, scale: 1, duration: 0.3, ease: 'power2.out' });
                    }
                } else {
                    hipContainer.style.display = 'none';
                    hipInput.required = false;
                    hipInput.value = '';
                }
            });
        });

        // Experience Level selector
        const expCards = document.querySelectorAll('.exp-card');
        expCards.forEach(card => {
            card.addEventListener('click', function() {
                expCards.forEach(c => c.classList.remove('selected'));
                this.classList.add('selected');
                this.querySelector('input[type="radio"]').checked = true;
            });
        });
    })();
    </script>
    <?php
    render_footer();
}
