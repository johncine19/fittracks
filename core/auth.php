<?php
declare(strict_types=1);

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE user_id = ? AND status = "active"');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        $requestedPage = (string) ($_GET['page'] ?? '');
        if ($requestedPage !== '' && !in_array($requestedPage, ['login', 'logout', 'register', 'verify_email', 'reset_password'], true)) {
            $_SESSION['return_to'] = $requestedPage;
        }
        redirect('login');
    }

    $page = $_GET['page'] ?? 'dashboard';
    
    // Auto-expire member subscriptions on-the-fly if lapsed
    if ($user['role'] === 'member') {
        if (function_exists('auto_expire_lapsed_member_subscriptions')) {
            auto_expire_lapsed_member_subscriptions((int) $user['user_id']);
        }
    }

    // Globally enforce physical profile + goal setup for members
    if ($user['role'] === 'member' && !in_array($page, ['setup_profile', 'setup_goal', 'logout'], true)) {
        $memberProfile = member_profile((int) $user['user_id']);
        if ($memberProfile === null) {
            redirect('setup_profile');
        } elseif (empty($memberProfile['primary_goal'])) {
            redirect('setup_goal');
        }
    }

    // Handle Gym Owner onboarding & subscription interception
    if ($user['role'] === 'gym_owner' && !in_array($page, ['gym_onboarding', 'gym_pending', 'gym_rejected', 'gym_subscription', 'logout'], true)) {
        if (function_exists('auto_expire_lapsed_gym_subscriptions')) {
            $ownerGymId = (int) scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [(int)$user['user_id']]);
            if ($ownerGymId > 0) {
                auto_expire_lapsed_gym_subscriptions($ownerGymId);
            }
        }
        $gym = db()->query('SELECT gym_id, status, subscription_plan, subscription_status, subscription_renewal_date FROM gyms WHERE owner_user_id = ' . (int)$user['user_id'])->fetch();
        
        if (!$gym) {
            redirect('gym_onboarding');
        } elseif ($gym['status'] === 'pending') {
            redirect('gym_pending');
        } elseif ($gym['status'] === 'rejected') {
            redirect('gym_rejected');
        } elseif ($gym['status'] === 'suspended') {
            flash('Your gym account has been suspended by platform administration. Please contact support.', 'danger');
            redirect('logout');
        }
        // Approved gym owners have immediate access to dashboard (Free Trial or Limited Free Account)
    }

    return $user;
}

function can(array $user, array $roles): bool
{
    return in_array($user['role'], $roles, true);
}

function require_roles(array $roles): array
{
    $user = require_login();
    if (!can($user, $roles)) {
        http_response_code(403);
        render_header('Access denied', $user);
        echo '<section class="panel"><h1>Access denied</h1><p>Your account does not have permission to open this page.</p></section>';
        render_footer();
        exit;
    }
    return $user;
}

function handle_logout(): never
{
    $silent = isset($_GET['silent']) || (isset($_SERVER['HTTP_REFERER']) && str_contains($_SERVER['HTTP_REFERER'], 'gym_rejected'));
    session_destroy();
    session_start();
    if (!$silent) {
        flash('You have successfully logged out.', 'success');
    }
    redirect('login');
}


