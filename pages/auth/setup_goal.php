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

    if ($user['role'] !== 'member') {
        redirect('dashboard');
    }

    $profile = member_profile((int) $user['user_id']);
    if (!$profile) {
        redirect('setup_profile');
    }

    if (!empty($profile['primary_goal'])) {
        $hasMembership = scalar('SELECT 1 FROM memberships WHERE user_id = ? AND status IN ("active", "pending")', [$user['user_id']]);
        $hasGym = scalar('SELECT 1 FROM gym_members WHERE user_id = ?', [$user['user_id']]);
        if (!$hasMembership && !$hasGym) {
            redirect('gym_selection');
        }
        redirect('dashboard');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $goal = post('primary_goal');
        if (!$goal) {
            flash('Please select a primary goal.', 'danger');
            redirect('setup_goal');
        }

        // Save detailed goal
        $pdo = db();
        $pdo->prepare('UPDATE member_profiles SET primary_goal = ? WHERE user_id = ?')->execute([$goal, $user['user_id']]);

        // Re-fetch profile with goal
        $profile['primary_goal'] = $goal;

        // Map detailed goal to basic goal for recommendation engine
        $basicGoal = map_detailed_goal_to_basic($goal);

        $tier = $profile['fitness_tier'] ?? 1;
        $sex = $profile['biological_sex'];
        $activity = $profile['activity_level'];

        // Workout rule lookup using BASIC goal
        $wRule = $pdo->prepare('SELECT recommended_workout_structure FROM workout_rules WHERE experience_level = ? AND (biological_sex = ? OR biological_sex = "any") AND primary_goal = ? AND (activity_level = ? OR activity_level = "any") LIMIT 1');
        $wRule->execute([$tier, $sex, $basicGoal, $activity]);
        $workoutStruct = $wRule->fetchColumn();
        if (!$workoutStruct) {
            $wRule->execute([1, 'any', $basicGoal, 'any']);
            $workoutStruct = $wRule->fetchColumn() ?: 'General full body workout 3 times a week.';
        }

        // Diet rule lookup using BASIC goal
        $dRule = $pdo->prepare('SELECT macro_split, notes FROM diet_rules WHERE experience_level = ? AND (biological_sex = ? OR biological_sex = "any") AND primary_goal = ? AND (activity_level = ? OR activity_level = "any") LIMIT 1');
        $dRule->execute([$tier, $sex, $basicGoal, $activity]);
        $dietInfo = $dRule->fetch();
        if (!$dietInfo) {
            $dRule->execute([1, 'any', $basicGoal, 'any']);
            $dietInfo = $dRule->fetch();
        }
        $dietStruct = $dietInfo ? ($dietInfo['macro_split'] . ' - ' . $dietInfo['notes']) : 'Balanced diet.';

        // Also generate their actual workout plan using the basic goal mapping logic
        generate_workout_plan((int) $user['user_id']);

        $msgBody = "Based on your goal to **" . $goal . "**, here is your starter guide!\n\n**Workout Structure:**\n$workoutStruct\n\n**Diet & Macros:**\n$dietStruct";
        notify_user((int) $user['user_id'], 'system', 'Your Starter Plan is Ready!', $msgBody);

        flash('Goal saved! Check your notifications for your starter plan.', 'success');
        $hasMembership = scalar('SELECT 1 FROM memberships WHERE user_id = ? AND status IN ("active", "pending")', [$user['user_id']]);
        $hasGym = scalar('SELECT 1 FROM gym_members WHERE user_id = ?', [$user['user_id']]);
        if (!$hasMembership && !$hasGym) {
            redirect('gym_selection');
        }
        redirect('dashboard');
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
        ]
    ];

    $goalIcons = [
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
        'Aesthetic & Muscle Building Goals' => [
            'name' => 'Muscle & Aesthetics',
            'count' => 5,
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>'
        ],
        'Athletic & Performance Goals' => [
            'name' => 'Athletics & Power',
            'count' => 4,
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>'
        ],
        'Body Composition Goals' => [
            'name' => 'Weight & Fat Loss',
            'count' => 3,
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg>'
        ]
    ];

    $totalGoals = array_sum(array_map('count', $goals));

    render_header('Select Your Goal', null);
    ?>
    <style>
        .split-login-frame.goal-mode {
            max-width: 1180px;
        }
        .goal-toolbar {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 18px;
        }
        .goal-search-wrap {
            position: relative;
            width: 100%;
        }
        .goal-search-wrap svg {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            color: var(--muted);
            pointer-events: none;
            transition: color 0.2s;
        }
        #goal-search {
            width: 100%;
            background: var(--panel-soft);
            border: 1.5px solid var(--line);
            border-radius: 10px;
            padding: 11px 14px 11px 42px;
            color: var(--ink);
            font-size: 13px;
            font-weight: 500;
            outline: none;
            box-sizing: border-box;
            transition: all 0.2s;
        }
        #goal-search:focus {
            background: var(--panel);
            border-color: var(--lime);
            box-shadow: 0 0 0 2px color-mix(in srgb, var(--lime) 25%, transparent);
        }
        .category-tabs {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 4px;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }
        .category-tabs::-webkit-scrollbar { display: none; }
        .category-tab-btn {
            background: var(--panel-soft);
            border: 1px solid var(--line);
            color: var(--muted);
            border-radius: 8px;
            padding: 7px 13px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            transition: all 0.2s ease;
            user-select: none;
        }
        .category-tab-btn:hover {
            color: var(--ink);
            border-color: var(--lime);
        }
        .category-tab-btn.active {
            background: color-mix(in srgb, var(--lime) 12%, var(--panel));
            border-color: var(--lime);
            color: var(--ink);
            font-weight: 700;
        }
        .category-tab-btn .badge-count {
            background: var(--panel);
            color: var(--muted);
            padding: 2px 6px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }
        .category-tab-btn.active .badge-count {
            background: var(--lime);
            color: #090b10;
        }
        .goals-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 20px;
            max-height: 400px;
            overflow-y: auto;
            padding-right: 4px;
        }
        .goals-grid::-webkit-scrollbar {
            width: 5px;
        }
        .goals-grid::-webkit-scrollbar-thumb {
            background: var(--line);
            border-radius: 4px;
        }
        .goal-card {
            position: relative;
            background: var(--panel-soft);
            border: 1.5px solid var(--line);
            border-radius: 12px;
            padding: 13px 14px;
            cursor: pointer;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            transition: all 0.2s ease;
            user-select: none;
        }
        .goal-card:hover {
            transform: translateY(-2px);
            border-color: var(--lime);
        }
        .goal-card.selected {
            border-color: var(--lime);
            background: color-mix(in srgb, var(--lime) 10%, var(--panel));
            box-shadow: 0 0 0 2px color-mix(in srgb, var(--lime) 30%, transparent);
        }
        .goal-card input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .goal-icon-box {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: var(--panel);
            border: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: var(--ink);
            transition: all 0.2s;
        }
        .goal-card:hover .goal-icon-box {
            color: var(--lime);
            border-color: var(--lime);
        }
        .goal-card.selected .goal-icon-box {
            background: var(--lime);
            color: #090b10;
            border-color: var(--lime);
        }
        .goal-content {
            flex-grow: 1;
            min-width: 0;
        }
        .goal-title {
            font-weight: 700;
            font-size: 13px;
            color: var(--ink);
            line-height: 1.3;
            margin-bottom: 3px;
        }
        .goal-desc {
            font-size: 11px;
            color: var(--muted);
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .goal-check-indicator {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 2px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 2px;
            transition: all 0.2s;
            background: var(--panel);
        }
        .goal-check-indicator svg {
            display: none;
            width: 11px;
            height: 11px;
            color: #090b10;
            stroke-width: 3;
        }
        .goal-card.selected .goal-check-indicator {
            border-color: var(--lime);
            background: var(--lime);
        }
        .goal-card.selected .goal-check-indicator svg {
            display: block;
        }
        .goal-no-results {
            display: none;
            text-align: center;
            padding: 30px 16px;
            color: var(--muted);
            grid-column: 1 / -1;
            background: var(--panel-soft);
            border-radius: 12px;
            border: 1.5px dashed var(--line);
            font-size: 13px;
        }
        .goal-no-results.show { display: block; }
        .goal-selected-callout {
            background: var(--panel-soft);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13px;
        }
        @media (max-width: 768px) {
            .goals-grid {
                grid-template-columns: 1fr;
                max-height: 340px;
            }
        }
    </style>

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
                                    <path d="M6 5v14M18 5v14M6 12h12M3 8v8M21 8v8"/>
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
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--line);">
                        <span style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: var(--lime); text-transform: uppercase; letter-spacing: 0.05em;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            Step 2 of 2: Fitness Goal
                        </span>
                        <div style="display: flex; gap: 6px;">
                            <div style="width: 28px; height: 5px; border-radius: 3px; background: color-mix(in srgb, var(--lime) 60%, var(--line));"></div>
                            <div style="width: 28px; height: 5px; border-radius: 3px; background: var(--lime); box-shadow: 0 0 8px color-mix(in srgb, var(--lime) 50%, transparent);"></div>
                        </div>
                    </div>

                    <!-- Search & Filter Controls -->
                    <div class="goal-toolbar">
                        <div class="goal-search-wrap">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                            <input type="text" id="goal-search" placeholder="Search goals (e.g., strength, abs, fat loss)..." autocomplete="off">
                        </div>

                        <div class="category-tabs" role="tablist">
                            <button type="button" class="category-tab-btn active" data-category="all">
                                <span>All Goals</span>
                                <span class="badge-count"><?= $totalGoals ?></span>
                            </button>
                            <?php foreach ($categoryMeta as $catKey => $meta): ?>
                                <button type="button" class="category-tab-btn" data-category="<?= htmlspecialchars($catKey, ENT_QUOTES) ?>">
                                    <?= $meta['icon'] ?>
                                    <span><?= htmlspecialchars($meta['name']) ?></span>
                                    <span class="badge-count"><?= $meta['count'] ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <form method="post" action="index.php?page=setup_goal" id="goal-form" class="split-card-form" novalidate onsubmit="const btn = document.getElementById('submit-goal-btn'); if (btn) { btn.disabled = true; btn.innerHTML = '<svg class=\'fitness-loader mini\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\' style=\'margin-right:8px;\'><line x1=\'6\' y1=\'12\' x2=\'18\' y2=\'12\'></line><rect x=\'4\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'18\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'2\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect><rect x=\'20\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect></svg> GENERATING PLAN...'; }">
                        <?= csrf_field() ?>

                        <!-- Interactive Goal Cards Grid -->
                        <div class="goals-grid" id="goals-grid">
                            <?php foreach ($goals as $catName => $catGoals): ?>
                                <?php foreach ($catGoals as $gName => $gDesc): ?>
                                    <?php 
                                        $icon = $goalIcons[$gName] ?? '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>';
                                    ?>
                                    <label class="goal-card" data-category="<?= htmlspecialchars($catName, ENT_QUOTES) ?>" data-title="<?= strtolower(htmlspecialchars($gName, ENT_QUOTES)) ?>" data-desc="<?= strtolower(htmlspecialchars($gDesc, ENT_QUOTES)) ?>">
                                        <input type="radio" name="primary_goal" value="<?= htmlspecialchars($gName, ENT_QUOTES) ?>" required>
                                        <div class="goal-icon-box">
                                            <?= $icon ?>
                                        </div>
                                        <div class="goal-content">
                                            <div class="goal-title"><?= htmlspecialchars($gName) ?></div>
                                            <div class="goal-desc"><?= htmlspecialchars($gDesc) ?></div>
                                        </div>
                                        <div class="goal-check-indicator">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="20 6 9 17 4 12"/></svg>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            <?php endforeach; ?>

                            <div class="goal-no-results" id="no-results-box">
                                No goals match your search. Try another keyword or clear the search.
                            </div>
                        </div>

                        <!-- Live Selected Goal Confirmation Box -->
                        <div class="goal-selected-callout">
                            <span style="color: var(--muted);">Selected Goal:</span>
                            <strong style="color: var(--lime);" id="selected-goal-display">None chosen yet</strong>
                        </div>

                        <button type="submit" class="split-submit-btn" id="submit-goal-btn" disabled style="opacity: 0.6; cursor: not-allowed;">
                            <span>Confirm Goal & Generate Plan</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                                <polyline points="12 5 19 12 12 19"></polyline>
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const goalCards = document.querySelectorAll('.goal-card');
        const submitBtn = document.getElementById('submit-goal-btn');
        const selectedDisplay = document.getElementById('selected-goal-display');
        const searchInput = document.getElementById('goal-search');
        const categoryTabs = document.querySelectorAll('.category-tab-btn');
        const noResults = document.getElementById('no-results-box');

        let activeCategory = 'all';
        let searchQuery = '';

        // Card Selection Logic
        goalCards.forEach(card => {
            card.addEventListener('click', function() {
                goalCards.forEach(c => c.classList.remove('selected'));
                this.classList.add('selected');
                const radio = this.querySelector('input[type="radio"]');
                if (radio) radio.checked = true;

                const goalTitle = this.querySelector('.goal-title')?.textContent || 'Selected';
                selectedDisplay.textContent = goalTitle;

                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                    submitBtn.style.cursor = 'pointer';
                }
            });
        });

        // Category Tab Filter
        categoryTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                categoryTabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                activeCategory = this.getAttribute('data-category');
                filterGoals();
            });
        });

        // Search Input Filter
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                searchQuery = this.value.trim().toLowerCase();
                filterGoals();
            });
        }

        function filterGoals() {
            let visibleCount = 0;

            goalCards.forEach(card => {
                const cardCat = card.getAttribute('data-category');
                const title = card.getAttribute('data-title');
                const desc = card.getAttribute('data-desc');

                const matchesCat = (activeCategory === 'all' || cardCat === activeCategory);
                const matchesSearch = (!searchQuery || title.includes(searchQuery) || desc.includes(searchQuery));

                if (matchesCat && matchesSearch) {
                    card.style.display = 'flex';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            if (noResults) {
                if (visibleCount === 0) {
                    noResults.classList.add('show');
                } else {
                    noResults.classList.remove('show');
                }
            }
        }
    })();
    </script>
    <?php
    render_footer();
}