<?php
declare(strict_types=1);

function audit_logs_page(): void
{
    $user = require_roles(['platform_admin']);

    // Filters
    $filterAdmin  = $_GET['admin_id'] ?? '';
    $filterAction = $_GET['action_filter'] ?? '';
    $filterEntity = $_GET['entity_filter'] ?? '';
    $filterFrom   = $_GET['from'] ?? '';
    $filterTo     = $_GET['to'] ?? '';

    $where  = '1=1';
    $params = [];

    if ($filterAdmin !== '') {
        $where .= ' AND a.admin_user_id = ?';
        $params[] = (int) $filterAdmin;
    }
    if ($filterAction !== '') {
        $where .= ' AND a.action = ?';
        $params[] = $filterAction;
    }
    if ($filterEntity !== '') {
        $where .= ' AND a.entity_type = ?';
        $params[] = $filterEntity;
    }
    if ($filterFrom !== '') {
        $where .= ' AND a.created_at >= ?';
        $params[] = $filterFrom . ' 00:00:00';
    }
    if ($filterTo !== '') {
        $where .= ' AND a.created_at <= ?';
        $params[] = $filterTo . ' 23:59:59';
    }

    // Pagination
    $page   = max(1, (int) ($_GET['p'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;

    $total      = (int) scalar('SELECT COUNT(*) FROM admin_audit_logs a WHERE ' . $where, $params);
    $totalPages = max(1, (int) ceil($total / $limit));

    $rows = query_all(
        'SELECT a.*, CONCAT(u.first_name, " ", u.last_name) AS admin_name
         FROM admin_audit_logs a
         LEFT JOIN users u ON u.user_id = a.admin_user_id
         WHERE ' . $where . '
         ORDER BY a.created_at DESC
         LIMIT ' . $limit . ' OFFSET ' . $offset,
        $params
    );

    // Distinct values for filter dropdowns
    $admins  = query_all('SELECT DISTINCT a.admin_user_id, CONCAT(u.first_name, " ", u.last_name) AS name FROM admin_audit_logs a JOIN users u ON u.user_id = a.admin_user_id ORDER BY name');
    $actions = query_all('SELECT DISTINCT action FROM admin_audit_logs ORDER BY action');
    $entities = query_all('SELECT DISTINCT entity_type FROM admin_audit_logs ORDER BY entity_type');

    render_header('Audit Logs', $user);
    ?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Audit Logs</h1>
                <p>Track every admin action for accountability and security.</p>
            </div>
            <span class="badge badge-active"><?= $total ?> record<?= $total !== 1 ? 's' : '' ?></span>
        </div>

        <!-- Filters -->
        <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:20px;padding:16px;background:rgba(255,255,255,0.03);border-radius:10px;border:1px solid var(--line);">
            <input type="hidden" name="page" value="audit_logs">
            
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;color:var(--muted);">
                Admin
                <select name="admin_id" class="form-control" style="min-width:160px;">
                    <option value="">All admins</option>
                    <?php foreach ($admins as $a): ?>
                        <option value="<?= (int) $a['admin_user_id'] ?>" <?= selected((string) $a['admin_user_id'], $filterAdmin) ?>><?= h($a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;color:var(--muted);">
                Action
                <select name="action_filter" class="form-control" style="min-width:140px;">
                    <option value="">All actions</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?= h($a['action']) ?>" <?= selected($a['action'], $filterAction) ?>><?= h(ucwords(str_replace('_', ' ', $a['action']))) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;color:var(--muted);">
                Entity
                <select name="entity_filter" class="form-control" style="min-width:140px;">
                    <option value="">All entities</option>
                    <?php foreach ($entities as $e): ?>
                        <option value="<?= h($e['entity_type']) ?>" <?= selected($e['entity_type'], $filterEntity) ?>><?= h(ucwords(str_replace('_', ' ', $e['entity_type']))) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;color:var(--muted);">
                From
                <input type="date" name="from" class="form-control" value="<?= h($filterFrom) ?>" style="min-width:140px;">
            </label>

            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;color:var(--muted);">
                To
                <input type="date" name="to" class="form-control" value="<?= h($filterTo) ?>" style="min-width:140px;">
            </label>

            <div style="display:flex;gap:6px;">
                <button type="submit" class="btn" style="background:var(--lime);color:var(--bg);font-weight:bold;height:38px;">Filter</button>
                <a href="index.php?page=audit_logs" class="btn btn-secondary" style="height:38px;display:flex;align-items:center;">Reset</a>
            </div>
        </form>

        <!-- Table -->
        <?php if (!$rows): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <p>No audit log entries found.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Admin</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>Entity ID</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td style="white-space:nowrap;font-size:13px;color:var(--muted);">
                            <?= date('M j, Y g:i A', strtotime($row['created_at'])) ?>
                        </td>
                        <td>
                            <strong><?= h($row['admin_name'] ?? 'Unknown') ?></strong>
                        </td>
                        <td>
                            <?php
                            $actionColors = [
                                'create' => '#22c55e', 'edit' => '#3b82f6', 'delete' => '#ef4444',
                                'update_status' => '#f59e0b', 'checkin' => '#22c55e', 'checkout' => '#6366f1',
                                'qr_checkin' => '#22c55e', 'qr_checkout' => '#6366f1',
                                'mark_paid' => '#22c55e', 'convert' => '#8b5cf6',
                                'end' => '#ef4444', 'forward' => '#3b82f6', 'reject' => '#ef4444',
                                'member_checkin' => '#22c55e',
                            ];
                            $color = $actionColors[$row['action']] ?? '#94a3b8';
                            ?>
                            <span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;background:<?= $color ?>22;color:<?= $color ?>;border:1px solid <?= $color ?>44;">
                                <?= h(ucwords(str_replace('_', ' ', $row['action']))) ?>
                            </span>
                        </td>
                        <td style="font-size:13px;">
                            <?= h(ucwords(str_replace('_', ' ', $row['entity_type']))) ?>
                        </td>
                        <td style="font-size:13px;color:var(--muted);">
                            <?= h($row['entity_id'] ?? '—') ?>
                        </td>
                        <td style="font-size:12px;color:var(--muted);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($row['details'] ?? '') ?>">
                            <?php
                            if ($row['details']) {
                                $decoded = json_decode($row['details'], true);
                                if ($decoded) {
                                    $parts = [];
                                    foreach ($decoded as $k => $v) {
                                        if (is_bool($v)) $v = $v ? 'yes' : 'no';
                                        $parts[] = str_replace('_', ' ', $k) . ': ' . $v;
                                    }
                                    echo h(implode(' · ', $parts));
                                } else {
                                    echo h($row['details']);
                                }
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div style="display:flex;justify-content:center;gap:6px;margin-top:18px;">
                <?php
                $qs = $_GET;
                for ($i = 1; $i <= $totalPages; $i++):
                    $qs['p'] = $i;
                    $active = $i === $page;
                ?>
                    <a href="?<?= http_build_query($qs) ?>"
                       style="padding:6px 12px;border-radius:6px;font-size:13px;font-weight:<?= $active ? '700' : '400' ?>;
                              background:<?= $active ? 'var(--lime)' : 'rgba(255,255,255,0.05)' ?>;
                              color:<?= $active ? 'var(--bg)' : 'var(--muted)' ?>;
                              text-decoration:none;border:1px solid <?= $active ? 'var(--lime)' : 'var(--line)' ?>;">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
    render_footer();
}
