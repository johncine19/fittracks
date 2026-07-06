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
            if ($column === 'price' || $column === 'revenue' || $column === 'amount') $value = money($value);
            if (in_array($column, ['action', 'actions', 'progress', 'adherence', 'feedback', 'end_date'])) {
                echo '<td>' . (string) $value . '</td>';
            } else {
                echo '<td>' . h((string) $value) . '</td>';
            }
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    return ob_get_clean();
}

function render_member_form(string $context, ?array $user = null, ?array $profile = null): void
{
    ?>
    <form method="post" class="form grid-form" onsubmit="const btn = this.querySelector('button[type=submit]'); btn.disabled = true; btn.innerHTML = '<span class=\'loader\' style=\'width:16px;height:16px;border:2px solid var(--bg);border-bottom-color:transparent;border-radius:50%;display:inline-block;box-sizing:border-box;animation:rotation 1s linear infinite;margin-right:8px;vertical-align:-2px;\'></span> Saving...';">
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
        <label>Age
            <input name="age" type="number" min="16" max="120"
                   class="input <?= isset($errors['age']) ? 'input-error' : '' ?>"
                   value="<?= h($profile['age'] ?? '') ?>">
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
    $isDown = str_contains($trend, '▼');
    echo '<article class="dash-stat ' . ($featured ? 'featured' : '') . '">';
    echo '<div class="stat-head"><span>' . h($label) . '</span><i>' . $icon . '</i></div>';
    echo '<strong>' . h($value) . '</strong>';
    echo '<p>' . h($subtext) . '</p>';
    echo '<em' . ($isDown ? ' class="trend-down"' : '') . '>' . h($trend) . '</em>';
    echo '</article>';
}

function render_current_workout(int $memberUserId, bool $dashboardMode = false, ?int $forcePlanId = null): void
{
    if ($forcePlanId) {
        $stmt = db()->prepare('SELECT * FROM training_plans WHERE plan_id = ?');
        $stmt->execute([$forcePlanId]);
        $plan = $stmt->fetch();
        if ($plan) {
            $memberUserId = (int) $plan['member_user_id'];
        }
    } else {
        $stmt = db()->prepare(
            'SELECT * FROM training_plans
             WHERE member_user_id = ? AND status = "active"
             ORDER BY plan_id DESC LIMIT 1'
        );
        $stmt->execute([$memberUserId]);
        $plan = $stmt->fetch();
    }

    echo '<section class="panel"><h2>Your workout plan</h2>';
    if (!$plan) {
        echo '<p class="muted">No workout plan generated yet. Save your physical profile to create one.</p></section>';
        return;
    }

    if (!$dashboardMode) {
        metric_cards([
            'Goal'       => ucwords(str_replace('_', ' ', (string) $plan['goal'])),
            'Status'     => $plan['status'],
            'Started'    => $plan['start_date'],
            'Training days' => workout_day_count((int) $plan['plan_id']),
        ]);
    }

    $stmt = db()->prepare(
        'SELECT tpe.exercise_id, tpe.day_of_week, tpe.sequence_order, tpe.sets, tpe.reps, tpe.rest_seconds,
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

    if ($dashboardMode) {
        $todayNum = (int) date('N');
        $todayName = workout_day_name($todayNum);
        
        echo '<h3 style="margin:1.25rem 0 0.5rem; color: var(--lime);">Today: ' . h($todayName) . '</h3>';
        
        if (isset($grouped[$todayName])) {
            $exercises = $grouped[$todayName];
            $tableRows = [];

            $stmt = db()->prepare('SELECT exercise_id FROM exercise_completions WHERE user_id = ? AND plan_id = ? AND completed_date = ?');
            $stmt->execute([$memberUserId, $plan['plan_id'], date('Y-m-d')]);
            $completedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($exercises as $ex) {
                if (in_array($ex['exercise_id'], $completedIds)) continue;

                $tableRows[] = [
                    'name'         => $ex['name'],
                    'category'     => $ex['category'],
                    'muscle_group' => $ex['muscle_group'],
                    'sets'         => $ex['sets'],
                    'reps'         => $ex['reps'],
                    'rest_seconds' => $ex['rest_seconds'] . ' s',
                    'action'       => '<button type="button" class="btn btn-primary" style="padding: 4px 10px; font-size: 12px; background: var(--lime); color: var(--bg);" onclick="completeExercise(' . $plan['plan_id'] . ', ' . $ex['exercise_id'] . ')">Complete</button>'
                ];
            }

            if ($tableRows) {
                echo render_simple_table($tableRows, ['name', 'category', 'muscle_group', 'sets', 'reps', 'rest_seconds', 'action']);
            } else if (count($exercises) > 0) {
                echo '<div style="text-align:center; padding: 2rem; background: rgba(199,255,34,0.1); border-radius: 12px; border: 1px solid rgba(199,255,34,0.3); margin-bottom: 2rem;"><h3 style="color: var(--lime); margin: 0 0 8px;">🎉 All Done!</h3><p style="margin:0; color: var(--muted);">Great job! You\'ve crushed all your exercises for today!</p></div>';
            } else {
                echo '<p class="muted" style="margin-bottom: 2rem;">No workout scheduled for today. Enjoy your rest day!</p>';
            }
        } else {
            echo '<p class="muted" style="margin-bottom: 2rem;">No workout scheduled for today. Enjoy your rest day!</p>';
        }

        echo '<p style="margin-top:1.5rem;"><a href="index.php?page=my_workout" class="btn btn-primary" style="display:inline-block; padding:8px 16px; text-decoration: none; border-radius: 6px;">View full workout plan &rarr;</a></p>';
    } else {
        $daysArray = [
            'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3,
            'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6, 'Sunday' => 7
        ];
        
        $startOfWeek = date('Y-m-d', strtotime('monday this week'));
        $endOfWeek = date('Y-m-d', strtotime('sunday this week'));
        $stmt = db()->prepare('SELECT exercise_id, completed_date FROM exercise_completions WHERE user_id = ? AND plan_id = ? AND completed_date >= ? AND completed_date <= ?');
        $stmt->execute([$memberUserId, $plan['plan_id'], $startOfWeek, $endOfWeek]);
        $completionsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $completions = [];
        foreach ($completionsRaw as $c) {
            $completions[$c['completed_date']][] = $c['exercise_id'];
        }

        echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 2rem;">';
        foreach ($daysArray as $dayName => $dayNum) {
            $exercises = $grouped[$dayName] ?? [];
            $dateForThisDay = date('Y-m-d', strtotime($dayName . ' this week'));
            $completedExIds = $completions[$dateForThisDay] ?? [];
            
            echo '<div class="panel" style="display: flex; flex-direction: column;">';
            echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid var(--line);">';
            echo '<h3 style="margin: 0; color: var(--lime);">' . h($dayName) . '</h3>';
            echo '</div>';
            
            echo '<div style="flex: 1; display: flex; flex-direction: column; gap: 10px; min-height: 50px;">';
            if (empty($exercises)) {
                echo '<div class="empty-state" style="text-align: center; color: var(--muted); padding: 20px 0; font-size: 13px; font-style: italic;">Rest day. No exercises assigned.</div>';
            } else {
                foreach ($exercises as $ex) {
                    $isCompleted = in_array($ex['exercise_id'], $completedExIds);
                    echo '<div style="background: color-mix(in srgb, var(--bg) 50%, transparent); border: 1px solid var(--line); border-radius: 6px; padding: 10px;">';
                    
                    if ($isCompleted) {
                        echo '<div style="display: flex; justify-content: space-between; align-items: flex-start;">';
                        echo '<div style="font-weight: bold; font-size: 14px; color: var(--ink); text-decoration: line-through; opacity: 0.7;">' . h($ex['name']) . '</div>';
                        echo '<svg viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="3" style="width: 18px; height: 18px; flex-shrink: 0;"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                        echo '</div>';
                    } else {
                        echo '<div style="font-weight: bold; font-size: 14px; color: var(--ink);">' . h($ex['name']) . '</div>';
                    }
                    
                    echo '<div style="font-size: 12px; color: var(--muted); margin-top: 4px;' . ($isCompleted ? ' opacity: 0.7;' : '') . '">';
                    echo $ex['sets'] . ' sets &times; ' . h($ex['reps']);
                    echo '<span style="margin: 0 5px;">|</span>';
                    echo 'Rest: ' . $ex['rest_seconds'] . 's';
                    echo '</div>';
                    echo '</div>';
                }
            }
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';

        if (!$rows) {
            echo '<p class="muted">No exercises assigned to this plan yet.</p>';
        }
    }

    if ($dashboardMode) {
        $csrfToken = csrf_token();
        echo <<<HTML
        <script>
        function completeExercise(planId, exerciseId) {
            Swal.fire({
                title: 'Completed already?',
                text: "Are you sure you want to mark this exercise as finished?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: 'var(--lime-dark)',
                cancelButtonColor: 'var(--line)',
                confirmButtonText: 'Yes, I crushed it!',
                cancelButtonText: 'No',
                background: 'var(--bg)',
                color: 'var(--ink)'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('index.php?page=complete_exercise', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'plan_id=' + planId + '&exercise_id=' + exerciseId + '&csrf_token=' + encodeURIComponent('{$csrfToken}')
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            if (data.tier_upgraded) {
                                Swal.fire({
                                    title: 'Level Up!',
                                    text: 'You have been promoted to ' + data.tier_upgraded.new_tier_name + '!',
                                    icon: 'success',
                                    background: 'var(--bg)',
                                    color: 'var(--ink)'
                                }).then(() => {
                                    window.location.reload();
                                });
                            } else {
                                window.location.reload();
                            }
                        } else {
                            Swal.fire('Error', data.message, 'error');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        Swal.fire('Error', 'Failed to complete exercise.', 'error');
                    });
                }
            });
        }
        </script>
HTML;
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
            background: var(--panel-soft);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 1rem;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        .rec-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(199, 255, 34, 0.12);
            border-color: rgba(199, 255, 34, 0.3);
        }
        .rec-card.rec-high {
            border-left: 3px solid var(--accent, #7c5cfc);
            background: linear-gradient(90deg, rgba(124,92,252,0.05) 0%, transparent 100%);
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
                    <?php foreach ($items as $item):
                        $hasLink = in_array($item['type'], ['coach_message', 'class_reminder', 'renewal_reminder', 'milestone'], true);
                        $clickUrl = $hasLink ? 'index.php?page=notification_click&nid=' . (int) $item['notification_id'] : null;
                    ?>
                        <li class="<?= $item['is_read'] ? '' : 'unread' ?>">
                            <div class="notif-menu-meta">
                                <span class="notif-type notif-type-<?= h($item['type']) ?>"><?= h(notification_type_label($item['type'])) ?></span>
                                <time><?= h(notification_time_ago($item['created_at'])) ?></time>
                            </div>
                            <?php if ($clickUrl): ?>
                                <a href="<?= h($clickUrl) ?>" class="notif-menu-link">
                                    <strong><?= h($item['title']) ?></strong>
                                    <p><?= h($item['message']) ?></p>
                                </a>
                            <?php else: ?>
                                <strong><?= h($item['title']) ?></strong>
                                <p><?= h($item['message']) ?></p>
                            <?php endif; ?>
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
    // but other callers may still reference this
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

// ═══════════════════════════════════════════════════════════════
// SKELETON LOADING HELPERS
// ═══════════════════════════════════════════════════════════════

/**
 * Renders skeleton stat cards (dashboard KPI cards)
 */
function render_skeleton_stats(int $count = 4): void
{
    echo '<div class="skeleton-wrapper"><section class="dash-grid stats-row">';
    for ($i = 0; $i < $count; $i++) {
        echo '<div class="sk-card sk-rect stat sk">';
        echo '<div class="sk sk-text short" style="margin-bottom:18px"></div>';
        echo '<div class="sk sk-title" style="height:32px;width:40%;margin-bottom:8px"></div>';
        echo '<div class="sk sk-text medium" style="height:12px"></div>';
        echo '<div class="sk sk-text short" style="height:12px;margin-top:12px"></div>';
        echo '</div>';
    }
    echo '</section></div>';
}

/**
 * Renders a skeleton table with header bar + rows
 */
function render_skeleton_table(int $cols = 6, int $rows = 8): void
{
    echo '<div class="skeleton-wrapper">';
    // Header bar
    echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding:8px 0">';
    echo '<div class="sk sk-title" style="width:120px;margin:0"></div>';
    echo '<div class="sk sk-text short" style="width:60px;margin:0;height:12px"></div>';
    echo '</div>';
    // Table header
    echo '<div class="sk-table-row" style="border-bottom:2px solid var(--line)">';
    for ($c = 0; $c < $cols; $c++) {
        $cls = $c === 0 ? 'wide' : ($c === $cols - 1 ? 'narrow' : '');
        echo '<div class="sk sk-cell ' . $cls . '"></div>';
    }
    echo '</div>';
    // Table rows
    for ($r = 0; $r < $rows; $r++) {
        echo '<div class="sk-table-row">';
        for ($c = 0; $c < $cols; $c++) {
            $cls = $c === 0 ? 'wide' : ($c === $cols - 1 ? 'narrow' : '');
            echo '<div class="sk sk-cell ' . $cls . '"></div>';
        }
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Renders skeleton list items with avatar + text lines
 */
function render_skeleton_list(int $rows = 5, string $title = ''): void
{
    echo '<div class="skeleton-wrapper">';
    if ($title) {
        echo '<div class="sk sk-title" style="width:160px;margin-bottom:14px"></div>';
    }
    echo '<div class="list-stack" style="gap:10px">';
    for ($i = 0; $i < $rows; $i++) {
        echo '<div class="sk-list-item">';
        echo '<div class="sk sk-circle"></div>';
        echo '<div class="sk-list-item-lines">';
        echo '<div class="sk sk-text medium" style="margin:0"></div>';
        echo '<div class="sk sk-text short" style="margin:0;height:11px"></div>';
        echo '</div>';
        echo '<div class="sk sk-text" style="width:60px;margin:0;height:11px"></div>';
        echo '</div>';
    }
    echo '</div></div>';
}

/**
 * Renders a skeleton chart placeholder
 */
function render_skeleton_chart(): void
{
    echo '<div class="skeleton-wrapper">';
    echo '<div class="sk-card" style="min-height:308px">';
    echo '<div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:18px">';
    echo '<div><div class="sk sk-title" style="width:140px;margin-bottom:6px"></div>';
    echo '<div class="sk sk-text short" style="height:11px"></div></div>';
    echo '<div class="sk sk-text" style="width:50px;height:24px;border-radius:999px;margin:0"></div>';
    echo '</div>';
    echo '<div class="sk sk-rect chart"></div>';
    echo '</div></div>';
}

/**
 * Renders skeleton cards in a grid
 */
function render_skeleton_cards(int $count = 6): void
{
    echo '<div class="skeleton-wrapper"><div class="sk-card-grid">';
    for ($i = 0; $i < $count; $i++) {
        echo '<div class="sk-card">';
        echo '<div style="display:flex;justify-content:space-between;margin-bottom:14px">';
        echo '<div class="sk sk-text" style="width:60px;height:20px;margin:0;border-radius:99px"></div>';
        echo '</div>';
        echo '<div class="sk sk-title" style="width:70%;margin-bottom:14px"></div>';
        echo '<div class="sk sk-text full" style="height:12px"></div>';
        echo '<div class="sk sk-text medium" style="height:12px"></div>';
        echo '<div class="sk sk-text full" style="height:12px;margin-top:14px"></div>';
        echo '<div style="margin-top:16px"><div class="sk sk-rect" style="height:38px;border-radius:8px"></div></div>';
        echo '</div>';
    }
    echo '</div></div>';
}

/**
 * Renders a chat skeleton (sidebar + messages)
 */
function render_skeleton_chat(): void
{
    echo '<div class="skeleton-wrapper">';
    echo '<section class="panel wide" style="padding:0;display:flex;height:calc(100vh - 120px);min-height:500px;overflow:hidden;border:1px solid var(--line)">';
    // Sidebar
    echo '<div style="width:300px;border-right:1px solid var(--line);display:flex;flex-direction:column">';
    echo '<div style="padding:20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center">';
    echo '<div class="sk sk-title" style="width:100px;margin:0"></div>';
    echo '<div class="sk sk-circle" style="width:24px;height:24px"></div>';
    echo '</div>';
    for ($i = 0; $i < 5; $i++) {
        echo '<div style="display:flex;gap:12px;padding:15px 20px;border-bottom:1px solid var(--line);align-items:center">';
        echo '<div class="sk sk-circle"></div>';
        echo '<div style="flex:1"><div class="sk sk-text medium" style="margin:0 0 6px"></div>';
        echo '<div class="sk sk-text short" style="height:11px;margin:0"></div></div>';
        echo '</div>';
    }
    echo '</div>';
    // Chat area
    echo '<div style="flex:1;display:flex;flex-direction:column">';
    echo '<div style="padding:15px 20px;border-bottom:1px solid var(--line);display:flex;gap:12px;align-items:center">';
    echo '<div class="sk sk-circle"></div>';
    echo '<div><div class="sk sk-text" style="width:120px;margin:0 0 4px"></div>';
    echo '<div class="sk sk-text" style="width:60px;height:11px;margin:0"></div></div>';
    echo '</div>';
    echo '<div style="flex:1;padding:20px;display:flex;flex-direction:column;gap:16px">';
    $bubbles = [
        ['left', '180px', '36px'],
        ['right', '220px', '48px'],
        ['left', '260px', '36px'],
        ['right', '140px', '36px'],
        ['left', '200px', '48px'],
        ['right', '180px', '36px'],
    ];
    foreach ($bubbles as $b) {
        echo '<div class="sk sk-chat-bubble ' . $b[0] . '" style="width:' . $b[1] . ';height:' . $b[2] . '"></div>';
    }
    echo '</div>';
    echo '<div style="padding:15px 20px;border-top:1px solid var(--line)">';
    echo '<div class="sk sk-rect" style="height:44px;border-radius:22px"></div>';
    echo '</div>';
    echo '</div>';
    echo '</section></div>';
}

/**
 * Renders skeleton notification items
 */
function render_skeleton_notifications(int $count = 5): void
{
    echo '<div class="skeleton-wrapper"><div class="notif-list">';
    for ($i = 0; $i < $count; $i++) {
        echo '<div class="sk-notif-item">';
        echo '<div style="display:flex;justify-content:space-between;margin-bottom:10px">';
        echo '<div class="sk sk-text" style="width:70px;height:20px;margin:0;border-radius:99px"></div>';
        echo '<div class="sk sk-text" style="width:50px;height:12px;margin:0"></div>';
        echo '</div>';
        echo '<div class="sk sk-text medium" style="height:15px;margin-bottom:8px"></div>';
        echo '<div class="sk sk-text full" style="height:12px"></div>';
        echo '</div>';
    }
    echo '</div></div>';
}

/**
 * Renders skeleton for profile page
 */
function render_skeleton_profile(): void
{
    echo '<div class="skeleton-wrapper"><div class="sk-card" style="text-align:center;padding:32px">';
    echo '<div class="sk sk-circle lg" style="margin:0 auto 16px"></div>';
    echo '<div class="sk sk-title" style="width:40%;margin:0 auto 8px"></div>';
    echo '<div class="sk sk-text short" style="margin:0 auto 20px"></div>';
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:400px;margin:0 auto">';
    for ($i = 0; $i < 4; $i++) {
        echo '<div class="sk sk-rect" style="height:42px;border-radius:7px"></div>';
    }
    echo '</div></div></div>';
}

/**
 * Renders a skeleton banner (welcome section)
 */
function render_skeleton_banner(): void
{
    echo '<div class="skeleton-wrapper">';
    echo '<div class="sk sk-rect banner" style="margin-bottom:24px"></div>';
    echo '</div>';
}

