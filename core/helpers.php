<?php
declare(strict_types=1);

function app_env(string $key, mixed $default = ''): mixed
{
    $val = '';
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        $val = $_ENV[$key];
    } elseif (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        $val = $_SERVER[$key];
    } else {
        $g = getenv($key);
        if ($g !== false && $g !== '') {
            $val = $g;
        }
    }
    if ($val === '') {
        return $default;
    }
    if (is_string($val)) {
        return trim($val, " \t\n\r\0\x0B\"'");
    }
    return $val;
}

function env(string $key, mixed $default = ''): mixed
{
    return app_env($key, $default);
}

/**
 * Public URL for a file under /assets with a cache-busting version param.
 * Example: asset_url('css/pages/reports.css') => "assets/css/pages/reports.css?v=1728181234"
 */
function asset_url(string $path): string
{
    $path = ltrim($path, '/');
    $file = __DIR__ . '/../assets/' . $path;
    $version = is_file($file) ? filemtime($file) : 1;
    return 'assets/' . $path . '?v=' . $version;
}

function get_setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = db()->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll();
            foreach ($rows as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable) {
        }
    }
    return $cache[$key] ?? $default;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function escapeHtml(mixed $value): string
{
    return h($value);
}

function money(float|string|null $value): string
{
    return '₱' . number_format((float) $value, 2);
}

function flash(?string $message = null, string $type = 'success'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function redirect(string $page = 'dashboard'): never
{
    if (str_starts_with($page, 'index.php') || str_starts_with($page, 'http://') || str_starts_with($page, 'https://')) {
        header('Location: ' . $page);
    } elseif (str_contains($page, '?')) {
        [$p, $query] = explode('?', $page, 2);
        header('Location: index.php?page=' . urlencode($p) . '&' . $query);
    } elseif (str_contains($page, '&')) {
        [$p, $query] = explode('&', $page, 2);
        header('Location: index.php?page=' . urlencode($p) . '&' . $query);
    } else {
        header('Location: index.php?page=' . urlencode($page));
    }
    exit;
}

function post(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function selected(string $a, ?string $b): string
{
    return $a === $b ? 'selected' : '';
}

function checked(bool $value): string
{
    return $value ? 'checked' : '';
}

function metric_cards(array $items): void
{
    echo '<div class="metrics">';
    foreach ($items as $label => $value) {
        echo '<article class="metric"><span>' . h($label) . '</span><strong>' . h((string) $value) . '</strong></article>';
    }
    echo '</div>';
}

function table_empty(int $colspan, string $message): void
{
    echo '<tr><td colspan="' . $colspan . '" class="muted">' . h($message) . '</td></tr>';
}

function nav_icon(string $key): string
{
    $icons = [
        // Grid/home — Dashboard
        'dashboard' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
        // People — Users
        'users' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        // Person — Members
        'members' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        // Link — Trainer assignments
        'trainer_assignments' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        // Star — Trainer members / clients
        'trainer_members' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
        // Tag — Plans
        'plans' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
        // Credit card — Payments
        'payments' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
        // ID card — Memberships
        'memberships' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>',
        // Check circle — Attendance
        'attendance' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        // Calendar — Classes
        'classes' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        // Bookmark — Book classes
        'book_classes' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>',
        // Bar chart — Reports
        'reports' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        // User circle — Profile
        'profile' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        // Dumbbell — Workouts / Exercises
        'my_workout' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v16"/><path d="M10 4v16"/><path d="M6 12h4"/><path d="M14 4v16"/><path d="M18 4v16"/><path d="M14 12h4"/></svg>',
        'exercises' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v16"/><path d="M10 4v16"/><path d="M6 12h4"/><path d="M14 4v16"/><path d="M18 4v16"/><path d="M14 12h4"/></svg>',
        // Bell — Notifications
        'notifications' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
        // Map pin — Gym Selection
        'gym_selection' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>',
        // Activity / pulse — Progress
        'progress' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
        // Dumbbell — Training
        'training' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v16"/><path d="M10 4v16"/><path d="M6 12h4"/><path d="M14 4v16"/><path d="M18 4v16"/><path d="M14 12h4"/></svg>',
        // Message bubble — Messages
        'messages' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        // User group
        'trainers' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'walk_ins' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        // QR Code
        'qr_attendance' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="5" height="5" x="3" y="3" rx="1"/><rect width="5" height="5" x="16" y="3" rx="1"/><rect width="5" height="5" x="3" y="16" rx="1"/><path d="M21 16h-3a2 2 0 0 0-2 2v3"/><path d="M21 21v.01"/><path d="M12 7v3a2 2 0 0 1-2 2H7"/><path d="M3 12h.01"/><path d="M12 3h.01"/><path d="M12 16v.01"/><path d="M16 12h1"/><path d="M21 12v.01"/><path d="M12 21v-1"/></svg>',
        // Commissions (Peso sign)
        'commissions' => '<span style="font-size: 18px; font-weight: bold; line-height: 18px;">₱</span>',
        'my_commissions' => '<span style="font-size: 18px; font-weight: bold; line-height: 18px;">₱</span>',
        // Scanner
        'scanner' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="12" x2="21" y2="12"/></svg>',
        // Audit logs (document icon)
        'audit_logs' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
        'settings' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        'gym_payouts' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        'gym_applications' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>',
        'gyms' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 12 12 17 22 12"/><polyline points="2 17 12 22 22 17"/></svg>',
        'announcements' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>',
        'gym_profile' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><line x1="9" y1="22" x2="9" y2="22"/><line x1="15" y1="22" x2="15" y2="22"/><line x1="9" y1="6" x2="9" y2="6"/><line x1="15" y1="6" x2="15" y2="6"/><line x1="9" y1="10" x2="9" y2="10"/><line x1="15" y1="10" x2="15" y2="10"/><line x1="9" y1="14" x2="9" y2="14"/><line x1="15" y1="14" x2="15" y2="14"/><line x1="9" y1="18" x2="9" y2="18"/><line x1="15" y1="18" x2="15" y2="18"/></svg>',
        'admin_workouts' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><line x1="8" y1="11" x2="16" y2="11"/><line x1="8" y1="15" x2="16" y2="15"/></svg>',
        'diet' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>',
        'food_library' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>',
        'platform_plans' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
        'equipment' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="4" height="12" rx="1"/><rect x="18" y="6" width="4" height="12" rx="1"/><path d="M6 12h12"/><path d="M6 9h1a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H6"/><path d="M18 9h-1a2 2 0 0 0-2 2v2a2 2 0 0 0 2 2h1"/></svg>',
        'gym_equipment' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="4" height="12" rx="1"/><rect x="18" y="6" width="4" height="12" rx="1"/><path d="M6 12h12"/><path d="M6 9h1a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H6"/><path d="M18 9h-1a2 2 0 0 0-2 2v2a2 2 0 0 0 2 2h1"/></svg>',
    ];
    return $icons[$key] ?? '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/></svg>';
}

function get_platform_subscription_plans(): array
{
    $pdo = db();
    try {
        $rows = $pdo->query('SELECT * FROM platform_subscription_plans WHERE is_active = 1 ORDER BY price ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
    }

    if (!$rows) {
        return [
            'starter' => [
                'name' => 'Starter',
                'price' => 599,
                'price_label' => '₱599',
                'annual_price' => 5990,
                'annual_price_label' => '₱5,990',
                'desc' => 'Best for small, solo, or boutique gyms.',
                'popular' => false,
                'features' => [
                    'Up to 100 Active Members',
                    'Solo Gym Owner Model (0 Trainers)',
                    'Walk-in Management & Daily Pass',
                    'Dynamic QR Check-in & Scanner',
                    'Membership Plans & GCash / Online Pay',
                    'Basic Expiration Reminders (7-Day Notice)',
                    'Basic Financial Reports & CSV Export',
                    'Basic Activity History',
                ],
            ],
            'professional' => [
                'name' => 'Professional',
                'price' => 999,
                'price_label' => '₱999',
                'annual_price' => 9990,
                'annual_price_label' => '₱9,990',
                'desc' => 'Best for growing commercial gyms with staff.',
                'popular' => true,
                'features' => [
                    'Up to 500 Active Members',
                    'Personal Trainers & Coaching (Unlimited)',
                    'Trainer Commission Tracking & Payouts',
                    'Workout Plans & Exercise Library',
                    'Class Scheduling & Online Booking with Waitlists',
                    'Automated Multi-Stage Renewal Reminders (30d/14d/7d/1d)',
                    'Member Engagement Scoring & Churn Risk Alerts',
                    'Advanced Analytics & Financial Growth Trends',
                    'Staff Activity Logs',
                ],
            ],
            'business' => [
                'name' => 'Business',
                'price' => 1999,
                'price_label' => '₱1,999',
                'annual_price' => 19990,
                'annual_price_label' => '₱19,990',
                'desc' => 'For multi-branch & large-scale fitness centers.',
                'popular' => false,
                'features' => [
                    'Unlimited Active Members',
                    'Multi-Branch Management & Centralized Dashboard',
                    'Consolidated Cross-Branch Financial Reporting',
                    'Full Compliance Security Audit Trail (IPs, Diffs)',
                    'Custom App Brand Color & White-Label Theme',
                    'Dedicated Account Manager & Priority Support',
                ],
            ],
        ];
    }

    $plans = [];
    foreach ($rows as $row) {
        $features = array_values(array_filter(array_map('trim', explode("\n", (string)$row['features']))));
        $monthlyPrice = (float)$row['price'];
        $annualPrice = !empty($row['annual_price']) ? (float)$row['annual_price'] : round($monthlyPrice * 10, 2);
        $annualSavings = max(0, ($monthlyPrice * 12) - $annualPrice);

        $plans[$row['plan_key']] = [
            'id' => (int)$row['id'],
            'key' => $row['plan_key'],
            'name' => $row['name'],
            'price' => $monthlyPrice,
            'price_label' => '₱' . number_format($monthlyPrice),
            'annual_price' => $annualPrice,
            'annual_price_label' => '₱' . number_format($annualPrice),
            'annual_savings' => $annualSavings,
            'annual_savings_label' => '₱' . number_format($annualSavings),
            'desc' => $row['description'],
            'popular' => (bool)$row['is_popular'],
            'features' => $features,
            'raw_features' => $row['features'],
        ];
    }
    return $plans;
}

function get_membership_plan_features(array $plan): array
{
    $desc = trim((string)($plan['description'] ?? ''));
    if ($desc !== '') {
        $lines = array_filter(array_map('trim', explode("\n", $desc)));
        if (count($lines) >= 3) {
            return array_values($lines);
        }
    }
    
    $days = (int)($plan['duration_days'] ?? 30);
    $type = strtolower((string)($plan['plan_type'] ?? ''));
    $name = strtolower((string)($plan['plan_name'] ?? ''));
    
    if ($days >= 360 || str_contains($type, 'annual') || str_contains($name, 'annual') || str_contains($name, 'elite')) {
        return [
            "Full Gym & Equipment Access (" . $days . " Days)",
            "Unlimited Group Fitness & Classes",
            "1-on-1 Personal Trainer Consultation",
            "Priority Equipment & Queue Pass",
            "Complimentary Guest Passes (1/month)",
            "Dedicated Locker & Full Amenities",
        ];
    }
    
    if ($days >= 90 || str_contains($type, 'quarter') || str_contains($name, 'quarter') || str_contains($name, 'plus')) {
        return [
            "Full Gym & Equipment Access (" . $days . " Days)",
            "Priority Class Scheduling & Booking",
            "Free Fitness & Body Composition Assessment",
            "Discounted Personal Training Sessions",
            "Locker Room & Shower Access",
            "Automated Workout & Progress Tracking",
        ];
    }
    
    return [
        "Full Gym & Equipment Access (" . $days . " Days)",
        "Standard Group Class Bookings",
        "Walk-in Pass & QR Code Check-in",
        "Locker Room & Shower Access",
        "Basic Workout & Habit Tracking",
    ];
}

/**
 * Subscription Tier & Feature Entitlement Helpers
 */
function gym_subscription_tier(array|int|null $gym = null): string
{
    if (is_int($gym)) {
        $gym = db()->query("SELECT * FROM gyms WHERE gym_id = " . (int)$gym)->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$gym) {
        $user = current_user();
        if ($user) {
            $gym = get_user_gym($user);
        }
    }
    if (!$gym) {
        return 'none';
    }
    
    // Gym must be approved by platform admin
    if (($gym['status'] ?? '') !== 'approved') {
        return 'none';
    }

    $status = (string)($gym['subscription_status'] ?? '');
    $renewalDate = $gym['subscription_renewal_date'] ?? null;
    $rawPlan = strtolower(trim((string)($gym['subscription_plan'] ?? '')));

    // 1. Free Trial Evaluation (50 members & 2 trainers limit with Pro features)
    if ($status === 'trialing' || str_contains($rawPlan, 'trial')) {
        if (!empty($renewalDate) && strtotime((string)$renewalDate) < strtotime('today')) {
            // Trial has expired -> gracefully fallback to limited free tier
            return 'free';
        }
        return 'trial';
    }

    // 2. Paid Active Subscription
    if ($status === 'active') {
        if (!empty($renewalDate) && strtotime((string)$renewalDate) < strtotime('today')) {
            // Subscription lapsed -> gracefully fallback to limited free tier
            return 'free';
        }

        if (str_contains($rawPlan, 'business')) {
            return 'business';
        }
        if (str_contains($rawPlan, 'pro')) {
            return 'professional';
        }
        if (str_contains($rawPlan, 'starter')) {
            return 'starter';
        }

        return !empty($rawPlan) ? 'starter' : 'free';
    }

    // 3. Fallback for approved gyms (Free community tier)
    return 'free';
}

function gym_trial_info(array|int|null $gym = null): array
{
    if (is_int($gym)) {
        $gym = db()->query("SELECT * FROM gyms WHERE gym_id = " . (int)$gym)->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$gym) {
        $user = current_user();
        if ($user) {
            $gym = get_user_gym($user);
        }
    }
    if (!$gym) {
        return [
            'is_trial' => false,
            'is_trial_active' => false,
            'is_trial_expired' => false,
            'is_free' => false,
            'days_left' => 0,
            'renewal_date' => null,
            'plan_name' => 'None',
        ];
    }

    $status = (string)($gym['subscription_status'] ?? '');
    $renewalDate = $gym['subscription_renewal_date'] ?? null;
    $rawPlan = strtolower(trim((string)($gym['subscription_plan'] ?? '')));
    $isTrial = ($status === 'trialing' || str_contains($rawPlan, 'trial'));

    $today = strtotime('today');
    $renewalTimestamp = !empty($renewalDate) ? strtotime((string)$renewalDate) : null;
    $daysLeft = $renewalTimestamp ? (int) ceil(($renewalTimestamp - $today) / 86400) : 0;
    if ($daysLeft < 0) {
        $daysLeft = 0;
    }

    $isTrialActive = ($isTrial && $renewalTimestamp !== null && $renewalTimestamp >= $today);
    $isTrialExpired = ($isTrial && $renewalTimestamp !== null && $renewalTimestamp < $today);
    $isFree = ($status === 'free' || $isTrialExpired || ($status === 'inactive' && ($gym['status'] ?? '') === 'approved'));

    return [
        'is_trial' => $isTrial,
        'is_trial_active' => $isTrialActive,
        'is_trial_expired' => $isTrialExpired,
        'is_free' => $isFree,
        'days_left' => $daysLeft,
        'renewal_date' => $renewalDate,
        'plan_name' => $isTrialActive ? 'Free Trial' : ($isFree ? 'Free Tier' : ($gym['subscription_plan'] ?? 'Free Tier')),
    ];
}

function gym_member_limit(array|int|null $gym = null): int
{
    $tier = gym_subscription_tier($gym);
    return match ($tier) {
        'free' => 25,
        'trial' => 50,
        'starter' => 100,
        'professional' => 500,
        'business' => PHP_INT_MAX,
        default => 0,
    };
}

function gym_trainer_limit(array|int|null $gym = null): int
{
    $tier = gym_subscription_tier($gym);
    return match ($tier) {
        'free' => 0,
        'starter' => 0,
        'trial' => 2,
        'professional' => PHP_INT_MAX,
        'business' => PHP_INT_MAX,
        default => 0,
    };
}

function gym_active_trainer_count(int $gymId): int
{
    if ($gymId <= 0) return 0;
    return (int) scalar(
        'SELECT COUNT(*) FROM trainer_profiles tp
         JOIN users u ON u.user_id = tp.user_id
         WHERE tp.gym_id = ? AND u.status = "active"',
        [$gymId]
    );
}

function gym_can_add_trainer(int $gymId, ?array $gym = null): bool
{
    if (!$gym) {
        $gym = db()->query("SELECT * FROM gyms WHERE gym_id = " . (int)$gymId)->fetch(PDO::FETCH_ASSOC);
    }
    if (!$gym || !gym_has_feature('trainers', $gym)) {
        return false;
    }
    $limit = gym_trainer_limit($gym);
    if ($limit === PHP_INT_MAX) {
        return true;
    }
    $current = gym_active_trainer_count($gymId);
    return $current < $limit;
}

function gym_active_member_count(int $gymId): int
{
    if ($gymId <= 0) return 0;
    $count = (int) scalar(
        'SELECT COUNT(DISTINCT gm.user_id) 
         FROM gym_members gm 
         JOIN users u ON u.user_id = gm.user_id 
         WHERE gm.gym_id = ? AND u.status = "active"',
        [$gymId]
    );
    return $count;
}

function gym_can_add_member(int $gymId, ?array $gym = null): bool
{
    if (!$gym) {
        $gym = db()->query("SELECT * FROM gyms WHERE gym_id = " . (int)$gymId)->fetch(PDO::FETCH_ASSOC);
    }
    if (!$gym || ($gym['status'] ?? '') !== 'approved' || gym_subscription_tier($gym) === 'none') {
        return false;
    }
    $limit = gym_member_limit($gym);
    if ($limit === PHP_INT_MAX) {
        return true;
    }
    $current = gym_active_member_count($gymId);
    return $current < $limit;
}

function gym_has_feature(string $feature, array|int|null $gym = null): bool
{
    $tier = gym_subscription_tier($gym);
    if ($tier === 'none') {
        return false;
    }

    // Business tier has access to everything
    if ($tier === 'business') {
        return true;
    }

    $tierWeights = [
        'free' => 0,
        'starter' => 1,
        'trial' => 2,
        'professional' => 2,
        'business' => 3,
    ];

    $featureRequirements = [
        // Free & Above (Core Essential Operations - Trial & Free Gyms can use these)
        'scanner' => 'free',
        'attendance' => 'free',
        'walk_ins' => 'free',
        'memberships' => 'free',
        'payments' => 'free',
        'basic_dashboard' => 'free',
        'gym_profile' => 'free',
        'plans' => 'free',

        // Starter & Above (Staff & Basic Reporting)
        'users' => 'starter',
        'reports' => 'starter',
        'basic_reports' => 'starter',
        'export_csv' => 'starter',
        'renewal_reminders' => 'starter',
        'basic_renewal_reminders' => 'starter',
        'basic_activity' => 'starter',
        'basic_classes' => 'starter',

        // Professional & Above (Staff, Coaching, Growth, Advanced Automation)
        'trainers' => 'professional',
        'trainer_assignments' => 'professional',
        'commissions' => 'professional',
        'workouts' => 'professional',
        'exercises' => 'professional',
        'training' => 'professional',
        'admin_workouts' => 'professional',
        'classes' => 'professional',
        'advanced_classes' => 'professional',
        'class_waitlists' => 'professional',
        'automated_renewal_reminders' => 'professional',
        'engagement_tracking' => 'professional',
        'advanced_reports' => 'professional',
        'staff_activity' => 'professional',

        // Business only (Multi-Branch, Full Compliance, Custom Branding, EOD, Staff Privacy)
        'custom_branding' => 'business',
        'staff_permissions' => 'business',
        'eod_summary' => 'business',
        'audit_logs' => 'business',
        'full_audit_logs' => 'business',
        'multi_branch' => 'business',
        'consolidated_reports' => 'business',
        'custom_renewal_reminders' => 'business',
    ];

    $requiredTier = $featureRequirements[$feature] ?? 'professional';
    $currentWeight = $tierWeights[$tier] ?? 0;
    $requiredWeight = $tierWeights[$requiredTier] ?? 2;

    return $currentWeight >= $requiredWeight;
}

function require_gym_feature(string $feature): void
{
    $user = current_user();
    if ($user && ($user['role'] ?? '') === 'platform_admin') {
        return;
    }

    $gym = $user ? get_user_gym($user) : null;
    if (!$gym || !gym_has_feature($feature, $gym)) {
        http_response_code(403);
        $featureTitles = [
            'trainers' => 'Trainers & Staff Management',
            'trainer_assignments' => 'Trainer Assignments',
            'commissions' => 'Trainer Commission Tracking',
            'classes' => 'Class Scheduling & Booking',
            'reports' => 'Financial & Attendance Reports',
            'advanced_reports' => 'Financial & Attendance Reports',
            'custom_branding' => 'Custom App Brand Theme',
            'audit_logs' => 'Security Audit Logs',
            'workouts' => 'Workout Builder & Plans',
            'users' => 'Staff & User Management',
        ];
        $featureName = $featureTitles[$feature] ?? ucwords(str_replace('_', ' ', $feature));
        $tier = gym_subscription_tier($gym);
        $planName = $tier === 'free' ? 'Free Tier' : ($tier !== 'none' ? ucfirst($tier) : 'Inactive');
        
        $neededTier = in_array($feature, ['custom_branding', 'audit_logs', 'multi_branch', 'full_audit_logs'], true)
            ? 'Business'
            : (in_array($feature, ['trainers', 'trainer_assignments', 'commissions', 'classes', 'renewal_reminders', 'engagement_tracking', 'workouts', 'exercises', 'training', 'admin_workouts', 'advanced_reports'], true)
                ? 'Professional'
                : 'Starter');

        render_header('Upgrade Required', $user);
        ?>
        <div style="max-width: 640px; margin: 60px auto; padding: 40px 32px; background: rgba(15, 21, 18, 0.9); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 20px; box-shadow: 0 25px 60px rgba(0,0,0,0.6); text-align: center; backdrop-filter: blur(16px);">
            <div style="width: 64px; height: 64px; margin: 0 auto 20px; background: rgba(132, 204, 22, 0.12); border: 1px solid rgba(132, 204, 22, 0.3); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#84cc16" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
            </div>
            <span style="display: inline-block; padding: 4px 12px; background: rgba(132, 204, 22, 0.15); color: #84cc16; font-size: 11px; font-weight: 800; letter-spacing: 0.8px; border-radius: 20px; text-transform: uppercase; margin-bottom: 12px;">
                <?= h($neededTier) ?> Plan Feature
            </span>
            <h1 style="font-size: 1.8rem; font-weight: 800; color: #fff; margin: 0 0 12px; letter-spacing: -0.5px;">
                <?= h($featureName) ?>
            </h1>
            <p style="color: #94a3b8; font-size: 15px; line-height: 1.6; margin: 0 auto 28px; max-width: 480px;">
                This module is not included in your current <strong style="color: #fff;"><?= h($planName) ?></strong> plan. Upgrade to the <strong style="color: #84cc16;"><?= h($neededTier) ?></strong> tier to unlock full access.
            </p>
            <div style="display: flex; gap: 14px; justify-content: center; flex-wrap: wrap;">
                <a href="index.php?page=dashboard" class="btn" style="background: rgba(255,255,255,0.06); color: #fff; border: 1px solid rgba(255,255,255,0.12); padding: 12px 24px; border-radius: 12px; font-weight: 600; text-decoration: none;">
                    Back to Dashboard
                </a>
                <a href="index.php?page=gym_subscription" class="btn btn-primary" style="padding: 12px 28px; border-radius: 12px; font-weight: 700; text-decoration: none;">
                    Upgrade Subscription →
                </a>
            </div>
        </div>
        <?php
        render_footer();
        exit;
    }
}

/**
 * Check if the currently logged-in user or given user is restricted from seeing gym financials.
 * Returns true if the user is staff/trainer and staff financial privacy is enabled or user is front_desk.
 */
function is_staff_financials_restricted(?array $user = null): bool
{
    if ($user === null) {
        $user = current_user();
    }
    if (!$user) {
        return false;
    }
    // Platform Admins and Gym Owners always see full financials
    if (in_array($user['role'] ?? '', ['platform_admin', 'gym_owner'], true)) {
        return false;
    }

    // Members do not have staff financial access
    if (($user['role'] ?? '') === 'member') {
        return true;
    }

    // If staff/trainer role:
    if (($user['role'] ?? '') === 'trainer') {
        if (($user['staff_role'] ?? '') === 'front_desk') {
            return true;
        }

        $gym = get_user_gym($user);
        if ($gym && !empty($gym['staff_hide_financials'])) {
            return true;
        }
    }

    return false;
}

/**
 * High-performance End-of-Day (EOD) summary computation.
 * Executes indexed single-table queries in <2ms.
 */
function gym_get_eod_summary(int $gymId, ?string $date = null): array
{
    $pdo = db();
    $targetDate = $date ? date('Y-m-d', strtotime($date)) : date('Y-m-d');
    $nextDate = date('Y-m-d', strtotime($targetDate . ' +1 day'));

    // 1. Membership Payments Today
    $subStmt = $pdo->prepare('
        SELECT COUNT(*) AS txn_count, COALESCE(SUM(amount), 0) AS total_revenue
        FROM payments
        WHERE gym_id = ? AND status = "paid" AND DATE(payment_date) = ?
    ');
    $subStmt->execute([$gymId, $targetDate]);
    $subData = $subStmt->fetch(PDO::FETCH_ASSOC) ?: ['txn_count' => 0, 'total_revenue' => 0];

    // 2. Walk-in Passes Today
    $walkStmt = $pdo->prepare('
        SELECT COUNT(*) AS txn_count, COALESCE(SUM(walk_in_fee), 0) AS total_revenue
        FROM walk_in_transactions
        WHERE gym_id = ? AND DATE(created_at) = ?
    ');
    $walkStmt->execute([$gymId, $targetDate]);
    $walkData = $walkStmt->fetch(PDO::FETCH_ASSOC) ?: ['txn_count' => 0, 'total_revenue' => 0];

    // 3. Attendance Check-ins Today
    $attStmt = $pdo->prepare('
        SELECT COUNT(*) AS checkin_count
        FROM attendance
        WHERE gym_id = ? AND DATE(check_in_time) = ?
    ');
    $attStmt->execute([$gymId, $targetDate]);
    $attCount = (int) ($attStmt->fetchColumn() ?: 0);

    // 4. Memberships Expiring Tomorrow
    $expStmt = $pdo->prepare('
        SELECT COUNT(*) AS expiring_count
        FROM memberships
        WHERE gym_id = ? AND status = "active" AND DATE(end_date) = ?
    ');
    $expStmt->execute([$gymId, $nextDate]);
    $expiringCount = (int) ($expStmt->fetchColumn() ?: 0);

    // 5. Active Enrolled Members
    $activeMemberCount = gym_active_member_count($gymId);

    $totalGross = (float)$subData['total_revenue'] + (float)$walkData['total_revenue'];
    $totalTxns = (int)$subData['txn_count'] + (int)$walkData['txn_count'];

    return [
        'date' => $targetDate,
        'date_formatted' => date('F j, Y', strtotime($targetDate)),
        'total_gross' => $totalGross,
        'total_transactions' => $totalTxns,
        'subscriptions_revenue' => (float)$subData['total_revenue'],
        'subscriptions_count' => (int)$subData['txn_count'],
        'walkins_revenue' => (float)$walkData['total_revenue'],
        'walkins_count' => (int)$walkData['txn_count'],
        'checkins_count' => $attCount,
        'expiring_tomorrow' => $expiringCount,
        'active_members' => $activeMemberCount,
    ];
}

/**
 * Send executive End-of-Day Daily Settlement email to gym owner.
 */
function gym_send_eod_summary_email(int $gymId, ?string $date = null, bool $immediate = false): bool
{
    $pdo = db();
    $gym = $pdo->query('SELECT g.*, u.email AS owner_email, u.first_name, u.last_name FROM gyms g JOIN users u ON u.user_id = g.owner_user_id WHERE g.gym_id = ' . (int)$gymId)->fetch(PDO::FETCH_ASSOC);
    if (!$gym || empty($gym['owner_email'])) {
        return false;
    }

    $summary = gym_get_eod_summary($gymId, $date);
    $gymName = trim((string)($gym['name'] ?? 'FitTrack Gym'));
    $ownerName = trim(((string)($gym['first_name'] ?? '')) . ' ' . ((string)($gym['last_name'] ?? ''))) ?: 'Gym Owner';

    return Emails::sendEODSummary($gym['owner_email'], $ownerName, $gymName, $summary, $immediate);
}




function upload_url(?string $filename, string $folder = 'uploads', string $fallback = ''): string
{
    if (empty($filename)) {
        return $fallback;
    }

    if (str_starts_with($filename, 'http://') || str_starts_with($filename, 'https://')) {
        return $filename;
    }

    if ($folder === 'permits') {
        return 'index.php?page=view_permit&file=' . rawurlencode(basename($filename));
    }

    $cdnUrl = app_env('CDN_URL', app_env('STORAGE_PUBLIC_URL', ''));
    if (!empty($cdnUrl)) {
        return rtrim($cdnUrl, '/') . '/assets/' . $folder . '/' . ltrim($filename, '/');
    }

    return 'assets/' . $folder . '/' . $filename;
}

function initials(array $user): string
{
    return strtoupper(substr((string) $user['first_name'], 0, 1) . substr((string) $user['last_name'], 0, 1));
}

function render_avatar(array $row, string $size = 'small'): string
{
    $ini = initials($row);
    if (!empty($row['profile_picture'])) {
        $dim = $size === 'small' ? '32px' : '36px';
        $src = upload_url($row['profile_picture'], 'uploads');
        return '<img src="' . h($src) . '" alt="' . h($ini) . '" class="avatar ' . $size . '" loading="lazy" decoding="async" style="object-fit:cover;width:' . $dim . ';height:' . $dim . ';">';
    }
    return '<span class="avatar ' . $size . '">' . h($ini) . '</span>';
}


//TIERS LOGIC
function get_fitness_tier_name(int $tier): string {
    return match ($tier) {
        1 => 'Newbie',
        2 => 'Iron Recruit',
        3 => 'Bronze Beast',
        4 => 'Silver Spartan',
        5 => 'Gold Gladiator',
        default => 'Apex Legend',
    };
}

function check_and_upgrade_tier(int $userId, int $planId): ?array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM exercise_completions WHERE user_id = ?');
    $stmt->execute([$userId]);
    $totalCompleted = (int) $stmt->fetchColumn();
    
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM training_plan_exercises WHERE plan_id = ?');
    $stmt->execute([$planId]);
    $exercisesPerWeek = (int) $stmt->fetchColumn();
    if ($exercisesPerWeek === 0) return null;
    
    $completedWeeks = (int) floor($totalCompleted / $exercisesPerWeek);
    $newTier = 1;
    if ($completedWeeks >= 52) $newTier = 6;
    elseif ($completedWeeks >= 24) $newTier = 5;
    elseif ($completedWeeks >= 12) $newTier = 4;
    elseif ($completedWeeks >= 4) $newTier = 3;
    elseif ($completedWeeks >= 1) $newTier = 2;
    
    $stmt = $pdo->prepare('SELECT fitness_tier, completed_weeks FROM member_profiles WHERE user_id = ?');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
    
    if ($profile && ((int)$profile['fitness_tier'] !== $newTier || (int)$profile['completed_weeks'] !== $completedWeeks)) {
        $pdo->prepare('UPDATE member_profiles SET fitness_tier = ?, completed_weeks = ? WHERE user_id = ?')->execute([$newTier, $completedWeeks, $userId]);
        if ($profile['fitness_tier'] < $newTier) {
            return ['old_tier' => (int)$profile['fitness_tier'], 'new_tier' => $newTier, 'new_tier_name' => get_fitness_tier_name($newTier)];
        }
    }
    return null;
}

function process_trainer_commission(int $paymentId, float $amount): void
{
    $pdo = db();
    // 1. Get the membership ID and plan commission rate from the payment
    $paymentInfo = $pdo->query("
        SELECT m.user_id, m.membership_id, mp.commission_rate
        FROM payments p
        JOIN memberships m ON m.membership_id = p.membership_id
        JOIN membership_plans mp ON mp.plan_id = m.plan_id
        WHERE p.payment_id = " . (int)$paymentId
    )->fetch();

    if (!$paymentInfo || $paymentInfo['commission_rate'] <= 0) {
        return;
    }

    // 2. Check if the member has an active trainer and get the trainer's user_id
    $trainer = $pdo->query("
        SELECT tp.user_id 
        FROM trainer_assignments ta
        JOIN trainer_profiles tp ON tp.trainer_id = ta.trainer_id
        WHERE ta.member_user_id = " . (int)$paymentInfo['user_id'] . " 
        AND ta.status = 'active'
        LIMIT 1
    ")->fetch();

    if ($trainer) {
        // 3. Calculate and insert commission
        $commissionAmount = $amount * ((float)$paymentInfo['commission_rate'] / 100);
        if ($commissionAmount > 0) {
            $pdo->prepare('INSERT INTO trainer_commissions (trainer_id, payment_id, amount, status) VALUES (?, ?, ?, "pending")')
                ->execute([$trainer['user_id'], $paymentId, $commissionAmount]);
        }
    }
}

function grant_retroactive_commission(int $memberUserId): void
{
    $pdo = db();
    $recentPayment = $pdo->query("
        SELECT p.payment_id, p.amount 
        FROM memberships m
        JOIN payments p ON p.membership_id = m.membership_id
        WHERE m.user_id = " . (int)$memberUserId . " 
        AND m.status = 'active' 
        AND p.status = 'paid'
        ORDER BY p.payment_date DESC 
        LIMIT 1
    ")->fetch();

    if ($recentPayment) {
        $exists = scalar('SELECT COUNT(*) FROM trainer_commissions WHERE payment_id = ?', [$recentPayment['payment_id']]);
        if (!$exists) {
            process_trainer_commission((int)$recentPayment['payment_id'], (float)$recentPayment['amount']);
        }
    }
}

function audit_log(int $adminUserId, string $action, string $entityType, ?string $entityId = null, ?string $details = null): void
{
    try {
        db()->prepare('INSERT INTO admin_audit_logs (admin_user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)')
            ->execute([$adminUserId, $action, $entityType, $entityId, $details]);
    } catch (Throwable) {
        // Non-fatal — audit logging should never break the main flow
    }
}

function map_detailed_goal_to_basic(string $detailedGoal): string
{
    $map = [
        'increasing_strength' => 'muscle_gain',
        'building_muscle' => 'muscle_gain',
        'losing_weight' => 'fat_loss',
        'reducing_body_fat' => 'fat_loss',
        'improving_endurance' => 'general_health',
        'general_fitness' => 'general_health',
        'casual' => 'casual',
        'casual_fitness' => 'casual',
        'Casual Lifestyle / Flexible' => 'casual',
        'Building a visible six-pack' => 'fat_loss',
        'Growing larger biceps and arms' => 'muscle_gain',
        'Developing a wide chest' => 'muscle_gain',
        'Sculpting a V-tapered back' => 'muscle_gain',
        'Shaping the lower body' => 'muscle_gain',
        'Increasing maximum strength' => 'muscle_gain',
        'Boosting explosive power' => 'general_health',
        'Enhancing physical endurance' => 'general_health',
        'Improving body flexibility' => 'general_health',
        'Losing excess body fat' => 'fat_loss',
        'Gaining lean body mass' => 'muscle_gain',
        'Reaching body recomposition' => 'maintenance'
    ];
    
    return $map[$detailedGoal] ?? 'general_health';
}

function resolve_goal_category(string $goal): string
{
    $g = strtolower(trim($goal));
    if ($g === 'casual' || str_contains($g, 'casual') || str_contains($g, 'flexible') || str_contains($g, 'lifestyle')) {
        return 'casual';
    }
    if ($g === 'increasing_strength' || str_contains($g, 'strength') || str_contains($g, 'powerlifting') || str_contains($g, 'explosive') || str_contains($g, 'heavy')) {
        return 'increasing_strength';
    }
    if ($g === 'building_muscle' || $g === 'muscle_gain' || str_contains($g, 'muscle') || str_contains($g, 'hypertrophy') || str_contains($g, 'bicep') || str_contains($g, 'chest') || str_contains($g, 'back') || str_contains($g, 'lean body') || str_contains($g, 'lower body')) {
        return 'building_muscle';
    }
    if ($g === 'losing_weight' || $g === 'weight_loss' || str_contains($g, 'lose weight') || str_contains($g, 'weight loss') || str_contains($g, 'losing weight')) {
        return 'losing_weight';
    }
    if ($g === 'reducing_body_fat' || $g === 'fat_loss' || str_contains($g, 'body fat') || str_contains($g, 'fat') || str_contains($g, 'six-pack') || str_contains($g, 'shred') || str_contains($g, 'recomposition')) {
        return 'reducing_body_fat';
    }
    if ($g === 'improving_endurance' || str_contains($g, 'endurance') || str_contains($g, 'cardio') || str_contains($g, 'stamina') || str_contains($g, 'running') || str_contains($g, 'flexibility')) {
        return 'improving_endurance';
    }
    return 'general_fitness';
}

function get_recommendations_by_goal(PDO $pdo, string $detailedGoal): array
{
    $basicGoal = map_detailed_goal_to_basic($detailedGoal);
    
    // Define keywords based on basic goal
    $keywords = [];
    if ($basicGoal === 'fat_loss') {
        $keywords = ['hiit', 'cardio', 'zumba', 'burn', 'fat', 'sweat', 'core', 'abs', 'cycle', 'spin'];
    } elseif ($basicGoal === 'muscle_gain') {
        $keywords = ['strength', 'weight', 'power', 'lift', 'crossfit', 'bodybuilding', 'hypertrophy', 'muscle'];
    } elseif ($basicGoal === 'general_health' || $basicGoal === 'maintenance' || $basicGoal === 'casual') {
        $keywords = ['yoga', 'pilates', 'wellness', 'stretch', 'balance', 'flow', 'mobility', 'health', 'walk'];
    }
    
    $classes = [];
    $gyms = [];
    
    if (!empty($keywords)) {
        // Build query to find matching classes that belong to approved gyms
        $conditions = [];
        $params = [];
        foreach ($keywords as $kw) {
            $conditions[] = 'c.class_name LIKE ? OR c.description LIKE ?';
            $params[] = '%' . $kw . '%';
            $params[] = '%' . $kw . '%';
        }
        
        $sql = "SELECT c.*, g.name AS gym_name, g.address AS gym_address, g.contact_info 
                FROM classes c
                JOIN gyms g ON c.gym_id = g.gym_id
                WHERE g.status = 'approved' AND (" . implode(' OR ', $conditions) . ")
                ORDER BY RAND() LIMIT 4";
                
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $classes = $stmt->fetchAll();
        
        // Find matching gyms (gyms that host these classes, or have matching names/descriptions)
        // Wait, gyms table doesn't have a description right now, only name and address.
        // So we'll just recommend the gyms that host the recommended classes.
        $gymIds = array_unique(array_column($classes, 'gym_id'));
        if (!empty($gymIds)) {
            $placeholders = implode(',', array_fill(0, count($gymIds), '?'));
            $gymStmt = $pdo->prepare("SELECT * FROM gyms WHERE gym_id IN ($placeholders) AND status = 'approved' LIMIT 4");
            $gymStmt->execute($gymIds);
            $gyms = $gymStmt->fetchAll();
        }
    }
    
    // Fallback if no classes match (e.g., new platform, empty DB)
    if (empty($classes)) {
        $classes = $pdo->query("SELECT c.*, g.name AS gym_name, g.address AS gym_address 
                                FROM classes c JOIN gyms g ON c.gym_id = g.gym_id 
                                WHERE g.status = 'approved' ORDER BY RAND() LIMIT 4")->fetchAll();
    }
    
    if (empty($gyms)) {
        $gyms = $pdo->query("SELECT * FROM gyms WHERE status = 'approved' ORDER BY RAND() LIMIT 4")->fetchAll();
    }
    
    return [
        'classes' => $classes,
        'gyms' => $gyms
    ];
}

function get_user_gym(array $user): ?array
{
    $pdo = db();
    $role = $user['role'] ?? null;
    $userId = (int) ($user['user_id'] ?? 0);
    if (!$userId) return null;

    if ($role === 'gym_owner') {
        $gym = $pdo->query("SELECT * FROM gyms WHERE owner_user_id = $userId LIMIT 1")->fetch();
        return $gym ?: null;
    }
    
    if ($role === 'trainer') {
        $gym = $pdo->query("SELECT g.* FROM gyms g JOIN trainer_profiles tp ON tp.gym_id = g.gym_id WHERE tp.user_id = $userId LIMIT 1")->fetch();
        return $gym ?: null;
    }
    
    if ($role === 'member') {
        if (!empty($_SESSION['current_gym_id'])) {
            $gymId = (int)$_SESSION['current_gym_id'];
            $gym = $pdo->query("SELECT * FROM gyms WHERE gym_id = $gymId LIMIT 1")->fetch();
            if ($gym) return $gym;
        }

        // 1. Most recent active physical check-in
        $gym = $pdo->query("SELECT g.* FROM gyms g JOIN attendance a ON a.gym_id = g.gym_id WHERE a.user_id = $userId AND a.check_out_time IS NULL ORDER BY a.check_in_time DESC LIMIT 1")->fetch();
        if ($gym) return $gym;

        // 2. Most recent physical check-in overall
        $gym = $pdo->query("SELECT g.* FROM gyms g JOIN attendance a ON a.gym_id = g.gym_id WHERE a.user_id = $userId ORDER BY a.check_in_time DESC LIMIT 1")->fetch();
        if ($gym) return $gym;

        // 3. Find gym of active membership
        $gym = $pdo->query("SELECT g.* FROM gyms g JOIN membership_plans mp ON mp.gym_id = g.gym_id JOIN memberships m ON m.plan_id = mp.plan_id WHERE m.user_id = $userId AND m.status = 'active' ORDER BY m.membership_id DESC LIMIT 1")->fetch();
        if ($gym) return $gym;
        
        // 4. Fallback to any pending membership gym
        $gym = $pdo->query("SELECT g.* FROM gyms g JOIN membership_plans mp ON mp.gym_id = g.gym_id JOIN memberships m ON m.plan_id = mp.plan_id WHERE m.user_id = $userId AND m.status = 'pending' ORDER BY m.membership_id DESC LIMIT 1")->fetch();
        if ($gym) return $gym;

        // 5. Fallback to gym_members direct association
        $gym = $pdo->query("SELECT g.* FROM gyms g JOIN gym_members gm ON gm.gym_id = g.gym_id WHERE gm.user_id = $userId LIMIT 1")->fetch();
        if ($gym) return $gym;
    }
    
    return null;
}

/**
 * Fetch the latest announcements targeting a specific role or everyone.
 */
function get_active_announcements(string $role, int $limit = 5): array
{
    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT a.title, a.content, a.created_at, u.first_name, u.last_name 
        FROM announcements a 
        JOIN users u ON u.user_id = a.created_by 
        WHERE a.target_audience = "all" OR a.target_audience = ?
        ORDER BY a.created_at DESC 
        LIMIT ?
    ');
    // PDO doesn't automatically cast limit bind params correctly if not configured properly, so we explicitly cast via bindValue
    $stmt->bindValue(1, $role, PDO::PARAM_STR);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Render the announcement carousel inside a modal triggered by a button.
 */
function render_announcement_carousel(array $announcements): void
{
    if (empty($announcements)) {
        return;
    }
    
    $carouselId = 'carousel_' . uniqid();
    ?>
    <button onclick="document.getElementById('announcementModal_<?= $carouselId ?>').showModal()" class="animate-fade-in" style="margin-bottom: 24px; display: flex; align-items: center; gap: 8px; background: var(--panel-soft); border: 1px solid var(--line); padding: 12px 20px; border-radius: 12px; color: var(--ink); cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='var(--line)'" onmouseout="this.style.background='var(--panel-soft)'">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <span style="font-weight: bold;">View Announcements (<?= count($announcements) ?>)</span>
    </button>

    <dialog id="announcementModal_<?= $carouselId ?>" class="modal" style="padding: 0; background: transparent; border: none; max-width: 500px; width: 100%;">
        <div style="background: var(--surface); border: 1px solid var(--line); border-radius: 16px; overflow: hidden; position: relative; padding-top: 12px;">
            <button onclick="this.closest('dialog').close()" style="position: absolute; top: 12px; right: 12px; background: rgba(255,255,255,0.1); border: none; width: 32px; height: 32px; border-radius: 50%; color: var(--ink); cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 10; font-size: 20px; line-height: 1;">&times;</button>
            
            <div class="announcement-carousel" id="<?= $carouselId ?>" style="margin-bottom: 0; border: none; border-radius: 0;">
                <div class="announcement-slides" id="slides_<?= $carouselId ?>">
                    <?php foreach ($announcements as $ann): ?>
                        <div class="announcement-slide">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                <span style="background: rgba(199,255,34,0.15); color: var(--lime); padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">Notice</span>
                                <span style="color: var(--muted); font-size: 12px;"><?= date('M j, Y', strtotime($ann['created_at'])) ?></span>
                            </div>
                            <h3 style="margin: 0 0 8px 0; color: var(--ink); font-size: 18px; padding-right: 24px;"><?= h($ann['title']) ?></h3>
                            <p style="margin: 0; color: var(--muted); font-size: 14px; line-height: 1.5; white-space: pre-line;"><?= h($ann['content']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if (count($announcements) > 1): ?>
                <div class="announcement-dots" id="dots_<?= $carouselId ?>">
                    <?php foreach ($announcements as $index => $ann): ?>
                        <div class="announcement-dot <?= $index === 0 ? 'active' : '' ?>" data-index="<?= $index ?>"></div>
                    <?php endforeach; ?>
                </div>
                <script>
                (function() {
                    const carousel = document.getElementById('<?= $carouselId ?>');
                    const slidesContainer = document.getElementById('slides_<?= $carouselId ?>');
                    const dots = document.getElementById('dots_<?= $carouselId ?>').querySelectorAll('.announcement-dot');
                    let currentIndex = 0;
                    const total = <?= count($announcements) ?>;
                    let interval;
                    
                    function showSlide(index) {
                        currentIndex = index;
                        slidesContainer.style.transform = `translateX(-${index * 100}%)`;
                        dots.forEach(d => d.classList.remove('active'));
                        dots[index].classList.add('active');
                    }
                    
                    function nextSlide() {
                        showSlide((currentIndex + 1) % total);
                    }
                    
                    dots.forEach(dot => {
                        dot.addEventListener('click', function() {
                            showSlide(parseInt(this.getAttribute('data-index')));
                            resetInterval();
                        });
                    });
                    
                    function resetInterval() {
                        clearInterval(interval);
                        interval = setInterval(nextSlide, 7000);
                    }
                    resetInterval();
                })();
                </script>
                <?php endif; ?>
            </div>
        </div>
    </dialog>
    <?php
}

if (!function_exists('setup_error')) {
    function setup_error(Throwable $e): void
    {
        error_log('Application Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        http_response_code(500);
        $isDev = in_array(strtolower($_ENV['APP_ENV'] ?? 'production'), ['development', 'dev', 'local'], true) 
                 || (isset($_SERVER['REMOTE_ADDR']) && in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'], true));
        $cssFile = __DIR__ . '/../assets/app.css';
        $v = file_exists($cssFile) ? filemtime($cssFile) : time();
        ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FITTRACK - System Notification</title>
    <link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
</head>
<body>
<main class="shell">
    <section class="panel" style="max-width: 600px; margin: 40px auto;">
        <h1>Service Temporarily Unavailable</h1>
        <p>The system encountered a configuration or database connectivity issue. Please try again in a few moments.</p>
        <?php if ($isDev): ?>
            <div style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); border-radius: 8px; padding: 12px; margin: 16px 0; font-family: monospace; font-size: 13px; color: #ef4444; word-break: break-all;">
                <strong>Debug Info:</strong> <?= h($e->getMessage()) ?>
            </div>
            <ol style="font-size: 13px; color: var(--muted, #888); padding-left: 20px;">
                <li>Ensure MySQL / database service is imported and running.</li>
                <li>Verify database credentials in your <code>.env</code> file.</li>
            </ol>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
        <?php
    }
}

function ensure_coach_profile(int $userId): int
{
    $coachId = (int) scalar('SELECT trainer_id FROM trainer_profiles WHERE user_id = ?', [$userId]);
    if (!$coachId) {
        db()->prepare('INSERT INTO trainer_profiles (user_id) VALUES (?)')->execute([$userId]);
        $coachId = (int) db()->lastInsertId();
    }
    return $coachId;
}

function get_user_gym_id(array $user): ?int
{
    if (isset($user['gym_id']) && (int)$user['gym_id'] > 0) {
        return (int)$user['gym_id'];
    }

    $role = $user['role'] ?? '';
    $userId = (int)($user['user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    if ($role === 'gym_owner' || $role === 'admin') {
        $gymId = scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ? LIMIT 1', [$userId]);
        return $gymId ? (int)$gymId : null;
    }

    if ($role === 'member') {
        $gymId = scalar('SELECT gym_id FROM gym_members WHERE user_id = ? LIMIT 1', [$userId]);
        if (!$gymId) {
            $cg = get_user_gym($user);
            $gymId = $cg['gym_id'] ?? null;
        }
        return $gymId ? (int)$gymId : null;
    }

    if ($role === 'trainer') {
        $gymId = scalar('SELECT gym_id FROM trainer_profiles WHERE user_id = ? AND gym_id IS NOT NULL LIMIT 1', [$userId]);
        if (!$gymId) {
            $gymId = scalar('SELECT gym_id FROM gym_members WHERE user_id = ? LIMIT 1', [$userId]);
        }
        if (!$gymId) {
            $gymId = scalar('SELECT gym_id FROM classes WHERE instructor_id = ? AND gym_id IS NOT NULL LIMIT 1', [$userId]);
        }
        if (!$gymId) {
            $cg = get_user_gym($user);
            $gymId = $cg['gym_id'] ?? null;
        }
        return $gymId ? (int)$gymId : null;
    }

    $cg = get_user_gym($user);
    return !empty($cg['gym_id']) ? (int)$cg['gym_id'] : null;
}

function format_equipment_duration(int $seconds): string
{
    if ($seconds <= 0) return '00:00';
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    if ($hours > 0) {
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
    return sprintf('%02d:%02d', $minutes, $secs);
}

if (!function_exists('get_meal_photo_url')) {
    function get_meal_photo_url(?string $customUrl, string $foodItems, string $mealType): string {
        $cUrl = trim((string) $customUrl);
        if (!empty($cUrl)) {
            return $cUrl;
        }

        $lower = strtolower($foodItems);

        // Tier 1: Specific dishes & prepared recipes (highest priority)
        $specificDishes = [
            // Apples & fruit snacks
            'apple slice'   => 'https://images.unsplash.com/photo-1560806887-1e4cd0b6cbd6?auto=format&fit=crop&w=600&q=80',
            'apple'         => 'https://images.unsplash.com/photo-1560806887-1e4cd0b6cbd6?auto=format&fit=crop&w=600&q=80',
            
            // Green bean / sitaw dishes & Tofu
            'adobong sitaw' => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?auto=format&fit=crop&w=600&q=80',
            'sitaw'         => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?auto=format&fit=crop&w=600&q=80',
            'green bean'    => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?auto=format&fit=crop&w=600&q=80',
            'tofu scramble' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80',
            
            // Soups & Stews
            'tinola'        => 'https://images.unsplash.com/photo-1547592180-85f173990554?auto=format&fit=crop&w=600&q=80', // Ginger chicken broth with greens
            'sinigang'      => 'https://images.unsplash.com/photo-1559847844-5315695dadae?auto=format&fit=crop&w=600&q=80', // Sour tamarind soup with shrimp/fish/veggies
            'munggo'        => 'https://images.unsplash.com/photo-1547592180-85f173990554?auto=format&fit=crop&w=600&q=80', // Hearty mung bean stew
            'laing'         => 'https://images.unsplash.com/photo-1455619452474-d2be8b1e70cd?auto=format&fit=crop&w=600&q=80', // Taro leaves in rich coconut milk
            'bicol express' => 'https://images.unsplash.com/photo-1455619452474-d2be8b1e70cd?auto=format&fit=crop&w=600&q=80',
            'bicol'         => 'https://images.unsplash.com/photo-1455619452474-d2be8b1e70cd?auto=format&fit=crop&w=600&q=80',
            'gising'        => 'https://images.unsplash.com/photo-1455619452474-d2be8b1e70cd?auto=format&fit=crop&w=600&q=80',
            'pinakbet'      => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?auto=format&fit=crop&w=600&q=80', // Filipino vegetable medley
            'pakbet'        => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?auto=format&fit=crop&w=600&q=80',
            
            // Porridges & Breakfast bowls
            'arroz caldo'   => 'https://images.unsplash.com/photo-1563379091339-03b21ab4a4f8?auto=format&fit=crop&w=600&q=80', // Warm chicken rice porridge with egg
            'lugaw'         => 'https://images.unsplash.com/photo-1563379091339-03b21ab4a4f8?auto=format&fit=crop&w=600&q=80',
            'congee'        => 'https://images.unsplash.com/photo-1563379091339-03b21ab4a4f8?auto=format&fit=crop&w=600&q=80',
            'champorado'    => 'https://images.unsplash.com/photo-1584776296944-ab6fb57b0bdd?auto=format&fit=crop&w=600&q=80', // Dark rich chocolate porridge bowl
            'pancake'       => 'https://images.unsplash.com/photo-1565299585323-38d6b0865b47?auto=format&fit=crop&w=600&q=80', // Stack of fluffy pancakes with fruit
            'tortang talong'=> 'https://images.unsplash.com/photo-1582169296194-e4d644c48063?auto=format&fit=crop&w=600&q=80', // Filipino eggplant omelet
            'omelet'        => 'https://images.unsplash.com/photo-1510693206972-df098062cb71?auto=format&fit=crop&w=600&q=80',
            
            // Silog Meals
            'tapsilog'      => 'https://images.unsplash.com/photo-1525351484163-7529414344d8?auto=format&fit=crop&w=600&q=80',
            'bangsilog'     => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?auto=format&fit=crop&w=600&q=80',
            'tinapasilog'   => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?auto=format&fit=crop&w=600&q=80',
            'longsilog'     => 'https://images.unsplash.com/photo-1525351484163-7529414344d8?auto=format&fit=crop&w=600&q=80',
            'longganisa'    => 'https://images.unsplash.com/photo-1525351484163-7529414344d8?auto=format&fit=crop&w=600&q=80',
            'silog'         => 'https://images.unsplash.com/photo-1525351484163-7529414344d8?auto=format&fit=crop&w=600&q=80',
            
            // Grilled, Roasted & Braised Meats
            'lechon'        => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=600&q=80', // Crispy roast pork belly
            'letchon'       => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=600&q=80',
            'crispy pata'   => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=600&q=80',
            'bagnet'        => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=600&q=80',
            'inasal'        => 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?auto=format&fit=crop&w=600&q=80', // Filipino flame-grilled BBQ chicken
            'skewer'        => 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?auto=format&fit=crop&w=600&q=80',
            'ribeye'        => 'https://images.unsplash.com/photo-1558030006-450675393462?auto=format&fit=crop&w=600&q=80', // Seared steak with asparagus
            'steak'         => 'https://images.unsplash.com/photo-1558030006-450675393462?auto=format&fit=crop&w=600&q=80',
            'bistek'        => 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=600&q=80', // Sliced beef with onions
            'picadillo'     => 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=600&q=80',
            'adobo'         => 'https://images.unsplash.com/photo-1598515214211-89d3c73ae83b?auto=format&fit=crop&w=600&q=80', // Braised chicken in garlic soy glaze
            'liempo'        => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=600&q=80',
            'chicharon'     => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=600&q=80',
            
            // Seafood dishes
            'salmon teriyaki'=> 'https://images.unsplash.com/photo-1467003909585-2f8a72700288?auto=format&fit=crop&w=600&q=80',
            'salmon'        => 'https://images.unsplash.com/photo-1467003909585-2f8a72700288?auto=format&fit=crop&w=600&q=80', // Pan-seared salmon fillet
            'tuna belly'    => 'https://images.unsplash.com/photo-1501595091296-3aa970afb3ff?auto=format&fit=crop&w=600&q=80',
            'tuna'          => 'https://images.unsplash.com/photo-1501595091296-3aa970afb3ff?auto=format&fit=crop&w=600&q=80',
            'bangus'        => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?auto=format&fit=crop&w=600&q=80', // Grilled whole milkfish
            'tinapa'        => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?auto=format&fit=crop&w=600&q=80',
            
            // Healthy Snacks & Fitness Staples
            'greek yogurt'  => 'https://images.unsplash.com/photo-1488477181946-6428a0291777?auto=format&fit=crop&w=600&q=80', // Greek yogurt with blueberries
            'yogurt'        => 'https://images.unsplash.com/photo-1488477181946-6428a0291777?auto=format&fit=crop&w=600&q=80',
            'edamame'       => 'https://images.unsplash.com/photo-1556801712-76c8eb07bbc9?auto=format&fit=crop&w=600&q=80', // Bright green steamed edamame pods
            'hard-boiled egg'=> 'https://images.unsplash.com/photo-1587486913049-53fc88980cfc?auto=format&fit=crop&w=600&q=80', // Sliced boiled eggs
            'boiled egg'    => 'https://images.unsplash.com/photo-1587486913049-53fc88980cfc?auto=format&fit=crop&w=600&q=80',
            'cottage cheese'=> 'https://images.unsplash.com/photo-1550258987-190a2d41a8ba?auto=format&fit=crop&w=600&q=80', // Fresh cottage cheese with pineapple
            'pineapple'     => 'https://images.unsplash.com/photo-1550258987-190a2d41a8ba?auto=format&fit=crop&w=600&q=80',
            'protein shake' => 'https://images.unsplash.com/photo-1553530666-ba11a7da3888?auto=format&fit=crop&w=600&q=80', // Shaker smoothie
            'whey'          => 'https://images.unsplash.com/photo-1553530666-ba11a7da3888?auto=format&fit=crop&w=600&q=80',
            'smoothie'      => 'https://images.unsplash.com/photo-1553530666-ba11a7da3888?auto=format&fit=crop&w=600&q=80',
            'shake'         => 'https://images.unsplash.com/photo-1553530666-ba11a7da3888?auto=format&fit=crop&w=600&q=80',
            'kamote'        => 'https://images.unsplash.com/photo-1596040033229-a9821ebd058d?auto=format&fit=crop&w=600&q=80', // Sweet potato
            'sweet potato'  => 'https://images.unsplash.com/photo-1596040033229-a9821ebd058d?auto=format&fit=crop&w=600&q=80',
            'saba'          => 'https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?auto=format&fit=crop&w=600&q=80', // Banana
            'banana'        => 'https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?auto=format&fit=crop&w=600&q=80',
        ];

        uksort($specificDishes, fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($specificDishes as $keyword => $url) {
            if (strpos($lower, $keyword) !== false) {
                return $url;
            }
        }

        // Tier 2: General ingredient keywords (checked only if no specific dish matched)
        $generalIngredients = [
            'fried chicken' => 'https://images.unsplash.com/photo-1626082927389-6cd097cdc6ec?auto=format&fit=crop&w=600&q=80',
            'chicken'       => 'https://images.unsplash.com/photo-1598515214211-89d3c73ae83b?auto=format&fit=crop&w=600&q=80',
            'pork'          => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=600&q=80',
            'beef'          => 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=600&q=80',
            'shrimp'        => 'https://images.unsplash.com/photo-1559847844-5315695dadae?auto=format&fit=crop&w=600&q=80',
            'hipon'         => 'https://images.unsplash.com/photo-1559847844-5315695dadae?auto=format&fit=crop&w=600&q=80',
            'fish'          => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?auto=format&fit=crop&w=600&q=80',
            'talong'        => 'https://images.unsplash.com/photo-1582169296194-e4d644c48063?auto=format&fit=crop&w=600&q=80',
            'eggplant'      => 'https://images.unsplash.com/photo-1582169296194-e4d644c48063?auto=format&fit=crop&w=600&q=80',
            'egg'           => 'https://images.unsplash.com/photo-1525351484163-7529414344d8?auto=format&fit=crop&w=600&q=80',
            'tofu'          => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80',
            'oat'           => 'https://images.unsplash.com/photo-1584776296944-ab6fb57b0bdd?auto=format&fit=crop&w=600&q=80',
            'salad'         => 'https://images.unsplash.com/photo-1512621776951-a57141f2eefd?auto=format&fit=crop&w=600&q=80',
            'peanut'        => 'https://images.unsplash.com/photo-1508061253366-f7da158b6d46?auto=format&fit=crop&w=600&q=80',
            'almond'        => 'https://images.unsplash.com/photo-1508061253366-f7da158b6d46?auto=format&fit=crop&w=600&q=80',
        ];

        uksort($generalIngredients, fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($generalIngredients as $keyword => $url) {
            if (strpos($lower, $keyword) !== false) {
                return $url;
            }
        }

        $typeMap = [
            'Breakfast' => 'https://images.unsplash.com/photo-1533089860892-a7c6f0a88666?auto=format&fit=crop&w=600&q=80',
            'Lunch'     => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80',
            'Dinner'    => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?auto=format&fit=crop&w=600&q=80',
            'Snack'     => 'https://images.unsplash.com/photo-1490818387583-1baba5e638af?auto=format&fit=crop&w=600&q=80',
        ];

        $normType = ucfirst(strtolower(trim($mealType)));
        return $typeMap[$normType] ?? $typeMap['Lunch'];
    }
}

/**
 * Returns an array of compatible dietary restrictions for a given target restriction.
 * E.g. 'vegetarian' -> ['vegetarian', 'vegan']
 *      'pescatarian' -> ['pescatarian', 'vegetarian', 'vegan']
 *      'halal' -> ['halal', 'pescatarian', 'vegetarian', 'vegan']
 *      'vegan' -> ['vegan']
 *      'none' -> [] (meaning unrestricted / any)
 */
function get_compatible_dietary_restrictions(string $restriction): array
{
    $target = strtolower(trim($restriction));
    switch ($target) {
        case 'vegan':
            return ['vegan'];
        case 'vegetarian':
            return ['vegetarian', 'vegan'];
        case 'pescatarian':
            return ['pescatarian', 'vegetarian', 'vegan'];
        case 'halal':
            return ['halal', 'pescatarian', 'vegetarian', 'vegan'];
        case 'keto':
            return ['keto'];
        case 'gluten-free':
            return ['gluten-free'];
        case 'dairy-free':
            return ['dairy-free', 'vegan'];
        case 'nut-allergy':
            return ['nut-allergy'];
        case 'none':
        case '':
        case 'all':
        default:
            return [];
    }
}

/**
 * Automatically generates a tailored 7-day dietary plan adhering to the member's dietary restriction,
 * biometric targets (BMR/TDEE), primary goal, and gym food items library.
 */
function generate_dietary_plan(int $memberUserId, ?int $trainerId = null, bool $forceRegenerate = false): ?int
{
    $pdo = db();

    // 1. If not forcing regeneration, return existing active plan if present
    if (!$forceRegenerate) {
        $existingPlanId = (int) scalar('SELECT plan_id FROM dietary_plans WHERE member_user_id = ? AND status = "active" LIMIT 1', [$memberUserId]);
        if ($existingPlanId > 0) {
            return $existingPlanId;
        }
    }

    // 2. Fetch member profile
    $profile = $pdo->query('SELECT * FROM member_profiles WHERE user_id = ' . (int)$memberUserId)->fetch(PDO::FETCH_ASSOC);
    if (!$profile) {
        return null;
    }

    $goal = $profile['primary_goal'] ?: 'general_health';
    $tier = (int) ($profile['fitness_tier'] ?: 1);
    $expLevel = in_array($tier, [1, 2], true) ? 1 : (in_array($tier, [3, 4], true) ? 2 : 3);

    // Map goal to basic for diet rules
    $basicGoal = function_exists('map_detailed_goal_to_basic')
        ? map_detailed_goal_to_basic($goal)
        : ((stripos($goal, 'fat') !== false || stripos($goal, 'weight') !== false || stripos($goal, 'lean') !== false || stripos($goal, 'six-pack') !== false)
            ? 'fat_loss'
            : ((stripos($goal, 'muscle') !== false || stripos($goal, 'bicep') !== false || stripos($goal, 'chest') !== false || stripos($goal, 'bulk') !== false)
                ? 'muscle_gain'
                : 'general_health'));

    // 3. Calculate BMR (Mifflin-St Jeor formula)
    $w = (float) ($profile['weight_kg'] ?: 70);
    $h = (float) ($profile['height_cm'] ?: 170);
    $a = (int) ($profile['age'] ?: 25);
    $bmr = 10 * $w + 6.25 * $h - 5 * $a;
    $bmr += (($profile['biological_sex'] ?? 'male') === 'female') ? -161 : 5;

    // 4. Calculate TDEE
    $multipliers = [
        'sedentary' => 1.2,
        'lightly_active' => 1.375,
        'moderately_active' => 1.55,
        'very_active' => 1.725,
        'extra_active' => 1.9,
    ];
    $tdee = $bmr * ($multipliers[$profile['activity_level'] ?? 'moderately_active'] ?? 1.375);

    // 5. Goal Calorie Adjustment
    $targetCals = $tdee;
    if ($basicGoal === 'fat_loss') {
        $targetCals -= 500;
    } elseif ($basicGoal === 'muscle_gain') {
        $targetCals += 300;
    }
    $targetCals = max(1200, (int) round($targetCals));

    // 6. Macro Split
    $dRule = $pdo->prepare('SELECT macro_split FROM diet_rules WHERE primary_goal = ? AND experience_level = ? LIMIT 1');
    $dRule->execute([$basicGoal, $expLevel]);
    $rule = $dRule->fetch(PDO::FETCH_ASSOC);
    if (!$rule) {
        $dRule->execute(['general_health', 1]);
        $rule = $dRule->fetch(PDO::FETCH_ASSOC);
    }
    $splitStr = $rule['macro_split'] ?? '35% Protein / 35% Carbs / 30% Fat';
    preg_match('/(\d+)%\s+Protein\s*\/\s*(\d+)%\s+Carbs\s*\/\s*(\d+)%\s+Fat/i', $splitStr, $matches);
    $p_pct = (isset($matches[1]) ? (int)$matches[1] : 35) / 100;
    $c_pct = (isset($matches[2]) ? (int)$matches[2] : 35) / 100;
    $f_pct = (isset($matches[3]) ? (int)$matches[3] : 30) / 100;

    $p_g = (int) round(($targetCals * $p_pct) / 4);
    $c_g = (int) round(($targetCals * $c_pct) / 4);
    $f_g = (int) round(($targetCals * $f_pct) / 9);

    // 7. Archive any existing active plans
    $pdo->prepare('UPDATE dietary_plans SET status = "completed" WHERE member_user_id = ? AND status = "active"')->execute([$memberUserId]);

    // 8. Title with dietary restriction badge
    $rawRestriction = trim((string)($profile['dietary_restrictions'] ?? 'none'));
    $restriction = ($rawRestriction !== '' && strtolower($rawRestriction) !== 'none') ? strtolower($rawRestriction) : 'none';
    $restrictionLabel = ($restriction !== 'none') ? ' (' . ucwords(str_replace('-', ' ', $restriction)) . ')' : '';
    $title = 'Starter Nutrition Plan' . $restrictionLabel;

    $stmtInsert = $pdo->prepare('INSERT INTO dietary_plans (member_user_id, trainer_id, title, goal, status) VALUES (?, ?, ?, ?, "active")');
    $stmtInsert->execute([$memberUserId, $trainerId, $title, $goal]);
    $planId = (int) $pdo->lastInsertId();

    if ($planId <= 0) {
        return null;
    }

    // 9. Fetch foods filtered by gym scope and dietary restriction
    $memberGymId = (int) scalar('SELECT gym_id FROM gym_members WHERE user_id = ? LIMIT 1', [$memberUserId]);
    $compatibleDiets = get_compatible_dietary_restrictions($restriction);

    if (!empty($compatibleDiets)) {
        $inClause = implode(',', array_fill(0, count($compatibleDiets), '?'));
        $foodQuery = "
            SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc 
            FROM food_items 
            WHERE is_active = 1 
              AND (gym_id = ? OR gym_id IS NULL OR gym_id = 0)
              AND dietary_restriction IN ({$inClause})
            ORDER BY (gym_id IS NOT NULL AND gym_id > 0) DESC, RAND()
        ";
        $foodParams = array_merge([$memberGymId ?: null], $compatibleDiets);
    } else {
        $foodQuery = "
            SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc 
            FROM food_items 
            WHERE is_active = 1 
              AND (gym_id = ? OR gym_id IS NULL OR gym_id = 0)
            ORDER BY (gym_id IS NOT NULL AND gym_id > 0) DESC, (dietary_restriction = 'none') DESC, RAND()
        ";
        $foodParams = [$memberGymId ?: null];
    }

    $foodStmt = $pdo->prepare($foodQuery);
    $foodStmt->execute($foodParams);
    $dbFoods = $foodStmt->fetchAll(PDO::FETCH_ASSOC);

    $foodsByType = ['Breakfast' => [], 'Lunch' => [], 'Dinner' => [], 'Snack' => []];
    foreach ($dbFoods as $f) {
        $mt = ucfirst(strtolower((string)$f['meal_type']));
        if (isset($foodsByType[$mt])) {
            $foodsByType[$mt][] = $f;
        }
    }

    // Fallback for meal types with 0 matches
    foreach (['Breakfast', 'Lunch', 'Dinner', 'Snack'] as $mt) {
        if (empty($foodsByType[$mt])) {
            if (!empty($compatibleDiets)) {
                $inClause = implode(',', array_fill(0, count($compatibleDiets), '?'));
                $fallbackStmt = $pdo->prepare("
                    SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc 
                    FROM food_items 
                    WHERE is_active = 1 
                      AND meal_type = ? 
                      AND dietary_restriction IN ({$inClause}) 
                    ORDER BY RAND()
                ");
                $fallbackStmt->execute(array_merge([$mt], $compatibleDiets));
                $foodsByType[$mt] = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            if (empty($foodsByType[$mt])) {
                if (!in_array($restriction, ['vegetarian', 'vegan', 'halal'])) {
                    $fallbackStmt = $pdo->prepare("SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc FROM food_items WHERE is_active = 1 AND meal_type = ? ORDER BY RAND()");
                    $fallbackStmt->execute([$mt]);
                    $foodsByType[$mt] = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $fallbackStmt = $pdo->prepare("SELECT food_id, name, meal_type, serving_size, calories, protein_g, carbs_g, fat_g, image_url, recipe_desc FROM food_items WHERE is_active = 1 AND dietary_restriction IN ('vegetarian', 'vegan') ORDER BY RAND()");
                    $fallbackStmt->execute();
                    $foodsByType[$mt] = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }
    }

    // 10. Generate 7-day meal schedule
    $dist = ['Breakfast' => 0.25, 'Lunch' => 0.35, 'Dinner' => 0.30, 'Snack' => 0.10];
    $stmtMeal = $pdo->prepare('INSERT INTO dietary_plan_meals (plan_id, day_of_week, meal_type, food_items, image_url, calories, protein_g, carbs_g, fat_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

    for ($d = 1; $d <= 7; $d++) {
        foreach ($dist as $mType => $pct) {
            $mCals = (int) round($targetCals * $pct);
            $mP = (int) round($p_g * $pct);
            $mC = (int) round($c_g * $pct);
            $mF = (int) round($f_g * $pct);
            $portionGrams = max(50, (int) round($mCals / 1.5));

            $options = $foodsByType[$mType];
            $selectedFood = !empty($options) ? $options[($d - 1) % count($options)] : null;

            if ($selectedFood) {
                $mFood = $portionGrams . "g of " . $selectedFood['name'];
                $mImg = !empty($selectedFood['image_url']) 
                    ? $selectedFood['image_url'] 
                    : (function_exists('get_diet_meal_image_url') ? get_diet_meal_image_url($selectedFood['name'], $mType) : null);
            } else {
                $mFood = $portionGrams . "g of Healthy " . $mType;
                $mImg = function_exists('get_diet_meal_image_url') ? get_diet_meal_image_url($mFood, $mType) : null;
            }

            $stmtMeal->execute([$planId, $d, $mType, $mFood, $mImg, $mCals, $mP, $mC, $mF]);
        }
    }

    return $planId;
}

/**
 * Auto-checkout any unclosed attendance sessions from previous days.
 * Sets checkout time to 23:59:59 of the check-in date.
 *
 * Highly optimized for scale:
 * - Uses direct datetime comparison (`check_in_time < CURDATE()`) so MySQL uses B-Tree indexes.
 * - Targeted user checkouts execute instantly (<0.2ms) via indexed lookup.
 * - Global checkouts are throttled to run at most once every 15 minutes unless forced (e.g., by cron).
 *
 * @param mixed $userId If an int is provided, auto-closes for this specific user. If null or empty array (from Queue), closes all past-day open sessions.
 * @param bool $force If true, bypasses the 15-minute throttle for global runs.
 * @return int Number of sessions closed.
 */
function auto_checkout_past_attendance(mixed $userId = null, bool $force = false): int
{
    $pdo = db();
    try {
        if (is_array($userId)) {
            $userId = isset($userId['user_id']) ? (int) $userId['user_id'] : null;
        } elseif ($userId !== null) {
            $userId = (int) $userId;
        }

        // 1. Single User Check-Out (Targeted & Ultra-Fast)
        if ($userId !== null && $userId > 0) {
            $stmt = $pdo->prepare("
                UPDATE attendance 
                SET check_out_time = CONCAT(DATE(check_in_time), ' 23:59:59')
                WHERE user_id = ? 
                  AND check_out_time IS NULL 
                  AND check_in_time < CURDATE()
            ");
            $stmt->execute([$userId]);
            return $stmt->rowCount();
        }

        // 2. Global Check-Out (Throttled so multiple page loads don't run redundant updates)
        static $lastGlobalRun = 0;
        $now = time();
        if (!$force && ($now - $lastGlobalRun) < 900) { // 15-minute cooldown
            return 0;
        }
        $lastGlobalRun = $now;

        $stmt = $pdo->prepare("
            UPDATE attendance 
            SET check_out_time = CONCAT(DATE(check_in_time), ' 23:59:59')
            WHERE check_out_time IS NULL 
              AND check_in_time < CURDATE()
            LIMIT 1000
        ");
        $stmt->execute();
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('auto_checkout_past_attendance error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Automatically completes active equipment sessions and cancels waiting queues
 * when a member checks out of the gym.
 */
function release_user_equipment_on_checkout(int $userId, ?int $gymId = null): void
{
    if ($userId <= 0) return;
    try {
        $pdo = db();
        
        // 1. Finish any active equipment session for this user
        $sessStmt = $pdo->prepare("SELECT session_id, equipment_id, gym_id FROM equipment_sessions WHERE user_id = ? AND session_status = 'active'");
        $sessStmt->execute([$userId]);
        $activeSessions = $sessStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($activeSessions as $sess) {
            $sId = (int)$sess['session_id'];
            $eqId = (int)$sess['equipment_id'];

            $pdo->prepare("UPDATE equipment_sessions SET session_status = 'completed', end_time = NOW() WHERE session_id = ?")->execute([$sId]);
            $pdo->prepare("UPDATE gym_equipment SET current_session_id = NULL WHERE equipment_id = ?")->execute([$eqId]);

            // Promote next in line if waiting
            $next = $pdo->prepare("SELECT queue_id FROM equipment_queues WHERE equipment_id = ? AND queue_status = 'waiting' ORDER BY queue_position ASC, joined_at ASC LIMIT 1");
            $next->execute([$eqId]);
            $nextQId = (int)($next->fetchColumn() ?: 0);

            if ($nextQId > 0) {
                $pdo->prepare("UPDATE equipment_queues SET queue_status = 'notified', notified_at = NOW(), claim_deadline = DATE_ADD(NOW(), INTERVAL 2 MINUTE) WHERE queue_id = ?")->execute([$nextQId]);
            }
            $pdo->prepare("UPDATE gym_equipment SET status = 'available' WHERE equipment_id = ? AND status NOT IN ('maintenance', 'out_of_service')")->execute([$eqId]);
        }

        // 2. Cancel any waiting or notified queues for this user
        $qStmt = $pdo->prepare("SELECT queue_id, equipment_id, gym_id, queue_status FROM equipment_queues WHERE user_id = ? AND queue_status IN ('waiting', 'notified')");
        $qStmt->execute([$userId]);
        $myQueues = $qStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($myQueues as $q) {
            $qId = (int)$q['queue_id'];
            $eqId = (int)$q['equipment_id'];
            $wasNotified = ($q['queue_status'] === 'notified');

            $pdo->prepare("UPDATE equipment_queues SET queue_status = 'cancelled', resolved_at = NOW() WHERE queue_id = ?")->execute([$qId]);

            // Reorder remaining queue
            $stmtReorder = $pdo->prepare("SELECT queue_id FROM equipment_queues WHERE equipment_id = ? AND queue_status = 'waiting' ORDER BY queue_position ASC, joined_at ASC");
            $stmtReorder->execute([$eqId]);
            $items = $stmtReorder->fetchAll(PDO::FETCH_COLUMN);
            $pos = 1;
            $upd = $pdo->prepare("UPDATE equipment_queues SET queue_position = ? WHERE queue_id = ?");
            foreach ($items as $itemQid) {
                $upd->execute([$pos, $itemQid]);
                $pos++;
            }

            if ($wasNotified) {
                // Advance to next waiting member
                $next = $pdo->prepare("SELECT queue_id FROM equipment_queues WHERE equipment_id = ? AND queue_status = 'waiting' ORDER BY queue_position ASC, joined_at ASC LIMIT 1");
                $next->execute([$eqId]);
                $nextQId = (int)($next->fetchColumn() ?: 0);
                if ($nextQId > 0) {
                    $pdo->prepare("UPDATE equipment_queues SET queue_status = 'notified', notified_at = NOW(), claim_deadline = DATE_ADD(NOW(), INTERVAL 2 MINUTE) WHERE queue_id = ?")->execute([$nextQId]);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('release_user_equipment_on_checkout error: ' . $e->getMessage());
    }
}

/**
 * ============================================================================
 * TWO-WAY RATING SYSTEM HELPERS
 * 1. Members -> Gym Rating & Reviews
 * 2. Gym Owners -> FitTrack Platform Rating & Reviews
 * ============================================================================
 */

/**
 * Render visual star rating SVG icons with optional numeric score and count badge.
 */
function render_star_rating(float|int $rating, string|int $size = 'md', bool $showNumber = false, ?int $reviewCount = null, string $class = ''): string
{
    $rounded = round((float) $rating, 1);
    $fullStars = (int) floor($rounded);
    $fraction = $rounded - $fullStars;
    $hasHalf = ($fraction >= 0.25 && $fraction <= 0.75);
    if ($fraction > 0.75) {
        $fullStars++;
        $hasHalf = false;
    }
    $emptyStars = max(0, 5 - $fullStars - ($hasHalf ? 1 : 0));

    if (is_int($size)) {
        $sizePx = $size;
    } elseif (is_numeric($size)) {
        $sizePx = (int) $size;
    } else {
        $sizePx = match ($size) {
            'xs' => 12,
            'sm' => 15,
            'md' => 18,
            'lg' => 24,
            'xl' => 32,
            default => 18,
        };
    }

    $gold = '#fbbf24';
    $emptyColor = 'var(--star-empty, rgba(148, 163, 184, 0.35))';
    $uid = substr(md5((string) mt_rand()), 0, 6);

    $starSvg = '<svg width="' . $sizePx . '" height="' . $sizePx . '" viewBox="0 0 24 24" fill="' . $gold . '" style="display:inline-block;vertical-align:middle;flex-shrink:0;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
    $halfStarSvg = '<svg width="' . $sizePx . '" height="' . $sizePx . '" viewBox="0 0 24 24" style="display:inline-block;vertical-align:middle;flex-shrink:0;"><defs><linearGradient id="halfGrad_' . $uid . '"><stop offset="50%" stop-color="' . $gold . '"/><stop offset="50%" stop-color="' . $emptyColor . '"/></linearGradient></defs><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" fill="url(#halfGrad_' . $uid . ')"/></svg>';
    $emptySvg = '<svg width="' . $sizePx . '" height="' . $sizePx . '" viewBox="0 0 24 24" fill="' . $emptyColor . '" style="display:inline-block;vertical-align:middle;flex-shrink:0;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';

    $out = '<div class="star-rating-display ' . h($class) . '" style="display:inline-flex;align-items:center;gap:3px;" title="' . number_format($rounded, 1) . ' out of 5 stars">';
    $out .= str_repeat($starSvg, $fullStars);
    if ($hasHalf) {
        $out .= $halfStarSvg;
    }
    $out .= str_repeat($emptySvg, $emptyStars);

    if ($showNumber) {
        $out .= '<strong style="margin-left:6px;font-size:' . ($sizePx >= 20 ? '1.1rem' : '0.9rem') . ';color:#fbbf24;font-weight:700;">' . number_format($rounded, 1) . '</strong>';
    }
    if ($reviewCount !== null) {
        $out .= '<span style="margin-left:4px;font-size:0.82rem;color:var(--muted);font-weight:500;">(' . $reviewCount . ')</span>';
    }
    $out .= '</div>';
    return $out;
}

/**
 * ----------------------------------------------------------------------------
 * 1. MEMBERS -> GYM RATINGS
 * ----------------------------------------------------------------------------
 */

function get_gym_rating_stats(int $gymId): array
{
    $pdo = db();
    try {
        $row = $pdo->prepare('
            SELECT 
                COUNT(*) as total_reviews,
                COALESCE(AVG(rating), 0) as avg_rating,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as star_5,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as star_4,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as star_3,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as star_2,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as star_1
            FROM gym_ratings 
            WHERE gym_id = ?
        ');
        $row->execute([$gymId]);
        $stats = $row->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($stats['total_reviews'] ?? 0);
        $avg = round((float) ($stats['avg_rating'] ?? 0), 1);
        
        $breakdown = [
            5 => (int) ($stats['star_5'] ?? 0),
            4 => (int) ($stats['star_4'] ?? 0),
            3 => (int) ($stats['star_3'] ?? 0),
            2 => (int) ($stats['star_2'] ?? 0),
            1 => (int) ($stats['star_1'] ?? 0),
        ];

        $breakdownPct = [];
        foreach ($breakdown as $stars => $cnt) {
            $breakdownPct[$stars] = $total > 0 ? (int) round(($cnt / $total) * 100) : 0;
        }

        return [
            'total_reviews' => $total,
            'avg_rating' => $avg,
            'breakdown' => $breakdown,
            'breakdown_pct' => $breakdownPct,
        ];
    } catch (Throwable $e) {
        error_log('get_gym_rating_stats error: ' . $e->getMessage());
        return [
            'total_reviews' => 0,
            'avg_rating' => 0.0,
            'breakdown' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
            'breakdown_pct' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
        ];
    }
}

function get_gym_reviews(int $gymId, int $limit = 50, ?int $filterStar = null): array
{
    $pdo = db();
    try {
        $sql = '
            SELECT r.*, 
                   u.first_name, u.last_name, u.profile_picture, u.email,
                   EXISTS(SELECT 1 FROM gym_members gm WHERE gm.user_id = r.user_id AND gm.gym_id = r.gym_id) as is_enrolled
            FROM gym_ratings r
            JOIN users u ON u.user_id = r.user_id
            WHERE r.gym_id = ?
        ';
        $params = [$gymId];
        if ($filterStar !== null && $filterStar >= 1 && $filterStar <= 5) {
            $sql .= ' AND r.rating = ? ';
            $params[] = $filterStar;
        }
        $sql .= ' ORDER BY r.updated_at DESC, r.created_at DESC LIMIT ' . (int) $limit;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('get_gym_reviews error: ' . $e->getMessage());
        return [];
    }
}

function get_user_gym_review(int $userId, int $gymId): ?array
{
    try {
        $stmt = db()->prepare('SELECT * FROM gym_ratings WHERE user_id = ? AND gym_id = ? LIMIT 1');
        $stmt->execute([$userId, $gymId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable) {
        return null;
    }
}

function can_user_review_gym(int $userId, int $gymId): bool
{
    $pdo = db();
    try {
        // Enrolled member
        $enrolled = (bool) scalar('SELECT 1 FROM gym_members WHERE user_id = ? AND gym_id = ? LIMIT 1', [$userId, $gymId]);
        if ($enrolled) return true;

        // Active or past membership plan
        $hasMembership = (bool) scalar('
            SELECT 1 FROM memberships m 
            JOIN membership_plans p ON p.plan_id = m.plan_id 
            WHERE m.user_id = ? AND p.gym_id = ? LIMIT 1
        ', [$userId, $gymId]);
        if ($hasMembership) return true;

        // Checked-in / attendance records
        $hasAttended = (bool) scalar('SELECT 1 FROM attendance WHERE user_id = ? AND gym_id = ? LIMIT 1', [$userId, $gymId]);
        if ($hasAttended) return true;

        // Walk-in transactions
        $hasWalkIn = (bool) scalar('SELECT 1 FROM walk_in_transactions WHERE user_id = ? AND gym_id = ? LIMIT 1', [$userId, $gymId]);
        if ($hasWalkIn) return true;

        return false;
    } catch (Throwable) {
        return false;
    }
}

function save_gym_rating(int $userId, int $gymId, int $rating, ?string $review): array
{
    $rating = max(1, min(5, $rating));
    $cleanReview = $review !== null ? mb_substr(trim($review), 0, 3000) : null;
    if ($cleanReview === '') $cleanReview = null;

    $pdo = db();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS gym_ratings (
            rating_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            gym_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            rating TINYINT UNSIGNED NOT NULL,
            review TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_member_gym_rating (user_id, gym_id),
            INDEX idx_gym_ratings_gym (gym_id),
            INDEX idx_gym_ratings_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $stmt = $pdo->prepare('
            INSERT INTO gym_ratings (gym_id, user_id, rating, review, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                rating = VALUES(rating),
                review = VALUES(review),
                updated_at = NOW()
        ');
        $stmt->execute([$gymId, $userId, $rating, $cleanReview]);

        // Notify Gym Owner
        try {
            $ownerId = (int) scalar('SELECT owner_user_id FROM gyms WHERE gym_id = ?', [$gymId]);
            if ($ownerId > 0 && function_exists('notify_user')) {
                $reviewer = scalar('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE user_id = ?', [$userId]) ?: 'A gym member';
                $starStr = str_repeat('★', $rating);
                notify_user(
                    $ownerId,
                    'system',
                    'New Member Review',
                    "{$reviewer} submitted a {$rating}-star review ({$starStr}) for your gym." . ($cleanReview ? " \"{$cleanReview}\"" : ""),
                    $gymId
                );
            }
        } catch (Throwable) {}

        return ['success' => true, 'message' => 'Your review for this gym has been recorded.'];
    } catch (Throwable $e) {
        error_log('save_gym_rating error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to save review: ' . $e->getMessage()];
    }
}

/**
 * ----------------------------------------------------------------------------
 * 2. GYM OWNERS -> FITTRACK PLATFORM RATINGS & FEEDBACK
 * ----------------------------------------------------------------------------
 */

function get_platform_rating_stats(): array
{
    $pdo = db();
    try {
        $row = $pdo->query('
            SELECT 
                COUNT(*) as total_reviews,
                COALESCE(AVG(rating), 0) as avg_rating,
                COALESCE(AVG(system_experience), 0) as avg_system,
                COALESCE(AVG(features_rating), 0) as avg_features,
                COALESCE(AVG(service_rating), 0) as avg_service,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as star_5,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as star_4,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as star_3,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as star_2,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as star_1
            FROM platform_reviews
        ')->fetch(PDO::FETCH_ASSOC) ?: [];

        $total = (int) ($row['total_reviews'] ?? 0);
        $avg = round((float) ($row['avg_rating'] ?? 0), 1);

        $breakdown = [
            5 => (int) ($row['star_5'] ?? 0),
            4 => (int) ($row['star_4'] ?? 0),
            3 => (int) ($row['star_3'] ?? 0),
            2 => (int) ($row['star_2'] ?? 0),
            1 => (int) ($row['star_1'] ?? 0),
        ];

        return [
            'total_reviews' => $total,
            'avg_rating' => $total > 0 ? $avg : 0.0,
            'avg_system' => $total > 0 ? round((float) ($row['avg_system'] ?? 0), 1) : 0.0,
            'avg_features' => $total > 0 ? round((float) ($row['avg_features'] ?? 0), 1) : 0.0,
            'avg_service' => $total > 0 ? round((float) ($row['avg_service'] ?? 0), 1) : 0.0,
            'breakdown' => $breakdown,
        ];
    } catch (Throwable $e) {
        return [
            'total_reviews' => 0,
            'avg_rating' => 0.0,
            'avg_system' => 0.0,
            'avg_features' => 0.0,
            'avg_service' => 0.0,
            'breakdown' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
        ];
    }
}

function get_platform_reviews(int $limit = 20): array
{
    $pdo = db();
    try {
        $stmt = $pdo->prepare('
            SELECT pr.*,
                   u.first_name, u.last_name, u.profile_picture, u.email,
                   COALESCE(g.name, (SELECT g2.name FROM gyms g2 WHERE g2.owner_user_id = u.user_id LIMIT 1), "Commercial Gym Partner") as gym_name,
                   COALESCE(g.logo_url, (SELECT g2.logo_url FROM gyms g2 WHERE g2.owner_user_id = u.user_id LIMIT 1)) as gym_logo,
                   COALESCE(g.brand_color, (SELECT g2.brand_color FROM gyms g2 WHERE g2.owner_user_id = u.user_id LIMIT 1)) as gym_color
            FROM platform_reviews pr
            JOIN users u ON u.user_id = pr.user_id
            LEFT JOIN gyms g ON g.gym_id = pr.gym_id
            ORDER BY pr.updated_at DESC, pr.created_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('get_platform_reviews error: ' . $e->getMessage());
        return [];
    }
}

function get_owner_platform_review(int $userId): ?array
{
    try {
        $stmt = db()->prepare('SELECT * FROM platform_reviews WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable) {
        return null;
    }
}

function save_platform_review(int $userId, ?int $gymId, int $rating, ?string $review, ?int $systemExp = null, ?int $features = null, ?int $service = null): array
{
    $rating = max(1, min(5, $rating));
    $cleanReview = $review !== null ? mb_substr(trim($review), 0, 3000) : null;
    if ($cleanReview === '') $cleanReview = null;

    if ($systemExp !== null) $systemExp = max(1, min(5, $systemExp));
    if ($features !== null) $features = max(1, min(5, $features));
    if ($service !== null) $service = max(1, min(5, $service));

    if (!$gymId) {
        $gymId = (int) (scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ? LIMIT 1', [$userId]) ?? 0) ?: null;
    }

    $pdo = db();
    try {
        $stmt = $pdo->prepare('
            INSERT INTO platform_reviews (user_id, gym_id, rating, review, system_experience, features_rating, service_rating, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                gym_id = VALUES(gym_id),
                rating = VALUES(rating),
                review = VALUES(review),
                system_experience = VALUES(system_experience),
                features_rating = VALUES(features_rating),
                service_rating = VALUES(service_rating),
                updated_at = NOW()
        ');
        $stmt->execute([$userId, $gymId, $rating, $cleanReview, $systemExp, $features, $service]);

        if (function_exists('audit_log')) {
            audit_log($userId, 'platform_rating', 'platform', (string) $rating, json_encode([
                'rating' => $rating,
                'system_experience' => $systemExp,
                'features' => $features,
                'service' => $service,
                'review_preview' => mb_substr((string)$cleanReview, 0, 100)
            ]));
        }

        return ['success' => true, 'message' => 'Thank you for your feedback! Your review for FitTrack platform has been saved and will appear on the landing page.'];
    } catch (Throwable $e) {
        error_log('save_platform_review error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to save platform review: ' . $e->getMessage()];
    }
}

/**
 * Renders the Floating Rating Modal in the lower right for logged-in gym members.
 * Includes close (✕) button, "Don't show this again today" option, and direct rating/review action.
 */
function render_member_floating_rating_modal(array $user): void
{
    if (($user['role'] ?? '') !== 'member') {
        return;
    }

    $currentPage = $_GET['page'] ?? 'dashboard';
    // Do not show on the full view_gym page where the review form is already prominent
    if ($currentPage === 'view_gym') {
        return;
    }

    $userId = (int) ($user['user_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    // Resolve member's primary gym
    $gymId = (int) ($user['gym_id'] ?? 0);
    if (!$gymId) {
        $gymId = (int) scalar('SELECT gym_id FROM gym_members WHERE user_id = ? LIMIT 1', [$userId]);
    }
    if (!$gymId) {
        $gymId = (int) scalar('SELECT mp.gym_id FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = ? AND m.status = "active" LIMIT 1', [$userId]);
    }
    if (!$gymId) {
        $gymId = (int) scalar('SELECT gym_id FROM attendance WHERE user_id = ? ORDER BY attendance_id DESC LIMIT 1', [$userId]);
    }
    if (!$gymId) {
        return; // No gym associated yet
    }

    $gym = query_one('SELECT gym_id, name, logo_url FROM gyms WHERE gym_id = ?', [$gymId]);
    if (!$gym) {
        return;
    }

    $myRating = get_user_gym_review($userId, $gymId);
    $gymName = h($gym['name'] ?? 'Your Gym');
    $starScore = $myRating ? (float)$myRating['rating'] : 0.0;
    ?>
    <!-- FitTracks Floating Lower-Right Rating Modal for Members -->
    <style>
        #ft-floating-rating-modal {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 999999;
            width: 380px;
            max-width: calc(100vw - 32px);
            background: rgba(15, 23, 42, 0.96) !important;
            border: 1px solid rgba(255, 255, 255, 0.15) !important;
            border-radius: 16px;
            padding: 16px 18px 14px;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.6), 0 0 25px rgba(199, 255, 34, 0.18);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            transform: translateY(30px) scale(0.97);
            opacity: 0;
            pointer-events: none;
            transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.35s ease, box-shadow 0.3s ease;
            box-sizing: border-box;
            font-family: inherit;
        }
        #ft-floating-rating-modal.is-unrated {
            border-color: rgba(199, 255, 34, 0.45) !important;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.6), 0 0 25px rgba(199, 255, 34, 0.18);
        }
        #ft-floating-rating-modal.is-rated {
            border-color: rgba(255, 255, 255, 0.15) !important;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.6), 0 0 25px rgba(245, 158, 11, 0.12);
        }
        #ft-floating-rating-modal.is-active {
            transform: translateY(0) scale(1);
            opacity: 1;
            pointer-events: auto;
        }
        #ft-close-rating-modal {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #94a3b8;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        #ft-close-rating-modal:hover {
            color: #ffffff !important;
            background: rgba(255, 255, 255, 0.2) !important;
            transform: scale(1.06);
        }
        .ft-modal-quick-star {
            background: none;
            border: none;
            cursor: pointer;
            padding: 2px;
            line-height: 1;
            color: rgba(255, 255, 255, 0.25);
            transition: transform 0.15s ease, color 0.15s ease;
        }
        .ft-modal-quick-star.active,
        .ft-modal-quick-star:hover {
            color: #fbbf24 !important;
            transform: scale(1.18);
        }
        @media (max-width: 640px) {
            #ft-floating-rating-modal {
                bottom: 84px;
                right: 16px;
                left: 16px;
                width: auto;
                max-width: none;
            }
        }
        /* Base typography */
        #ft-floating-rating-modal .ft-modal-title {
            font-size: 14px;
            font-weight: 700;
            color: #ffffff;
            line-height: 1.3;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        #ft-floating-rating-modal .ft-modal-subtext {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 3px;
            line-height: 1.4;
        }
        #ft-floating-rating-modal .ft-modal-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            flex-wrap: wrap;
            gap: 8px;
        }
        #ft-floating-rating-modal .ft-modal-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            color: #94a3b8;
            cursor: pointer;
            user-select: none;
        }
        /* Light mode support */
        html[data-theme="light"] #ft-floating-rating-modal,
        [data-theme="light"] #ft-floating-rating-modal {
            background: rgba(255, 255, 255, 0.98) !important;
            border-color: #cbd5e1 !important;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.15), 0 0 20px rgba(101, 163, 13, 0.1) !important;
        }
        html[data-theme="light"] #ft-floating-rating-modal .ft-modal-title,
        [data-theme="light"] #ft-floating-rating-modal .ft-modal-title {
            color: #0f172a !important;
        }
        html[data-theme="light"] #ft-floating-rating-modal .ft-modal-subtext,
        [data-theme="light"] #ft-floating-rating-modal .ft-modal-subtext {
            color: #475569 !important;
        }
        html[data-theme="light"] #ft-floating-rating-modal .ft-modal-footer,
        [data-theme="light"] #ft-floating-rating-modal .ft-modal-footer {
            border-top-color: #e2e8f0 !important;
        }
        html[data-theme="light"] #ft-floating-rating-modal .ft-modal-label,
        [data-theme="light"] #ft-floating-rating-modal .ft-modal-label {
            color: #64748b !important;
        }
        html[data-theme="light"] #ft-floating-rating-modal .ft-modal-quick-box,
        [data-theme="light"] #ft-floating-rating-modal .ft-modal-quick-box {
            background: rgba(0, 0, 0, 0.04) !important;
            border: 1px solid rgba(0, 0, 0, 0.06);
        }
        html[data-theme="light"] #ft-floating-rating-modal .ft-modal-quick-label,
        [data-theme="light"] #ft-floating-rating-modal .ft-modal-quick-label {
            color: #64748b !important;
        }
        html[data-theme="light"] #ft-close-rating-modal,
        [data-theme="light"] #ft-close-rating-modal {
            background: #f1f5f9 !important;
            border-color: #e2e8f0 !important;
            color: #64748b !important;
        }
        html[data-theme="light"] #ft-close-rating-modal:hover,
        [data-theme="light"] #ft-close-rating-modal:hover {
            background: #e2e8f0 !important;
            color: #0f172a !important;
        }
        html[data-theme="light"] .ft-modal-quick-star {
            color: rgba(0, 0, 0, 0.2);
        }
    </style>

    <div id="ft-floating-rating-modal" class="<?= $myRating ? 'is-rated' : 'is-unrated' ?>" role="dialog" aria-labelledby="ft-floating-rating-title">
        <!-- Close (✕) button -->
        <button type="button" id="ft-close-rating-modal" aria-label="Close rating modal" title="Close">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>

        <div style="padding-right:26px;">
            <div style="min-width:0;">
                <div class="ft-modal-title" id="ft-floating-rating-title">
                    <?= $myRating ? 'Your Rating for ' . $gymName : 'Rate ' . $gymName ?>
                </div>

                <div class="ft-modal-subtext">
                    <?php if ($myRating): ?>
                        <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-top:2px;">
                            <span>You rated</span>
                            <span style="color:#fbbf24; font-weight:700;"><?= render_star_rating($starScore, 13, true) ?></span>
                        </div>
                        <span style="display:block; font-size:11px; opacity:0.85; margin-top:2px;">Contributes to your gym's score</span>
                    <?php else: ?>
                        <span>How is your experience? Help your gym grow with a quick rating!</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!$myRating): ?>
        <!-- Quick 1-5 Star Selector for unrated members -->
        <div class="ft-modal-quick-box" style="margin-top:10px; padding:8px 12px; background:rgba(255,255,255,0.04); border-radius:10px; display:flex; align-items:center; justify-content:space-between;">
            <span class="ft-modal-quick-label" style="font-size:11px; color:#94a3b8; font-weight:600;">Tap to Rate:</span>
            <div style="display:flex; align-items:center; gap:3px;" id="ft-quick-stars-picker">
                <?php for ($s = 1; $s <= 5; $s++): ?>
                    <button type="button" class="ft-modal-quick-star" data-rating="<?= $s ?>" title="<?= $s ?> Stars" aria-label="<?= $s ?> Stars">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    </button>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Bottom Row: "Don't show again today" & Action Button -->
        <div class="ft-modal-footer">
            <label class="ft-modal-label">
                <input type="checkbox" id="ft-dont-show-rating-today" style="cursor:pointer; accent-color:var(--lime); width:13.5px; height:13.5px; margin:0;">
                <span>Don't show this again today</span>
            </label>

            <a href="index.php?page=view_gym&gym_id=<?= $gymId ?>#gym-ratings-section" id="ft-btn-open-gym-review" class="btn <?= $myRating ? 'btn-secondary' : 'btn-lime' ?>" style="font-size:11.5px; padding:5px 13px; text-decoration:none; display:inline-flex; align-items:center; gap:5px; font-weight:700; border-radius:8px;">
                <span><?= $myRating ? 'Edit Review' : 'Rate Gym ★' ?></span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
        </div>
    </div>

    <script>
    (function() {
        const modal = document.getElementById('ft-floating-rating-modal');
        if (!modal) return;

        const userId = <?= $userId ?>;
        const gymId = <?= $gymId ?>;
        const storageKey = 'ft_hide_rating_modal_' + userId;
        const sessionKey = 'ft_dismissed_rating_session_' + userId;
        const today = new Date().toISOString().slice(0, 10);

        // If member chose "Don't show again today", do not show today
        if (localStorage.getItem(storageKey) === today) {
            return;
        }

        // If dismissed in this current session, don't show on intra-session reloads
        if (sessionStorage.getItem(sessionKey) === '1') {
            return;
        }

        // Show floating modal smoothly after small entrance delay
        setTimeout(() => {
            modal.classList.add('is-active');
        }, 750);

        const closeBtn = document.getElementById('ft-close-rating-modal');
        const dontShowCheckbox = document.getElementById('ft-dont-show-rating-today');
        const actionBtn = document.getElementById('ft-btn-open-gym-review');

        function dismissModal(rememberForToday) {
            modal.classList.remove('is-active');
            sessionStorage.setItem(sessionKey, '1');
            if (rememberForToday || (dontShowCheckbox && dontShowCheckbox.checked)) {
                localStorage.setItem(storageKey, today);
            }
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                dismissModal(dontShowCheckbox && dontShowCheckbox.checked);
            });
        }

        if (dontShowCheckbox) {
            dontShowCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    localStorage.setItem(storageKey, today);
                } else {
                    localStorage.removeItem(storageKey);
                }
            });
        }

        if (actionBtn) {
            actionBtn.addEventListener('click', function() {
                localStorage.setItem(storageKey, today);
            });
        }

        // Quick 1-click star rating
        const quickStars = document.querySelectorAll('.ft-modal-quick-star');
        quickStars.forEach(btn => {
            btn.addEventListener('click', function() {
                const rating = parseInt(this.getAttribute('data-rating'), 10);
                if (!rating) return;
                localStorage.setItem(storageKey, today);
                window.location.href = 'index.php?page=view_gym&gym_id=' + gymId + '&rating=' + rating + '#gym-ratings-section';
            });
        });
    })();
    </script>
    <?php
}

/**
 * Renders the Floating Platform Rating Modal in the lower right for Gym Owners.
 * Includes close (✕) button, "Don't show this again today" option, and direct feedback actions.
 */
function render_owner_floating_rating_modal(array $user): void
{
    if (!in_array(($user['role'] ?? ''), ['gym_owner', 'admin'], true)) {
        return;
    }

    $userId = (int) ($user['user_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    $myReview = get_owner_platform_review($userId);
    $starScore = $myReview ? (float)$myReview['rating'] : 0.0;
    ?>
    <!-- FitTracks Floating Lower-Right Rating Modal for Gym Owners -->
    <style>
        #ft-owner-floating-rating-modal {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 999999;
            width: 385px;
            max-width: calc(100vw - 32px);
            background: rgba(15, 23, 42, 0.96) !important;
            border: 1px solid rgba(255, 255, 255, 0.15) !important;
            border-radius: 16px;
            padding: 16px 18px 14px;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.6), 0 0 25px rgba(56, 189, 248, 0.15);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            transform: translateY(30px) scale(0.97);
            opacity: 0;
            pointer-events: none;
            transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.35s ease, box-shadow 0.3s ease;
            box-sizing: border-box;
            font-family: inherit;
        }
        #ft-owner-floating-rating-modal.is-unrated {
            border-color: rgba(56, 189, 248, 0.45) !important;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.6), 0 0 25px rgba(56, 189, 248, 0.2);
        }
        #ft-owner-floating-rating-modal.is-rated {
            border-color: rgba(255, 255, 255, 0.15) !important;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.6), 0 0 25px rgba(245, 158, 11, 0.12);
        }
        #ft-owner-floating-rating-modal.is-active {
            transform: translateY(0) scale(1);
            opacity: 1;
            pointer-events: auto;
        }
        #ft-close-owner-rating-modal {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #94a3b8;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        #ft-close-owner-rating-modal:hover {
            color: #ffffff !important;
            background: rgba(255, 255, 255, 0.2) !important;
            transform: scale(1.06);
        }
        .ft-owner-modal-quick-star {
            background: none;
            border: none;
            cursor: pointer;
            padding: 2px;
            line-height: 1;
            color: rgba(255, 255, 255, 0.25);
            transition: transform 0.15s ease, color 0.15s ease;
        }
        .ft-owner-modal-quick-star.active,
        .ft-owner-modal-quick-star:hover {
            color: #fbbf24 !important;
            transform: scale(1.18);
        }
        @media (max-width: 640px) {
            #ft-owner-floating-rating-modal {
                bottom: 84px;
                right: 16px;
                left: 16px;
                width: auto;
                max-width: none;
            }
        }
        /* Base typography */
        #ft-owner-floating-rating-modal .ft-owner-modal-title {
            font-size: 14px;
            font-weight: 700;
            color: #ffffff;
            line-height: 1.3;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        #ft-owner-floating-rating-modal .ft-owner-modal-subtext {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 3px;
            line-height: 1.4;
        }
        #ft-owner-floating-rating-modal .ft-owner-modal-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            flex-wrap: wrap;
            gap: 8px;
        }
        #ft-owner-floating-rating-modal .ft-owner-modal-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            color: #94a3b8;
            cursor: pointer;
            user-select: none;
        }
        /* Light mode support */
        html[data-theme="light"] #ft-owner-floating-rating-modal,
        [data-theme="light"] #ft-owner-floating-rating-modal {
            background: rgba(255, 255, 255, 0.98) !important;
            border-color: #cbd5e1 !important;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.15), 0 0 20px rgba(56, 189, 248, 0.12) !important;
        }
        html[data-theme="light"] #ft-owner-floating-rating-modal .ft-owner-modal-title,
        [data-theme="light"] #ft-owner-floating-rating-modal .ft-owner-modal-title {
            color: #0f172a !important;
        }
        html[data-theme="light"] #ft-owner-floating-rating-modal .ft-owner-modal-subtext,
        [data-theme="light"] #ft-owner-floating-rating-modal .ft-owner-modal-subtext {
            color: #475569 !important;
        }
        html[data-theme="light"] #ft-owner-floating-rating-modal .ft-owner-modal-footer,
        [data-theme="light"] #ft-owner-floating-rating-modal .ft-owner-modal-footer {
            border-top-color: #e2e8f0 !important;
        }
        html[data-theme="light"] #ft-owner-floating-rating-modal .ft-owner-modal-label,
        [data-theme="light"] #ft-owner-floating-rating-modal .ft-owner-modal-label {
            color: #64748b !important;
        }
        html[data-theme="light"] #ft-owner-floating-rating-modal .ft-owner-modal-quick-box,
        [data-theme="light"] #ft-owner-floating-rating-modal .ft-owner-modal-quick-box {
            background: rgba(0, 0, 0, 0.04) !important;
            border: 1px solid rgba(0, 0, 0, 0.06);
        }
        html[data-theme="light"] #ft-owner-floating-rating-modal .ft-owner-modal-quick-label,
        [data-theme="light"] #ft-owner-floating-rating-modal .ft-owner-modal-quick-label {
            color: #64748b !important;
        }
        html[data-theme="light"] #ft-close-owner-rating-modal,
        [data-theme="light"] #ft-close-owner-rating-modal {
            background: #f1f5f9 !important;
            border-color: #e2e8f0 !important;
            color: #64748b !important;
        }
        html[data-theme="light"] #ft-close-owner-rating-modal:hover,
        [data-theme="light"] #ft-close-owner-rating-modal:hover {
            background: #e2e8f0 !important;
            color: #0f172a !important;
        }
        html[data-theme="light"] .ft-owner-modal-quick-star {
            color: rgba(0, 0, 0, 0.2);
        }
    </style>

    <div id="ft-owner-floating-rating-modal" class="<?= $myReview ? 'is-rated' : 'is-unrated' ?>" role="dialog" aria-labelledby="ft-owner-floating-rating-title">
        <!-- Close (✕) button -->
        <button type="button" id="ft-close-owner-rating-modal" aria-label="Close rating modal" title="Close">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>

        <div style="padding-right:26px;">
            <div style="min-width:0;">
                <div class="ft-owner-modal-title" id="ft-owner-floating-rating-title">
                    <?= $myReview ? 'FitTrack Platform Review' : 'Rate FitTrack Platform' ?>
                </div>

                <div class="ft-owner-modal-subtext">
                    <?php if ($myReview): ?>
                        <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-top:2px;">
                            <span>You rated</span>
                            <span style="color:#fbbf24; font-weight:700;"><?= render_star_rating($starScore, 13, true) ?></span>
                        </div>
                        <span style="display:block; font-size:11px; opacity:0.85; margin-top:2px;">Featured on the public landing page</span>
                    <?php else: ?>
                        <span>How is your gym software experience? Share feedback to feature on our landing page!</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!$myReview): ?>
        <!-- Quick 1-5 Star Selector for unrated gym owners -->
        <div class="ft-owner-modal-quick-box" style="margin-top:10px; padding:8px 12px; background:rgba(255,255,255,0.04); border-radius:10px; display:flex; align-items:center; justify-content:space-between;">
            <span class="ft-owner-modal-quick-label" style="font-size:11px; color:#94a3b8; font-weight:600;">Tap to Rate:</span>
            <div style="display:flex; align-items:center; gap:3px;" id="ft-owner-quick-stars-picker">
                <?php for ($s = 1; $s <= 5; $s++): ?>
                    <button type="button" class="ft-owner-modal-quick-star" data-rating="<?= $s ?>" title="<?= $s ?> Stars" aria-label="<?= $s ?> Stars">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    </button>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Bottom Row: "Don't show again today" & Action Button -->
        <div class="ft-owner-modal-footer">
            <label class="ft-owner-modal-label">
                <input type="checkbox" id="ft-owner-dont-show-rating-today" style="cursor:pointer; accent-color:var(--lime); width:13.5px; height:13.5px; margin:0;">
                <span>Don't show this again today</span>
            </label>

            <a href="index.php?page=profile&tab=ratings_feedback#platform-feedback-card" id="ft-btn-open-owner-review" class="btn <?= $myReview ? 'btn-secondary' : 'btn-lime' ?>" style="font-size:11.5px; padding:5px 13px; text-decoration:none; display:inline-flex; align-items:center; gap:5px; font-weight:700; border-radius:8px;">
                <span><?= $myReview ? 'Edit Feedback' : 'Rate FitTrack ★' ?></span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
        </div>
    </div>

    <script>
    (function() {
        const modal = document.getElementById('ft-owner-floating-rating-modal');
        if (!modal) return;

        const userId = <?= $userId ?>;
        const storageKey = 'ft_hide_owner_rating_modal_' + userId;
        const sessionKey = 'ft_dismissed_owner_rating_session_' + userId;
        const today = new Date().toISOString().slice(0, 10);

        // If gym owner chose "Don't show again today", do not show today
        if (localStorage.getItem(storageKey) === today) {
            return;
        }

        // If dismissed in this current session, don't show on intra-session reloads
        if (sessionStorage.getItem(sessionKey) === '1') {
            return;
        }

        // Show floating modal smoothly after small entrance delay
        setTimeout(() => {
            modal.classList.add('is-active');
        }, 750);

        const closeBtn = document.getElementById('ft-close-owner-rating-modal');
        const dontShowCheckbox = document.getElementById('ft-owner-dont-show-rating-today');
        const actionBtn = document.getElementById('ft-btn-open-owner-review');

        function dismissModal(rememberForToday) {
            modal.classList.remove('is-active');
            sessionStorage.setItem(sessionKey, '1');
            if (rememberForToday || (dontShowCheckbox && dontShowCheckbox.checked)) {
                localStorage.setItem(storageKey, today);
            }
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                dismissModal(dontShowCheckbox && dontShowCheckbox.checked);
            });
        }

        if (dontShowCheckbox) {
            dontShowCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    localStorage.setItem(storageKey, today);
                } else {
                    localStorage.removeItem(storageKey);
                }
            });
        }

        if (actionBtn) {
            actionBtn.addEventListener('click', function() {
                localStorage.setItem(storageKey, today);
            });
        }

        // Quick 1-click star rating
        const quickStars = document.querySelectorAll('.ft-owner-modal-quick-star');
        quickStars.forEach(btn => {
            btn.addEventListener('click', function() {
                const rating = parseInt(this.getAttribute('data-rating'), 10);
                if (!rating) return;
                localStorage.setItem(storageKey, today);
                window.location.href = 'index.php?page=profile&tab=ratings_feedback&rating=' + rating + '#platform-feedback-card';
            });
        });
    })();
    </script>
    <?php
}



