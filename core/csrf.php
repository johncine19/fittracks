<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    if (in_array($_GET['page'] ?? '', ['google_auth', 'google_gym_auth'], true)) {
        return;
    }

    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_XSRF_TOKEN'] ?? '';
    $valid = is_string($token) && $token !== '' && hash_equals($_SESSION['csrf_token'] ?? '', $token);

    if (!$valid) {
        http_response_code(403);
        $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
            || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'))
            || (!empty($_GET['page']) && (str_ends_with((string)$_GET['page'], '_api') || in_array($_GET['page'], ['food_lookup', 'equipment_api', 'notification_action'])));

        if ($isAjax) {
            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Security check failed or session expired. Please refresh the page and try again.']);
            exit;
        }
        $currentPage = $_GET['page'] ?? '';
        if ($currentPage === 'login' || isset($_POST['email'])) {
            if (function_exists('flash')) {
                flash('Your session expired due to inactivity. Please try signing in again.', 'warning');
            }
            header('Location: index.php?page=login');
            exit;
        }

        if (function_exists('render_header')) {
            render_header('Security check failed');
            echo '<section class="panel" style="max-width: 520px; margin: 40px auto; padding: 24px; text-align: center; border-radius: 12px; background: rgba(30, 41, 59, 0.7); border: 1px solid rgba(255,255,255,0.1);">';
            echo '<h1 style="color: #f87171; margin-bottom: 12px; font-size: 20px;">Security Check Failed</h1>';
            echo '<p style="color: #94a3b8; line-height: 1.6; margin-bottom: 20px;">Your session expired or the form was submitted from an untrusted source. Please refresh the page and try again.</p>';
            echo '<div style="display: flex; gap: 12px; justify-content: center;">';
            echo '<a href="javascript:history.back()" class="btn btn-secondary" style="padding: 10px 20px; border-radius: 8px; text-decoration: none; color: #fff; background: #334155;">Go Back</a>';
            echo '<a href="index.php" class="btn btn-primary" style="padding: 10px 20px; border-radius: 8px; text-decoration: none; color: #000; background: #84cc16; font-weight: 600;">Return Home</a>';
            echo '</div>';
            echo '</section>';
            if (function_exists('render_footer')) {
                render_footer();
            }
        } else {
            echo 'Security check failed. Please refresh and try again.';
        }
        exit;
    }
}
