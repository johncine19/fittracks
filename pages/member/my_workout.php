<?php
declare(strict_types=1);

function my_workout_page(): void
{
    $user = require_roles(['member']);
    $pdo = db();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $hasTrainerPlan = scalar('SELECT 1 FROM training_plans WHERE member_user_id = ? AND trainer_id IS NOT NULL AND status = "active" LIMIT 1', [$user['user_id']]);
        if ($hasTrainerPlan) {
            flash('Your trainer has published a workout plan for you. You cannot overwrite it.', 'danger');
        } else {
            generate_workout_plan((int) $user['user_id']);
            notify_user((int) $user['user_id'], 'system', 'Workout Plan Generated', 'Your weekly exercise schedule has been refreshed based on your current profile.');
            flash('Workout plan generated successfully!', 'success');
        }
        redirect('my_workout');
    }
    
    render_header('My Workout Plan', $user);
    
    // Check if they have an active plan
    $stmt = $pdo->prepare('SELECT p.*, tp.user_id as t_user_id, u.first_name as t_first, u.last_name as t_last FROM training_plans p LEFT JOIN trainer_profiles tp ON p.trainer_id = tp.trainer_id LEFT JOIN users u ON u.user_id = tp.user_id WHERE p.member_user_id = ? AND p.status = "active" ORDER BY p.plan_id DESC LIMIT 1');
    $stmt->execute([$user['user_id']]);
    $plan = $stmt->fetch();
    
    $trainerName = $plan['trainer_id'] ? h($plan['t_first'] . ' ' . $plan['t_last']) : 'Auto-generated';
    $hasTrainerPlan = (bool)($plan && $plan['trainer_id']);
    ?>
    <div>
        <!-- Glassmorphic Banner -->
        <div class="animate-fade-in" style="background: linear-gradient(135deg, color-mix(in srgb, var(--lime) 10%, transparent) 0%, color-mix(in srgb, var(--lime) 5%, transparent) 100%); border: 1px solid color-mix(in srgb, var(--lime) 20%, transparent); border-radius: 16px; padding: 28px 32px; margin-bottom: 24px; box-shadow: 0 4px 24px rgba(0,0,0,0.1); backdrop-filter: blur(16px); display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1 style="margin: 0; font-size: 26px; color: var(--ink); display: flex; align-items: center; gap: 12px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
                    My Workout Plan
                </h1>
                <p style="margin: 8px 0 0 0; color: var(--muted); font-size: 15px; max-width: 600px;">
                    <?php if ($plan): ?>
                        Goal: <?= h(ucwords(str_replace('_', ' ', $plan['goal']))) ?> | Assigned by: <?= $trainerName ?>
                    <?php else: ?>
                        You currently don't have an active workout plan. Generate one now!
                    <?php endif; ?>
                </p>
            </div>
            
            <?php if (!$hasTrainerPlan): ?>
            <div style="display:flex; gap:12px;">
                <form method="post" style="margin:0;">
                    <?= csrf_field() ?>
                    <button type="submit" data-confirm="<?= $plan ? 'This will archive your current plan and generate a new one. Continue?' : 'Generate a new AI workout plan?' ?>" data-confirm-btn="<?= $plan ? 'Yes, regenerate' : 'Yes, generate' ?>" style="background: var(--lime); border: none; color: var(--bg); padding: 10px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: opacity 0.2s;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.92-10.26l5.67-5.67"/></svg>
                        <?= $plan ? 'Regenerate Plan' : 'Generate Plan' ?>
                    </button>
                </form>
            </div>
            <?php else: ?>
                <div style="background: rgba(0,0,0,0.2); padding: 8px 16px; border-radius: 8px; border: 1px solid var(--line);">
                    <p style="margin:0; color: var(--muted); font-size: 13px;">Managed by your trainer</p>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($plan): ?>
            <?php
            // Fetch today's exercises for the Live Player
            $todayNum = (int) date('N');
            $stmtEx = $pdo->prepare('SELECT tpe.exercise_id, tpe.sequence_order, tpe.sets, tpe.reps, tpe.rest_seconds, e.name, e.category 
                                     FROM training_plan_exercises tpe 
                                     JOIN exercises e ON e.exercise_id = tpe.exercise_id 
                                     WHERE tpe.plan_id = ? AND tpe.day_of_week = ? 
                                     ORDER BY tpe.sequence_order');
            $stmtEx->execute([(int)$plan['plan_id'], $todayNum]);
            $todaysExercises = $stmtEx->fetchAll(PDO::FETCH_ASSOC);

            // Filter out already completed exercises for today
            $stmtComp = $pdo->prepare('SELECT exercise_id FROM exercise_completions WHERE user_id = ? AND plan_id = ? AND completed_date = ?');
            $stmtComp->execute([$user['user_id'], $plan['plan_id'], date('Y-m-d')]);
            $completedIds = $stmtComp->fetchAll(PDO::FETCH_COLUMN);

            $pendingExercises = array_values(array_filter($todaysExercises, function($ex) use ($completedIds) {
                return !in_array($ex['exercise_id'], $completedIds);
            }));
            ?>

            <?php if (empty($todaysExercises)): ?>
                <div style="background: rgba(255,255,255,0.05); padding: 20px; border-radius: 12px; margin-bottom: 24px; text-align: center; border: 1px dashed var(--line);">
                    <p style="margin: 0; color: var(--muted);">Today is a rest day. No exercises scheduled.</p>
                </div>
            <?php elseif (empty($pendingExercises)): ?>
                <div style="background: rgba(199,255,34,0.1); padding: 20px; border-radius: 12px; margin-bottom: 24px; text-align: center; border: 1px solid rgba(199,255,34,0.3);">
                    <h3 style="margin: 0 0 10px; color: var(--lime);">🎉 Workout Complete!</h3>
                    <p style="margin: 0; color: var(--muted);">You have finished all your exercises for today.</p>
                </div>
            <?php else: ?>
                <div style="margin-bottom: 24px; display: flex; justify-content: center;">
                    <button onclick="startLiveWorkout()" style="background: var(--accent, #7c5cfc); color: #fff; border: none; padding: 16px 32px; border-radius: 30px; font-size: 18px; font-weight: 800; display: flex; align-items: center; gap: 12px; cursor: pointer; box-shadow: 0 4px 20px rgba(124,92,252,0.4); transition: transform 0.2s;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                        Start Live Workout
                    </button>
                </div>
                
                <!-- Live Player Modal -->
                <div id="workout-player-modal" class="workout-player-modal" style="display: none;">
                    <div class="player-container">
                        <button class="player-close" onclick="closeLiveWorkout()">&times;</button>
                        
                        <div id="player-progress" class="player-progress-bar">
                            <div class="progress-fill" style="width: 0%;"></div>
                        </div>

                        <div class="player-content">
                            <div id="player-header">
                                <span id="player-exercise-count" class="player-pill">Exercise 1 of X</span>
                                <h2 id="player-exercise-name">Exercise Name</h2>
                                <p id="player-exercise-target" style="color: var(--muted); font-size: 18px; margin-top: 8px;">3 Sets &times; 10 Reps</p>
                            </div>

                            <div id="player-timer-screen" style="display: none; text-align: center; margin-top: 40px;">
                                <h3 style="color: var(--lime); margin-bottom: 10px;">Rest</h3>
                                <div class="timer-ring">
                                    <span id="player-timer-text">60</span>
                                </div>
                                <button onclick="skipRest()" class="player-btn-secondary" style="margin-top: 20px;">Skip Rest</button>
                            </div>

                            <div id="player-controls" style="margin-top: 60px; text-align: center;">
                                <button id="btn-complete-set" onclick="completeSet()" class="player-btn-primary">Complete Set 1</button>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                    const planId = <?= $plan['plan_id'] ?>;
                    const exercises = <?= json_encode($pendingExercises) ?>;
                    let currentExIndex = 0;
                    let currentSet = 1;
                    let restTimer = null;

                    function startLiveWorkout() {
                        document.getElementById('workout-player-modal').style.display = 'flex';
                        currentExIndex = 0;
                        currentSet = 1;
                        renderExercise();
                    }

                    function closeLiveWorkout() {
                        if(confirm('Are you sure you want to exit your live workout? Progress is saved per exercise.')) {
                            document.getElementById('workout-player-modal').style.display = 'none';
                            clearInterval(restTimer);
                            window.location.reload();
                        }
                    }

                    function renderExercise() {
                        if (currentExIndex >= exercises.length) {
                            finishWorkout();
                            return;
                        }
                        
                        document.getElementById('player-timer-screen').style.display = 'none';
                        document.getElementById('player-controls').style.display = 'block';

                        const ex = exercises[currentExIndex];
                        const totalEx = exercises.length;
                        
                        document.getElementById('player-exercise-count').innerText = `Exercise ${currentExIndex + 1} of ${totalEx}`;
                        document.getElementById('player-exercise-name').innerText = ex.name;
                        document.getElementById('player-exercise-target').innerHTML = `${ex.sets} Sets &times; ${ex.reps} Reps <br><span style="font-size:14px; opacity:0.7;">Rest: ${ex.rest_seconds}s</span>`;
                        
                        document.getElementById('btn-complete-set').innerText = `Complete Set ${currentSet} of ${ex.sets}`;
                        
                        const progress = ((currentExIndex) / totalEx) * 100;
                        document.querySelector('.progress-fill').style.width = progress + '%';
                    }

                    function completeSet() {
                        const ex = exercises[currentExIndex];
                        
                        if (currentSet < ex.sets) {
                            currentSet++;
                            startRest(ex.rest_seconds);
                        } else {
                            // Completed all sets for this exercise, save to DB
                            const btn = document.getElementById('btn-complete-set');
                            btn.innerText = 'Saving...';
                            btn.disabled = true;

                            fetch('index.php?page=complete_exercise', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: `plan_id=${planId}&exercise_id=${ex.exercise_id}`
                            }).then(() => {
                                btn.disabled = false;
                                currentExIndex++;
                                currentSet = 1;
                                
                                if (currentExIndex < exercises.length) {
                                    startRest(ex.rest_seconds);
                                } else {
                                    renderExercise(); // triggers finishWorkout()
                                }
                            });
                        }
                    }

                    function startRest(seconds) {
                        document.getElementById('player-controls').style.display = 'none';
                        const timerScreen = document.getElementById('player-timer-screen');
                        timerScreen.style.display = 'block';
                        
                        let remaining = seconds;
                        document.getElementById('player-timer-text').innerText = remaining;
                        
                        clearInterval(restTimer);
                        restTimer = setInterval(() => {
                            remaining--;
                            document.getElementById('player-timer-text').innerText = remaining;
                            if (remaining <= 0) {
                                skipRest();
                            }
                        }, 1000);
                    }

                    function skipRest() {
                        clearInterval(restTimer);
                        renderExercise();
                    }

                    function finishWorkout() {
                        document.querySelector('.progress-fill').style.width = '100%';
                        document.getElementById('player-header').innerHTML = `<h2 style="color:var(--lime); font-size:36px; margin-top:40px;">🎉 Workout Complete!</h2><p style="color:var(--muted); font-size:18px;">Incredible job today!</p>`;
                        document.getElementById('player-timer-screen').style.display = 'none';
                        document.getElementById('player-controls').innerHTML = `<button class="player-btn-primary" onclick="window.location.reload()">Finish</button>`;
                    }
                </script>
            <?php endif; ?>

            <?php render_current_workout((int) $user['user_id'], false); ?>
            <?php render_exercise_recommendations((int) $user['user_id'], false); ?>
        <?php else: ?>
             <div class="panel" style="text-align: center; padding: 50px 20px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--line)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 16px;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                <h2 style="color: var(--muted); margin-bottom: 10px;">No Active Workout Plan</h2>
                <p>Generate an AI workout plan above to get started!</p>
             </div>
        <?php endif; ?>
    </div>
    <?php
    render_footer();
}
