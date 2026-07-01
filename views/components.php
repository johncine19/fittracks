<?php
declare(strict_types=1);

function render_simple_table(array $rows, array $columns): string
{
    ob_start();
    echo '<div class="table-wrap"><table><thead><tr>';
    foreach ($columns as $column) {
        echo '<th>' . h(ucwords(str_replace('_', ' ', $column))) . '</th>';
    }
    echo '</tr></thead><tbody>';
    if (!$rows) {
        table_empty(count($columns), 'No records yet.');
    }
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($columns as $column) {
            $value = $row[$column] ?? '';
            if ($column === 'price') $value = money($value);
            echo '<td>' . h((string) $value) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    return ob_get_clean();
}

function render_member_form(string $context, ?array $user = null, ?array $profile = null): void
{
    ?>
    <form method="post" class="form grid-form">
        <?= csrf_field() ?>
        <?php if ($context !== 'profile'): ?>
            <label>First name <input name="first_name" required value="<?= h($user['first_name'] ?? '') ?>"></label>
            <label>Last name  <input name="last_name"  required value="<?= h($user['last_name']  ?? '') ?>"></label>
            <label>Email      <input type="email" name="email" required value="<?= h($user['email'] ?? '') ?>"></label>
            <label>Phone
                <input name="phone" type="tel" pattern="[0-9]{11}" maxlength="11"
                       title="Please enter exactly 11 digits" placeholder="09123456789"
                       value="<?= h($user['phone'] ?? '') ?>"
                       oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)">
            </label>
            <label>Password   <input type="password" name="password" <?= $context === 'register' ? 'required minlength="8"' : '' ?> placeholder="<?= $context === 'register' ? 'Min. 8 characters' : 'Leave blank to keep current' ?>"></label>
        <?php endif; ?>
        <label>Height (cm)
            <input name="height_cm" type="number" step="0.01" min="1"
                   <?= $context !== 'profile' ? 'required' : '' ?>
                   value="<?= h($profile['height_cm'] ?? '') ?>">
        </label>
        <label>Weight (kg)
            <input name="weight_kg" type="number" step="0.01" min="1"
                   <?= $context !== 'profile' ? 'required' : '' ?>
                   value="<?= h($profile['weight_kg'] ?? '') ?>">
        </label>
        <label>Date of birth
            <input name="date_of_birth" type="date"
                   <?= $context !== 'profile' ? 'required' : '' ?>
                   value="<?= h($profile['date_of_birth'] ?? '') ?>">
        </label>
        <label>Biological sex
            <select name="biological_sex" <?= $context !== 'profile' ? 'required' : '' ?>>
                <option value="male"   <?= selected('male',   $profile['biological_sex'] ?? null) ?>>Male</option>
                <option value="female" <?= selected('female', $profile['biological_sex'] ?? null) ?>>Female</option>
            </select>
        </label>
        <label>Activity level
            <select name="activity_level" <?= $context !== 'profile' ? 'required' : '' ?>>
                <?php foreach (['sedentary', 'lightly_active', 'moderately_active', 'very_active', 'extra_active'] as $level): ?>
                    <option value="<?= h($level) ?>" <?= selected($level, $profile['activity_level'] ?? null) ?>>
                        <?= h(ucwords(str_replace('_', ' ', $level))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Primary goal
            <select name="primary_goal" <?= $context !== 'profile' ? 'required' : '' ?>>
                <?php foreach (['fat_loss', 'muscle_gain', 'maintenance', 'general_health'] as $goal): ?>
                    <option value="<?= h($goal) ?>" <?= selected($goal, $profile['primary_goal'] ?? null) ?>>
                        <?= h(ucwords(str_replace('_', ' ', $goal))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="full-width btn-primary" style="margin-top:10px;">Save</button>
    </form>
    <?php
}

function dashboard_stat(string $label, string $value, string $subtext, string $trend, string $icon, bool $featured = false): void
{
    echo '<article class="dash-stat ' . ($featured ? 'featured' : '') . '">';
    echo '<div class="stat-head"><span>' . h($label) . '</span><i>' . h($icon) . '</i></div>';
    echo '<strong>' . h($value) . '</strong>';
    echo '<p>' . h($subtext) . '</p>';
    echo '<em>' . h($trend) . '</em>';
    echo '</article>';
}

function render_current_workout(int $memberUserId, bool $withActions = true): void
{
    $stmt = db()->prepare(
        'SELECT * FROM training_plans
         WHERE member_user_id = ? AND trainer_id IS NULL
         ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([$memberUserId]);
    $plan = $stmt->fetch();

    echo '<section class="panel"><h2>Your workout plan</h2>';
    if (!$plan) {
        echo '<p class="muted">No workout plan generated yet. Save your physical profile to create one.</p></section>';
        return;
    }

    metric_cards([
        'Goal'       => ucwords(str_replace('_', ' ', (string) $plan['goal'])),
        'Status'     => $plan['status'],
        'Started'    => $plan['start_date'],
        'Training days' => workout_day_count((int) $plan['plan_id']),
    ]);

    $stmt = db()->prepare(
        'SELECT tpe.day_of_week, tpe.sequence_order, tpe.sets, tpe.reps, tpe.rest_seconds,
                e.name, e.category, e.muscle_group
         FROM training_plan_exercises tpe
         JOIN exercises e ON e.exercise_id = tpe.exercise_id
         WHERE tpe.plan_id = ?
         ORDER BY tpe.day_of_week, tpe.sequence_order'
    );
    $stmt->execute([(int) $plan['plan_id']]);
    $rows = $stmt->fetchAll();

    $grouped = [];
    foreach ($rows as $row) {
        $day = workout_day_name((int) $row['day_of_week']);
        $grouped[$day][] = $row;
    }

    foreach ($grouped as $day => $exercises) {
        echo '<h3 style="margin:1.25rem 0 0.5rem;">' . h($day) . '</h3>';
        $tableRows = array_map(static fn(array $ex): array => [
            'name'         => $ex['name'],
            'category'     => $ex['category'],
            'muscle_group' => $ex['muscle_group'],
            'sets'         => $ex['sets'],
            'reps'         => $ex['reps'],
            'rest_seconds' => $ex['rest_seconds'] . ' s',
        ], $exercises);
        echo render_simple_table($tableRows, ['name', 'category', 'muscle_group', 'sets', 'reps', 'rest_seconds']);
    }

    if (!$rows) {
        echo '<p class="muted">No exercises assigned to this plan yet.</p>';
    }

    if ($withActions) {
        echo '<p style="margin-top:1rem;"><a href="index.php?page=my_workout">View full workout plan →</a></p>';
    }
    echo '</section>';
}

function render_exercise_recommendations(int $userId, bool $compact = false): void
{
    $recs = get_exercise_recommendations($userId);
    $profile = member_profile($userId);

    echo '<section class="panel exercise-recs">';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;">';
    echo '<h2>Recommended Exercises</h2>';
    if ($profile) {
        $goalLabel = ucwords(str_replace('_', ' ', $profile['primary_goal']));
        echo '<span class="badge badge-accent" style="font-size:12px;">' . h($goalLabel) . '</span>';
    }
    echo '</div>';

    if (!$recs) {
        echo '<p class="muted">Complete your physical profile to get personalised exercise recommendations.</p>';
        echo '</section>';
        return;
    }

    echo '<p class="muted" style="font-size:13px;margin-bottom:1rem;">Based on your profile, activity level, and fitness goal.</p>';

    if ($compact) {
        // Compact card grid for dashboard
        echo '<div class="rec-grid">';
        foreach (array_slice($recs, 0, 4) as $rec) {
            $ex = $rec['exercise'];
            $priorityClass = $rec['priority'] === 'high' ? 'rec-high' : '';
            echo '<div class="rec-card ' . $priorityClass . '">';
            echo '<div class="rec-card-head">';
            echo '<strong>' . h($ex['name']) . '</strong>';
            echo '<span class="badge badge-cat badge-' . h($rec['category']) . '">' . h(ucfirst($rec['category'])) . '</span>';
            echo '</div>';
            echo '<p class="rec-muscle">' . h(ucfirst($ex['muscle_group'])) . '</p>';
            echo '<div class="rec-params">';
            echo '<span>' . (int) $rec['sets'] . ' sets</span>';
            echo '<span>' . h($rec['reps']) . '</span>';
            if ($rec['rest_seconds'] > 0) {
                echo '<span>' . (int) $rec['rest_seconds'] . 's rest</span>';
            }
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '<p style="margin-top:0.75rem;"><a href="index.php?page=my_workout">View all recommendations →</a></p>';
    } else {
        // Full table view for workout page
        echo '<div class="table-wrap"><table>';
        echo '<thead><tr><th>Exercise</th><th>Category</th><th>Muscle Group</th><th>Sets</th><th>Reps</th><th>Rest</th><th>Why Recommended</th></tr></thead>';
        echo '<tbody>';
        foreach ($recs as $rec) {
            $ex = $rec['exercise'];
            $priorityClass = $rec['priority'] === 'high' ? 'style="border-left:3px solid var(--accent);"' : '';
            echo '<tr ' . $priorityClass . '>';
            echo '<td><strong>' . h($ex['name']) . '</strong></td>';
            echo '<td><span class="badge badge-cat badge-' . h($rec['category']) . '">' . h(ucfirst($rec['category'])) . '</span></td>';
            echo '<td>' . h(ucfirst($ex['muscle_group'])) . '</td>';
            echo '<td>' . (int) $rec['sets'] . '</td>';
            echo '<td>' . h($rec['reps']) . '</td>';
            echo '<td>' . ($rec['rest_seconds'] > 0 ? (int) $rec['rest_seconds'] . 's' : '—') . '</td>';
            echo '<td class="rec-reason">' . h($rec['recommendation']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    echo '</section>';
    ?>
    <style>
        .exercise-recs .badge-accent {
            background: var(--accent, #7c5cfc);
            color: #fff;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 600;
        }
        .badge-cat {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .badge-strength { background: rgba(99,102,241,0.15); color: #6366f1; }
        .badge-cardio   { background: rgba(239,68,68,0.15);  color: #ef4444; }
        .badge-core     { background: rgba(34,197,94,0.15);  color: #22c55e; }

        .rec-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 0.75rem;
        }
        .rec-card {
            background: var(--panel-soft, #1a1a2e);
            border: 1px solid var(--line, #2a2a3e);
            border-radius: 10px;
            padding: 1rem;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .rec-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
        }
        .rec-card.rec-high {
            border-left: 3px solid var(--accent, #7c5cfc);
        }
        .rec-card-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.4rem;
        }
        .rec-card-head strong { font-size: 14px; }
        .rec-muscle {
            font-size: 12px;
            color: var(--muted);
            margin: 0 0 0.6rem;
        }
        .rec-params {
            display: flex;
            gap: 0.75rem;
            font-size: 12px;
            color: var(--muted);
        }
        .rec-params span {
            background: var(--surface-1, rgba(255,255,255,0.05));
            padding: 2px 8px;
            border-radius: 6px;
        }
        .rec-reason {
            font-size: 12px;
            color: var(--muted);
            max-width: 320px;
            line-height: 1.4;
        }
    </style>
    <?php
}


function workout_day_name(int $dayOfWeek): string
{
    return ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$dayOfWeek] ?? 'Day ' . $dayOfWeek;
}

function workout_day_count(int $planId): int
{
    return (int) scalar(
        'SELECT COUNT(DISTINCT day_of_week) FROM training_plan_exercises WHERE plan_id = ?',
        [$planId]
    );
}

function render_notification_bell(array $user, string $currentPage): void
{
    $userId = (int) $user['user_id'];
    $unread = unread_notification_count($userId);
    $items  = get_notifications($userId, 8);
    ?>
    <div class="notif-wrap" id="notif-wrap">
        <button type="button" class="notif-bell" id="notif-toggle" aria-label="Notifications" aria-expanded="false">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <?php if ($unread > 0): ?>
                <span class="notif-badge"><?= $unread > 9 ? '9+' : (int) $unread ?></span>
            <?php endif; ?>
        </button>
        <div class="notif-dropdown" id="notif-dropdown" hidden>
            <div class="notif-dropdown-head">
                <strong>Notifications</strong>
                <?php if ($unread > 0): ?>
                    <form method="post" action="index.php?page=notification_action" class="notif-mark-all">
                        <?= csrf_field() ?>
                        <input type="hidden" name="notification_action" value="mark_all_read">
                        <input type="hidden" name="return_page" value="<?= h($currentPage) ?>">
                        <button type="submit">Mark all read</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if (!$items): ?>
                <p class="notif-empty">No notifications yet.</p>
            <?php else: ?>
                <ul class="notif-menu">
                    <?php foreach ($items as $item): ?>
                        <li class="<?= $item['is_read'] ? '' : 'unread' ?>">
                            <div class="notif-menu-meta">
                                <span class="notif-type notif-type-<?= h($item['type']) ?>"><?= h(notification_type_label($item['type'])) ?></span>
                                <time><?= h(notification_time_ago($item['created_at'])) ?></time>
                            </div>
                            <strong><?= h($item['title']) ?></strong>
                            <p><?= h($item['message']) ?></p>
                            <?php if (!$item['is_read']): ?>
                                <form method="post" action="index.php?page=notification_action">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="notification_action" value="mark_read">
                                    <input type="hidden" name="notification_id" value="<?= (int) $item['notification_id'] ?>">
                                    <input type="hidden" name="return_page" value="<?= h($currentPage) ?>">
                                    <button type="submit">Mark read</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <a class="notif-view-all" href="index.php?page=notifications">View all notifications</a>
        </div>
    </div>
    <?php
}

function render_pagination(int $page, int $totalPages, string $baseUrl, string $paramName = 'p'): void
{
    if ($totalPages <= 1) return;

    $sep = str_contains($baseUrl, '?') ? '&' : '?';

    echo '<div class="pagination">';

    // Previous
    if ($page > 1) {
        echo '<a href="' . h($baseUrl . $sep . $paramName . '=' . ($page - 1)) . '" class="page-link">← Prev</a>';
    } else {
        echo '<span class="page-link disabled">← Prev</span>';
    }

    // Page numbers with ellipsis
    $start = max(1, $page - 2);
    $end   = min($totalPages, $page + 2);

    if ($start > 1) {
        echo '<a href="' . h($baseUrl . $sep . $paramName . '=1') . '" class="page-link">1</a>';
        if ($start > 2) echo '<span class="page-ellipsis">…</span>';
    }
    for ($i = $start; $i <= $end; $i++) {
        if ($i === $page) {
            echo '<span class="page-current">' . $i . '</span>';
        } else {
            echo '<a href="' . h($baseUrl . $sep . $paramName . '=' . $i) . '" class="page-link">' . $i . '</a>';
        }
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) echo '<span class="page-ellipsis">…</span>';
        echo '<a href="' . h($baseUrl . $sep . $paramName . '=' . $totalPages) . '" class="page-link">' . $totalPages . '</a>';
    }

    // Next
    if ($page < $totalPages) {
        echo '<a href="' . h($baseUrl . $sep . $paramName . '=' . ($page + 1)) . '" class="page-link">Next →</a>';
    } else {
        echo '<span class="page-link disabled">Next →</span>';
    }

    echo '</div>';
}

function render_registration_form(): void
{
    // Kept for backward compatibility — register.php now renders inline
    // but staff_apply or other callers may still reference this
    ?>
    <div class="auth-card">
        <div class="auth-card-header">
            <h1 class="auth-title">FITTRACKS</h1>
            <p class="auth-subtitle">Create your account</p>
        </div>
        <form method="post" class="auth-form" novalidate>
            <?= csrf_field() ?>
            <div class="auth-form-row">
                <div class="auth-field">
                    <label>FIRST NAME</label>
                    <div class="auth-input-group">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <input name="first_name" required placeholder="First name"
                               oninvalid="this.setCustomValidity('Please enter your first name.')"
                               oninput="this.setCustomValidity('')">
                    </div>
                </div>
                <div class="auth-field">
                    <label>LAST NAME</label>
                    <div class="auth-input-group">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <input name="last_name" required placeholder="Last name"
                               oninvalid="this.setCustomValidity('Please enter your last name.')"
                               oninput="this.setCustomValidity('')">
                    </div>
                </div>
            </div>

            <div class="auth-field">
                <label>EMAIL ADDRESS</label>
                <div class="auth-input-group">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    <input type="email" name="email" required placeholder="Enter your email"
                           oninvalid="this.setCustomValidity('Please enter a valid email address.')"
                           oninput="this.setCustomValidity('')">
                </div>
            </div>

            <div class="auth-field">
                <label>PHONE <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></label>
                <div class="auth-input-group">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                    <input name="phone" type="tel" maxlength="11" placeholder="09xxxxxxxxx"
                           oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)">
                </div>
            </div>

            <div class="auth-field">
                <label>PASSWORD</label>
                <div class="auth-input-group">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <input type="password" name="password" required minlength="8" placeholder="Min. 8 characters"
                           oninput="this.setCustomValidity(this.value.length < 8 ? 'Password must be at least 8 characters.' : '')"
                           oninvalid="this.setCustomValidity(this.value.length < 8 ? 'Password must be at least 8 characters.' : 'Please enter a password.')">
                </div>
            </div>

            <button type="submit" class="auth-submit-btn">CREATE ACCOUNT <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg></button>

            <div class="auth-form-footer">
                Already have an account? <a href="index.php?page=login">Sign in</a>
            </div>
        </form>
        <div class="corner corner-tl"></div>
        <div class="corner corner-tr"></div>
        <div class="corner corner-bl"></div>
        <div class="corner corner-br"></div>
    </div>
    <?php
}
