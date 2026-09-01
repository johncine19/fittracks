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

        // Also generate their actual workout plan using the basic goal mapping logic which we will add to workouts.php
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

    $categoryIcons = [
        'Aesthetic & Muscle Building Goals' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>',
        'Athletic & Performance Goals' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>',
        'Body Composition Goals' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg>',
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
            width: 340px;
            height: 340px;
            border-radius: 50%;
            filter: blur(90px);
            opacity: 0.14;
        }
        .goal-blob--a { background: var(--lime); top: -120px; left: -100px; }
        .goal-blob--b { background: var(--lime); bottom: -140px; right: -110px; }

        .goal-card-wrap {
            position: relative;
            z-index: 1;
        }

        .goal-progress-track {
            width: 100%;
            height: 4px;
            background: rgba(255,255,255,0.08);
            border-radius: 999px;
            margin: 4px 0 22px;
            overflow: hidden;
        }
        .goal-progress-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, var(--lime), rgba(199,255,34,0.5));
            border-radius: 999px;
        }

        .goal-search-wrap {
            position: relative;
            margin-bottom: 20px;
        }
        .goal-search-wrap svg {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            color: var(--muted);
            pointer-events: none;
        }
        #goal-search {
            width: 100%;
            background: var(--surface);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 8px;
            padding: 11px 14px 11px 40px;
            color: var(--ink);
            font-size: 0.92rem;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        #goal-search:focus {
            border-color: rgba(199, 255, 34, 0.5);
            box-shadow: 0 0 0 3px rgba(199, 255, 34, 0.08);
        }
        #goal-search::placeholder { color: var(--muted); }

        .goal-list-scroll {
            max-height: 440px;
            overflow-y: auto;
            padding-right: 10px;
            margin-bottom: 4px;
            scrollbar-width: thin;
            scrollbar-color: rgba(199,255,34,0.35) transparent;
        }
        .goal-list-scroll::-webkit-scrollbar { width: 6px; }
        .goal-list-scroll::-webkit-scrollbar-track { background: transparent; }
        .goal-list-scroll::-webkit-scrollbar-thumb {
            background: rgba(199,255,34,0.3);
            border-radius: 999px;
        }
        .goal-list-scroll::-webkit-scrollbar-thumb:hover {
            background: rgba(199,255,34,0.5);
        }

        .goal-group { margin-bottom: 4px; }
        .goal-group.is-hidden { display: none; }

        .goal-group-title {
            color: var(--lime);
            font-size: 1.05rem;
            font-weight: 600;
            margin: 22px 0 10px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .goal-group-title .count {
            margin-left: auto;
            font-weight: 400;
            font-size: 0.78rem;
            color: var(--muted);
        }

        .goal-card {
            position: relative;
            background: var(--surface);
            border: 2px solid transparent;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 10px;
            cursor: pointer;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            will-change: transform;
        }
        .goal-card.is-filtered-out { display: none; }

        .goal-card input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            pointer-events: none;
        }

        .goal-card.selected {
            border-color: var(--lime);
            background: rgba(199, 255, 34, 0.06);
        }

        .radio-btn {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.3);
            display: inline-block;
            flex-shrink: 0;
            position: relative;
            margin-top: 2px;
            transition: border-color 0.2s;
        }
        .goal-card.selected .radio-btn { border-color: var(--lime); }
        .goal-card.selected .radio-btn::after {
            content: '';
            position: absolute;
            top: 4px;
            left: 4px;
            width: 8px;
            height: 8px;
            background: var(--lime);
            border-radius: 50%;
        }

        .goal-text { min-width: 0; }
        .goal-title {
            font-weight: 600;
            font-size: 1.02rem;
            color: var(--ink);
            margin-bottom: 4px;
        }
        .goal-desc {
            font-size: 0.87rem;
            color: var(--muted);
            line-height: 1.45;
        }

        .goal-no-results {
            display: none;
            text-align: center;
            color: var(--muted);
            font-size: 0.9rem;
            padding: 30px 10px;
        }
        .goal-no-results.show { display: block; }

        .goal-footer {
            position: relative;
            z-index: 1;
            padding-top: 16px;
            border-top: 1px solid rgba(255,255,255,0.08);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .selected-chip {
            font-size: 0.85rem;
            color: var(--muted);
            min-height: 1.2em;
        }
        .selected-chip strong { color: var(--lime); font-weight: 600; }

        #submit-btn.active {
            box-shadow: 0 0 0 0 rgba(199,255,34,0.4);
        }

        @media (max-width: 560px) {
            .goal-list-scroll { max-height: 380px; }
        }
    </style>

    <section style="padding: 40px 0; min-height: 80vh; display:flex; align-items:center; justify-content:center;">
        <div class="auth-card goal-card-wrap" style="max-width:800px; width:100%; position:relative; overflow:hidden;">
            <div class="goal-bg-decor" aria-hidden="true">
                <div class="goal-blob goal-blob--a"></div>
                <div class="goal-blob goal-blob--b"></div>
            </div>

            <div class="auth-card-header" style="position:relative; z-index:1;">
                <div style="display:flex; justify-content:center; gap: 8px; margin-bottom: 20px;">
                    <div style="width:30px; height:6px; border-radius:3px; background:var(--lime); opacity:0.3;"></div>
                    <div style="width:30px; height:6px; border-radius:3px; background:var(--lime); opacity:0.3;"></div>
                    <div style="width:30px; height:6px; border-radius:3px; background:var(--lime);"></div>
                </div>
                <h1 class="auth-title" style="font-size:1.8rem;">Select Your Primary Goal</h1>
                <p class="auth-subtitle">Choose the specific outcome you want to achieve. This helps us generate the perfect plan for you.</p>
            </div>

            <div class="goal-progress-track" style="position:relative; z-index:1;">
                <div class="goal-progress-fill" id="goal-progress-fill"></div>
            </div>

            <form method="post" action="index.php?page=setup_goal" id="goal-form" style="position:relative; z-index:1;">
                <?= csrf_field() ?>

                <div class="goal-search-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="7"></circle>
                        <path d="M21 21l-3.8-3.8"></path>
                    </svg>
                    <input type="text" id="goal-search" placeholder="Search goals, e.g. 'six-pack' or 'endurance'..." autocomplete="off">
                </div>

                <div class="goal-list-scroll" id="goal-list">
                    <?php $gi = 0; foreach ($goals as $category => $items): $gi++; ?>
                        <div class="goal-group" data-group-index="<?= $gi ?>">
                            <div class="goal-group-title">
                                <span><?= $categoryIcons[$category] ?? '🎯' ?></span>
                                <span><?= h($category) ?></span>
                                <span class="count"><?= count($items) ?> goals</span>
                            </div>
                            <?php foreach ($items as $title => $desc): ?>
                                <label class="goal-card" data-search="<?= h(mb_strtolower($title . ' ' . $desc)) ?>">
                                    <input type="radio" name="primary_goal" value="<?= h($title) ?>" class="goal-radio" required>
                                    <span class="radio-btn" aria-hidden="true"></span>
                                    <span class="goal-text">
                                        <span class="goal-title"><?= h($title) ?></span>
                                        <span class="goal-desc"><?= h($desc) ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="goal-no-results" id="goal-no-results">
                        No goals match your search. Try a different keyword.
                    </div>
                </div>

                <div class="goal-footer">
                    <div class="selected-chip" id="selected-chip"></div>
                    <button type="submit" class="auth-submit-btn full-width" id="submit-btn" disabled style="opacity: 0.5; cursor: not-allowed;">
                        FINISH SETUP & CONTINUE
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg>
                    </button>
                </div>
            </form>
        </div>
    </section>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script>
    (function () {
        var TOTAL_GOALS = <?= (int) $totalGoals ?>;
        var card = document.querySelector('.auth-card');
        var header = document.querySelector('.auth-card-header');
        var progressTrack = document.querySelector('.goal-progress-track');
        var searchWrap = document.querySelector('.goal-search-wrap');
        var groups = Array.prototype.slice.call(document.querySelectorAll('.goal-group'));
        var cards = Array.prototype.slice.call(document.querySelectorAll('.goal-card'));
        var footer = document.querySelector('.goal-footer');
        var submitBtn = document.getElementById('submit-btn');
        var searchInput = document.getElementById('goal-search');
        var selectedChip = document.getElementById('selected-chip');
        var noResults = document.getElementById('goal-no-results');
        var progressFill = document.getElementById('goal-progress-fill');
        var blobs = document.querySelectorAll('.goal-blob');

        var hasGSAP = typeof gsap !== 'undefined';

        // ---- Entrance animation ----
        if (hasGSAP) {
            gsap.set(card, { opacity: 0, y: 24 });
            gsap.set([header, progressTrack, searchWrap], { opacity: 0, y: 14 });
            gsap.set(groups, { opacity: 0, y: 18 });
            gsap.set(footer, { opacity: 0, y: 10 });

            var tl = gsap.timeline({ defaults: { ease: 'power3.out' } });
            tl.to(card, { opacity: 1, y: 0, duration: 0.5 })
              .to(header, { opacity: 1, y: 0, duration: 0.4 }, '-=0.25')
              .to(progressTrack, { opacity: 1, y: 0, duration: 0.3 }, '-=0.2')
              .to(searchWrap, { opacity: 1, y: 0, duration: 0.3 }, '-=0.15')
              .to(groups, { opacity: 1, y: 0, duration: 0.35, stagger: 0.08 }, '-=0.1')
              .to(footer, { opacity: 1, y: 0, duration: 0.35 }, '-=0.15');

            // Ambient background blobs
            blobs.forEach(function (blob, i) {
                gsap.to(blob, {
                    x: i === 0 ? 30 : -30,
                    y: i === 0 ? 20 : -20,
                    duration: 8 + i * 2,
                    repeat: -1,
                    yoyo: true,
                    ease: 'sine.inOut'
                });
            });

            // Subtle hover lift on cards
            cards.forEach(function (c) {
                c.addEventListener('mouseenter', function () {
                    gsap.to(c, { y: -3, boxShadow: '0 8px 20px rgba(0,0,0,0.25)', duration: 0.25, ease: 'power2.out' });
                });
                c.addEventListener('mouseleave', function () {
                    gsap.to(c, { y: 0, boxShadow: '0 0px 0px rgba(0,0,0,0)', duration: 0.3, ease: 'power2.out' });
                });
            });
        }

        function countVisibleSelectable() {
            return cards.filter(function (c) { return !c.classList.contains('is-filtered-out'); }).length;
        }

        function updateProgress() {
            var checked = document.querySelector('.goal-radio:checked');
            var pct = checked ? 100 : 0;
            if (hasGSAP) {
                gsap.to(progressFill, { width: pct + '%', duration: 0.5, ease: 'power2.out' });
            } else {
                progressFill.style.width = pct + '%';
            }
        }

        // ---- Selection behavior ----
        document.querySelectorAll('.goal-radio').forEach(function (radio) {
            radio.addEventListener('change', function () {
                cards.forEach(function (c) { c.classList.remove('selected'); });
                var selectedCard = radio.closest('.goal-card');
                selectedCard.classList.add('selected');

                if (hasGSAP) {
                    gsap.fromTo(selectedCard.querySelector('.radio-btn'),
                        { scale: 0.5 },
                        { scale: 1, duration: 0.4, ease: 'back.out(3)' }
                    );
                    gsap.fromTo(selectedCard,
                        { scale: 0.985 },
                        { scale: 1, duration: 0.3, ease: 'power2.out' }
                    );
                }

                selectedChip.innerHTML = 'Selected: <strong>' + radio.value + '</strong>';
                if (hasGSAP) {
                    gsap.fromTo(selectedChip, { opacity: 0, y: 4 }, { opacity: 1, y: 0, duration: 0.25 });
                }

                updateProgress();

                if (submitBtn.disabled) {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                    submitBtn.style.cursor = 'pointer';
                    submitBtn.classList.add('active');
                    if (hasGSAP) {
                        gsap.fromTo(submitBtn,
                            { scale: 0.96 },
                            { scale: 1, duration: 0.5, ease: 'elastic.out(1, 0.55)' }
                        );
                        gsap.fromTo(submitBtn,
                            { boxShadow: '0 0 0 0 rgba(199,255,34,0.45)' },
                            { boxShadow: '0 0 0 10px rgba(199,255,34,0)', duration: 0.7, ease: 'power2.out' }
                        );
                    }
                }
            });
        });

        // ---- Search / filter ----
        if (searchInput) {
            var debounceTimer;
            searchInput.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                var q = this.value.trim().toLowerCase();
                debounceTimer = setTimeout(function () { applyFilter(q); }, 80);
            });
        }

        function applyFilter(query) {
            var anyVisibleAtAll = false;

            groups.forEach(function (group) {
                var groupCards = Array.prototype.slice.call(group.querySelectorAll('.goal-card'));
                var anyVisibleInGroup = false;

                groupCards.forEach(function (c) {
                    var matches = !query || c.dataset.search.indexOf(query) !== -1;
                    if (matches) {
                        anyVisibleInGroup = true;
                        anyVisibleAtAll = true;
                        if (c.classList.contains('is-filtered-out')) {
                            c.classList.remove('is-filtered-out');
                            if (hasGSAP) {
                                gsap.fromTo(c, { opacity: 0, y: -6 }, { opacity: 1, y: 0, duration: 0.25, ease: 'power2.out' });
                            }
                        }
                    } else {
                        c.classList.add('is-filtered-out');
                    }
                });

                group.classList.toggle('is-hidden', !anyVisibleInGroup);
            });

            noResults.classList.toggle('show', !anyVisibleAtAll);
        }

        updateProgress();
    })();
    </script>
    <?php
}