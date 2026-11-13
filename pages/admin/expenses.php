<?php
declare(strict_types=1);

function expenses_table_exists(): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $stmt = db()->query("SHOW TABLES LIKE 'gym_expenses'");
        $exists = (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        $exists = false;
    }
    return $exists;
}

function expenses_categories_table_exists(): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $stmt = db()->query("SHOW TABLES LIKE 'gym_expense_categories'");
        $exists = (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        $exists = false;
    }
    return $exists;
}

function expenses_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner', 'staff']);
    $gym = get_user_gym($user);
    $gymId = $gym ? (int)$gym['gym_id'] : null;

    if ($user['role'] === 'platform_admin' && !empty($_GET['gym_id'])) {
        $gymId = (int)$_GET['gym_id'];
        $gym = query_one('SELECT * FROM gyms WHERE gym_id = ?', [$gymId]);
    } elseif ($user['role'] === 'platform_admin' && !$gymId) {
        $gym = query_one('SELECT * FROM gyms ORDER BY gym_id ASC LIMIT 1');
        $gymId = $gym ? (int)$gym['gym_id'] : null;
    }

    $gymName = $gym['name'] ?? 'Gym';
    $isStaffRestricted = is_staff_financials_restricted($user);
    $canManageCategories = in_array($user['role'], ['platform_admin', 'gym_owner', 'staff'], true);
    $tableReady = expenses_table_exists();
    $catTableReady = expenses_categories_table_exists();

    $categoryRows = [];
    $categories = [];
    if ($catTableReady && $gymId) {
        $categoryRows = query_all(
            'SELECT category_id, name, created_by FROM gym_expense_categories WHERE gym_id = ? ORDER BY name ASC',
            [$gymId]
        );
        $categories = array_map(static fn(array $c): string => (string)$c['name'], $categoryRows);
    }

    $paymentMethods = [
        'cash' => 'Cash',
        'gcash' => 'GCash',
        'bank' => 'Bank Transfer',
        'other' => 'Other',
    ];

    // -------------------------------------------------------------
    // POST: Create Expense Category (Gym Owner / Platform Admin)
    // -------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'create_category') {
        if (!$canManageCategories || !$catTableReady || !$gymId) {
            flash('Unable to add category.', 'danger');
            redirect('expenses');
        }
        $catName = trim((string)post('category_name'));
        $catName = substr($catName, 0, 60);
        if ($catName === '') {
            flash('Please enter a category name.', 'danger');
            redirect('expenses');
        }
        $dup = scalar('SELECT category_id FROM gym_expense_categories WHERE gym_id = ? AND LOWER(name) = LOWER(?)', [$gymId, $catName]);
        if ($dup) {
            flash('Category "' . $catName . '" already exists.', 'warning');
            redirect('expenses');
        }
        db()->prepare('INSERT INTO gym_expense_categories (gym_id, name, created_by) VALUES (?, ?, ?)')
            ->execute([$gymId, $catName, (int)$user['user_id']]);
        audit_log((int)$user['user_id'], 'create', 'expense_category', (string)db()->lastInsertId(), json_encode(['name' => $catName]));
        flash('Category "' . $catName . '" added.', 'success');
        redirect('expenses');
    }

    // -------------------------------------------------------------
    // POST: Delete Expense Category (Gym Owner / Platform Admin)
    // -------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete_category') {
        if (!$canManageCategories || !$catTableReady || !$gymId) {
            flash('Unable to delete category.', 'danger');
            redirect('expenses');
        }
        $catId = (int)post('category_id');
        $catRow = query_one('SELECT * FROM gym_expense_categories WHERE category_id = ? AND gym_id = ?', [$catId, $gymId]);
        if (!$catRow) {
            flash('Category not found.', 'danger');
            redirect('expenses');
        }
        $canDeleteCat = in_array($user['role'], ['platform_admin', 'gym_owner'], true)
            || ((int)$catRow['created_by'] === (int)$user['user_id']);
        if (!$canDeleteCat) {
            flash('You can only remove categories you created.', 'danger');
            redirect('expenses');
        }
        db()->prepare('DELETE FROM gym_expense_categories WHERE category_id = ? AND gym_id = ?')->execute([$catId, $gymId]);
        audit_log((int)$user['user_id'], 'delete', 'expense_category', (string)$catId, json_encode(['name' => $catRow['name']]));
        flash('Category "' . $catRow['name'] . '" removed.', 'success');
        redirect('expenses');
    }

    // -------------------------------------------------------------
    // POST: Log New Expense
    // -------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'create_expense') {
        if (!$tableReady) {
            flash('Expenses table is not yet installed in the database.', 'danger');
            redirect('expenses');
        }
        if (!$gymId) {
            flash('No gym selected for this expense.', 'danger');
            redirect('expenses');
        }

        $category = trim((string)post('category'));
        if ($category === '__new__' && $canManageCategories) {
            $newCat = substr(trim((string)post('new_category')), 0, 60);
            if ($newCat === '') {
                flash('Please enter a name for the new category.', 'danger');
                redirect('expenses');
            }
            if ($catTableReady) {
                $existingCat = scalar('SELECT name FROM gym_expense_categories WHERE gym_id = ? AND LOWER(name) = LOWER(?)', [$gymId, $newCat]);
                if ($existingCat) {
                    $category = (string)$existingCat;
                } else {
                    db()->prepare('INSERT INTO gym_expense_categories (gym_id, name, created_by) VALUES (?, ?, ?)')
                        ->execute([$gymId, $newCat, (int)$user['user_id']]);
                    $category = $newCat;
                }
            } else {
                $category = $newCat;
            }
        } elseif (!in_array($category, $categories, true)) {
            flash('Please select a valid expense category.', 'danger');
            redirect('expenses');
        }
        $description = substr(trim((string)post('description')), 0, 255);
        $amount = round((float)post('amount'), 2);
        $method = trim((string)post('payment_method'));
        if (!array_key_exists($method, $paymentMethods)) {
            $method = 'cash';
        }
        $expenseDate = trim((string)post('expense_date'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
            $expenseDate = date('Y-m-d');
        }

        if ($description === '') {
            flash('Please enter an expense description.', 'danger');
            redirect('expenses');
        }
        if ($amount <= 0) {
            flash('Expense amount must be greater than ₱0.00.', 'danger');
            redirect('expenses');
        }

        $stmt = db()->prepare(
            'INSERT INTO gym_expenses (gym_id, category, description, amount, payment_method, expense_date, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$gymId, $category, $description, $amount, $method, $expenseDate, (int)$user['user_id']]);
        $newId = (string)db()->lastInsertId();

        audit_log((int)$user['user_id'], 'create', 'expense', $newId, json_encode([
            'category' => $category,
            'amount' => $amount,
            'expense_date' => $expenseDate,
        ]));
        flash('Expense logged successfully.', 'success');
        redirect('expenses');
    }

    // -------------------------------------------------------------
    // POST: Delete Expense
    // -------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete_expense') {
        if (!$tableReady || !$gymId) {
            redirect('expenses');
        }
        $expenseId = (int)post('expense_id');
        $exp = query_one('SELECT * FROM gym_expenses WHERE expense_id = ? AND gym_id = ?', [$expenseId, $gymId]);
        if (!$exp) {
            flash('Expense record not found.', 'danger');
            redirect('expenses');
        }
        $canDelete = in_array($user['role'], ['platform_admin', 'gym_owner'], true)
            || ((int)$exp['recorded_by'] === (int)$user['user_id']);
        if (!$canDelete) {
            flash('You can only delete expenses you recorded.', 'danger');
            redirect('expenses');
        }

        db()->prepare('DELETE FROM gym_expenses WHERE expense_id = ? AND gym_id = ?')->execute([$expenseId, $gymId]);
        audit_log((int)$user['user_id'], 'delete', 'expense', (string)$expenseId, json_encode([
            'description' => $exp['description'],
            'amount' => $exp['amount'],
        ]));
        flash('Expense removed.', 'success');
        redirect('expenses');
    }

    // -------------------------------------------------------------
    // Filters & Date Presets
    // -------------------------------------------------------------
    $todayYmd = date('Y-m-d');
    $monthStartYmd = date('Y-m-01');
    $filterFrom = trim((string)($_GET['from'] ?? $monthStartYmd));
    $filterTo = trim((string)($_GET['to'] ?? $todayYmd));
    if (isset($_GET['preset']) && $_GET['preset'] === 'all') {
        $filterFrom = '';
        $filterTo = '';
    }
    if ($filterFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
        $filterFrom = $monthStartYmd;
    }
    if ($filterTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
        $filterTo = $todayYmd;
    }
    $filterCategory = trim((string)($_GET['category'] ?? ''));
    if ($filterCategory !== '' && !in_array($filterCategory, $categories, true)) {
        $filterCategory = '';
    }
    $search = trim((string)($_GET['q'] ?? ''));

    $whereParts = [];
    $params = [];
    if ($gymId) {
        $whereParts[] = 'e.gym_id = ?';
        $params[] = $gymId;
    }
    if ($filterFrom !== '') {
        $whereParts[] = 'e.expense_date >= ?';
        $params[] = $filterFrom;
    }
    if ($filterTo !== '') {
        $whereParts[] = 'e.expense_date <= ?';
        $params[] = $filterTo;
    }
    if ($filterCategory !== '') {
        $whereParts[] = 'e.category = ?';
        $params[] = $filterCategory;
    }
    if ($search !== '') {
        $whereParts[] = '(e.description LIKE ? OR e.category LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    $whereSql = $whereParts ? implode(' AND ', $whereParts) : '1=1';

    // CSV Export
    if (($_GET['export'] ?? '') === 'csv') {
        if ($isStaffRestricted || !$tableReady) {
            redirect('expenses');
        }
        $expStmt = db()->prepare(
            "SELECT e.expense_date, e.category, e.description, e.amount, e.payment_method,
                    CONCAT(u.first_name, ' ', u.last_name) AS recorded_by_name
             FROM gym_expenses e
             LEFT JOIN users u ON u.user_id = e.recorded_by
             WHERE {$whereSql}
             ORDER BY e.expense_date DESC, e.expense_id DESC"
        );
        $expStmt->execute($params);
        $expRows = $expStmt->fetchAll(PDO::FETCH_ASSOC);

        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="expenses_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Date', 'Category', 'Description', 'Payment Method', 'Amount (PHP)', 'Recorded By']);
        foreach ($expRows as $er) {
            fputcsv($out, [
                date('M j, Y', strtotime((string)$er['expense_date'])),
                $er['category'],
                $er['description'],
                $paymentMethods[$er['payment_method']] ?? ucfirst((string)$er['payment_method']),
                number_format((float)$er['amount'], 2, '.', ''),
                $er['recorded_by_name'] ?: 'System',
            ]);
        }
        fclose($out);
        exit;
    }

    // Fetch expenses & totals (with pagination)
    $rows = [];
    $totalExpenses = 0.0;
    $expenseCount = 0;
    $perPage = 15;
    $expPage = max(1, (int)($_GET['p'] ?? 1));
    $totalPages = 1;
    $offset = 0;
    if ($tableReady) {
        $sumStmt = db()->prepare("SELECT COALESCE(SUM(e.amount), 0) AS total_amt, COUNT(*) AS cnt FROM gym_expenses e WHERE {$whereSql}");
        $sumStmt->execute($params);
        $sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $totalExpenses = (float)($sumRow['total_amt'] ?? 0);
        $expenseCount = (int)($sumRow['cnt'] ?? 0);

        $totalPages = max(1, (int)ceil($expenseCount / $perPage));
        $expPage = min($expPage, $totalPages);
        $offset = ($expPage - 1) * $perPage;

        $listStmt = db()->prepare(
            "SELECT e.*, CONCAT(u.first_name, ' ', u.last_name) AS recorded_by_name
             FROM gym_expenses e
             LEFT JOIN users u ON u.user_id = e.recorded_by
             WHERE {$whereSql}
             ORDER BY e.expense_date DESC, e.expense_id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $listStmt->execute($params);
        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Calculate Gross Revenue for the same date window (Subscriptions + Walk-ins)
    $revSub = 0.0;
    $revWalk = 0.0;
    if ($gymId) {
        $subSql = "SELECT COALESCE(SUM(p.amount), 0) FROM payments p
                   JOIN memberships m ON m.membership_id = p.membership_id
                   JOIN membership_plans mp ON mp.plan_id = m.plan_id
                   WHERE p.status = 'paid' AND mp.gym_id = ?";
        $subParams = [$gymId];
        if ($filterFrom !== '') {
            $subSql .= ' AND DATE(p.payment_date) >= ?';
            $subParams[] = $filterFrom;
        }
        if ($filterTo !== '') {
            $subSql .= ' AND DATE(p.payment_date) <= ?';
            $subParams[] = $filterTo;
        }
        $revSub = (float)scalar($subSql, $subParams);

        $walkSql = "SELECT COALESCE(SUM(w.amount_paid), 0) FROM walk_in_transactions w WHERE w.gym_id = ?";
        $walkParams = [$gymId];
        if ($filterFrom !== '') {
            $walkSql .= ' AND DATE(w.visit_date) >= ?';
            $walkParams[] = $filterFrom;
        }
        if ($filterTo !== '') {
            $walkSql .= ' AND DATE(w.visit_date) <= ?';
            $walkParams[] = $filterTo;
        }
        $revWalk = (float)scalar($walkSql, $walkParams);
    }
    $grossRevenue = $revSub + $revWalk;
    $netProfit = $grossRevenue - $totalExpenses;

    $isTodayPreset = ($filterFrom === $todayYmd && $filterTo === $todayYmd);
    $isMonthPreset = ($filterFrom === $monthStartYmd && $filterTo === $todayYmd);
    $isAllPreset = ($filterFrom === '' && $filterTo === '');

    $periodLabel = $isAllPreset
        ? 'All Time'
        : ($isTodayPreset ? 'Today' : (date('M j, Y', strtotime($filterFrom ?: $monthStartYmd)) . ' – ' . date('M j, Y', strtotime($filterTo ?: $todayYmd))));

    render_header('Expenses', $user);
    ?>
    <section class="panel">
        <div class="page-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px;">
            <div>
                <p style="margin:0;color:var(--muted);">Log operational costs for <strong><?= h($gymName) ?></strong> and track net profit against revenue.</p>
            </div>
            <div class="exp-header-actions">
                <?php if (!$isStaffRestricted && $tableReady): ?>
                    <a href="<?= h('index.php?' . http_build_query(array_filter(['page' => 'expenses', 'gym_id' => $gymId, 'from' => $filterFrom, 'to' => $filterTo, 'category' => $filterCategory, 'q' => $search, 'preset' => $isAllPreset ? 'all' : '', 'export' => 'csv']))) ?>" class="btn btn-secondary">Export CSV</a>
                <?php endif; ?>
                <?php if ($canManageCategories && $catTableReady): ?>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('manageCategoriesModal').showModal()">Manage Categories</button>
                <?php endif; ?>
                <button type="button" class="btn btn-primary exp-desktop-log-btn" onclick="document.getElementById('logExpenseModal').showModal()" <?= !$tableReady ? 'disabled' : '' ?>>+ Log Expense</button>
            </div>
        </div>

        <?php if (!$tableReady): ?>
            <div style="padding:14px 16px;border-radius:10px;border:1px solid rgba(245,158,11,0.35);background:rgba(245,158,11,0.1);color:#f59e0b;margin-bottom:18px;font-size:13.5px;">
                <strong>Setup needed:</strong> The <code>gym_expenses</code> table does not exist yet in your database. Run the TiDB SQL migration to start logging expenses.
            </div>
        <?php endif; ?>

        <!-- KPI Summary Cards -->
        <div class="exp-kpi-grid">
            <div class="exp-kpi-card">
                <span class="exp-kpi-label">Gross Revenue (<?= h($periodLabel) ?>)</span>
                <?php if ($isStaffRestricted): ?>
                    <strong class="exp-kpi-value is-muted">••••••</strong>
                    <span class="exp-kpi-sub">Staff Privacy active</span>
                <?php else: ?>
                    <strong class="exp-kpi-value"><?= h(money($grossRevenue)) ?></strong>
                    <span class="exp-kpi-sub">Subs <?= h(money($revSub)) ?> &bull; Walk-ins <?= h(money($revWalk)) ?></span>
                <?php endif; ?>
            </div>

            <div class="exp-kpi-card">
                <span class="exp-kpi-label">Total Expenses (<?= h($periodLabel) ?>)</span>
                <?php if ($isStaffRestricted): ?>
                    <strong class="exp-kpi-value is-muted">••••••</strong>
                    <span class="exp-kpi-sub">Staff Privacy active</span>
                <?php else: ?>
                    <strong class="exp-kpi-value" style="color:#f87171;">−<?= h(money($totalExpenses)) ?></strong>
                    <span class="exp-kpi-sub"><?= $expenseCount ?> logged item<?= $expenseCount === 1 ? '' : 's' ?></span>
                <?php endif; ?>
            </div>

            <div class="exp-kpi-card">
                <span class="exp-kpi-label">Net Profit (Revenue − Expenses)</span>
                <?php if ($isStaffRestricted): ?>
                    <strong class="exp-kpi-value is-muted">••••••</strong>
                    <span class="exp-kpi-sub">Staff Privacy active</span>
                <?php else: ?>
                    <strong class="exp-kpi-value" style="color:<?= $netProfit >= 0 ? 'var(--lime)' : '#f87171' ?>;">
                        <?= $netProfit < 0 ? '−' . h(money(abs($netProfit))) : h(money($netProfit)) ?>
                    </strong>
                    <span class="exp-kpi-sub"><?= $netProfit >= 0 ? 'Positive margin' : 'Expenses exceed revenue' ?></span>
                <?php endif; ?>
            </div>

            <div class="exp-kpi-card">
                <span class="exp-kpi-label">Expense Entries</span>
                <strong class="exp-kpi-value"><?= $expenseCount ?></strong>
                <span class="exp-kpi-sub">Matching current filter</span>
            </div>
        </div>

        <!-- Quick Presets & Filter Bar -->
        <div class="exp-quick-pills">
            <a href="<?= h('index.php?' . http_build_query(array_filter(['page' => 'expenses', 'gym_id' => $gymId, 'from' => $todayYmd, 'to' => $todayYmd]))) ?>" class="exp-pill <?= $isTodayPreset ? 'is-active' : '' ?>">Today</a>
            <a href="<?= h('index.php?' . http_build_query(array_filter(['page' => 'expenses', 'gym_id' => $gymId, 'from' => $monthStartYmd, 'to' => $todayYmd]))) ?>" class="exp-pill <?= $isMonthPreset ? 'is-active' : '' ?>">This Month</a>
            <a href="<?= h('index.php?' . http_build_query(array_filter(['page' => 'expenses', 'gym_id' => $gymId, 'preset' => 'all']))) ?>" class="exp-pill <?= $isAllPreset ? 'is-active' : '' ?>">All Time</a>
        </div>

        <form method="get" action="index.php" class="exp-filter-form">
            <input type="hidden" name="page" value="expenses">
            <?php if ($gymId): ?><input type="hidden" name="gym_id" value="<?= (int)$gymId ?>"><?php endif; ?>
            <label class="exp-filter-group">
                Search
                <input type="search" name="q" value="<?= h($search) ?>" placeholder="Description or category..." class="exp-input">
            </label>
            <label class="exp-filter-group">
                Category
                <select name="category" class="exp-input">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= h($cat) ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="exp-filter-group">
                From
                <input type="date" name="from" value="<?= h($filterFrom) ?>" class="exp-input">
            </label>
            <label class="exp-filter-group">
                To
                <input type="date" name="to" value="<?= h($filterTo) ?>" class="exp-input">
            </label>
            <div class="exp-filter-actions">
                <button type="submit" class="btn btn-primary">Filter</button>
            </div>
        </form>

        <!-- Expense Records Table -->
        <?php if (!$rows): ?>
            <div class="empty-state">
                <p>No expenses recorded for this period.<br>Click <strong>+ Log Expense</strong> to add your first entry.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap exp-desktop-table">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>Recorded By</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $canDel = in_array($user['role'], ['platform_admin', 'gym_owner'], true)
                            || ((int)$r['recorded_by'] === (int)$user['user_id']);
                    ?>
                        <tr>
                            <td><span style="font-size:12.5px;color:var(--muted);"><?= h(date('M j, Y', strtotime((string)$r['expense_date']))) ?></span></td>
                            <td><span class="badge"><?= h($r['category']) ?></span></td>
                            <td style="max-width:280px;overflow-wrap:anywhere;"><span style="font-size:12.5px;"><?= h($r['description']) ?></span></td>
                            <td><span style="color:var(--muted);font-size:12.5px;"><?= h($paymentMethods[$r['payment_method']] ?? ucfirst((string)$r['payment_method'])) ?></span></td>
                            <td>
                                <?php if ($isStaffRestricted && (int)$r['recorded_by'] !== (int)$user['user_id']): ?>
                                    <span style="color:var(--muted);font-size:12.5px;letter-spacing:2px;">••••••</span>
                                <?php else: ?>
                                    <strong style="color:#f87171;font-size:12.5px;">−<?= h(money((float)$r['amount'])) ?></strong>
                                <?php endif; ?>
                            </td>
                            <td><span style="color:var(--muted);font-size:12.5px;"><?= h($r['recorded_by_name'] ?: 'System') ?></span></td>
                            <td>
                                <?php if ($canDel): ?>
                                    <form method="post" onsubmit="return confirm('Delete this expense entry?');" style="margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_expense">
                                        <input type="hidden" name="expense_id" value="<?= (int)$r['expense_id'] ?>">
                                        <button type="submit" class="btn-sm btn-ghost" style="color:#f87171;">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Cards View -->
            <div class="exp-mobile-cards">
                <?php foreach ($rows as $r):
                    $canDel = in_array($user['role'], ['platform_admin', 'gym_owner'], true)
                        || ((int)$r['recorded_by'] === (int)$user['user_id']);
                    $methodLabel = $paymentMethods[$r['payment_method']] ?? ucfirst((string)$r['payment_method']);
                ?>
                    <div class="exp-mobile-card">
                        <div class="exp-mobile-card-top">
                            <span class="badge"><?= h($r['category']) ?></span>
                            <?php if ($isStaffRestricted && (int)$r['recorded_by'] !== (int)$user['user_id']): ?>
                                <span style="color:var(--muted);font-size:14px;font-weight:700;letter-spacing:2px;">••••••</span>
                            <?php else: ?>
                                <strong style="color:#f87171;font-size:15px;">−<?= h(money((float)$r['amount'])) ?></strong>
                            <?php endif; ?>
                        </div>
                        <div class="exp-mobile-card-desc"><?= h($r['description']) ?></div>
                        <div class="exp-mobile-card-meta">
                            <span><?= h(date('M j, Y', strtotime((string)$r['expense_date']))) ?> &bull; <?= h($methodLabel) ?></span>
                        </div>
                        <div class="exp-mobile-card-footer">
                            <span class="exp-mobile-card-by">By <?= h($r['recorded_by_name'] ?: 'System') ?></span>
                            <?php if ($canDel): ?>
                                <form method="post" onsubmit="return confirm('Delete this expense entry?');" style="margin:0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_expense">
                                    <input type="hidden" name="expense_id" value="<?= (int)$r['expense_id'] ?>">
                                    <button type="submit" class="btn-sm btn-ghost" style="color:#f87171;">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($expenseCount > 0):
                $expPagParams = ['page' => 'expenses'];
                if ($gymId) $expPagParams['gym_id'] = $gymId;
                if ($isAllPreset) {
                    $expPagParams['preset'] = 'all';
                } else {
                    if ($filterFrom !== '') $expPagParams['from'] = $filterFrom;
                    if ($filterTo !== '') $expPagParams['to'] = $filterTo;
                }
                if ($filterCategory !== '') $expPagParams['category'] = $filterCategory;
                if ($search !== '') $expPagParams['q'] = $search;
                $expBaseUrl = 'index.php?' . http_build_query($expPagParams);
            ?>
                <div class="pagination-bar">
                    <div class="pagination-info">
                        Showing <strong><?= $offset + 1 ?></strong> to <strong><?= min($offset + $perPage, $expenseCount) ?></strong> of <strong><?= $expenseCount ?></strong> records
                    </div>
                    <?php render_pagination($expPage, $totalPages, $expBaseUrl, 'p'); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- Mobile FAB for Logging Expense -->
    <button type="button" class="exp-mobile-fab" onclick="document.getElementById('logExpenseModal').showModal()" title="Log Expense" aria-label="Log Expense" <?= !$tableReady ? 'disabled' : '' ?>>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
    </button>

    <!-- Log Expense Modal -->
    <dialog id="logExpenseModal" class="modal" style="max-width:480px;width:92%;border-radius:14px;border:1px solid var(--line);background:var(--surface);color:var(--ink);padding:0;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--line);">
            <h3 style="margin:0;font-size:17px;">Log Gym Expense</h3>
            <button type="button" onclick="document.getElementById('logExpenseModal').close()" style="background:none;border:none;color:var(--muted);font-size:20px;cursor:pointer;">&times;</button>
        </div>
        <form method="post" style="padding:18px 20px;display:grid;gap:14px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_expense">
            <label class="exp-filter-group">
                Category *
                <select name="category" id="expenseCategorySelect" class="exp-input" required onchange="toggleNewExpenseCategory(this)">
                    <option value="">-- Select category --</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= h($cat) ?>"><?= h($cat) ?></option>
                    <?php endforeach; ?>
                    <?php if ($canManageCategories): ?>
                        <option value="__new__">+ Add New Category...</option>
                    <?php endif; ?>
                </select>
            </label>
            <?php if ($canManageCategories): ?>
                <label class="exp-filter-group" id="newCategoryInputWrap" style="display:none;">
                    New Category Name *
                    <input type="text" name="new_category" id="newCategoryInput" class="exp-input" placeholder="e.g. Electricity, Water, Rent" maxlength="60">
                </label>
            <?php elseif (empty($categories)): ?>
                <div style="font-size:12.5px;color:#f59e0b;">No categories added yet. Ask your gym owner to add expense categories.</div>
            <?php endif; ?>
            <label class="exp-filter-group">
                Description *
                <input type="text" name="description" class="exp-input" placeholder="e.g. Monthly electricity bill, drinking water" maxlength="255" required>
            </label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <label class="exp-filter-group">
                    Amount (₱) *
                    <input type="number" name="amount" step="0.01" min="0.01" class="exp-input" placeholder="0.00" required>
                </label>
                <label class="exp-filter-group">
                    Date *
                    <input type="date" name="expense_date" value="<?= h($todayYmd) ?>" max="<?= h($todayYmd) ?>" class="exp-input" required>
                </label>
            </div>
            <label class="exp-filter-group">
                Payment Method *
                <select name="payment_method" class="exp-input" required>
                    <?php foreach ($paymentMethods as $val => $lbl): ?>
                        <option value="<?= h($val) ?>"><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:4px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('logExpenseModal').close()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Expense</button>
            </div>
        </form>
    </dialog>

    <?php if ($canManageCategories && $catTableReady): ?>
    <!-- Manage Categories Modal -->
    <dialog id="manageCategoriesModal" class="modal" style="max-width:440px;width:92%;border-radius:14px;border:1px solid var(--line);background:var(--surface);color:var(--ink);padding:0;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--line);">
            <h3 style="margin:0;font-size:17px;">Expense Categories</h3>
            <button type="button" onclick="document.getElementById('manageCategoriesModal').close()" style="background:none;border:none;color:var(--muted);font-size:20px;cursor:pointer;">&times;</button>
        </div>
        <div style="padding:18px 20px;display:grid;gap:14px;">
            <form method="post" style="display:flex;gap:8px;align-items:center;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_category">
                <input type="text" name="category_name" class="exp-input" placeholder="New category name..." maxlength="60" required style="flex:1;">
                <button type="submit" class="btn btn-primary" style="height:38px;white-space:nowrap;">+ Add</button>
            </form>
            <?php if (!$categoryRows): ?>
                <p style="margin:4px 0;font-size:13px;color:var(--muted);">No categories yet. Add your first category above.</p>
            <?php else: ?>
                <div style="display:grid;gap:8px;max-height:260px;overflow-y:auto;">
                    <?php foreach ($categoryRows as $cRow):
                        $canDeleteCat = in_array($user['role'], ['platform_admin', 'gym_owner'], true)
                            || ((int)($cRow['created_by'] ?? 0) === (int)$user['user_id']);
                    ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;border:1px solid var(--line);border-radius:8px;background:var(--bg);">
                            <span style="font-size:13.5px;font-weight:600;"><?= h($cRow['name']) ?></span>
                            <?php if ($canDeleteCat): ?>
                                <form method="post" onsubmit="return confirm('Remove category <?= h(addslashes($cRow['name'])) ?>?');" style="margin:0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="category_id" value="<?= (int)$cRow['category_id'] ?>">
                                    <button type="submit" class="btn-sm btn-ghost" style="color:#f87171;">Remove</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </dialog>
    <?php endif; ?>

    <script>
    function toggleNewExpenseCategory(sel) {
        const wrap = document.getElementById('newCategoryInputWrap');
        const input = document.getElementById('newCategoryInput');
        if (!wrap || !input) return;
        const isNew = sel.value === '__new__';
        wrap.style.display = isNew ? 'grid' : 'none';
        input.required = isNew;
        if (isNew) input.focus();
    }
    </script>

    <style>
        .exp-header-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .exp-mobile-fab {
            display: none;
        }
        .exp-mobile-cards {
            display: none;
        }
        @media (max-width: 768px) {
            .exp-desktop-table {
                display: none !important;
            }
            .exp-mobile-cards {
                display: grid;
                gap: 10px;
                margin-bottom: 14px;
            }
            .exp-mobile-card {
                background: var(--surface);
                border: 1px solid var(--line);
                border-radius: 12px;
                padding: 14px;
                display: grid;
                gap: 8px;
            }
            .exp-mobile-card-top {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 8px;
            }
            .exp-mobile-card-desc {
                font-size: 13.5px;
                font-weight: 600;
                color: var(--ink);
                line-height: 1.35;
                overflow-wrap: anywhere;
            }
            .exp-mobile-card-meta {
                font-size: 12px;
                color: var(--muted);
            }
            .exp-mobile-card-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding-top: 8px;
                border-top: 1px solid var(--line);
            }
            .exp-mobile-card-by {
                font-size: 12px;
                color: var(--muted);
            }
            .exp-header-actions {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }
            .exp-header-actions .btn {
                width: 100%;
                justify-content: center;
                text-align: center;
                box-sizing: border-box;
            }
            .exp-desktop-log-btn {
                display: none !important;
            }
            .exp-mobile-fab {
                display: flex;
                align-items: center;
                justify-content: center;
                position: fixed;
                bottom: calc(24px + env(safe-area-inset-bottom, 0px));
                right: calc(20px + env(safe-area-inset-right, 0px));
                width: 56px;
                height: 56px;
                border-radius: 50%;
                border: none;
                background: var(--lime);
                color: var(--bg);
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.15);
                cursor: pointer;
                z-index: 999;
                -webkit-tap-highlight-color: transparent;
                transition: transform 0.2s ease;
            }
            .exp-mobile-fab:active {
                transform: scale(0.92);
            }
            .exp-mobile-fab:disabled {
                opacity: 0.45;
                pointer-events: none;
            }
        }
        .exp-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .exp-kpi-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px 16px;
            display: grid;
            gap: 4px;
        }
        .exp-kpi-label {
            font-size: 12px;
            color: var(--muted);
            font-weight: 600;
        }
        .exp-kpi-value {
            font-size: 21px;
            font-weight: 800;
            color: var(--ink);
        }
        .exp-kpi-value.is-muted {
            color: var(--muted);
            letter-spacing: 2px;
        }
        .exp-kpi-sub {
            font-size: 12px;
            color: var(--muted);
        }
        .exp-quick-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }
        .exp-pill {
            padding: 7px 14px;
            border-radius: 999px;
            font-size: 12.5px;
            font-weight: 700;
            text-decoration: none;
            color: var(--muted);
            background: var(--surface);
            border: 1px solid var(--line);
        }
        .exp-pill.is-active {
            background: var(--lime);
            color: var(--bg);
            border-color: var(--lime);
        }
        .exp-filter-form {
            display: grid;
            grid-template-columns: 1.8fr 1.2fr 1fr 1fr auto;
            gap: 12px;
            align-items: end;
            padding: 14px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 12px;
            margin-bottom: 18px;
        }
        .exp-filter-group {
            display: grid;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
        }
        .exp-input {
            width: 100%;
            height: 38px;
            box-sizing: border-box;
            padding: 8px 11px;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--bg);
            color: var(--ink);
            font-size: 13px;
            font-family: inherit;
        }
        @media (max-width: 860px) {
            .exp-kpi-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .exp-filter-form {
                grid-template-columns: 1fr 1fr;
            }
            .exp-filter-form > :first-child {
                grid-column: span 2;
            }
            .exp-filter-actions {
                grid-column: span 2;
            }
            .exp-filter-actions .btn {
                width: 100%;
            }
        }
    </style>
    <?php
    render_footer();
}
