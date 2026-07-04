<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/workouts.php';

function workout_builder_page(): void
{
    $user = require_roles(['trainer']);
    $memberId = (int) ($_GET['member_user_id'] ?? 0);
    
    if (!$memberId) {
        redirect('dashboard');
    }
    
    $pdo = db();
    
    // Fetch member
    $member = $pdo->query('SELECT first_name, last_name, profile_picture FROM users WHERE user_id = ' . $memberId)->fetch();
    $profile = $pdo->query('SELECT primary_goal, weight_kg FROM member_profiles WHERE user_id = ' . $memberId)->fetch();
    
    // Fetch trainer ID
    $trainerProfile = $pdo->query('SELECT trainer_id FROM trainer_profiles WHERE user_id = ' . (int)$user['user_id'])->fetch();
    if (!$trainerProfile) {
        die("Trainer profile not found.");
    }
    $trainerId = (int) $trainerProfile['trainer_id'];
    
    // Check if there is an active draft plan for this member by this trainer
    $stmt = $pdo->prepare('SELECT * FROM training_plans WHERE member_user_id = ? AND trainer_id = ? AND status = "draft" LIMIT 1');
    $stmt->execute([$memberId, $trainerId]);
    $draft = $stmt->fetch();
    
    if (!$draft) {
        // Create an empty draft
        $goal = $profile['primary_goal'] ?? 'general_health';
        $title = 'Personalised Workout Plan';
        $stmt = $pdo->prepare('INSERT INTO training_plans (member_user_id, trainer_id, title, goal, start_date, status) VALUES (?, ?, ?, ?, CURDATE(), "draft")');
        $stmt->execute([$memberId, $trainerId, $title, $goal]);
        $planId = (int) $pdo->lastInsertId();
    } else {
        $planId = (int) $draft['plan_id'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        
        if ($action === 'auto_generate') {
            auto_populate_plan($planId, $memberId);
            flash('Plan auto-generated. Review before publishing.', 'success');
            redirect('workout_builder&member_user_id=' . $memberId);
        }
        
        if ($action === 'publish') {
            // Archive old active plans
            $pdo->prepare('UPDATE training_plans SET status = "archived" WHERE member_user_id = ? AND status = "active"')->execute([$memberId]);
            // Set draft to active
            $pdo->prepare('UPDATE training_plans SET status = "active" WHERE plan_id = ?')->execute([$planId]);
            
            notify_user($memberId, 'system', 'New Workout Plan!', 'Your trainer has published a new workout plan for you.');
            flash('Workout plan published successfully!', 'success');
            redirect('trainer_members');
        }

        if ($action === 'add_exercise') {
            $dayOfWeek = (int) post('day_of_week');
            $exerciseId = (int) post('exercise_id');
            $sets = (int) post('sets');
            $reps = post('reps');
            $rest = (int) post('rest_seconds');

            $maxOrder = $pdo->query("SELECT MAX(sequence_order) FROM training_plan_exercises WHERE plan_id = $planId AND day_of_week = $dayOfWeek")->fetchColumn();
            $order = $maxOrder ? $maxOrder + 1 : 1;

            $stmt = $pdo->prepare('INSERT INTO training_plan_exercises (plan_id, exercise_id, day_of_week, sequence_order, sets, reps, rest_seconds) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$planId, $exerciseId, $dayOfWeek, $order, $sets, $reps, $rest]);
            flash('Exercise added.', 'success');
            redirect('workout_builder&member_user_id=' . $memberId);
        }

        if ($action === 'remove_exercise') {
            $planExId = (int) post('plan_exercise_id');
            $pdo->prepare('DELETE FROM training_plan_exercises WHERE plan_exercise_id = ? AND plan_id = ?')->execute([$planExId, $planId]);
            flash('Exercise removed.', 'success');
            redirect('workout_builder&member_user_id=' . $memberId);
        }
        
        // API endpoint for drag & drop
        if ($action === 'reorder') {
            header('Content-Type: application/json');
            $orderData = json_decode(file_get_contents('php://input'), true);
            if (isset($orderData['items']) && is_array($orderData['items'])) {
                $targetDay = (int) ($orderData['day_of_week'] ?? 0);
                foreach ($orderData['items'] as $index => $planExId) {
                    if ($targetDay > 0) {
                        $pdo->prepare('UPDATE training_plan_exercises SET sequence_order = ?, day_of_week = ? WHERE plan_exercise_id = ? AND plan_id = ?')
                            ->execute([$index + 1, $targetDay, (int) $planExId, $planId]);
                    } else {
                        $pdo->prepare('UPDATE training_plan_exercises SET sequence_order = ? WHERE plan_exercise_id = ? AND plan_id = ?')
                            ->execute([$index + 1, (int) $planExId, $planId]);
                    }
                }
            }
            echo json_encode(['success' => true]);
            exit;
        }
    }

    // Fetch exercises for the dropdown
    $allExercises = $pdo->query('SELECT exercise_id, name, muscle_group FROM exercises ORDER BY muscle_group, name')->fetchAll();

    // Fetch assigned exercises for this draft
    $stmt = $pdo->prepare('
        SELECT tpe.*, e.name as exercise_name, e.muscle_group 
        FROM training_plan_exercises tpe
        JOIN exercises e ON tpe.exercise_id = e.exercise_id
        WHERE tpe.plan_id = ?
        ORDER BY tpe.day_of_week, tpe.sequence_order
    ');
    $stmt->execute([$planId]);
    $planExercises = $stmt->fetchAll();

    $days = [
        1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
        4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'
    ];

    render_header('Workout Builder', $user);
    ?>
    
    <!-- Load SortableJS for drag and drop -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>

    <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
        <div style="display: flex; gap: 15px; align-items: center;">
            <?= render_avatar($member, 'large') ?>
            <div>
                <h1><?= h($member['first_name'] . ' ' . $member['last_name']) ?>'s Workout Builder</h1>
                <p>Goal: <span style="color: var(--lime); font-weight: bold;"><?= h(ucwords(str_replace('_', ' ', $profile['primary_goal'] ?? ''))) ?></span> | Weight: <?= h((string)($profile['weight_kg'] ?? 'N/A')) ?> kg</p>
                <span class="badge" style="background: var(--warning); color: var(--bg);">DRAFT MODE</span>
            </div>
        </div>
        <div style="display: flex; gap: 10px;">
            <button onclick="promptAutoGenerate()" class="btn t-btn-secondary" style="background: transparent; border: 1px solid var(--lime); color: var(--lime);">
                Auto-Generate
            </button>
            <form method="post" style="margin:0;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="publish">
                <button class="btn t-btn-primary" style="background: var(--lime); color: var(--bg);">
                    Publish Plan
                </button>
            </form>
        </div>
    </div>

    <!-- Hidden form for auto-generating -->
    <form id="autoGenForm" method="post" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="auto_generate">
    </form>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 2rem;">
        <?php foreach ($days as $dayNum => $dayName): ?>
            <div class="panel" style="display: flex; flex-direction: column;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid var(--line);">
                    <h3 style="margin: 0; color: var(--lime);"><?= $dayName ?></h3>
                    <button onclick="openAddExerciseModal(<?= $dayNum ?>, '<?= $dayName ?>')" class="btn" style="padding: 4px 10px; font-size: 12px; background: transparent; border: 1px dashed var(--muted); color: var(--muted); border-radius: 4px;">
                        + Add
                    </button>
                </div>
                
                <div class="sortable-list" data-day="<?= $dayNum ?>" style="flex: 1; display: flex; flex-direction: column; gap: 10px; min-height: 50px;">
                    <?php 
                    $dayExs = array_filter($planExercises, fn($ex) => $ex['day_of_week'] == $dayNum);
                    if (empty($dayExs)): 
                    ?>
                        <div class="empty-state" style="text-align: center; color: var(--muted); padding: 20px 0; font-size: 13px; font-style: italic;">
                            Rest day. Click + Add to assign exercises.
                        </div>
                    <?php else: ?>
                        <?php foreach ($dayExs as $ex): ?>
                            <div data-id="<?= $ex['plan_exercise_id'] ?>" style="background: color-mix(in srgb, var(--bg) 50%, transparent); border: 1px solid var(--line); border-radius: 6px; padding: 10px; display: flex; justify-content: space-between; align-items: center; cursor: grab;">
                                <div>
                                    <div style="font-weight: bold; font-size: 14px; color: var(--ink);"><?= h($ex['exercise_name']) ?></div>
                                    <div style="font-size: 12px; color: var(--muted); margin-top: 4px;">
                                        <?= $ex['sets'] ?> sets &times; <?= h($ex['reps']) ?>
                                        <span style="margin: 0 5px;">|</span>
                                        Rest: <?= $ex['rest_seconds'] ?>s
                                    </div>
                                </div>
                                <form method="post" style="margin:0;" onsubmit="return confirm('Remove this exercise?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_exercise">
                                    <input type="hidden" name="plan_exercise_id" value="<?= $ex['plan_exercise_id'] ?>">
                                    <button type="submit" style="background: none; border: none; color: var(--danger); cursor: pointer; padding: 5px;">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Hidden form payload for JS Modal -->
    <div id="exerciseOptions" style="display:none;">
        <?php
        $currentGroup = '';
        foreach ($allExercises as $ex) {
            if ($currentGroup !== $ex['muscle_group']) {
                if ($currentGroup !== '') echo '</optgroup>';
                $currentGroup = $ex['muscle_group'];
                echo '<optgroup label="' . h(ucwords($currentGroup)) . '">';
            }
            echo '<option value="' . $ex['exercise_id'] . '">' . h($ex['name']) . '</option>';
        }
        if ($currentGroup !== '') echo '</optgroup>';
        ?>
    </div>

    <script>
    function promptAutoGenerate() {
        Swal.fire({
            title: 'Auto-Generate Plan?',
            text: "This will overwrite your current draft. Are you sure you want to proceed?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            confirmButtonText: 'Yes, generate it!'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('autoGenForm').submit();
            }
        });
    }

    function openAddExerciseModal(dayNum, dayName) {
        const optionsHtml = document.getElementById('exerciseOptions').innerHTML;
        
        Swal.fire({
            title: 'Add to ' + dayName,
            html: `
                <form id="addExForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 15px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_exercise">
                    <input type="hidden" name="day_of_week" value="${dayNum}">
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Exercise *
                        <select name="exercise_id" class="form-control" required style="width:100%; margin-top:5px;">
                            <option value="">Select an exercise...</option>
                            ${optionsHtml}
                        </select>
                    </label>
                    
                    <div style="display:flex; gap:10px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Sets
                            <input type="number" name="sets" value="3" class="form-control" required style="width:100%; margin-top:5px;">
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Reps
                            <input type="text" name="reps" value="10-12" class="form-control" required style="width:100%; margin-top:5px;">
                        </label>
                    </div>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Rest (seconds)
                        <select name="rest_seconds" class="form-control" style="width:100%; margin-top:5px;">
                            <option value="0">None</option>
                            <option value="30">30s</option>
                            <option value="45">45s</option>
                            <option value="60" selected>60s</option>
                            <option value="90">90s</option>
                            <option value="120">120s</option>
                        </select>
                    </label>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Add Exercise',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('addExForm');
                if (!form.exercise_id.value || !form.sets.value || !form.reps.value) {
                    Swal.showValidationMessage('Please fill out all fields');
                    return false;
                }
                form.submit();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const lists = document.querySelectorAll('.sortable-list');
        lists.forEach(list => {
            new Sortable(list, {
                group: 'shared', // Allows drag and drop between days
                animation: 150,
                ghostClass: 'sortable-ghost',
                onEnd: function (evt) {
                    const currentList = evt.to;
                    const items = Array.from(currentList.children)
                                       .filter(el => el.hasAttribute('data-id'))
                                       .map(el => el.getAttribute('data-id'));
                    
                    const targetDay = currentList.getAttribute('data-day');
                    
                    if (items.length > 0) {
                        fetch('index.php?page=workout_builder&member_user_id=<?= $memberId ?>', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?>'
                            },
                            body: JSON.stringify({
                                action: 'reorder',
                                day_of_week: targetDay,
                                items: items
                            })
                        }).then(() => {
                            if (evt.from !== evt.to) {
                                // Reload to update empty states properly if an item was completely moved
                                window.location.reload();
                            }
                        });
                    }
                }
            });
        });
    });
    </script>
    <style>
        .sortable-ghost { opacity: 0.4; background-color: var(--line); }
    </style>
    <?php
}
