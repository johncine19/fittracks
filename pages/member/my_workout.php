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
