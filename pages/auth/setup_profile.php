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
        .split-login-frame.profile-mode {
            max-width: 1100px;
        }
        .profile-step-pane {
            display: none;
            animation: fadeInStep 0.3s ease forwards;
        }
        .profile-step-pane.active {
            display: block;
        }
        @keyframes fadeInStep {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .sex-select-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }
        .sex-card {
            border: 1.5px solid var(--line);
            background: var(--panel-soft);
            border-radius: 12px;
            padding: 14px 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.2s ease;
            position: relative;
        }
        .sex-card:hover {
            border-color: var(--lime);
            transform: translateY(-1px);
        }
        .sex-card.selected {
            border-color: var(--lime);
            background: color-mix(in srgb, var(--lime) 10%, var(--panel-soft));
            box-shadow: 0 0 0 1px var(--lime);
        }
        .sex-card input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .sex-card-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--panel);
            border: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            font-weight: 700;
            color: var(--ink);
            flex-shrink: 0;
            transition: all 0.2s;
        }
        .sex-card.selected .sex-card-icon {
            background: var(--lime);
            color: #090b10;
            border-color: var(--lime);
        }
        .exp-select-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }
        .exp-card {
            border: 1.5px solid #e2e8f0;
            background: #ffffff;
            border-radius: 14px;
            padding: 14px 6px;
            cursor: pointer;
            text-align: center;
            transition: all 0.22s ease;
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
            user-select: none;
        }
        .exp-card:hover {
            border-color: #84cc16;
            transform: translateY(-2px);
            box-shadow: 0 4px 14px rgba(132, 204, 22, 0.15);
        }
        .exp-card.selected {
            border-color: #84cc16;
            background: rgba(132, 204, 22, 0.07);
            box-shadow: 0 0 0 2px rgba(132, 204, 22, 0.35);
        }
        .exp-card input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .exp-card-icon-wrap {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
            transition: all 0.2s ease;
        }
        .exp-card.selected .exp-card-icon-wrap {
            transform: scale(1.1);
        }
        .exp-icon-starter {
            background: rgba(34, 197, 94, 0.14);
            color: #16a34a;
        }
        .exp-icon-intermediate {
            background: rgba(59, 130, 246, 0.14);
            color: #2563eb;
        }
        .exp-icon-advanced {
            background: rgba(168, 85, 247, 0.14);
            color: #9333ea;
        }
        .exp-card-title {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.2;
            white-space: nowrap;
        }
        .exp-card-sub {
            font-size: 11px;
            color: #64748b;
            margin-top: 3px;
            white-space: nowrap;
        }
        .exp-card.selected .exp-card-title {
            color: #1e3a0f;
        }
        .exp-card.selected .exp-card-sub {
            color: #4d7c0f;
            font-weight: 600;
        }
        .metric-unit-tag {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
            pointer-events: none;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .input-with-unit input {
            padding-right: 48px !important;
        }
        .stepper-header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--line);
        }
        .step-pill-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 700;
            color: var(--lime);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .step-dots-row {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .step-dot {
            width: 22px;
            height: 5px;
            border-radius: 3px;
            background: var(--line);
            transition: all 0.3s ease;
        }
        .step-dot.active {
            background: var(--lime);
            box-shadow: 0 0 8px color-mix(in srgb, var(--lime) 50%, transparent);
        }
        .step-dot.done {
            background: color-mix(in srgb, var(--lime) 60%, var(--line));
        }
        .step-nav-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }
        .btn-step-prev {
            flex: 0 0 110px;
            background: var(--panel-soft);
            border: 1px solid var(--line);
            color: var(--ink);
            padding: 12px 18px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-step-prev:hover {
            background: var(--line);
        }
        .btn-step-next {
            flex: 1;
            width: 100%;
            box-sizing: border-box;
            margin: 0 !important;
        }
        .profile-step-pane > .split-form-group,
        .profile-step-pane > .split-form-row-2col {
            margin-bottom: 16px;
        }
        .split-input-wrap select {
            width: 100%;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            background-color: #ffffff;
            padding: 13px 38px 13px 44px;
            color: #0f172a;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            background-size: 16px 16px;
        }
        .split-input-wrap select:focus {
            border-color: #84cc16;
            outline: none;
            box-shadow: 0 0 0 3px rgba(132, 204, 22, 0.18);
        }
        .split-input-wrap select option {
            background: #ffffff;
            color: #0f172a;
            padding: 8px;
        }
        .live-preview-box {
            background: var(--panel-soft);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px 16px;
            margin-top: 16px;
            display: flex;
            align-items: center;
            justify-content: space-around;
            text-align: center;
        }
        .live-preview-val {
            font-size: 18px;
            font-weight: 800;
            color: var(--lime);
            font-family: -apple-system, BlinkMacSystemFont, monospace;
        }
        .live-preview-lbl {
            font-size: 11px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 2px;
        }
        @media (max-width: 640px) {
            .sex-select-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 480px) {
            .exp-select-grid {
                gap: 6px;
            }
            .exp-card {
                padding: 10px 4px;
            }
            .exp-card-icon-wrap {
                width: 30px;
                height: 30px;
                margin-bottom: 5px;
            }
            .exp-card-title {
                font-size: 11.5px;
            }
            .exp-card-sub {
                font-size: 9.5px;
            }
        }
        @media (max-width: 360px) {
            .exp-select-grid {
                grid-template-columns: 1fr;
            }
            .exp-card {
                flex-direction: row;
                justify-content: flex-start;
                text-align: left;
                padding: 10px 14px;
                gap: 12px;
            }
            .exp-card-icon-wrap {
                margin-bottom: 0;
            }
        }
        .setup-schedule-preferences {
            background: var(--panel-soft);
            border: 1.5px solid var(--line);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 16px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .setup-pref-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .setup-pref-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .setup-pref-label svg {
            color: var(--lime);
            flex-shrink: 0;
        }
        .setup-chip-options {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .setup-chip-btn {
            background: var(--panel);
            border: 1.5px solid var(--line);
            color: var(--muted);
            border-radius: 8px;
            padding: 7px 12px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .setup-chip-btn:hover {
            color: var(--ink);
            border-color: color-mix(in srgb, var(--lime) 60%, var(--line));
        }
        .setup-chip-btn.active {
            background: color-mix(in srgb, var(--lime) 15%, var(--panel));
            border-color: var(--lime);
            color: var(--lime);
            font-weight: 700;
            box-shadow: 0 0 10px color-mix(in srgb, var(--lime) 20%, transparent);
        }
    </style>

    <div class="split-login-viewport">
        <div class="split-login-frame profile-mode">
            <!-- Left Hero Showcase -->
            <div class="split-login-showcase">
                <!-- Decorative Dot Matrix SVG -->
                <svg class="showcase-decor-dots" width="70" height="70" viewBox="0 0 70 70" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="10" cy="10" r="2.5" fill="#ffffff" />
                    <circle cx="26" cy="10" r="2.5" fill="#ffffff" />
                    <circle cx="42" cy="10" r="2.5" fill="#ffffff" />
                    <circle cx="58" cy="10" r="2.5" fill="#ffffff" />
                    <circle cx="10" cy="26" r="2.5" fill="#ffffff" />
                    <circle cx="26" cy="26" r="2.5" fill="#ffffff" />
                    <circle cx="42" cy="26" r="2.5" fill="#ffffff" />
                    <circle cx="58" cy="26" r="2.5" fill="#ffffff" />
                    <circle cx="10" cy="42" r="2.5" fill="#ffffff" />
                    <circle cx="26" cy="42" r="2.5" fill="#ffffff" />
                    <circle cx="42" cy="42" r="2.5" fill="#ffffff" />
                    <circle cx="58" cy="42" r="2.5" fill="#ffffff" />
                    <circle cx="10" cy="58" r="2.5" fill="#ffffff" />
                    <circle cx="26" cy="58" r="2.5" fill="#ffffff" />
                    <circle cx="42" cy="58" r="2.5" fill="#ffffff" />
                    <circle cx="58" cy="58" r="2.5" fill="#ffffff" />
                </svg>

                <!-- Decorative Diagonal Speed Stripes SVG -->
                <svg class="showcase-decor-stripes" viewBox="0 0 260 260" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <polygon points="120,260 220,0 260,0 160,260" fill="url(#limeGradient1)" opacity="0.65" />
                    <polygon points="40,260 140,0 170,0 70,260" fill="url(#limeGradient2)" opacity="0.45" />
                    <polygon points="0,260 90,0 110,0 20,260" fill="url(#limeGradient1)" opacity="0.25" />
                    <defs>
                        <linearGradient id="limeGradient1" x1="0%" y1="100%" x2="100%" y2="0%">
                            <stop offset="0%" stop-color="#4d7c0f" />
                            <stop offset="50%" stop-color="#84cc16" />
                            <stop offset="100%" stop-color="#bef264" />
                        </linearGradient>
                        <linearGradient id="limeGradient2" x1="0%" y1="100%" x2="100%" y2="0%">
                            <stop offset="0%" stop-color="#3f6212" />
                            <stop offset="100%" stop-color="#a3e635" />
                        </linearGradient>
                    </defs>
                </svg>

                <div class="split-login-showcase-content">
                    <!-- Top Brand Header -->
                    <div class="showcase-brand">
                        <div class="showcase-brand-icon">
                            <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: 100%; height: 100%;">
                                <path d="M6 8 L32 8 L29 14 L15 14 L13 18 L26 18 L23 24 L10 24 L5 34 L1 34 L6 8 Z" fill="#84cc16" />
                                <polygon points="12,5 36,5 34,9 10,9" fill="#a3e635" opacity="0.8" />
                                <polygon points="2,32 10,32 8,36 0,36" fill="#65a30d" />
                            </svg>
                        </div>
                        <div class="showcase-brand-text">
                            <div class="showcase-brand-name">FIT<span>TRACK</span></div>
                            <div class="showcase-brand-tagline">Manage. Engage. Grow.</div>
                        </div>
                    </div>

                    <!-- Middle Headline & Copy -->
                    <div class="showcase-hero-copy">
                        <h1 class="showcase-title">
                            Physical Baseline.
                            <span class="highlight">Biometric Precision.</span>
                        </h1>
                        <p class="showcase-desc">
                            FitTrack turns your physical measurements into personalized workout splits, Navy Body Fat analysis, and adaptive nutrition targets.
                        </p>
                    </div>

                    <!-- Three Feature Items -->
                    <div class="showcase-features">
                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"/>
                                    <line x1="16" y1="8" x2="2" y2="22"/>
                                    <line x1="17.5" y1="15" x2="9" y2="15"/>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>US Navy Body Fat Formula</h4>
                                <p>Scientific circumference ratios provide accurate lean mass estimates.</p>
                            </div>
                        </div>

                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Metabolic Expenditure</h4>
                                <p>Calculates your BMR and activity energy burn to guide nutrition.</p>
                            </div>
                        </div>

                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"/>
                                    <polyline points="12 6 12 12 14 14"/>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Adaptive Fitness Tier</h4>
                                <p>Calibrates exercise volume and rest intervals suited to your level.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side Elevated Card Pane -->
            <div class="split-login-card-pane">
                <div class="split-login-card onboarding-card">
                    <div class="split-card-header">
                        <h2 class="split-card-title">Setup Profile</h2>
                        <p class="split-card-subtitle">Establish your physical baseline for <span class="brand-highlight">FitTrack</span></p>
                    </div>

                    <!-- Stepper Header -->
                    <div class="stepper-header-bar">
                        <span class="step-pill-indicator" id="step-badge-text">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            Step 1 of 4: Core Stats
                        </span>
                        <div class="step-dots-row">
                            <div class="step-dot active" id="sdot-1"></div>
                            <div class="step-dot" id="sdot-2"></div>
                            <div class="step-dot" id="sdot-3"></div>
                            <div class="step-dot" id="sdot-4"></div>
                        </div>
                    </div>

                    <form method="post" action="index.php?page=setup_profile" id="profile-form" class="split-card-form" novalidate onsubmit="const btn = document.getElementById('submit-profile-btn'); if (btn) { btn.disabled = true; btn.innerHTML = '<svg class=\'fitness-loader mini\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\' style=\'margin-right:8px;\'><line x1=\'6\' y1=\'12\' x2=\'18\' y2=\'12\'></line><rect x=\'4\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'18\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'2\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect><rect x=\'20\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect></svg> SAVING PROFILE...'; }">
                        <?= csrf_field() ?>

                        <!-- STEP 1: Core Stats -->
                        <div class="profile-step-pane active" id="pane-step-1">
                            <label style="font-size: 13px; font-weight: 700; color: var(--ink); margin-bottom: 8px; display: block;">Biological Sex</label>
                            <div class="sex-select-grid">
                                <label class="sex-card selected" id="sex-male-btn">
                                    <input type="radio" name="biological_sex" value="male" checked>
                                    <div class="sex-card-icon">♂</div>
                                    <div>
                                        <div style="font-weight: 700; font-size: 14px; color: var(--ink);">Male</div>
                                        <div style="font-size: 12px; color: var(--muted);">Standard formula</div>
                                    </div>
                                </label>
                                <label class="sex-card" id="sex-female-btn">
                                    <input type="radio" name="biological_sex" value="female">
                                    <div class="sex-card-icon">♀</div>
                                    <div>
                                        <div style="font-weight: 700; font-size: 14px; color: var(--ink);">Female</div>
                                        <div style="font-size: 12px; color: var(--muted);">Includes hip metric</div>
                                    </div>
                                </label>
                            </div>

                            <div class="split-form-row-2col">
                                <div class="split-form-group">
                                    <label>Age (16–120)</label>
                                    <div class="split-input-wrap auth-input-group input-with-unit">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 14 14"/>
                                        </svg>
                                        <input id="field-age" name="age" type="number" min="16" max="120" placeholder="e.g. 25" required>
                                        <span class="metric-unit-tag">yrs</span>
                                    </div>
                                </div>

                                <div class="split-form-group">
                                    <label>Height (100–250)</label>
                                    <div class="split-input-wrap auth-input-group input-with-unit">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/>
                                        </svg>
                                        <input id="field-height" name="height_cm" type="number" step="0.1" min="100" max="250" placeholder="e.g. 175" required>
                                        <span class="metric-unit-tag">cm</span>
                                    </div>
                                </div>
                            </div>

                            <div class="split-form-group">
                                <label>Current Weight (20–300)</label>
                                <div class="split-input-wrap auth-input-group input-with-unit">
                                    <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
                                    </svg>
                                    <input id="field-weight" name="weight_kg" type="number" step="0.1" min="20" max="300" placeholder="e.g. 70" required>
                                    <span class="metric-unit-tag">kg</span>
                                </div>
                            </div>

                            <div class="step-nav-actions">
                                <button type="button" class="split-submit-btn btn-step-next" onclick="goToProfileStep(2)">
                                    <span>Continue to Measurements</span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <line x1="5" y1="12" x2="19" y2="12"></line>
                                        <polyline points="12 5 19 12 12 19"></polyline>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- STEP 2: Body Circumference -->
                        <div class="profile-step-pane" id="pane-step-2">
                            <div style="background: var(--panel-soft); border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; margin-bottom: 18px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--muted);">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2" style="flex-shrink:0;">
                                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                                </svg>
                                <span>Use a flexible tape measure snug against skin without compressing tissue.</span>
                            </div>

                            <div class="split-form-row-2col">
                                <div class="split-form-group">
                                    <label>Neck (Narrowest point)</label>
                                    <div class="split-input-wrap auth-input-group input-with-unit">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="8"/>
                                        </svg>
                                        <input id="field-neck" name="neck_cm" type="number" step="0.1" min="20" max="100" placeholder="e.g. 38" required>
                                        <span class="metric-unit-tag">cm</span>
                                    </div>
                                </div>

                                <div class="split-form-group">
                                    <label>Waist (At navel level)</label>
                                    <div class="split-input-wrap auth-input-group input-with-unit">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/>
                                        </svg>
                                        <input id="field-waist" name="waist_cm" type="number" step="0.1" min="30" max="200" placeholder="e.g. 82" required>
                                        <span class="metric-unit-tag">cm</span>
                                    </div>
                                </div>
                            </div>

                            <div class="split-form-group" id="hip-group" style="display: none;">
                                <label style="color: var(--lime);">Hip (Widest glute point)</label>
                                <div class="split-input-wrap auth-input-group input-with-unit">
                                    <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <ellipse cx="12" cy="12" rx="10" ry="6"/>
                                    </svg>
                                    <input id="field-hip" name="hip_cm" type="number" step="0.1" min="30" max="200" placeholder="e.g. 96">
                                    <span class="metric-unit-tag">cm</span>
                                </div>
                            </div>

                            <!-- Live Metric Preview -->
                            <div class="live-preview-box">
                                <div>
                                    <div class="live-preview-val" id="preview-bmi">—</div>
                                    <div class="live-preview-lbl">Est. BMI</div>
                                </div>
                                <div style="width: 1px; height: 30px; background: var(--line);"></div>
                                <div>
                                    <div class="live-preview-val" id="preview-bf">—</div>
                                    <div class="live-preview-lbl">Navy Body Fat %</div>
                                </div>
                                <div style="width: 1px; height: 30px; background: var(--line);"></div>
                                <div>
                                    <div class="live-preview-val" id="preview-bmr">—</div>
                                    <div class="live-preview-lbl">Basal Burn (BMR)</div>
                                </div>
                            </div>

                            <div class="step-nav-actions">
                                <button type="button" class="btn-step-prev" onclick="goToProfileStep(1)">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                    Back
                                </button>
                                <button type="button" class="split-submit-btn btn-step-next" onclick="goToProfileStep(3)">
                                    <span>Continue to Lifestyle</span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <line x1="5" y1="12" x2="19" y2="12"></line>
                                        <polyline points="12 5 19 12 12 19"></polyline>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- STEP 3: Lifestyle & Diet -->
                        <div class="profile-step-pane" id="pane-step-3">
                            <label style="font-size: 13px; font-weight: 700; color: var(--ink); margin-bottom: 8px; display: block;">Experience Level</label>
                            <div class="exp-select-grid">
                                <label class="exp-card selected" id="exp-1">
                                    <input type="radio" name="experience_level" value="1" checked>
                                    <div class="exp-card-icon-wrap exp-icon-starter">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M12 22v-8m0 0a4 4 0 1 0-4-4m4 4a4 4 0 1 1 4-4"/>
                                        </svg>
                                    </div>
                                    <div class="exp-card-title">Starter</div>
                                    <div class="exp-card-sub">&lt; 6 months</div>
                                </label>
                                <label class="exp-card" id="exp-2">
                                    <input type="radio" name="experience_level" value="2">
                                    <div class="exp-card-icon-wrap exp-icon-intermediate">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                                        </svg>
                                    </div>
                                    <div class="exp-card-title">Intermediate</div>
                                    <div class="exp-card-sub">6mo – 2 yrs</div>
                                </label>
                                <label class="exp-card" id="exp-3">
                                    <input type="radio" name="experience_level" value="3">
                                    <div class="exp-card-icon-wrap exp-icon-advanced">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/>
                                            <path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/>
                                            <path d="M4 22h16"/>
                                            <path d="M10 14.66V17c0 .55-.45 1-1 1H8c-.55 0-1 .45-1 1v1h10v-1c0-.55-.45-1-1-1h-1c-.55 0-1-.45-1-1v-2.34"/>
                                            <path d="M6 4h12a2 2 0 0 1 2 2v3a6 6 0 0 1-6 6h0a6 6 0 0 1-6-6V6a2 2 0 0 1 2-2Z"/>
                                        </svg>
                                    </div>
                                    <div class="exp-card-title">Advanced</div>
                                    <div class="exp-card-sub">2+ yrs lifting</div>
                                </label>
                            </div>

                            <div class="split-form-group">
                                <label>Daily Activity Level</label>
                                <div class="split-input-wrap auth-input-group">
                                    <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                                    </svg>
                                    <select name="activity_level" id="field-activity" required>
                                        <option value="sedentary">Sedentary (Desk job, minimal movement)</option>
                                        <option value="lightly_active" selected>Lightly Active (1–3 days exercise)</option>
                                        <option value="moderately_active">Moderately Active (3–5 days workout)</option>
                                        <option value="very_active">Very Active (6–7 days intense)</option>
                                        <option value="extra_active">Extra Active (Physical job + training)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="split-form-group">
                                <label>Dietary Preference</label>
                                <div class="split-input-wrap auth-input-group">
                                    <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M18 8h1a4 4 0 0 1 0 8h-1"></path>
                                        <path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path>
                                        <line x1="6" y1="1" x2="6" y2="4"></line>
                                        <line x1="10" y1="1" x2="10" y2="4"></line>
                                        <line x1="14" y1="1" x2="14" y2="4"></line>
                                    </svg>
                                    <select name="dietary_restrictions" id="field-diet" required>
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

                            <div class="step-nav-actions">
                                <button type="button" class="btn-step-prev" onclick="goToProfileStep(2)">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                    Back
                                </button>
                                <button type="button" class="split-submit-btn btn-step-next" onclick="goToProfileStep(4)">
                                    <span>Continue to Routine</span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <line x1="5" y1="12" x2="19" y2="12"></line>
                                        <polyline points="12 5 19 12 12 19"></polyline>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- STEP 4: Routine & Schedule -->
                        <div class="profile-step-pane" id="pane-step-4">
                            <div style="background: var(--panel-soft); border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; margin-bottom: 18px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--muted);">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2" style="flex-shrink:0;">
                                    <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                                </svg>
                                <span>Set your realistic training availability to calibrate your customized workout routine.</span>
                            </div>

                            <!-- Routine Schedule Preferences: Days / Week & Session Duration -->
                            <div class="setup-schedule-preferences">
                                <div class="setup-pref-group">
                                    <label class="setup-pref-label">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                        Weekly Training Commitment:
                                    </label>
                                    <div class="setup-chip-options" id="prof-days-chip-options">
                                        <input type="hidden" name="weekly_workout_target" id="input_weekly_workout_target" value="3">
                                        <button type="button" class="setup-chip-btn" data-value="2">2 Days</button>
                                        <button type="button" class="setup-chip-btn active" data-value="3">3 Days (Recommended)</button>
                                        <button type="button" class="setup-chip-btn" data-value="4">4 Days</button>
                                        <button type="button" class="setup-chip-btn" data-value="5">5+ Days</button>
                                    </div>
                                </div>

                                <div class="setup-pref-group">
                                    <label class="setup-pref-label">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                        Ideal Session Duration:
                                    </label>
                                    <div class="setup-chip-options" id="prof-duration-chip-options">
                                        <input type="hidden" name="preferred_duration_mins" id="input_preferred_duration_mins" value="45">
                                        <button type="button" class="setup-chip-btn" data-value="30">30 mins (Quick)</button>
                                        <button type="button" class="setup-chip-btn active" data-value="45">45–60 mins (Standard)</button>
                                        <button type="button" class="setup-chip-btn" data-value="75">75+ mins (Extended)</button>
                                    </div>
                                </div>
                            </div>

                            <div class="step-nav-actions">
                                <button type="button" class="btn-step-prev" onclick="goToProfileStep(3)">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                    Back
                                </button>
                                <button type="submit" class="split-submit-btn btn-step-next" id="submit-profile-btn">
                                    <span>Finish & Pick Goal</span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <polyline points="20 6 9 17 4 12"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    let currentProfileStep = 1;

    function goToProfileStep(targetStep) {
        if (targetStep > currentProfileStep) {
            if (currentProfileStep === 1) {
                const age = document.getElementById('field-age');
                const height = document.getElementById('field-height');
                const weight = document.getElementById('field-weight');

                let valid = true;
                [age, height, weight].forEach(el => el.closest('.split-input-wrap')?.classList.remove('has-error'));

                if (!age.value || parseFloat(age.value) < 16 || parseFloat(age.value) > 120) {
                    age.closest('.split-input-wrap')?.classList.add('has-error');
                    age.focus();
                    valid = false;
                } else if (!height.value || parseFloat(height.value) < 100 || parseFloat(height.value) > 250) {
                    height.closest('.split-input-wrap')?.classList.add('has-error');
                    height.focus();
                    valid = false;
                } else if (!weight.value || parseFloat(weight.value) < 20 || parseFloat(weight.value) > 300) {
                    weight.closest('.split-input-wrap')?.classList.add('has-error');
                    weight.focus();
                    valid = false;
                }
                if (!valid) return;
            } else if (currentProfileStep === 2) {
                const neck = document.getElementById('field-neck');
                const waist = document.getElementById('field-waist');
                const hip = document.getElementById('field-hip');
                const isFemale = document.querySelector('input[name="biological_sex"]:checked')?.value === 'female';

                let valid = true;
                [neck, waist, hip].forEach(el => el?.closest('.split-input-wrap')?.classList.remove('has-error'));

                if (!neck.value || parseFloat(neck.value) < 20 || parseFloat(neck.value) > 100) {
                    neck.closest('.split-input-wrap')?.classList.add('has-error');
                    neck.focus();
                    valid = false;
                } else if (!waist.value || parseFloat(waist.value) < 30 || parseFloat(waist.value) > 200) {
                    waist.closest('.split-input-wrap')?.classList.add('has-error');
                    waist.focus();
                    valid = false;
                } else if (isFemale && (!hip.value || parseFloat(hip.value) < 30 || parseFloat(hip.value) > 200)) {
                    hip.closest('.split-input-wrap')?.classList.add('has-error');
                    hip.focus();
                    valid = false;
                }
                if (!valid) return;
            }
        }

        // Switch active step pane
        document.querySelectorAll('.profile-step-pane').forEach(el => el.classList.remove('active'));
        document.getElementById(`pane-step-${targetStep}`)?.classList.add('active');

        // Update dots and pill
        currentProfileStep = targetStep;
        for (let i = 1; i <= 4; i++) {
            const dot = document.getElementById(`sdot-${i}`);
            if (dot) {
                dot.className = 'step-dot' + (i === targetStep ? ' active' : (i < targetStep ? ' done' : ''));
            }
        }

        const badge = document.getElementById('step-badge-text');
        if (badge) {
            const titles = {
                1: 'Step 1 of 4: Core Stats',
                2: 'Step 2 of 4: Measurements',
                3: 'Step 3 of 4: Lifestyle & Diet',
                4: 'Step 4 of 4: Routine & Schedule'
            };
            badge.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> ${titles[targetStep] || ''}`;
        }

        updateLiveMetrics();
    }

    function updateLiveMetrics() {
        const height = parseFloat(document.getElementById('field-height')?.value || '0');
        const weight = parseFloat(document.getElementById('field-weight')?.value || '0');
        const age = parseFloat(document.getElementById('field-age')?.value || '0');
        const neck = parseFloat(document.getElementById('field-neck')?.value || '0');
        const waist = parseFloat(document.getElementById('field-waist')?.value || '0');
        const hip = parseFloat(document.getElementById('field-hip')?.value || '0');
        const isFemale = document.querySelector('input[name="biological_sex"]:checked')?.value === 'female';

        const bmiEl = document.getElementById('preview-bmi');
        const bfEl = document.getElementById('preview-bf');
        const bmrEl = document.getElementById('preview-bmr');

        if (height > 0 && weight > 0) {
            const hM = height / 100;
            const bmi = weight / (hM * hM);
            if (bmiEl) bmiEl.textContent = bmi.toFixed(1);

            // BMR estimation (Mifflin-St Jeor)
            if (age > 0) {
                let bmr = 0;
                if (!isFemale) {
                    bmr = 10 * weight + 6.25 * height - 5 * age + 5;
                } else {
                    bmr = 10 * weight + 6.25 * height - 5 * age - 161;
                }
                if (bmrEl) bmrEl.textContent = Math.round(bmr) + ' kcal';
            }
        }

        // Navy Body Fat Formula estimation
        if (height > 0 && waist > 0 && neck > 0) {
            try {
                let bf = 0;
                if (!isFemale && waist > neck) {
                    bf = 495 / (1.0324 - 0.19077 * Math.log10(waist - neck) + 0.15456 * Math.log10(height)) - 450;
                } else if (isFemale && hip > 0 && (waist + hip) > neck) {
                    bf = 495 / (1.29579 - 0.35004 * Math.log10(waist + hip - neck) + 0.22100 * Math.log10(height)) - 450;
                }
                if (bf > 3 && bf < 65) {
                    if (bfEl) bfEl.textContent = bf.toFixed(1) + '%';
                }
            } catch (e) {}
        }
    }

    (function() {
        // Sex Selector Toggle
        const sexBtns = document.querySelectorAll('.sex-card');
        const hipGroup = document.getElementById('hip-group');
        const hipInput = document.getElementById('field-hip');

        sexBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                sexBtns.forEach(b => b.classList.remove('selected'));
                this.classList.add('selected');
                const radio = this.querySelector('input[type="radio"]');
                if (radio) radio.checked = true;

                if (radio && radio.value === 'female') {
                    if (hipGroup) hipGroup.style.display = 'block';
                    if (hipInput) hipInput.required = true;
                } else {
                    if (hipGroup) hipGroup.style.display = 'none';
                    if (hipInput) {
                        hipInput.required = false;
                        hipInput.value = '';
                    }
                }
                updateLiveMetrics();
            });
        });

        // Experience Level Selector
        const expBtns = document.querySelectorAll('.exp-card');
        expBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                expBtns.forEach(b => b.classList.remove('selected'));
                this.classList.add('selected');
                const radio = this.querySelector('input[type="radio"]');
                if (radio) radio.checked = true;
            });
        });

        // Live calculation inputs
        ['field-height', 'field-weight', 'field-age', 'field-neck', 'field-waist', 'field-hip'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', updateLiveMetrics);
        });

        // Routine Schedule Preference Chips (Days & Duration)
        function initProfChips(containerId, inputId) {
            const container = document.getElementById(containerId);
            const hiddenInput = document.getElementById(inputId);
            if (!container || !hiddenInput) return;
            container.querySelectorAll('.setup-chip-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    container.querySelectorAll('.setup-chip-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    hiddenInput.value = this.getAttribute('data-value');
                });
            });
        }
        initProfChips('prof-days-chip-options', 'input_weekly_workout_target');
        initProfChips('prof-duration-chip-options', 'input_preferred_duration_mins');
    })();
    </script>
    <?php
    render_footer();
}
