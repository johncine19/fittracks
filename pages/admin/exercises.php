<?php
declare(strict_types=1);

function exercises_page(): void
{
    $user = require_roles(['admin']);
    $pdo = db();
    verify_csrf();

    $action = $_GET['action'] ?? 'list';
    $id = (int) ($_GET['id'] ?? 0);

    $catStmt = $pdo->query('SELECT DISTINCT category FROM exercises WHERE category IS NOT NULL AND category != "" ORDER BY category');
    $allCategories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $mgStmt = $pdo->query('SELECT DISTINCT muscle_group FROM exercises WHERE muscle_group IS NOT NULL AND muscle_group != "" ORDER BY muscle_group');
    $allMuscleGroups = $mgStmt->fetchAll(PDO::FETCH_COLUMN);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postAction = post('action');
        
        if ($postAction === 'create' || $postAction === 'edit') {
            $name = trim((string) post('name'));
            $category = trim((string) post('category'));
            $muscle_group = trim((string) post('muscle_group'));
            $description = trim((string) post('description'));
            
            if (!$name) {
                flash('Exercise name is required.', 'danger');
            } else {
                if ($postAction === 'create') {
                    $stmt = $pdo->prepare('INSERT INTO exercises (name, category, muscle_group, description) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$name, $category, $muscle_group, $description]);
                    flash('Exercise created successfully.');
                } else {
                    $stmt = $pdo->prepare('UPDATE exercises SET name = ?, category = ?, muscle_group = ?, description = ? WHERE exercise_id = ?');
                    $stmt->execute([$name, $category, $muscle_group, $description, $id]);
                    flash('Exercise updated successfully.');
                }
                redirect('exercises');
            }
        } elseif ($postAction === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM exercises WHERE exercise_id = ?');
            $stmt->execute([$id]);
            flash('Exercise deleted successfully.');
            redirect('exercises');
        }
    }

    render_header('Exercises Management', $user);
    
    echo '<datalist id="categoriesList">';
    foreach ($allCategories as $c) echo '<option value="' . h($c) . '">';
    echo '</datalist>';
    
    echo '<datalist id="muscleGroupsList">';
    foreach ($allMuscleGroups as $mg) echo '<option value="' . h($mg) . '">';
    echo '</datalist>';

    echo '<section class="panel">';
    
    if ($action === 'create' || $action === 'edit') {
        $ex = null;
        if ($action === 'edit') {
            $stmt = $pdo->prepare('SELECT * FROM exercises WHERE exercise_id = ?');
            $stmt->execute([$id]);
            $ex = $stmt->fetch();
            if (!$ex) redirect('exercises');
        }
        
        $title = $action === 'create' ? 'Add Exercise' : 'Edit Exercise';
        echo '<div class="page-header"><h2>' . h($title) . '</h2><a href="index.php?page=exercises" class="btn btn-secondary">Back to List</a></div>';
        
        echo '<form method="post" class="form-grid">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="' . h($action) . '">';
        
        echo '<div class="form-group"><label>Name *</label><input type="text" name="name" class="form-control" required value="' . h($ex['name'] ?? '') . '"></div>';
        echo '<div class="form-group"><label>Category</label><input type="text" name="category" list="categoriesList" class="form-control" value="' . h($ex['category'] ?? '') . '"></div>';
        echo '<div class="form-group"><label>Muscle Group</label><input type="text" name="muscle_group" list="muscleGroupsList" class="form-control" value="' . h($ex['muscle_group'] ?? '') . '"></div>';
        echo '<div class="form-group"><label>Description</label><textarea name="description" class="form-control" rows="3">' . h($ex['description'] ?? '') . '</textarea></div>';
        
        echo '<div class="form-actions"><button type="submit" class="btn btn-primary">Save Exercise</button></div>';
        echo '</form>';
        
    } else {
        $q = trim((string) ($_GET['q'] ?? ''));
        $cat = trim((string) ($_GET['cat'] ?? ''));

        $csrf = csrf_field();
        echo '<div class="page-header"><h2>Exercises</h2><button type="button" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold; padding: 8px 16px; border: none; cursor: pointer; border-radius: 6px;" onclick="showAddExerciseModal()">Add Exercise</button></div>';
        
        echo <<<HTML
        <script>
        function showAddExerciseModal() {
            Swal.fire({
                title: 'Add New Exercise',
                html: `
                    <form id="addExForm" method="post" action="index.php?page=exercises" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                        {$csrf}
                        <input type="hidden" name="action" value="create">
                        <div>
                            <label style="display:block; margin-bottom: 4px; color: var(--muted); font-size: 14px;">Name *</label>
                            <input type="text" name="name" class="form-control" required style="width: 100%; box-sizing: border-box;" placeholder="e.g. Squat">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom: 4px; color: var(--muted); font-size: 14px;">Category</label>
                            <input type="text" name="category" list="categoriesList" class="form-control" style="width: 100%; box-sizing: border-box;" placeholder="e.g. strength">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom: 4px; color: var(--muted); font-size: 14px;">Muscle Group</label>
                            <input type="text" name="muscle_group" list="muscleGroupsList" class="form-control" style="width: 100%; box-sizing: border-box;" placeholder="e.g. legs">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom: 4px; color: var(--muted); font-size: 14px;">Description</label>
                            <textarea name="description" class="form-control" rows="3" style="width: 100%; box-sizing: border-box;"></textarea>
                        </div>
                    </form>
                `,
                showCancelButton: true,
                confirmButtonColor: 'var(--lime-dark)',
                cancelButtonColor: 'var(--line)',
                confirmButtonText: 'Save Exercise',
                background: 'var(--bg)',
                color: 'var(--ink)',
                preConfirm: () => {
                    const form = document.getElementById('addExForm');
                    if (!form.name.value) {
                        Swal.showValidationMessage('Exercise name is required');
                        return false;
                    }
                    form.submit();
                }
            });
        }
        </script>
HTML;
        
        echo '<form method="get" style="display:flex; gap:10px; margin-bottom: 20px;">';
        echo '<input type="hidden" name="page" value="exercises">';
        echo '<input type="text" name="q" class="form-control" placeholder="Search by name..." value="' . h($q) . '" style="max-width:300px;">';
        
        echo '<select name="cat" class="form-control" style="max-width:200px;">';
        echo '<option value="">All Categories</option>';
        foreach ($allCategories as $c) {
            echo '<option value="' . h($c) . '" ' . selected($cat, $c) . '>' . h(ucfirst($c)) . '</option>';
        }
        echo '</select>';
        
        echo '<button type="submit" class="btn btn-primary" style="background: var(--lime); color: var(--bg); font-weight: bold;">Filter</button>';
        if ($q || $cat) {
            echo '<a href="index.php?page=exercises" class="btn" style="background: transparent; border: 1px solid var(--line); color: var(--muted); text-decoration: none; display: flex; align-items: center; padding: 0 16px;">Clear</a>';
        }
        echo '</form>';
        
        $sql = 'SELECT * FROM exercises WHERE 1=1';
        $params = [];
        
        if ($q !== '') {
            $sql .= ' AND name LIKE ?';
            $params[] = '%' . $q . '%';
        }
        if ($cat !== '') {
            $sql .= ' AND category = ?';
            $params[] = $cat;
        }
        $sql .= ' ORDER BY name ASC';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $exercises = $stmt->fetchAll();
        
        $tableRows = array_map(static fn(array $ex): array => [
            'name'         => $ex['name'],
            'category'     => $ex['category'],
            'muscle_group' => $ex['muscle_group'],
            'description'  => $ex['description'],
            'actions'      => '<a href="index.php?page=exercises&action=edit&id=' . $ex['exercise_id'] . '" class="btn btn-secondary" style="padding:4px 8px;font-size:12px;">Edit</a> ' . 
                              '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete this exercise?\');">' . 
                              csrf_field() . 
                              '<input type="hidden" name="action" value="delete">' . 
                              '<button type="submit" formaction="index.php?page=exercises&id=' . $ex['exercise_id'] . '" class="btn btn-danger" style="padding:4px 8px;font-size:12px;">Delete</button></form>'
        ], $exercises);
        
        echo render_simple_table($tableRows, ['name', 'category', 'muscle_group', 'description', 'actions']);
    }
    
    echo '</section>';
    render_footer();
}
