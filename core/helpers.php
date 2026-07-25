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
        'member_transfers' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>',
        'announcements' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>',
        'gym_profile' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><line x1="9" y1="22" x2="9" y2="22"/><line x1="15" y1="22" x2="15" y2="22"/><line x1="9" y1="6" x2="9" y2="6"/><line x1="15" y1="6" x2="15" y2="6"/><line x1="9" y1="10" x2="9" y2="10"/><line x1="15" y1="10" x2="15" y2="10"/><line x1="9" y1="14" x2="9" y2="14"/><line x1="15" y1="14" x2="15" y2="14"/><line x1="9" y1="18" x2="9" y2="18"/><line x1="15" y1="18" x2="15" y2="18"/></svg>',
        'admin_workouts' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><line x1="8" y1="11" x2="16" y2="11"/><line x1="8" y1="15" x2="16" y2="15"/></svg>',
        'diet' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>',
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

function get_recommendations_by_goal(PDO $pdo, string $detailedGoal): array
{
    $basicGoal = map_detailed_goal_to_basic($detailedGoal);
    
    // Define keywords based on basic goal
    $keywords = [];
    if ($basicGoal === 'fat_loss') {
        $keywords = ['hiit', 'cardio', 'zumba', 'burn', 'fat', 'sweat', 'core', 'abs', 'cycle', 'spin'];
    } elseif ($basicGoal === 'muscle_gain') {
        $keywords = ['strength', 'weight', 'power', 'lift', 'crossfit', 'bodybuilding', 'hypertrophy', 'muscle'];
    } elseif ($basicGoal === 'general_health' || $basicGoal === 'maintenance') {
        $keywords = ['yoga', 'pilates', 'wellness', 'stretch', 'balance', 'flow', 'mobility', 'health'];
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
        // Find gym of active membership first
        $gym = $pdo->query("SELECT g.* FROM gyms g JOIN membership_plans mp ON mp.gym_id = g.gym_id JOIN memberships m ON m.plan_id = mp.plan_id WHERE m.user_id = $userId AND m.status = 'active' ORDER BY m.membership_id DESC LIMIT 1")->fetch();
        if ($gym) return $gym;
        
        // Fallback to any pending membership gym
        $gym = $pdo->query("SELECT g.* FROM gyms g JOIN membership_plans mp ON mp.gym_id = g.gym_id JOIN memberships m ON m.plan_id = mp.plan_id WHERE m.user_id = $userId AND m.status = 'pending' ORDER BY m.membership_id DESC LIMIT 1")->fetch();
        if ($gym) return $gym;

        // Fallback to gym_members direct association (covers walk-ins, QR check-ins, expired memberships)
        $gym = $pdo->query("SELECT g.* FROM gyms g JOIN gym_members gm ON gm.gym_id = g.gym_id WHERE gm.user_id = $userId LIMIT 1")->fetch();
        if ($gym) return $gym;
    }
    
    return null;
}

