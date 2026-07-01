<?php
declare(strict_types=1);

function my_workout_page(): void
{
    $user = require_roles(['member']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        generate_workout_plan((int) $user['user_id']);
        notify_user((int) $user['user_id'], 'system', 'Workout plan recalculated', 'Your weekly exercise schedule has been refreshed based on your current profile.');
        flash('Workout plan recalculated.', 'success');
        redirect('my_workout');
    }
    render_header('Workouts', $user);
    echo '<form method="post" class="toolbar">' . csrf_field() . '<button>Recalculate workout plan</button></form>';
    render_current_workout((int) $user['user_id'], false);
    render_exercise_recommendations((int) $user['user_id'], false);
    render_footer();
}
