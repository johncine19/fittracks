<?php
declare(strict_types=1);

/**
 * CSRF protection.
 *
 * - csrf_token()  returns (and lazily creates) the token for this session.
 * - csrf_field()  echoes a hidden <input> to embed in every <form method="post">.
 * - verify_csrf() is called once, globally, for every POST request before
 *   routing (see index.php). It aborts the request with 403 if the token is
 *   missing or does not match.
 */
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

    $token = $_POST['csrf_token'] ?? '';
    $valid = is_string($token) && $token !== '' && hash_equals($_SESSION['csrf_token'] ?? '', $token);

    if (!$valid) {
        http_response_code(403);
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
