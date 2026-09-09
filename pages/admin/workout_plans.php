<?php
declare(strict_types=1);

function admin_workouts_page(): void
{
    require_roles(['platform_admin', 'gym_owner', 'trainer']);
    $viewPlanId = (int) ($_GET['view_plan_id'] ?? 0);
    $extra = $viewPlanId ? "&view_plan_id={$viewPlanId}" : "";
    redirect('training&tab=all' . $extra);
}

