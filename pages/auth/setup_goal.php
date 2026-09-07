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

    $categories = [
        'all' => [
            'name' => 'All Goals',
            'count' => 12,
            'icon' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>'
        ],
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
        .goal-bg-decor {
            position: absolute;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 0;
            border-radius: inherit;
        }
        .goal-blob {
            position: absolute;
            width: 320px;
            height: 320px;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.12;
        }
        .goal-blob--a { background: var(--lime); top: -100px; left: -80px; }
        .goal-blob--b { background: #38bdf8; bottom: -120px; right: -90px; }

        .goal-card-wrap {
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
        .stepper-pill.done {
            background: rgba(255, 255, 255, 0.05);
            color: var(--muted);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .stepper-pill.active {
            background: rgba(199, 255, 34, 0.12);
            color: var(--lime);
            border: 1px solid rgba(199, 255, 34, 0.35);
        }
        .stepper-arrow {
            color: var(--muted);
            opacity: 0.4;
        }

        /* Controls Toolbar: Search & Tabs */
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
            width: 17px;
            height: 17px;
            color: var(--muted);
            pointer-events: none;
            transition: color 0.2s;
        }
        #goal-search {
            width: 100%;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 10px 14px 10px 42px;
            color: var(--ink);
            font-size: 0.9rem;
            outline: none;
            transition: all 0.2s;
        }
        #goal-search:focus {
            background: rgba(255, 255, 255, 0.06);
            border-color: var(--lime);
            box-shadow: 0 0 0 3px rgba(199, 255, 34, 0.12);
        }
        #goal-search:focus + svg {
            color: var(--lime);
        }

        /* Category Filter Tabs */
        .category-tabs {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 2px;
            scrollbar-width: none;
        }
        .category-tabs::-webkit-scrollbar { display: none; }
        
        .category-tab-btn {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: var(--muted);
            border-radius: 8px;
            padding: 7px 13px;
            font-size: 0.82rem;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            white-space: nowrap;
            transition: all 0.2s ease;
            user-select: none;
        }
        .category-tab-btn:hover {
            color: var(--ink);
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.16);
        }
        .category-tab-btn.active {
            background: rgba(199, 255, 34, 0.14);
            border-color: var(--lime);
            color: var(--lime);
            font-weight: 600;
            box-shadow: 0 0 12px rgba(199, 255, 34, 0.12);
        }
        .category-tab-btn .badge-count {
            background: rgba(255, 255, 255, 0.08);
            padding: 1px 6px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 600;
        }
        .category-tab-btn.active .badge-count {
            background: rgba(199, 255, 34, 0.25);
            color: #fff;
        }

        /* Responsive 2-Column Grid (No internal scroll fatigue) */
        .goals-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }

        .goal-card {
            position: relative;
            background: rgba(255, 255, 255, 0.03);
            border: 1.5px solid rgba(255, 255, 255, 0.07);
            border-radius: 12px;
            padding: 14px 16px;
            cursor: pointer;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            transition: transform 0.2s ease, border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
            user-select: none;
        }
        .goal-card:hover {
            transform: translateY(-2px);
            border-color: rgba(199, 255, 34, 0.4);
            background: rgba(255, 255, 255, 0.05);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.25);
        }
        .goal-card.selected {
            border-color: var(--lime);
            background: rgba(199, 255, 34, 0.07);
            box-shadow: 0 0 18px rgba(199, 255, 34, 0.16);
        }

        .goal-card input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            pointer-events: none;
        }

        /* Icon Badge */
        .goal-icon-box {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: var(--muted);
            transition: all 0.2s;
        }
        .goal-card:hover .goal-icon-box {
            color: var(--lime);
            border-color: rgba(199, 255, 34, 0.3);
        }
        .goal-card.selected .goal-icon-box {
            background: rgba(199, 255, 34, 0.18);
            border-color: var(--lime);
            color: var(--lime);
        }

        .goal-content {
            flex-grow: 1;
            min-width: 0;
        }
        .goal-title {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--ink);
            line-height: 1.3;
            margin-bottom: 4px;
        }
        .goal-desc {
            font-size: 0.8rem;
            color: var(--muted);
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* Checkmark / Radio Pill */
        .goal-check-indicator {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, 0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 2px;
            transition: all 0.2s;
        }
        .goal-check-indicator svg {
            display: none;
            width: 12px;
            height: 12px;
            color: var(--bg);
            stroke-width: 3;
        }
        .goal-card.selected .goal-check-indicator {
            border-color: var(--lime);
            background: var(--lime);
        }
        .goal-card.selected .goal-check-indicator svg {
            display: block;
        }

        /* No Results State */
        .goal-no-results {
            display: none;
            text-align: center;
            padding: 36px 16px;
            color: var(--muted);
            grid-column: 1 / -1;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 12px;
            border: 1px dashed rgba(255, 255, 255, 0.1);
        }
        .goal-no-results.show { display: block; }

        /* Footer & Submit */
        .goal-action-bar {
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding-top: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .goal-selected-status {
            font-size: 0.85rem;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .goal-selected-status strong {
            color: var(--lime);
            font-weight: 600;
        }

        @media (max-width: 680px) {
            .goals-grid {
                grid-template-columns: 1fr;
            }
            .goal-action-bar {
                flex-direction: column;
                align-items: stretch;
            }
            .goal-action-bar button {
                width: 100%;
            }
        }
    </style>

    <section style="padding: 30px 16px; min-height: 85vh; display:flex; align-items:center; justify-content:center;">
        <div class="auth-card goal-card-wrap" style="max-width:860px; width:100%; position:relative; overflow:hidden;">
            <div class="goal-bg-decor" aria-hidden="true">
                <div class="goal-blob goal-blob--a"></div>
                <div class="goal-blob goal-blob--b"></div>
            </div>

            <!-- Onboarding Stepper Indicator -->
            <div class="onboarding-stepper" style="position:relative; z-index:1;">
                <span class="stepper-pill done">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    Step 1: Measurements
                </span>
                <span class="stepper-arrow">›</span>
                <span class="stepper-pill active">
                    Step 2: Primary Goal
                </span>
            </div>

            <div class="auth-card-header" style="position:relative; z-index:1; margin-bottom: 20px; text-align: center;">
                <h1 class="auth-title" style="font-size:1.75rem; margin-bottom: 6px;">What's Your Main Fitness Goal?</h1>
                <p class="auth-subtitle" style="max-width: 520px; margin: 0 auto;">Select your target focus. FitTracks will customize your workout split, exercise selection, and nutrition guidelines.</p>
            </div>

            <form method="post" action="index.php?page=setup_goal" id="goal-form" style="position:relative; z-index:1;">
                <?= csrf_field() ?>

                <!-- Toolbar: Instant Search + Category Tabs -->
                <div class="goal-toolbar">
                    <div class="goal-search-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="7"></circle>
                            <path d="M21 21l-3.8-3.8"></path>
                        </svg>
                        <input type="text" id="goal-search" placeholder="Search goals (e.g. 'six-pack', 'strength', 'fat loss')..." autocomplete="off">
                    </div>

                    <div class="category-tabs" role="tablist">
                        <?php foreach ($categories as $catKey => $cat): ?>
                            <button type="button" 
                                    class="category-tab-btn <?= $catKey === 'all' ? 'active' : '' ?>" 
                                    data-category="<?= h($catKey) ?>">
                                <span><?= $cat['icon'] ?></span>
                                <span><?= h($cat['name']) ?></span>
                                <span class="badge-count"><?= (int) $cat['count'] ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 2-Column Responsive Card Grid (Zero nested scrollbars) -->
                <div class="goals-grid" id="goals-grid">
                    <?php foreach ($goals as $category => $items): ?>
                        <?php foreach ($items as $title => $desc): ?>
                            <label class="goal-card" 
                                   data-category="<?= h($category) ?>" 
                                   data-search="<?= h(mb_strtolower($title . ' ' . $desc)) ?>">
                                <input type="radio" name="primary_goal" value="<?= h($title) ?>" class="goal-radio" required>
                                
                                <div class="goal-icon-box" aria-hidden="true">
                                    <?= $goalIcons[$title] ?? '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>' ?>
                                </div>

                                <div class="goal-content">
                                    <div class="goal-title"><?= h($title) ?></div>
                                    <div class="goal-desc"><?= h($desc) ?></div>
                                </div>

                                <div class="goal-check-indicator" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    <?php endforeach; ?>

                    <div class="goal-no-results" id="goal-no-results">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 8px; opacity: 0.5;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <p style="margin:0; font-size: 0.9rem;">No goals found matching your search.</p>
                        <button type="button" onclick="document.getElementById('goal-search').value=''; document.getElementById('goal-search').dispatchEvent(new Event('input'));" style="background: transparent; border: none; color: var(--lime); font-size: 0.85rem; margin-top: 6px; cursor: pointer; text-decoration: underline;">Clear search filter</button>
                    </div>
                </div>

                <!-- Bottom Action Bar -->
                <div class="goal-action-bar">
                    <div class="goal-selected-status" id="goal-selected-status">
                        <span style="opacity: 0.6;">Select a goal above to continue</span>
                    </div>

                    <button type="submit" class="auth-submit-btn" id="submit-btn" disabled style="opacity: 0.45; cursor: not-allowed; padding: 12px 28px;">
                        FINISH SETUP & GENERATE PLAN
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-left: 6px;"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg>
                    </button>
                </div>
            </form>
        </div>
    </section>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script>
    (function () {
        const cards = Array.from(document.querySelectorAll('.goal-card'));
        const tabBtns = Array.from(document.querySelectorAll('.category-tab-btn'));
        const searchInput = document.getElementById('goal-search');
        const noResults = document.getElementById('goal-no-results');
        const submitBtn = document.getElementById('submit-btn');
        const selectedStatus = document.getElementById('goal-selected-status');
        const hasGSAP = typeof gsap !== 'undefined';

        let activeCategory = 'all';
        let searchQuery = '';

        // GSAP Entrance
        if (hasGSAP) {
            gsap.fromTo('.auth-card', { opacity: 0, y: 20 }, { opacity: 1, y: 0, duration: 0.45, ease: 'power3.out' });
            gsap.fromTo('.goal-card', { opacity: 0, y: 15 }, { opacity: 1, y: 0, duration: 0.4, stagger: 0.03, ease: 'power2.out', delay: 0.1 });
        }

        // Category Tab Switching
        tabBtns.forEach(btn => {
            btn.addEventListener('click', function () {
                tabBtns.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                activeCategory = this.dataset.category;
                filterCards();
            });
        });

        // Live Search Input
        let debounceTimer;
        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            searchQuery = this.value.trim().toLowerCase();
            debounceTimer = setTimeout(filterCards, 60);
        });

        function filterCards() {
            let visibleCount = 0;

            cards.forEach(card => {
                const matchesCategory = (activeCategory === 'all') || (card.dataset.category === activeCategory);
                const matchesSearch = !searchQuery || (card.dataset.search.indexOf(searchQuery) !== -1);

                if (matchesCategory && matchesSearch) {
                    if (card.style.display === 'none') {
                        card.style.display = 'flex';
                        if (hasGSAP) {
                            gsap.fromTo(card, { opacity: 0, scale: 0.96 }, { opacity: 1, scale: 1, duration: 0.25, ease: 'power2.out' });
                        }
                    }
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            if (visibleCount === 0) {
                noResults.classList.add('show');
            } else {
                noResults.classList.remove('show');
            }
        }

        // Card Selection Handler
        document.querySelectorAll('.goal-radio').forEach(radio => {
            radio.addEventListener('change', function () {
                cards.forEach(c => c.classList.remove('selected'));
                const selectedCard = this.closest('.goal-card');
                selectedCard.classList.add('selected');

                // Animate check indicator
                if (hasGSAP) {
                    const indicator = selectedCard.querySelector('.goal-check-indicator');
                    gsap.fromTo(indicator, { scale: 0.6 }, { scale: 1, duration: 0.35, ease: 'back.out(2.5)' });
                }

                // Update status display
                selectedStatus.innerHTML = `Selected: <strong>${this.value}</strong>`;

                // Activate submit button
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.style.cursor = 'pointer';

                if (hasGSAP && !submitBtn.dataset.activated) {
                    submitBtn.dataset.activated = 'true';
                    gsap.fromTo(submitBtn, { scale: 0.96 }, { scale: 1, duration: 0.4, ease: 'back.out(2)' });
                }
            });
        });
    })();
    </script>
    <?php
}