<?php

declare(strict_types=1);

function setup_goal_page(): void
{
    require_once __DIR__ . '/../shared/workouts.php';
    define('AUTH_PAGE', true);

    $user = current_user();
    if (!$user) {
        redirect('login');
    }

    // Suppress conflicting "Welcome back" alert on onboarding goal setup
    if (isset($_SESSION['flash']['message']) && str_starts_with((string)$_SESSION['flash']['message'], 'Welcome back')) {
        unset($_SESSION['flash']);
    }

    if ($user['role'] !== 'member') {
        redirect('dashboard');
    }

    $profile = member_profile((int) $user['user_id']);
    if (!$profile) {
        redirect('setup_profile');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $goal = post('primary_goal');
        if (!$goal) {
            flash('Please select a primary goal.', 'danger');
            redirect(isset($_GET['edit']) ? 'setup_goal&edit=1' : 'setup_goal');
        }

        $weeklyTarget = max(1, min(7, (int) (post('weekly_workout_target') ?: ($profile['weekly_workout_target'] ?? 3))));
        $durationMins = max(15, min(180, (int) (post('preferred_duration_mins') ?: ($profile['preferred_duration_mins'] ?? 45))));

        // Read optional supporting targets (sanitized to positive values)
        $targetWeight = (post('target_weight_kg') !== null && post('target_weight_kg') !== '') ? abs((float) post('target_weight_kg')) : null;
        $targetBf = (post('target_body_fat_percent') !== null && post('target_body_fat_percent') !== '') ? abs((float) post('target_body_fat_percent')) : null;
        $targetWaist = (post('target_waist_cm') !== null && post('target_waist_cm') !== '') ? abs((float) post('target_waist_cm')) : null;
        $targetExercise = post('target_exercise') ?: null;
        $targetStrengthMax = (post('target_strength_max_kg') !== null && post('target_strength_max_kg') !== '') ? abs((float) post('target_strength_max_kg')) : null;
        $enduranceActivity = post('endurance_activity') ?: null;
        $targetEnduranceDist = (post('target_endurance_distance_km') !== null && post('target_endurance_distance_km') !== '') ? abs((float) post('target_endurance_distance_km')) : null;
        $targetEnduranceTime = (post('target_endurance_time_mins') !== null && post('target_endurance_time_mins') !== '') ? abs((int) post('target_endurance_time_mins')) : null;

        // Save primary goal, schedule preferences, and optional targets
        $pdo = db();
        $pdo->prepare('UPDATE member_profiles SET 
            primary_goal = ?, 
            weekly_workout_target = ?, 
            preferred_duration_mins = ?,
            target_weight_kg = ?,
            target_body_fat_percent = ?,
            target_waist_cm = ?,
            target_exercise = ?,
            target_strength_max_kg = ?,
            endurance_activity = ?,
            target_endurance_distance_km = ?,
            target_endurance_time_mins = ?
            WHERE user_id = ?')
            ->execute([
                $goal, $weeklyTarget, $durationMins,
                $targetWeight, $targetBf, $targetWaist,
                $targetExercise, $targetStrengthMax,
                $enduranceActivity, $targetEnduranceDist, $targetEnduranceTime,
                $user['user_id']
            ]);

        // Re-fetch profile with goal, schedule & targets
        $profile['primary_goal'] = $goal;
        $profile['weekly_workout_target'] = $weeklyTarget;
        $profile['preferred_duration_mins'] = $durationMins;
        $profile['target_weight_kg'] = $targetWeight;
        $profile['target_body_fat_percent'] = $targetBf;
        $profile['target_waist_cm'] = $targetWaist;
        $profile['target_exercise'] = $targetExercise;
        $profile['target_strength_max_kg'] = $targetStrengthMax;
        $profile['endurance_activity'] = $enduranceActivity;
        $profile['target_endurance_distance_km'] = $targetEnduranceDist;
        $profile['target_endurance_time_mins'] = $targetEnduranceTime;
        // Save goal preferences and continue to Step 3: Setup Review
        // Plans will be generated only upon final review confirmation to prevent duplicate plans/notifications.
        flash('Goal saved! Please review your fitness profile before final confirmation.', 'success');
        redirect('setup_review');
    }

    if (!empty($profile['primary_goal']) && !isset($_GET['edit'])) {
        redirect('setup_review');
    }

    $goals = [
        'Aesthetic & Muscle Building Goals' => [
            'Building a visible six-pack' => 'Reducing body fat and hyper-trophying the abdominal wall muscles.',
            'Growing larger biceps and arms' => 'Targeting the upper arms using curls and tricep extensions.',
            'Developing a wide chest' => 'Performing press and fly movements to grow the pectoral muscles.',
            'Sculpting a V-tapered back' => 'Doing pull-ups and rows to widen the latissimus dorsi.',
            'Shaping the lower body' => 'Building muscular legs and glutes through squats and lunges.'
        ],
        'Athletic & Performance Goals' => [
            'Increasing maximum strength' => 'Lifting heavier weights in core movements like deadlifts.',
            'Boosting explosive power' => 'Training for higher vertical jumps and faster sprint speeds.',
            'Enhancing physical endurance' => 'Staying active longer without feeling tired or out of breath.',
            'Improving body flexibility' => 'Extending the range of motion in joints to move freely.'
        ],
        'Body Composition Goals' => [
            'Losing excess body fat' => 'Burning calories to lean out and reveal muscle definition.',
            'Gaining lean body mass' => 'Putting on healthy weight strictly through clean muscle tissue.',
            'Reaching body recomposition' => 'Losing fat and building muscle at the exact same time.'
        ],
        'Casual Lifestyle & Wellness Goals' => [
            'Casual / Flexible Lifestyle' => 'Balanced nutrition, sustainable energy, and moderate exercise without extreme dieting.'
        ]
    ];

    $goalIcons = [
        'Casual / Flexible Lifestyle' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="2" x2="6" y2="4"/><line x1="10" y1="2" x2="10" y2="4"/><line x1="14" y1="2" x2="14" y2="4"/></svg>',
        'Building a visible six-pack' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2"></rect><line x1="12" y1="4" x2="12" y2="20"></line><line x1="4" y1="10" x2="20" y2="10"></line><line x1="4" y1="15" x2="20" y2="15"></line></svg>',
        'Growing larger biceps and arms' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 5v14M18 5v14M6 12h12M3 8v8M21 8v8"/></svg>',
        'Developing a wide chest' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'Sculpting a V-tapered back' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4l8 16 8-16-8 4z"/></svg>',
        'Shaping the lower body' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="4" r="2"/><path d="M15 9l-3 4-3-4M9 13l-2 7M15 13l2 7"/></svg>',
        'Increasing maximum strength' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>',
        'Boosting explosive power' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
        'Enhancing physical endurance' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.42 4.58a5.4 5.4 0 0 0-7.65 0l-.77.78-.77-.78a5.4 5.4 0 0 0-7.65 7.65l.77.78L12 20.65l7.65-7.64.77-.78a5.4 5.4 0 0 0 0-7.65z"/><polyline points="3.5 12 8 12 10 8 13 16 15 12 20.5 12"/></svg>',
        'Improving body flexibility' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="2"/><path d="M5 20l4-7 3 3 5-7 3 2"/></svg>',
        'Losing excess body fat' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
        'Gaining lean body mass' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>',
        'Reaching body recomposition' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/></svg>'
    ];

    $categoryMeta = [
        'Casual Lifestyle & Wellness Goals' => [
            'name' => 'Casual & Lifestyle',
            'desc' => 'Sustainable wellness, flexible nutrition, and active living.',
            'count' => 1,
            'icon' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="2" x2="6" y2="4"/><line x1="10" y1="2" x2="10" y2="4"/><line x1="14" y1="2" x2="14" y2="4"/></svg>'
        ],
        'Aesthetic & Muscle Building Goals' => [
            'name' => 'Muscle & Aesthetics',
            'desc' => 'Target hypertrophy, muscular definition, and aesthetic symmetry.',
            'count' => 5,
            'icon' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>'
        ],
        'Athletic & Performance Goals' => [
            'name' => 'Athletics & Power',
            'desc' => 'Build maximum strength, explosive power, speed, and endurance.',
            'count' => 4,
            'icon' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>'
        ],
        'Body Composition Goals' => [
            'name' => 'Weight & Fat Loss',
            'desc' => 'Burn calories, drop stubborn body fat, or achieve body recomposition.',
            'count' => 3,
            'icon' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg>'
        ]
    ];

    $totalGoals = array_sum(array_map('count', $goals));

    render_header('Select Your Goal', null);
?>
    <link rel="stylesheet" href="<?= h(asset_url('css/pages/setup_goal.css')) ?>">

    <div class="split-login-viewport">
        <div class="split-login-frame goal-mode">
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
                            Fitness Ambition.
                            <span class="highlight">Customized Routine.</span>
                        </h1>
                        <p class="showcase-desc">
                            Select your main focus. FitTrack's recommendation engine will automatically configure your starter workout split and daily macro split.
                        </p>
                    </div>

                    <!-- Three Feature Items -->
                    <div class="showcase-features">
                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M6 5v14M18 5v14M6 12h12M3 8v8M21 8v8" />
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Tailored Exercise Structure</h4>
                                <p>Curated movements prioritizing your specific muscular or athletic targets.</p>
                            </div>
                        </div>

                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path>
                                    <path d="M22 12A10 10 0 0 0 12 2v10z"></path>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Macro Distribution</h4>
                                <p>Balanced protein, carbohydrate, and fat ratios tuned for recovery.</p>
                            </div>
                        </div>

                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="18" y1="20" x2="18" y2="10"></line>
                                    <line x1="12" y1="20" x2="12" y2="4"></line>
                                    <line x1="6" y1="20" x2="6" y2="14"></line>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Workout Plan Generation</h4>
                                <p>Your first 4-week starter guide is instantly populated to your profile.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side Elevated Goal Card -->
            <div class="split-login-card-pane">
                <div class="split-login-card onboarding-card">
                    <div class="split-card-header">
                        <h2 class="split-card-title">Primary Goal</h2>
                        <p class="split-card-subtitle">Choose your focus for <span class="brand-highlight">FitTrack</span> starter plans</p>
                    </div>

                    <!-- Stepper Header -->
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0;">
                        <span id="stepper-indicator-label" style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: #65a30d; text-transform: uppercase; letter-spacing: 0.05em;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <polyline points="20 6 9 17 4 12" />
                            </svg>
                            <span id="stepper-step-text">Step 2: Focus Track (1 of 3)</span>
                        </span>
                        <div style="display: flex; gap: 6px;">
                            <div id="stepper-bar-1" style="width: 28px; height: 5px; border-radius: 3px; background: #84cc16; box-shadow: 0 0 8px rgba(132, 204, 22, 0.4);"></div>
                            <div id="stepper-bar-2" style="width: 28px; height: 5px; border-radius: 3px; background: #e2e8f0; transition: all 0.3s ease;"></div>
                            <div id="stepper-bar-3" style="width: 28px; height: 5px; border-radius: 3px; background: #e2e8f0; transition: all 0.3s ease;"></div>
                        </div>
                    </div>

                    <form method="post" action="index.php?page=setup_goal<?= isset($_GET['edit']) ? '&edit=1' : '' ?>" id="goal-form" class="split-card-form" novalidate onsubmit="const btn = document.getElementById('submit-goal-btn'); if (btn) { setTimeout(() => { btn.disabled = true; }, 10); btn.innerHTML = '<svg class=\'fitness-loader mini\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\' style=\'margin-right:8px;\'><line x1=\'6\' y1=\'12\' x2=\'18\' y2=\'12\'></line><rect x=\'4\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'18\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'2\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect><rect x=\'20\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect></svg> GENERATING PLAN...'; }">
                        <?= csrf_field() ?>

                        <!-- Preserve profile schedule preferences as hidden fields -->
                        <?php
                        $initDays = max(2, min(5, (int)($profile['weekly_workout_target'] ?: 3)));
                        $initDuration = (int)($profile['preferred_duration_mins'] ?: 45);
                        ?>
                        <input type="hidden" name="weekly_workout_target" value="<?= $initDays ?>">
                        <input type="hidden" name="preferred_duration_mins" value="<?= $initDuration ?>">

                        <!-- STAGE 1: Pick Focus Area Track -->
                        <div class="goal-stage-pane active" id="goal-stage-1">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <?php if (isset($_GET['edit'])): ?>
                                        <a href="index.php?page=setup_review" class="stage2-back-btn" style="text-decoration: none;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                                            <span>Back to Review</span>
                                        </a>
                                    <?php endif; ?>
                                    <a href="index.php?page=setup_profile&edit=1" class="stage2-back-btn" style="text-decoration: none;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                                        <span>Profile Setup</span>
                                    </a>
                                </div>
                                <span style="font-size: 11.5px; color: #64748b; font-weight: 600;">Measurements &amp; Routine</span>
                            </div>

                            <div class="stage-prompt">
                                Select a training track to explore targeted goals:
                            </div>

                            <!-- Instant Search across all goals -->
                            <div class="goal-toolbar" style="margin-bottom: 14px;">
                                <div class="goal-search-wrap">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="11" cy="11" r="8"></circle>
                                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                    </svg>
                                    <input type="text" id="goal-search" placeholder="Quick search goals (e.g., strength, abs, fat loss)..." autocomplete="off">
                                </div>
                            </div>

                            <div class="focus-category-list" id="category-cards-list">
                                <?php foreach ($categoryMeta as $catKey => $meta): ?>
                                    <div class="focus-category-card" data-category="<?= htmlspecialchars($catKey, ENT_QUOTES) ?>" onclick="selectCategory('<?= htmlspecialchars($catKey, ENT_QUOTES) ?>')">
                                        <div class="focus-category-icon">
                                            <?= $meta['icon'] ?>
                                        </div>
                                        <div class="focus-category-info">
                                            <div class="focus-category-top">
                                                <span class="focus-category-title"><?= htmlspecialchars($meta['name']) ?></span>
                                                <span class="focus-category-badge"><?= $meta['count'] ?> Goals</span>
                                            </div>
                                            <div class="focus-category-desc"><?= htmlspecialchars($meta['desc']) ?></div>
                                        </div>
                                        <div class="focus-category-arrow">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- STAGE 2: Pick Specific Goal & Confirm -->
                        <div class="goal-stage-pane" id="goal-stage-2">
                            <div class="stage2-nav-bar">
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <button type="button" class="stage2-back-btn" onclick="goToStage(1)">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                                        <span>All Tracks</span>
                                    </button>
                                    <?php if (isset($_GET['edit'])): ?>
                                        <a href="index.php?page=setup_review" class="stage2-back-btn" style="text-decoration: none;" title="Return to review">
                                            <span>Back to Review</span>
                                        </a>
                                    <?php endif; ?>
                                    <a href="index.php?page=setup_profile&edit=1" class="stage2-back-btn" style="text-decoration: none;" title="Edit body measurements and routine">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                                        <span>Profile Setup</span>
                                    </a>
                                </div>
                                <div class="stage2-active-pill" id="stage2-category-pill">
                                    <span>Muscle &amp; Aesthetics</span>
                                </div>
                            </div>

                            <!-- Interactive Goal Cards Grid (Compact, only 3-5 cards shown) -->
                            <div class="goals-grid" id="goals-grid">
                                <?php foreach ($goals as $catName => $catGoals): ?>
                                    <?php foreach ($catGoals as $gName => $gDesc): ?>
                                        <?php
                                        $icon = $goalIcons[$gName] ?? '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>';
                                        $currentProfileGoal = (string)($profile['primary_goal'] ?? '');
                                        $legacyMap = [
                                            'losing_weight'       => 'Losing excess body fat',
                                            'reducing_body_fat'   => 'Losing excess body fat',
                                            'increasing_strength' => 'Increasing maximum strength',
                                            'building_muscle'     => 'Gaining lean body mass',
                                            'improving_endurance' => 'Enhancing physical endurance',
                                            'casual'              => 'Casual / Flexible Lifestyle',
                                            'general_fitness'     => 'Casual / Flexible Lifestyle'
                                        ];
                                        $targetGoalName = $legacyMap[$currentProfileGoal] ?? $currentProfileGoal;
                                        $isCurrentGoal = ($currentProfileGoal === $gName || $targetGoalName === $gName);
                                        ?>
                                        <label class="goal-card <?= $isCurrentGoal ? 'selected' : '' ?>" data-category="<?= htmlspecialchars($catName, ENT_QUOTES) ?>" data-title="<?= strtolower(htmlspecialchars($gName, ENT_QUOTES)) ?>" data-desc="<?= strtolower(htmlspecialchars($gDesc, ENT_QUOTES)) ?>">
                                            <input type="radio" name="primary_goal" value="<?= htmlspecialchars($gName, ENT_QUOTES) ?>" <?= $isCurrentGoal ? 'checked' : '' ?> required>
                                            <div class="goal-icon-box">
                                                <?= $icon ?>
                                            </div>
                                            <div class="goal-content">
                                                <div class="goal-title"><?= htmlspecialchars($gName) ?></div>
                                                <div class="goal-desc"><?= htmlspecialchars($gDesc) ?></div>
                                            </div>
                                            <div class="goal-check-indicator">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                                    <polyline points="20 6 9 17 4 12" />
                                                </svg>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>

                                <div class="goal-no-results" id="no-results-box">
                                    No goals match your search. Try another keyword or clear the search.
                                </div>
                            </div>

                            <!-- Pre-calibrated schedule reminder from profile setup -->
                            <div class="schedule-preset-badge">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                    <line x1="16" y1="2" x2="16" y2="6"/>
                                    <line x1="8" y1="2" x2="8" y2="6"/>
                                    <line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                                <span>Pre-calibrated routine: <strong><?= $initDays ?> days/week &bull; ~<?= $initDuration ?> min sessions</strong></span>
                            </div>

                            <!-- Live Selected Goal Confirmation Box -->
                            <div class="goal-selected-callout">
                                <span style="color: #64748b;">Selected Goal:</span>
                                <strong style="color: var(--lime);" id="selected-goal-display">None chosen yet</strong>
                            </div>

                            <!-- Action buttons on Stage 2: Continue to Targets OR skip directly to review -->
                            <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 14px;">
                                <button type="button" class="split-submit-btn" id="to-targets-btn" disabled style="opacity: 0.6; cursor: not-allowed;" onclick="goToStage(3)">
                                    <span>Continue to Optional Targets</span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <line x1="5" y1="12" x2="19" y2="12"></line>
                                        <polyline points="12 5 19 12 12 19"></polyline>
                                    </svg>
                                </button>
                                <button type="submit" id="skip-targets-btn" class="stage2-back-btn" style="justify-content: center; background: transparent; border: none; color: #64748b; font-size: 12.5px;" disabled>
                                    <span>Skip optional targets &amp; generate plan →</span>
                                </button>
                            </div>
                        </div>

                        <!-- STAGE 3: Optional Supporting Targets Cards -->
                        <div class="goal-stage-pane" id="goal-stage-3">
                            <div class="stage2-nav-bar">
                                <button type="button" class="stage2-back-btn" onclick="goToStage(2)">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                                    <span>Back to Goal Selection</span>
                                </button>
                                <div class="stage2-active-pill">
                                    <span>Optional Supporting Targets</span>
                                </div>
                            </div>

                            <div class="stage-prompt" style="margin-bottom: 12px;">
                                Optional: tap any metric below to configure personal targets alongside your primary goal:
                            </div>

                            <!-- Hidden form inputs storing the configured target values -->
                            <input type="hidden" name="target_weight_kg" id="form-target-weight" value="<?= h((string)($profile['target_weight_kg'] ?? '')) ?>">
                            <input type="hidden" name="target_body_fat_percent" id="form-target-bodyfat" value="<?= h((string)($profile['target_body_fat_percent'] ?? '')) ?>">
                            <input type="hidden" name="target_waist_cm" id="form-target-waist" value="<?= h((string)($profile['target_waist_cm'] ?? '')) ?>">
                            <input type="hidden" name="target_exercise" id="form-target-exercise" value="<?= h((string)($profile['target_exercise'] ?? 'Bench Press')) ?>">
                            <input type="hidden" name="target_strength_max_kg" id="form-target-pr-weight" value="<?= h((string)($profile['target_strength_max_kg'] ?? '')) ?>">
                            <input type="hidden" name="endurance_activity" id="form-endurance-activity" value="<?= h((string)($profile['endurance_activity'] ?? 'Running')) ?>">
                            <input type="hidden" name="target_endurance_distance_km" id="form-endurance-distance" value="<?= h((string)($profile['target_endurance_distance_km'] ?? '')) ?>">
                            <input type="hidden" name="target_endurance_time_mins" id="form-endurance-time" value="<?= h((string)($profile['target_endurance_time_mins'] ?? '')) ?>">

                            <!-- 4 Target Cards Grid -->
                            <div class="target-cards-grid">
                                <!-- TARGET 1: Weight Loss / Scale Target -->
                                <div class="target-card <?= !empty($profile['target_weight_kg']) ? 'has-value' : '' ?>" id="card-target-weight" onclick="openTargetModal('weight')">
                                    <div class="tc-top">
                                        <span class="tc-title">Weight Loss Target</span>
                                        <span class="tc-badge primary">PRIMARY</span>
                                    </div>
                                    <div class="tc-body">
                                        <div class="tc-value" id="preview-target-weight">
                                            <?php if (!empty($profile['target_weight_kg'])): ?>
                                                <span>Target: <strong><?= number_format((float)$profile['target_weight_kg'], 1) ?> kg</strong></span>
                                            <?php else: ?>
                                                <span class="tc-empty">Not set &bull; Tap to configure</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="tc-sub">
                                            Current: <strong><?= number_format((float)($profile['weight_kg'] ?? 0), 1) ?> kg</strong> (Body Stats)
                                        </div>
                                    </div>
                                    <div class="tc-footer">
                                        <span class="tc-action-btn">Configure Target &rarr;</span>
                                    </div>
                                </div>

                                <!-- TARGET 2: Target Body Fat -->
                                <div class="target-card <?= !empty($profile['target_body_fat_percent']) ? 'has-value' : '' ?>" id="card-target-bodyfat" onclick="openTargetModal('bodyfat')">
                                    <div class="tc-top">
                                        <span class="tc-title">Target Body Fat</span>
                                        <span class="tc-badge optional">OPTIONAL</span>
                                    </div>
                                    <div class="tc-body">
                                        <div class="tc-value" id="preview-target-bodyfat">
                                            <?php if (!empty($profile['target_body_fat_percent'])): ?>
                                                <span>Target: <strong><?= number_format((float)$profile['target_body_fat_percent'], 1) ?> %</strong></span>
                                            <?php else: ?>
                                                <span class="tc-empty">Not set &bull; Tap to configure</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="tc-sub">Secondary composition target</div>
                                    </div>
                                    <div class="tc-footer">
                                        <span class="tc-action-btn">Configure Target &rarr;</span>
                                    </div>
                                </div>

                                <!-- TARGET 3: Target Waist Size -->
                                <div class="target-card <?= !empty($profile['target_waist_cm']) ? 'has-value' : '' ?>" id="card-target-waist" onclick="openTargetModal('waist')">
                                    <div class="tc-top">
                                        <span class="tc-title">Target Waist Size</span>
                                        <span class="tc-badge optional">OPTIONAL</span>
                                    </div>
                                    <div class="tc-body">
                                        <div class="tc-value" id="preview-target-waist">
                                            <?php if (!empty($profile['target_waist_cm'])): ?>
                                                <span>Target: <strong><?= number_format((float)$profile['target_waist_cm'], 1) ?> cm</strong></span>
                                            <?php else: ?>
                                                <span class="tc-empty">Not set &bull; Tap to configure</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="tc-sub">Measurement at navel</div>
                                    </div>
                                    <div class="tc-footer">
                                        <span class="tc-action-btn">Configure Target &rarr;</span>
                                    </div>
                                </div>

                                <!-- TARGET 4: Strength PR Target -->
                                <div class="target-card <?= !empty($profile['target_strength_max_kg']) ? 'has-value' : '' ?>" id="card-target-pr" onclick="openTargetModal('pr')">
                                    <div class="tc-top">
                                        <span class="tc-title">Strength PR Target</span>
                                        <span class="tc-badge optional">OPTIONAL</span>
                                    </div>
                                    <div class="tc-body">
                                        <div class="tc-value" id="preview-target-pr">
                                            <?php if (!empty($profile['target_strength_max_kg'])): ?>
                                                <span><?= h($profile['target_exercise'] ?? 'PR') ?>: <strong><?= number_format((float)$profile['target_strength_max_kg'], 1) ?> kg</strong></span>
                                            <?php else: ?>
                                                <span class="tc-empty">Not set &bull; Tap to configure</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="tc-sub">Compound lift benchmark</div>
                                    </div>
                                    <div class="tc-footer">
                                        <span class="tc-action-btn">Configure Target &rarr;</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Optional Endurance Target Pill -->
                            <div style="display: flex; justify-content: flex-start; margin-bottom: 14px;">
                                <button type="button" class="target-btn-pill <?= (!empty($profile['target_endurance_distance_km']) || !empty($profile['target_endurance_time_mins'])) ? 'active' : '' ?>" id="btn-open-endurance" onclick="openTargetModal('endurance')">
                                    <span id="preview-endurance-btn-text">
                                        <?php if (!empty($profile['target_endurance_distance_km']) || !empty($profile['target_endurance_time_mins'])): ?>
                                            <?= h($profile['endurance_activity'] ?? 'Cardio') ?>: <?= !empty($profile['target_endurance_distance_km']) ? $profile['target_endurance_distance_km'] . 'km' : '' ?><?= (!empty($profile['target_endurance_distance_km']) && !empty($profile['target_endurance_time_mins'])) ? ' in ' : '' ?><?= !empty($profile['target_endurance_time_mins']) ? $profile['target_endurance_time_mins'] . ' mins' : '' ?> (Edit)
                                        <?php else: ?>
                                            + Add Endurance Target
                                        <?php endif; ?>
                                    </span>
                                </button>
                            </div>

                            <button type="submit" class="split-submit-btn" id="submit-goal-btn" style="margin-top: 4px;">
                                <span>Save &amp; Continue to Review</span>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                    <polyline points="12 5 19 12 12 19"></polyline>
                                </svg>
                            </button>

                            <div style="text-align: center; margin-top: 10px;">
                                <button type="submit" style="background: none; border: none; color: #64748b; font-size: 12.5px; font-weight: 600; cursor: pointer; text-decoration: underline;">
                                    Skip optional targets &amp; continue to review →
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- TARGET MODALS -->
    <!-- MODAL 1: Weight Loss Target (Baseline aligned!) -->
    <dialog id="modal-target-weight" class="target-modal" onclick="if (event.target === this) this.close();">
        <div class="tm-header">
            <div class="tm-header-text">
                <div class="tm-title">
                    <span>Weight Loss Target</span>
                </div>
                <p class="tm-subtitle">Focus on reaching a healthy, sustainable target body weight.</p>
            </div>
            <button type="button" class="tm-close-btn" onclick="closeTargetModal('weight')" aria-label="Close modal" title="Close">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
        <div class="tm-body">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; align-items: start;">
                <!-- Column 1: Current Weight -->
                <div class="tm-field-box">
                    <label class="tm-field-label">Current Weight</label>
                    <div style="height: 44px; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 0 14px; display: flex; align-items: center; justify-content: space-between; box-sizing: border-box;">
                        <span style="font-size: 15px; font-weight: 800; color: #0f172a;"><?= number_format((float)($profile['weight_kg'] ?? 0), 1) ?></span>
                        <span style="font-size: 12px; font-weight: 700; color: #64748b;">kg</span>
                    </div>
                    <span class="tm-hint">From Body Stats</span>
                </div>
                <!-- Column 2: Target Weight -->
                <div class="tm-field-box">
                    <label class="tm-field-label">Target Weight <span style="color: #65a30d;">*</span></label>
                    <div class="tm-input-wrap">
                        <input type="number" step="0.1" min="20" max="300" id="modal-field-weight" placeholder="e.g. 68.0" value="<?= h((string)($profile['target_weight_kg'] ?? '')) ?>" autofocus>
                        <span class="tm-input-unit">kg</span>
                    </div>
                    <span class="tm-hint">Realistic target scale weight</span>
                </div>
            </div>
            <div style="background: rgba(132, 204, 22, 0.08); border: 1px solid rgba(132, 204, 22, 0.25); border-radius: 9px; padding: 10px 14px; font-size: 12px; color: #334155; line-height: 1.4;">
                Healthy, sustainable progress is usually between <strong>0.5 to 1.0 kg</strong> change per week.
            </div>
        </div>
        <div class="tm-footer">
            <button type="button" class="tm-btn-clear" onclick="clearTargetModal('weight')">Clear Target</button>
            <button type="button" class="tm-btn-save" onclick="saveTargetModal('weight')">
                <span>Save Target</span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            </button>
        </div>
    </dialog>

    <!-- MODAL 2: Target Body Fat -->
    <dialog id="modal-target-bodyfat" class="target-modal" onclick="if (event.target === this) this.close();">
        <div class="tm-header">
            <div class="tm-header-text">
                <div class="tm-title">
                    <span>Target Body Fat</span>
                </div>
                <p class="tm-subtitle">Secondary metric to track alongside your primary fitness goal.</p>
            </div>
            <button type="button" class="tm-close-btn" onclick="closeTargetModal('bodyfat')" aria-label="Close modal" title="Close">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
        <div class="tm-body">
            <div class="tm-field-box">
                <label class="tm-field-label">Target Body Fat Percentage</label>
                <div class="tm-input-wrap">
                    <input type="number" step="0.1" min="3" max="65" id="modal-field-bodyfat" placeholder="e.g. 15.0" value="<?= h((string)($profile['target_body_fat_percent'] ?? '')) ?>" autofocus>
                    <span class="tm-input-unit">%</span>
                </div>
                <span class="tm-hint">Healthy fitness ranges: Men 10–18% &bull; Women 18–26%</span>
            </div>
        </div>
        <div class="tm-footer">
            <button type="button" class="tm-btn-clear" onclick="clearTargetModal('bodyfat')">Clear Target</button>
            <button type="button" class="tm-btn-save" onclick="saveTargetModal('bodyfat')">
                <span>Save Target</span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            </button>
        </div>
    </dialog>

    <!-- MODAL 3: Target Waist Size -->
    <dialog id="modal-target-waist" class="target-modal" onclick="if (event.target === this) this.close();">
        <div class="tm-header">
            <div class="tm-header-text">
                <div class="tm-title">
                    <span>Target Waist Size</span>
                </div>
                <p class="tm-subtitle">Measure at the level of the belly button to monitor abdominal changes.</p>
            </div>
            <button type="button" class="tm-close-btn" onclick="closeTargetModal('waist')" aria-label="Close modal" title="Close">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
        <div class="tm-body">
            <div class="tm-field-box">
                <label class="tm-field-label">Target Waist Circumference</label>
                <div class="tm-input-wrap">
                    <input type="number" step="0.1" min="30" max="200" id="modal-field-waist" placeholder="e.g. 78.0" value="<?= h((string)($profile['target_waist_cm'] ?? '')) ?>" autofocus>
                    <span class="tm-input-unit">cm</span>
                </div>
                <span class="tm-hint"><?= !empty($profile['waist_cm']) ? 'Current waist measurement: <strong>' . number_format((float)$profile['waist_cm'], 1) . ' cm</strong>' : 'Measure relaxed across the navel' ?></span>
            </div>
        </div>
        <div class="tm-footer">
            <button type="button" class="tm-btn-clear" onclick="clearTargetModal('waist')">Clear Target</button>
            <button type="button" class="tm-btn-save" onclick="saveTargetModal('waist')">
                <span>Save Target</span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            </button>
        </div>
    </dialog>

    <!-- MODAL 4: Strength PR Target -->
    <dialog id="modal-target-pr" class="target-modal" onclick="if (event.target === this) this.close();">
        <div class="tm-header">
            <div class="tm-header-text">
                <div class="tm-title">
                    <span>Strength PR Target</span>
                </div>
                <p class="tm-subtitle">Benchmark personal record on a core compound exercise.</p>
            </div>
            <button type="button" class="tm-close-btn" onclick="closeTargetModal('pr')" aria-label="Close modal" title="Close">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
        <div class="tm-body">
            <div class="tm-field-box">
                <label class="tm-field-label">Target Exercise</label>
                <div class="tm-input-wrap">
                    <select id="modal-field-pr-exercise" style="padding-right: 14px;">
                        <option value="Bench Press" <?= ($profile['target_exercise'] ?? '') === 'Bench Press' ? 'selected' : '' ?>>Bench Press</option>
                        <option value="Back Squat" <?= ($profile['target_exercise'] ?? '') === 'Back Squat' ? 'selected' : '' ?>>Back Squat</option>
                        <option value="Deadlift" <?= ($profile['target_exercise'] ?? '') === 'Deadlift' ? 'selected' : '' ?>>Deadlift</option>
                        <option value="Overhead Press" <?= ($profile['target_exercise'] ?? '') === 'Overhead Press' ? 'selected' : '' ?>>Overhead Press</option>
                        <option value="Barbell Row" <?= ($profile['target_exercise'] ?? '') === 'Barbell Row' ? 'selected' : '' ?>>Barbell Row</option>
                    </select>
                </div>
            </div>
            <div class="tm-field-box">
                <label class="tm-field-label">Target PR Weight</label>
                <div class="tm-input-wrap">
                    <input type="number" step="0.5" min="5" max="500" id="modal-field-pr-weight" placeholder="e.g. 80.0" value="<?= h((string)($profile['target_strength_max_kg'] ?? '')) ?>" autofocus>
                    <span class="tm-input-unit">kg</span>
                </div>
                <span class="tm-hint">Target 1-rep maximum or top working set weight</span>
            </div>
        </div>
        <div class="tm-footer">
            <button type="button" class="tm-btn-clear" onclick="clearTargetModal('pr')">Clear Target</button>
            <button type="button" class="tm-btn-save" onclick="saveTargetModal('pr')">
                <span>Save Target</span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            </button>
        </div>
    </dialog>

    <!-- MODAL 5: Endurance Target -->
    <dialog id="modal-target-endurance" class="target-modal" onclick="if (event.target === this) this.close();">
        <div class="tm-header">
            <div class="tm-header-text">
                <div class="tm-title">
                    <span>Endurance Target</span>
                </div>
                <p class="tm-subtitle">Benchmark cardiovascular distance or time goal.</p>
            </div>
            <button type="button" class="tm-close-btn" onclick="closeTargetModal('endurance')" aria-label="Close modal" title="Close">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
        <div class="tm-body">
            <div class="tm-field-box">
                <label class="tm-field-label">Activity</label>
                <div class="tm-input-wrap">
                    <select id="modal-field-endurance-act" style="padding-right: 14px;">
                        <option value="Running" <?= ($profile['endurance_activity'] ?? '') === 'Running' ? 'selected' : '' ?>>Running</option>
                        <option value="Cycling" <?= ($profile['endurance_activity'] ?? '') === 'Cycling' ? 'selected' : '' ?>>Cycling</option>
                        <option value="Rowing" <?= ($profile['endurance_activity'] ?? '') === 'Rowing' ? 'selected' : '' ?>>Rowing</option>
                        <option value="Swimming" <?= ($profile['endurance_activity'] ?? '') === 'Swimming' ? 'selected' : '' ?>>Swimming</option>
                        <option value="Walking" <?= ($profile['endurance_activity'] ?? '') === 'Walking' ? 'selected' : '' ?>>Walking</option>
                    </select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="tm-field-box">
                    <label class="tm-field-label">Target Distance</label>
                    <div class="tm-input-wrap">
                        <input type="number" step="0.1" min="0.5" max="100" id="modal-field-endurance-dist" placeholder="e.g. 5.0" value="<?= h((string)($profile['target_endurance_distance_km'] ?? '')) ?>">
                        <span class="tm-input-unit">km</span>
                    </div>
                </div>
                <div class="tm-field-box">
                    <label class="tm-field-label">Target Time</label>
                    <div class="tm-input-wrap">
                        <input type="number" step="1" min="1" max="600" id="modal-field-endurance-time" placeholder="e.g. 30" value="<?= h((string)($profile['target_endurance_time_mins'] ?? '')) ?>">
                        <span class="tm-input-unit">mins</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="tm-footer">
            <button type="button" class="tm-btn-clear" onclick="clearTargetModal('endurance')">Clear Target</button>
            <button type="button" class="tm-btn-save" onclick="saveTargetModal('endurance')">
                <span>Save Target</span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            </button>
        </div>
    </dialog>

    <script src="<?= h(asset_url('js/pages/setup_goal.js')) ?>"></script>
<?php
    render_footer();
}
