<?php
declare(strict_types=1);

function verify_email_page(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'resend') {
        $user = require_login();

        if ($user['email_verified_at']) {
            flash('Your email is already verified.', 'info');
            redirect('profile');
        }

        if (RateLimiter::tooManyAttempts('resend_verify:' . $user['user_id'], 3, 600)) {
            flash('Please wait a few minutes before requesting another verification email.', 'danger');
            redirect('profile');
        }
        RateLimiter::hit('resend_verify:' . $user['user_id'], 600);

        $token = create_email_verification_token((int) $user['user_id']);
        $sent = send_verification_email($user['email'], $user['first_name'], $token);

        flash($sent
            ? 'Verification email sent. Please check your inbox.'
            : 'Could not send the verification email right now. Please try again later.',
            $sent ? 'success' : 'danger');
        redirect('profile');
    }

    $token = (string) ($_GET['token'] ?? '');
    $ok = $token !== '' && verify_email_token($token);

    render_header('Verify email');
    ?>
    <section class="panel" style="max-width:520px;margin:40px auto;text-align:center;">
        <?php if ($ok): ?>
            <h1>Email verified</h1>
            <p>Thanks — your email address has been confirmed.</p>
        <?php else: ?>
            <h1>Verification link invalid or expired</h1>
            <p>This link is no longer valid. You can request a new one from your Settings page.</p>
        <?php endif; ?>
        <p><a href="index.php?page=<?= current_user() ? 'profile' : 'login' ?>">Continue</a></p>
    </section>
    <?php
    render_footer();
}
