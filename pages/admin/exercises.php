<?php
declare(strict_types=1);

function exercises_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner']);
    $pdo = db();
    verify_csrf();

    $gymId = null;
    $gyms = [];
    if ($user['role'] === 'gym_owner') {
        $gymId = scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
        if (!$gymId) {
            flash('No gym found for this owner.', 'danger');
            redirect('dashboard');
        }
    } else {
        // Platform admin can select a gym when creating an exercise
        $gyms = $pdo->query('SELECT gym_id, name FROM gyms ORDER BY name ASC')->fetchAll();
    }

    $action = $_GET['action'] ?? 'list';
    
    // Build filter queries based on role
    $catQuery = 'SELECT DISTINCT category FROM exercises WHERE category IS NOT NULL AND category != ""';
    $mgQuery = 'SELECT DISTINCT muscle_group FROM exercises WHERE muscle_group IS NOT NULL AND muscle_group != ""';
    $filterParams = [];
    if ($gymId) {
        $catQuery .= ' AND gym_id = ?';
        $mgQuery .= ' AND gym_id = ?';
        $filterParams[] = $gymId;
    }
    $catQuery .= ' ORDER BY category';
    $mgQuery .= ' ORDER BY muscle_group';
    
    $catStmt = $pdo->prepare($catQuery);
    $catStmt->execute($filterParams);
    $allCategories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $mgStmt = $pdo->prepare($mgQuery);
    $mgStmt->execute($filterParams);
    $allMuscleGroups = $mgStmt->fetchAll(PDO::FETCH_COLUMN);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postAction = post('action');
        
        if ($postAction === 'create' || $postAction === 'edit') {
            $name = trim((string) post('name'));
            $category = trim((string) post('category'));
            $muscle_group = trim((string) post('muscle_group'));
            $description = trim((string) post('description'));
            
            // For platform admin creating an exercise, they must pick a gym
            $targetGymId = $gymId;
            if (!$targetGymId && $user['role'] === 'platform_admin') {
                $targetGymId = (int) post('gym_id');
            }
            
            if (!$name) {
                flash('Exercise name is required.', 'danger');
            } elseif (!$targetGymId) {
                flash('You must select a gym for this exercise.', 'danger');
            } else {
                if ($postAction === 'create') {
                    $stmt = $pdo->prepare('INSERT INTO exercises (name, category, muscle_group, description, gym_id) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([$name, $category, $muscle_group, $description, $targetGymId]);
                    audit_log($user['user_id'], 'create', 'exercise', (string) $pdo->lastInsertId(), json_encode(['name' => $name, 'category' => $category, 'gym_id' => $targetGymId]));
                    flash('Exercise created successfully.');
                } else {
                    $editId = (int) post('exercise_id');
                    // Ensure permission
                    $exGymId = scalar('SELECT gym_id FROM exercises WHERE exercise_id = ?', [$editId]);
                    if ($user['role'] === 'gym_owner' && (string)$exGymId !== (string)$gymId) {
                        flash('Permission denied.', 'danger');
                    } else {
                        $stmt = $pdo->prepare('UPDATE exercises SET name = ?, category = ?, muscle_group = ?, description = ? WHERE exercise_id = ?');
                        $stmt->execute([$name, $category, $muscle_group, $description, $editId]);
                        audit_log($user['user_id'], 'edit', 'exercise', (string) $editId, json_encode(['name' => $name]));
                        flash('Exercise updated successfully.');
                    }
                }
                redirect('exercises');
            }
        } elseif ($postAction === 'delete') {
            $delId = (int) post('exercise_id');
            $exGymId = scalar('SELECT gym_id FROM exercises WHERE exercise_id = ?', [$delId]);
            if ($user['role'] === 'gym_owner' && (string)$exGymId !== (string)$gymId) {
                flash('Permission denied.', 'danger');
            } else {
                $stmt = $pdo->prepare('DELETE FROM exercises WHERE exercise_id = ?');
                $stmt->execute([$delId]);
                audit_log($user['user_id'], 'delete', 'exercise', (string) $delId);
                flash('Exercise deleted successfully.');
            }
            redirect('exercises');
        }
    }

    render_header('Exercises', $user);
    
    echo '<datalist id="categoriesList">';
    foreach ($allCategories as $c) echo '<option value="' . h($c) . '">';
    echo '</datalist>';
    
    echo '<datalist id="muscleGroupsList">';
    foreach ($allMuscleGroups as $mg) echo '<option value="' . h($mg) . '">';
    echo '</datalist>';

    $q = trim((string) ($_GET['q'] ?? ''));
    $cat = trim((string) ($_GET['cat'] ?? ''));

    $csrfStr = csrf_field();

    $sql = 'SELECT e.*, g.name AS gym_name FROM exercises e LEFT JOIN gyms g ON e.gym_id = g.gym_id WHERE 1=1';
    $countSql = 'SELECT COUNT(*) FROM exercises e WHERE 1=1';
    $params = [];
    
    if ($gymId) {
        $sql .= ' AND e.gym_id = ?';
        $countSql .= ' AND e.gym_id = ?';
        $params[] = $gymId;
    }
    
    if ($q !== '') {
        $sql .= ' AND e.name LIKE ?';
        $countSql .= ' AND e.name LIKE ?';
        $params[] = '%' . $q . '%';
    }
    if ($cat !== '') {
        $sql .= ' AND e.category = ?';
        $countSql .= ' AND e.category = ?';
        $params[] = $cat;
    }
    
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $total = (int) $stmtCount->fetchColumn();
    
    $pageNum = max(1, (int)($_GET['p'] ?? 1));
    $limit = 12;
    $offset = ($pageNum - 1) * $limit;
    $totalPages = (int) ceil($total / $limit);

    $sql .= ' ORDER BY e.name ASC LIMIT ' . $limit . ' OFFSET ' . $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $exercises = $stmt->fetchAll();
    ?>

    <style>
        .ex-card { background: var(--panel-soft); border: 1px solid var(--line); border-radius: 16px; padding: 20px; transition: all 0.2s ease; display: flex; flex-direction: column; gap: 12px; position: relative; overflow: hidden; }
        .ex-card:hover { border-color: rgba(255,255,255,0.15); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.2); }
        .ex-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr)); gap: 16px; }
        .ex-pill { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .ex-pill.category { background: rgba(66,219,165,0.1); color: #42dba5; }
        .ex-pill.muscle { background: rgba(199,255,34,0.1); color: var(--lime); }
    </style>

    <div>
        <!-- Glassmorphic Banner -->
        <div class="animate-fade-in" style="background: linear-gradient(135deg, rgba(66,219,165,0.1) 0%, rgba(199,255,34,0.05) 100%); border: 1px solid rgba(66,219,165,0.2); border-radius: 16px; padding: 28px 32px; margin-bottom: 24px; box-shadow: 0 4px 24px rgba(0,0,0,0.1); backdrop-filter: blur(16px); display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1 style="margin: 0; font-size: 26px; color: var(--ink); display: flex; align-items: center; gap: 12px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#42dba5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                    Exercises Database
                </h1>
                <p style="margin: 8px 0 0 0; color: var(--muted); font-size: 15px; max-width: 600px;">
                    Manage the global library of exercises used for workout templates.
                </p>
            </div>
            <div style="display:flex; gap:12px;">
                <button type="button" onclick="document.getElementById('exModal').showModal(); document.getElementById('exForm').reset(); document.getElementById('exFormAction').value='create'; document.getElementById('exModalTitle').innerText='Add New Exercise';" style="background: var(--lime); border: none; color: var(--bg); padding: 10px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: opacity 0.2s;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    New Exercise
                </button>
            </div>
        </div>

        <form method="get" class="animate-fade-in" style="display:flex; gap:12px; margin-bottom: 24px; align-items: center; flex-wrap: wrap;">
            <input type="hidden" name="page" value="exercises">
            <div style="position: relative; max-width: 300px; flex-grow: 1;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%);"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" name="q" class="form-control" placeholder="Search exercises..." value="<?= h($q) ?>" style="padding-left: 40px; width: 100%;">
            </div>
            
            <select name="cat" class="form-control" style="max-width:200px;">
                <option value="">All Categories</option>
                <?php foreach ($allCategories as $c): ?>
                    <option value="<?= h($c) ?>" <?= selected($cat, $c) ?>><?= h(ucfirst($c)) ?></option>
                <?php endforeach; ?>
            </select>
            
            <button type="submit" class="btn" style="background: var(--surface); color: var(--ink); border: 1px solid var(--line); font-weight: 500;">Filter</button>
            <?php if ($q || $cat): ?>
                <a href="index.php?page=exercises" class="btn" style="background: transparent; border: none; color: var(--muted); text-decoration: none; padding: 0 8px;">Clear</a>
            <?php endif; ?>
        </form>

        <div class="animate-fade-in">
            <?php if (!$exercises): ?>
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <p>No exercises found matching your criteria.</p>
                </div>
            <?php else: ?>
                <div class="ex-grid">
                    <?php foreach ($exercises as $ex): $safeJson = htmlspecialchars(json_encode($ex)); ?>
                        <div class="ex-card">
                            <div style="display:flex; justify-content: space-between; align-items: flex-start;">
                                <div>
                                    <h3 style="margin:0; font-size:18px; color:var(--ink)"><?= h($ex['name']) ?></h3>
                                    <?php if ($user['role'] === 'platform_admin' && $ex['gym_name']): ?>
                                        <div style="font-size: 12px; color: var(--muted); margin-top: 4px;">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                                            <?= h($ex['gym_name']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div style="display:flex; gap:6px;">
                                    <button onclick="editExercise(<?= $safeJson ?>)" style="background:transparent; border:none; color:var(--muted); cursor:pointer; padding:4px;" title="Edit"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>
                                    <form method="post" onsubmit="return confirm('Are you sure you want to delete this exercise?');" style="margin:0;">
                                        <?= $csrfStr ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="exercise_id" value="<?= $ex['exercise_id'] ?>">
                                        <button type="submit" style="background:transparent; border:none; color:var(--danger); cursor:pointer; padding:4px;" title="Delete"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
                                    </form>
                                </div>
                            </div>
                            
                            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top: 4px;">
                                <?php if ($ex['category']): ?>
                                    <span class="ex-pill category"><?= h($ex['category']) ?></span>
                                <?php endif; ?>
                                <?php if ($ex['muscle_group']): ?>
                                    <span class="ex-pill muscle"><?= h($ex['muscle_group']) ?></span>
                                <?php endif; ?>
                            </div>

                            <p style="margin:0; font-size:14px; color:var(--muted); flex-grow:1; margin-top: 8px;">
                                <?= h($ex['description'] ?: 'No description provided.') ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div style="margin-top: 32px;">
                        <?php 
                        $queryString = '&q=' . urlencode($q) . '&cat=' . urlencode($cat);
                        render_pagination($pageNum, $totalPages, '?page=exercises' . $queryString); 
                        ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal -->
    <dialog id="exModal" class="modal">
        <div class="modal-header">
            <h3 id="exModalTitle" style="margin:0; font-size:20px;">Exercise</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('exModal').close()">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="modal-body">
            <form id="exForm" method="post" action="index.php?page=exercises" style="display:flex; flex-direction:column; gap:16px;">
                <?= $csrfStr ?>
                <input type="hidden" name="action" id="exFormAction" value="create">
                <input type="hidden" name="exercise_id" id="exFormId" value="">
                
                <label>Name * <input type="text" name="name" id="exName" class="form-control" placeholder="e.g. Barbell Squat" required></label>
                
                <?php if ($user['role'] === 'platform_admin'): ?>
                <label>Assign to Gym *
                    <select name="gym_id" id="exGymId" class="form-control" required>
                        <option value="">-- Select a Gym --</option>
                        <?php foreach ($gyms as $g): ?>
                            <option value="<?= $g['gym_id'] ?>"><?= h($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <label>Category
                        <input type="text" name="category" id="exCat" list="categoriesList" class="form-control" placeholder="e.g. Strength">
                    </label>
                    <label>Muscle Group
                        <input type="text" name="muscle_group" id="exMuscle" list="muscleGroupsList" class="form-control" placeholder="e.g. Legs">
                    </label>
                </div>
                
                <label>Description <textarea name="description" id="exDesc" class="form-control" placeholder="Form cues or notes..." rows="3"></textarea></label>
                
                <button type="submit" class="btn btn-primary" style="margin-top:8px;">Save Exercise</button>
            </form>
        </div>
    </dialog>

    <script>
    function editExercise(ex) {
        document.getElementById('exForm').reset();
        document.getElementById('exFormAction').value = 'edit';
        document.getElementById('exModalTitle').innerText = 'Edit Exercise';
        
        document.getElementById('exFormId').value = ex.exercise_id;
        document.getElementById('exName').value = ex.name;
        document.getElementById('exCat').value = ex.category || '';
        document.getElementById('exMuscle').value = ex.muscle_group || '';
        document.getElementById('exDesc').value = ex.description || '';
        
        var gymIdSelect = document.getElementById('exGymId');
        if (gymIdSelect) {
            gymIdSelect.value = ex.gym_id || '';
        }
        
        document.getElementById('exModal').showModal();
    }
    </script>
    <?php
    render_footer();
}
