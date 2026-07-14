<?php
declare(strict_types=1);

function pending_gym_page(): void
{
    define('AUTH_PAGE', true);
    $user = current_user();
    if (!$user) {
        redirect('login');
    }

    if ($user['role'] !== 'gym_owner') {
        redirect('dashboard');
    }

    $gym = db()->query('SELECT status FROM gyms WHERE owner_user_id = ' . (int) $user['user_id'])->fetch();
    if ($gym && $gym['status'] === 'approved') {
        redirect('dashboard');
    }

    if ($gym && $gym['status'] === 'rejected') {
        $title = "Application Rejected";
        $message = "Your gym application was rejected. Please contact the platform admin for more information.";
    } else {
        $title = "Application Pending";
        $message = "Your gym application has been submitted and is currently pending review by the platform administrator. You will be notified once it is approved.";
    }

    render_header('Pending Gym Application', null);
    ?>
    <section style="padding: 40px 0; min-height: 80vh; display:flex; align-items:center; justify-content:center;">
        <div class="auth-card" style="max-width:500px; width:100%; text-align: center;">
            <div class="auth-card-header">
                <h1 class="auth-title" style="font-size:1.6rem;"><?= h($title) ?></h1>
            </div>
            <div style="padding: 20px;">
                <p style="color: var(--muted); font-size: 1.1rem; line-height: 1.6; margin-bottom: 30px;">
                    <?= h($message) ?>
                </p>
                <a href="index.php?page=logout" class="btn" style="display: inline-block; background: var(--panel-soft); color: var(--ink); text-decoration: none;">Log Out</a>
            </div>
            <div class="corner corner-tl"></div>
            <div class="corner corner-tr"></div>
            <div class="corner corner-bl"></div>
            <div class="corner corner-br"></div>
        </div>
    </section>
    <?php
    render_footer();
}
