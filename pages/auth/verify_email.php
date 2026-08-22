<?php
declare(strict_types=1);

function verify_email_page(): void
{
    if (!defined('AUTH_PAGE')) define('AUTH_PAGE', true);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'resend') {
        // Support resend both from an authenticated session AND from the
        // login-page "pending verify" flow (user not yet logged in).
        $userId = null;

        if (!empty($_SESSION['user_id'])) {
            $user = current_user();
            if ($user) {
                $userId = (int) $user['user_id'];
                if ($user['email_verified_at']) {
                    flash('Your email is already verified.', 'info');
                    redirect('profile');
                }
            }
        } elseif (!empty($_SESSION['pending_verify_uid'])) {
            $userId = (int) $_SESSION['pending_verify_uid'];
            // Fetch bare user row (no session login required)
            $row = query_all('SELECT * FROM users WHERE user_id = ? AND status = "active"', [$userId]);
            if (!$row) {
                unset($_SESSION['pending_verify_uid']);
                flash('Session expired. Please try signing in again.', 'danger');
                redirect('login');
            }
            $user = $row[0];
            if ($user['email_verified_at']) {
                unset($_SESSION['pending_verify_uid']);
                flash('Your email is already verified. You can now sign in.', 'info');
                redirect('login');
            }
        }

        if (!$userId) {
            flash('Please sign in to resend the verification email.', 'danger');
            redirect('login');
        }

        $rateLimitKey = 'resend_verify:' . $userId;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3, 600)) {
            flash('Please wait a few minutes before requesting another verification email.', 'danger');
            // Redirect appropriately based on context
            redirect(empty($_SESSION['user_id']) ? 'login' : 'profile');
        }
        RateLimiter::hit($rateLimitKey, 600);

        $token = create_email_verification_token($userId);
        $sent  = send_verification_email($user['email'], $user['first_name'], $token);

        flash($sent
            ? 'Verification email sent. Please check your inbox.'
            : 'Could not send the verification email right now. Please try again later.',
            $sent ? 'success' : 'danger');

        redirect(empty($_SESSION['user_id']) ? 'login' : 'profile');
    }

    $token = (string) ($_GET['token'] ?? '');
    $verifiedUserId = $token !== '' ? verify_email_token($token) : null;

    if ($verifiedUserId !== null) {
        unset($_SESSION['pending_verify_uid']);

        // Auto-login the user immediately upon email verification
        $userRow = query_all('SELECT * FROM users WHERE user_id = ? AND status = "active"', [$verifiedUserId]);
        if ($userRow) {
            $verifiedUser = $userRow[0];
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $verifiedUser['user_id'];

            if ($verifiedUser['role'] === 'gym_owner') {
                flash('Email verified successfully! Please complete your gym profile to submit your application.', 'success');
                redirect('gym_onboarding');
            } elseif ($verifiedUser['role'] === 'member') {
                flash('Email verified successfully! Welcome to FitTrack.', 'success');
                redirect('setup_profile');
            } else {
                flash('Email verified successfully! Welcome to FitTrack.', 'success');
                redirect('dashboard');
            }
        }
    }

    render_header('Verify email');
    ?>
    <section class="panel" style="max-width:520px;margin:40px auto;text-align:center;">
        <h1>Verification link invalid or expired</h1>
        <p>This link is no longer valid or has already been used. You can request a new one from the sign-in page.</p>
        <p><a href="index.php?page=login" class="btn">Go to Sign In</a></p>
    </section>
    <?php
    render_footer();
}
