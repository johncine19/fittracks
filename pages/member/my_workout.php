<?php
declare(strict_types=1);

function my_workout_page(): void
{
    $user = require_roles(['member']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can_recalculate_workout((int) $user['user_id'])) {
            flash('Your workout plan was recalculated recently. Please wait 7 days.', 'danger');
        } else {
            generate_workout_plan((int) $user['user_id']);
            notify_user((int) $user['user_id'], 'system', 'Workout plan recalculated', 'Your weekly exercise schedule has been refreshed based on your current profile.');
            flash('Workout plan recalculated.', 'success');
        }
        redirect('my_workout');
    }
    render_header('Workouts', $user);
    if (can_recalculate_workout((int) $user['user_id'])) {
        echo '<form method="post" class="toolbar">' . csrf_field() . '<button>Recalculate workout plan</button></form>';
    } else {
        echo '<div class="toolbar"><p style="color: var(--muted); font-size: 14px; margin: 0; padding: 8px 0;">Workout plan was recently generated. Check back after 7 days to recalculate.</p></div>';
    }
    render_current_workout((int) $user['user_id'], false);
    render_exercise_recommendations((int) $user['user_id'], false);
    render_footer();
}
