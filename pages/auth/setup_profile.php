<?php
declare(strict_types=1);

function setup_profile_page(): void
{
    define('AUTH_PAGE', true);

    $user = current_user();
    if (!$user) {
        redirect('login');
    }

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
        redirect('setup_goal');
    }

    render_header('Complete your profile', null);
    ?>
    <style>
        .profile-wizard-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 32px 36px;
            width: 100%;
            max-width: 620px;
            position: relative;
            z-index: 1;
            margin: 0 auto;
            transition: all 0.3s ease;
        }

        /* Ambient glowing background */
        .profile-bg-decor {
            position: absolute;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 0;
        }
        .profile-blob {
            position: absolute;
            width: 320px;
            height: 320px;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.15;
        }
        .profile-blob--a { background: var(--lime); top: -80px; left: -80px; }
        .profile-blob--b { background: #0284c7; bottom: -100px; right: -80px; }

        /* Stepper progress */
        .wizard-stepper-wrap {
            margin-bottom: 24px;
        }
        .wizard-step-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.85rem;
        }
        .wizard-step-badge {
            font-weight: 700;
            color: var(--lime);
            letter-spacing: 0.5px;
            text-transform: uppercase;
            font-size: 0.78rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .wizard-step-count {
            color: var(--muted);
            font-size: 0.8rem;
            font-weight: 500;
        }
        .wizard-progress-bar {
            width: 100%;
            height: 6px;
            background: var(--panel-soft);
            border-radius: 999px;
            overflow: hidden;
            position: relative;
        }
        .wizard-progress-fill {
            height: 100%;
            width: 33.33%;
            background: linear-gradient(90deg, var(--lime), #42dba5);
            border-radius: 999px;
            transition: width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Step containers */
        .wizard-step {
            display: none;
            animation: fadeInStep 0.25s ease-out;
        }
        .wizard-step.active {
            display: block;
        }
        @keyframes fadeInStep {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .step-heading {
            margin-bottom: 20px;
            text-align: center;
        }
        .step-title {
            font-size: 1.45rem;
            font-weight: 800;
            color: var(--ink);
            margin: 0 0 6px;
            letter-spacing: -0.01em;
        }
        .step-desc {
            color: var(--muted);
            font-size: 0.88rem;
            margin: 0 auto;
            max-width: 440px;
            line-height: 1.45;
        }

        /* Sex toggle pills */
        .sex-toggle-wrap {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }
        .sex-btn {
            background: var(--panel);
            border: 1.5px solid var(--line);
            border-radius: 12px;
            padding: 14px 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.2s ease;
            user-select: none;
        }
        .sex-btn:hover {
            border-color: var(--lime);
            transform: translateY(-2px);
        }
        .sex-btn.selected {
            border-color: var(--lime);
            background: color-mix(in srgb, var(--lime) 10%, var(--panel));
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--lime) 20%, transparent);
        }
        .sex-btn input[type="radio"] {
            display: none;
        }
        .sex-icon-circle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--panel-soft);
            color: var(--ink);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: bold;
            flex-shrink: 0;
            transition: all 0.2s;
        }
        .sex-btn.selected .sex-icon-circle {
            background: var(--lime);
            color: #090b10;
        }

        /* Form Inputs */
        .input-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .input-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .input-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            text-align: left;
        }
        .input-label {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--ink);
            display: flex;
            justify-content: space-between;
        }
        .input-label .sub {
            color: var(--muted);
            font-weight: normal;
            font-size: 0.75rem;
        }
        .input-field-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .input-field-wrap input {
            width: 100%;
            background: var(--panel);
            border: 1.5px solid var(--line);
            border-radius: 10px;
            padding: 12px 44px 12px 14px;
            color: var(--ink);
            font-size: 0.95rem;
            font-weight: 600;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .input-field-wrap input:focus {
            border-color: var(--lime);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--lime) 25%, transparent);
            background: var(--panel);
        }
        .input-field-wrap input.has-error {
            border-color: var(--danger) !important;
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--danger) 25%, transparent) !important;
        }
        .input-unit {
            position: absolute;
            right: 12px;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--muted);
            pointer-events: none;
            text-transform: lowercase;
        }

        /* Info hint box */
        .info-hint-box {
            background: color-mix(in srgb, var(--panel-soft) 70%, transparent);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 20px;
            font-size: 0.8rem;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 10px;
            line-height: 1.4;
        }
        .info-hint-box svg {
            color: var(--lime);
            flex-shrink: 0;
        }

        /* Experience cards */
        .experience-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 18px;
        }
        .exp-btn {
            background: var(--panel);
            border: 1.5px solid var(--line);
            border-radius: 10px;
            padding: 12px 10px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
            user-select: none;
        }
        .exp-btn:hover {
            border-color: var(--lime);
            transform: translateY(-2px);
        }
        .exp-btn.selected {
            border-color: var(--lime);
            background: color-mix(in srgb, var(--lime) 10%, var(--panel));
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--lime) 20%, transparent);
        }
        .exp-btn input[type="radio"] {
            display: none;
        }
        .exp-title {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--ink);
            margin-bottom: 3px;
        }
        .exp-desc {
            font-size: 0.72rem;
            color: var(--muted);
            line-height: 1.3;
        }

        /* Select controls */
        .select-field {
            width: 100%;
            background: var(--panel);
            border: 1.5px solid var(--line);
            border-radius: 10px;
            padding: 12px 14px;
            color: var(--ink);
            font-size: 0.92rem;
            font-weight: 500;
            outline: none;
            transition: all 0.2s;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .select-field:focus {
            border-color: var(--lime);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--lime) 25%, transparent);
        }
        .select-field option {
            background: var(--panel);
            color: var(--ink);
        }

        /* Action buttons */
        .wizard-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 10px;
        }
        .wizard-btn-prev {
            background: var(--panel-soft);
            border: 1.5px solid var(--line);
            color: var(--ink);
            font-weight: 600;
            padding: 13px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            user-select: none;
        }
        .wizard-btn-prev:hover {
            background: var(--panel);
            border-color: var(--ink);
        }
        .wizard-btn-next {
            flex-grow: 1;
            background: var(--lime);
            color: #090b10;
            border: none;
            font-weight: 800;
            font-size: 0.92rem;
            padding: 13px 24px;
            border-radius: 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
            letter-spacing: 0.3px;
        }
        .wizard-btn-next:hover {
            opacity: 0.95;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px color-mix(in srgb, var(--lime) 30%, transparent);
        }

        /* Mobile responsiveness */
        @media (max-width: 640px) {
            .profile-wizard-card {
                padding: 20px 16px;
                border-radius: 14px;
            }
            .input-grid-3, .input-grid-2, .experience-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }
            .sex-toggle-wrap {
                grid-template-columns: 1fr;
            }
            .step-title {
                font-size: 1.25rem;
            }
            .wizard-actions {
                flex-direction: column-reverse;
            }
            .wizard-btn-prev, .wizard-btn-next {
                width: 100%;
                justify-content: center;
            }
        }
    </style>

    <section style="padding: 30px 16px; min-height: 85vh; display:flex; align-items:center; justify-content:center; position:relative;">
        <div class="profile-bg-decor" aria-hidden="true">
            <div class="profile-blob profile-blob--a"></div>
            <div class="profile-blob profile-blob--b"></div>
        </div>

        <div class="profile-wizard-card">
            <!-- Stepper Progress Bar -->
            <div class="wizard-stepper-wrap">
                <div class="wizard-step-info">
                    <span class="wizard-step-badge" id="step-badge-text">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        Step 1 of 3: Core Stats
                    </span>
                    <span class="wizard-step-count" id="step-indicator-text">33% Complete</span>
                </div>
                <div class="wizard-progress-bar">
                    <div class="wizard-progress-fill" id="wizard-progress-fill"></div>
                </div>
            </div>

            <form method="post" action="index.php?page=setup_profile" id="profile-form">
                <?= csrf_field() ?>

                <!-- ================= STEP 1: Core Stats ================= -->
                <div class="wizard-step active" id="step-1">
                    <div class="step-heading">
                        <h1 class="step-title">Basic Physical Profile</h1>
                        <p class="step-desc">Enter your core physical stats so we can establish your baseline metrics.</p>
                    </div>

                    <!-- Biological Sex Toggle -->
                    <div class="sex-toggle-wrap">
                        <label class="sex-btn selected" id="sex-male-btn">
                            <input type="radio" name="biological_sex" value="male" checked>
                            <div class="sex-icon-circle">♂</div>
                            <div style="text-align:left;">
                                <div style="font-weight:700; font-size:0.95rem; color:var(--ink);">Male</div>
                                <div style="font-size:0.75rem; color:var(--muted);">Standard formula</div>
                            </div>
                        </label>
                        <label class="sex-btn" id="sex-female-btn">
                            <input type="radio" name="biological_sex" value="female">
                            <div class="sex-icon-circle">♀</div>
                            <div style="text-align:left;">
                                <div style="font-weight:700; font-size:0.95rem; color:var(--ink);">Female</div>
                                <div style="font-size:0.75rem; color:var(--muted);">Includes hip calculation</div>
                            </div>
                        </label>
                    </div>

                    <!-- Age, Height, Weight -->
                    <div class="input-grid-3">
                        <div class="input-group">
                            <label class="input-label" for="field-age">Age <span class="sub">16-120</span></label>
                            <div class="input-field-wrap">
                                <input id="field-age" name="age" type="number" min="16" max="120" placeholder="e.g. 25" required>
                                <span class="input-unit">yrs</span>
                            </div>
                        </div>

                        <div class="input-group">
                            <label class="input-label" for="field-height">Height <span class="sub">100-250</span></label>
                            <div class="input-field-wrap">
                                <input id="field-height" name="height_cm" type="number" step="0.1" min="100" max="250" placeholder="e.g. 175" required>
                                <span class="input-unit">cm</span>
                            </div>
                        </div>

                        <div class="input-group">
                            <label class="input-label" for="field-weight">Weight <span class="sub">20-300</span></label>
                            <div class="input-field-wrap">
                                <input id="field-weight" name="weight_kg" type="number" step="0.1" min="20" max="300" placeholder="e.g. 70" required>
                                <span class="input-unit">kg</span>
                            </div>
                        </div>
                    </div>

                    <div class="wizard-actions">
                        <button type="button" class="wizard-btn-next" onclick="goToStep(2)">
                            Continue to Measurements
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                </div>

                <!-- ================= STEP 2: Body Circumference ================= -->
                <div class="wizard-step" id="step-2">
                    <div class="step-heading">
                        <h1 class="step-title">Body Circumference</h1>
                        <p class="step-desc">Used by the US Navy body fat formula to accurately calculate your lean mass and target calories.</p>
                    </div>

                    <div class="info-hint-box">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        <span>Use a tape measure snug against the skin without compressing the tissue.</span>
                    </div>

                    <div class="input-grid-2" id="measurements-grid">
                        <div class="input-group">
                            <label class="input-label" for="field-neck">Neck <span class="sub">Narrowest point</span></label>
                            <div class="input-field-wrap">
                                <input id="field-neck" name="neck_cm" type="number" step="0.1" min="20" max="100" placeholder="e.g. 38" required>
                                <span class="input-unit">cm</span>
                            </div>
                        </div>

                        <div class="input-group">
                            <label class="input-label" for="field-waist">Waist <span class="sub">At navel level</span></label>
                            <div class="input-field-wrap">
                                <input id="field-waist" name="waist_cm" type="number" step="0.1" min="30" max="200" placeholder="e.g. 82" required>
                                <span class="input-unit">cm</span>
                            </div>
                        </div>

                        <div class="input-group" id="hip-group" style="display: none; grid-column: 1 / -1;">
                            <label class="input-label" for="field-hip" style="color:var(--lime);">Hip <span class="sub">Widest glute point</span></label>
                            <div class="input-field-wrap">
                                <input id="field-hip" name="hip_cm" type="number" step="0.1" min="30" max="200" placeholder="e.g. 96">
                                <span class="input-unit">cm</span>
                            </div>
                        </div>
                    </div>

                    <div class="wizard-actions">
                        <button type="button" class="wizard-btn-prev" onclick="goToStep(1)">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                            Back
                        </button>
                        <button type="button" class="wizard-btn-next" onclick="goToStep(3)">
                            Continue to Habits
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                </div>

                <!-- ================= STEP 3: Lifestyle & Experience ================= -->
                <div class="wizard-step" id="step-3">
                    <div class="step-heading">
                        <h1 class="step-title">Fitness Habits & Experience</h1>
                        <p class="step-desc">Tailor your workout volume and nutritional macro distribution to your lifestyle.</p>
                    </div>

                    <div class="input-label" style="margin-bottom: 8px;">Experience Level</div>
                    <div class="experience-grid">
                        <label class="exp-btn selected" id="exp-1">
                            <input type="radio" name="experience_level" value="1" checked>
                            <div class="exp-title">🟢 Starter</div>
                            <div class="exp-desc">&lt; 6 months, building form</div>
                        </label>
                        <label class="exp-btn" id="exp-2">
                            <input type="radio" name="experience_level" value="2">
                            <div class="exp-title">🔵 Intermediate</div>
                            <div class="exp-desc">6mo – 2 yrs regular gym</div>
                        </label>
                        <label class="exp-btn" id="exp-3">
                            <input type="radio" name="experience_level" value="3">
                            <div class="exp-title">🟣 Advanced</div>
                            <div class="exp-desc">2+ yrs dedicated lifting</div>
                        </label>
                    </div>

                    <div class="input-grid-2">
                        <div class="input-group">
                            <label class="input-label" for="field-activity">Daily Activity Level</label>
                            <select name="activity_level" id="field-activity" class="select-field" required>
                                <option value="sedentary">Sedentary (Desk job, minimal movement)</option>
                                <option value="lightly_active" selected>Lightly Active (1-3 days exercise)</option>
                                <option value="moderately_active">Moderately Active (3-5 days workout)</option>
                                <option value="very_active">Very Active (6-7 days intense)</option>
                                <option value="extra_active">Extra Active (Physical job + training)</option>
                            </select>
                        </div>

                        <div class="input-group">
                            <label class="input-label" for="field-diet">Dietary Preference</label>
                            <select name="dietary_restrictions" id="field-diet" class="select-field" required>
                                <option value="none" selected>Standard (No restrictions)</option>
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

                    <div class="wizard-actions">
                        <button type="button" class="wizard-btn-prev" onclick="goToStep(2)">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                            Back
                        </button>
                        <button type="submit" class="wizard-btn-next" id="submit-profile-btn" style="background:var(--lime); color:#090b10;">
                            FINISH & SELECT GOAL
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <script>
    let currentStep = 1;

    function goToStep(targetStep) {
        // Validate current step before advancing
        if (targetStep > currentStep) {
            if (currentStep === 1) {
                const age = document.getElementById('field-age');
                const height = document.getElementById('field-height');
                const weight = document.getElementById('field-weight');

                let valid = true;
                [age, height, weight].forEach(el => el.classList.remove('has-error'));

                if (!age.value || parseFloat(age.value) < 16 || parseFloat(age.value) > 120) {
                    age.classList.add('has-error');
                    age.focus();
                    valid = false;
                } else if (!height.value || parseFloat(height.value) < 100 || parseFloat(height.value) > 250) {
                    height.classList.add('has-error');
                    height.focus();
                    valid = false;
                } else if (!weight.value || parseFloat(weight.value) < 20 || parseFloat(weight.value) > 300) {
                    weight.classList.add('has-error');
                    weight.focus();
                    valid = false;
                }

                if (!valid) return;
            } else if (currentStep === 2) {
                const neck = document.getElementById('field-neck');
                const waist = document.getElementById('field-waist');
                const hip = document.getElementById('field-hip');
                const isFemale = document.querySelector('input[name="biological_sex"]:checked')?.value === 'female';

                let valid = true;
                [neck, waist, hip].forEach(el => el.classList.remove('has-error'));

                if (!neck.value || parseFloat(neck.value) < 20 || parseFloat(neck.value) > 100) {
                    neck.classList.add('has-error');
                    neck.focus();
                    valid = false;
                } else if (!waist.value || parseFloat(waist.value) < 30 || parseFloat(waist.value) > 200) {
                    waist.classList.add('has-error');
                    waist.focus();
                    valid = false;
                } else if (isFemale && (!hip.value || parseFloat(hip.value) < 30 || parseFloat(hip.value) > 200)) {
                    hip.classList.add('has-error');
                    hip.focus();
                    valid = false;
                }

                if (!valid) return;
            }
        }

        // Switch active step
        document.querySelectorAll('.wizard-step').forEach(el => el.classList.remove('active'));
        document.getElementById(`step-${targetStep}`).classList.add('active');

        // Update progress bar & labels
        currentStep = targetStep;
        const fill = document.getElementById('wizard-progress-fill');
        const badge = document.getElementById('step-badge-text');
        const count = document.getElementById('step-indicator-text');

        if (targetStep === 1) {
            fill.style.width = '33.33%';
            badge.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Step 1 of 3: Core Stats';
            count.innerText = '33% Complete';
        } else if (targetStep === 2) {
            fill.style.width = '66.66%';
            badge.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Step 2 of 3: Measurements';
            count.innerText = '66% Complete';
        } else if (targetStep === 3) {
            fill.style.width = '100%';
            badge.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Step 3 of 3: Fitness & Diet';
            count.innerText = 'Almost Done!';
        }
    }

    (function() {
        // Sex Selector Toggle
        const sexBtns = document.querySelectorAll('.sex-btn');
        const hipGroup = document.getElementById('hip-group');
        const hipInput = document.getElementById('field-hip');

        sexBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                sexBtns.forEach(b => b.classList.remove('selected'));
                this.classList.add('selected');
                const radio = this.querySelector('input[type="radio"]');
                radio.checked = true;

                if (radio.value === 'female') {
                    hipGroup.style.display = 'flex';
                    hipInput.required = true;
                } else {
                    hipGroup.style.display = 'none';
                    hipInput.required = false;
                    hipInput.value = '';
                }
            });
        });

        // Experience Level Selector
        const expBtns = document.querySelectorAll('.exp-btn');
        expBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                expBtns.forEach(b => b.classList.remove('selected'));
                this.classList.add('selected');
                this.querySelector('input[type="radio"]').checked = true;
            });
        });
    })();
    </script>
    <?php
    render_footer();
}
