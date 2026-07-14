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
        redirect('login');
    }

    $page = $_GET['page'] ?? 'dashboard';
    
    // Globally enforce physical profile setup for members
    if ($user['role'] === 'member' && $page !== 'setup_profile' && $page !== 'logout') {
        if (member_profile((int) $user['user_id']) === null) {
            redirect('setup_profile');
        }
    }

    // Globally enforce gym approval for gym owners
    if ($user['role'] === 'gym_owner' && $page !== 'pending_gym' && $page !== 'logout') {
        $gymStatus = scalar('SELECT status FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
        if ($gymStatus !== 'approved') {
            redirect('pending_gym');
        }
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
    session_destroy();
    session_start();
    flash('You have successfully logged out.', 'success');
    redirect('login');
}

