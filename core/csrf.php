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

    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_XSRF_TOKEN'] ?? '';
    $valid = is_string($token) && $token !== '' && hash_equals($_SESSION['csrf_token'] ?? '', $token);

    if (!$valid) {
        http_response_code(403);
        $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
            || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'))
            || (!empty($_GET['page']) && str_ends_with((string)$_GET['page'], '_api'));

        if ($isAjax) {
            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Security check failed or session expired. Please refresh the page and try again.']);
            exit;
        }
        if (function_exists('render_header')) {
            render_header('Security check failed');
            echo '<section class="panel"><h1>Security check failed</h1><p>Your session expired or the form was submitted from an untrusted source. Please go back, refresh the page, and try again.</p></section>';
            if (function_exists('render_footer')) {
                render_footer();
            }
        } else {
            echo 'Security check failed. Please refresh and try again.';
        }
        exit;
    }
}
