<?php
declare(strict_types=1);

function setup_goal_page(): void
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
    if (!$profile) {
        redirect('setup_profile');
    }

    if (!empty($profile['primary_goal'])) {
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

    render_header('Select Your Goal', null);
    ?>
    <style>
        .goal-group-title {
            color: var(--lime);
            font-size: 1.2rem;
            margin: 25px 0 10px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding-bottom: 5px;
        }
        .goal-card {
            background: var(--surface);
            border: 2px solid transparent;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }
        .goal-card:hover {
            border-color: rgba(199, 255, 34, 0.4);
            transform: translateY(-2px);
        }
        .goal-card.selected {
            border-color: var(--lime);
            background: rgba(199, 255, 34, 0.05);
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
        }
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
        .goal-title {
            font-weight: bold;
            font-size: 1.05rem;
            color: var(--ink);
            margin-bottom: 4px;
        }
        .goal-desc {
            font-size: 0.9rem;
            color: var(--muted);
            line-height: 1.4;
        }
    </style>
    <section style="padding: 40px 0; min-height: 80vh; display:flex; align-items:center; justify-content:center;">
        <div class="auth-card" style="max-width:800px; width:100%;">
            <div class="auth-card-header">
                <h1 class="auth-title" style="font-size:1.8rem;">Select Your Primary Goal</h1>
                <p class="auth-subtitle">Choose the specific outcome you want to achieve. This helps us generate the perfect plan for you.</p>
            </div>
            <form method="post" action="index.php?page=setup_goal" id="goal-form">
                <?= csrf_field() ?>
                <input type="hidden" name="primary_goal" id="selected-goal-input" value="">
                
                <div style="max-height: 500px; overflow-y: auto; padding-right: 10px; margin-bottom: 20px;">
                    <?php foreach ($goals as $category => $items): ?>
                        <div class="goal-group-title"><?= h($category) ?></div>
                        <?php foreach ($items as $title => $desc): ?>
                            <div class="goal-card" onclick="selectGoal('<?= h(addslashes($title)) ?>', this)">
                                <div class="radio-btn"></div>
                                <div>
                                    <div class="goal-title"><?= h($title) ?></div>
                                    <div class="goal-desc"><?= h($desc) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
                
                <button type="submit" class="auth-submit-btn full-width" id="submit-btn" disabled style="opacity: 0.5; cursor: not-allowed;">
                    FINISH SETUP & CONTINUE
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg>
                </button>
            </form>
        </div>
    </section>
    
    <script>
    function selectGoal(title, element) {
        document.querySelectorAll('.goal-card').forEach(el => el.classList.remove('selected'));
        element.classList.add('selected');
        document.getElementById('selected-goal-input').value = title;
        
        const btn = document.getElementById('submit-btn');
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
    }
    </script>
    <?php
}
