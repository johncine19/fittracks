<?php
declare(strict_types=1);

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

function money(float|string|null $value): string
{
    return 'PHP ' . number_format((float) $value, 2);
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
    header('Location: index.php?page=' . urlencode($page));
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
        // Activity / pulse — Progress
        'progress' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
        // Dumbbell — Training
        'training' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v16"/><path d="M10 4v16"/><path d="M6 12h4"/><path d="M14 4v16"/><path d="M18 4v16"/><path d="M14 12h4"/></svg>',
        // Message bubble — Messages
        'messages' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
    ];
    return $icons[$key] ?? '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/></svg>';
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
        return '<img src="assets/uploads/' . h($row['profile_picture']) . '" alt="' . h($ini) . '" class="avatar ' . $size . '" style="object-fit:cover;width:' . $dim . ';height:' . $dim . ';">';
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
