<?php
declare(strict_types=1);

function my_diet_page(): void
{
    $user = require_roles(['member']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        generate_diet_plan((int) $user['user_id']);
        flash('Nutrition plan recalculated.');
        redirect('my_diet');
    }
    render_header('Nutrition', $user);
    echo '<form method="post" class="toolbar">' . csrf_field() . '<button>Recalculate targets</button></form>';
    render_current_diet((int) $user['user_id']);
    render_footer();
}
