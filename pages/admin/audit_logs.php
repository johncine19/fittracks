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

    // Build URL query string for pagination
    $queryParams = [];
    if ($filterAdmin !== '') $queryParams['admin_id'] = $filterAdmin;
    if ($filterAction !== '') $queryParams['action_filter'] = $filterAction;
    if ($filterEntity !== '') $queryParams['entity_filter'] = $filterEntity;
    if ($filterFrom !== '') $queryParams['from'] = $filterFrom;
    if ($filterTo !== '') $queryParams['to'] = $filterTo;
    $paginationBaseUrl = 'index.php?page=audit_logs' . ($queryParams ? '&' . http_build_query($queryParams) : '');

    // Distinct values for filter dropdowns
    $admins  = query_all('SELECT DISTINCT a.admin_user_id, CONCAT(u.first_name, " ", u.last_name) AS name FROM admin_audit_logs a JOIN users u ON u.user_id = a.admin_user_id ORDER BY name');
    $actions = query_all('SELECT DISTINCT action FROM admin_audit_logs ORDER BY action');
    $entities = query_all('SELECT DISTINCT entity_type FROM admin_audit_logs ORDER BY entity_type');

    $actionColors = [
        'create'         => '#22c55e',
        'edit'           => '#3b82f6',
        'delete'         => '#ef4444',
        'update_status'  => '#f59e0b',
        'checkin'        => '#22c55e',
        'checkout'       => '#6366f1',
        'qr_checkin'     => '#22c55e',
        'qr_checkout'    => '#6366f1',
        'mark_paid'      => '#22c55e',
        'convert'        => '#8b5cf6',
        'end'            => '#ef4444',
        'forward'        => '#3b82f6',
        'reject'         => '#ef4444',
        'approve'        => '#22c55e',
        'suspend'        => '#ef4444',
        'member_checkin' => '#22c55e',
    ];

    render_header('Audit Logs', $user);
    ?>
    <style>
    /* Responsive Desktop Table vs Mobile Cards */
    .audit-desktop-table {
        display: block;
    }
    .audit-mobile-cards {
        display: none;
    }

    @media (max-width: 768px) {
        .audit-desktop-table {
            display: none !important;
        }
        .audit-mobile-cards {
            display: flex !important;
            flex-direction: column;
            gap: 12px;
        }
    }

    /* Filters Form Layout */
    .audit-filters-form {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 12px;
        align-items: flex-end;
        margin-bottom: 20px;
        padding: 16px;
        background: var(--panel-soft, rgba(255, 255, 255, 0.04));
        border-radius: 12px;
        border: 1px solid var(--line);
    }

    .audit-filter-item {
        display: flex;
        flex-direction: column;
        gap: 5px;
        font-size: 12px;
        font-weight: 600;
        color: var(--muted);
        min-width: 0;
    }

    .audit-filter-item .form-control {
        width: 100%;
        box-sizing: border-box;
        padding: 8px 10px;
        border-radius: 8px;
        border: 1px solid var(--line);
        background: var(--panel, #10131b);
        color: var(--ink);
        font-size: 13px;
        outline: none;
        transition: border-color 0.2s ease;
    }

    .audit-filter-item .form-control:focus {
        border-color: var(--lime);
    }

    .audit-filter-actions {
        display: flex;
        gap: 8px;
        align-items: flex-end;
    }

    .audit-filter-actions .btn {
        flex: 1;
        height: 38px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 14px;
        font-size: 13px;
        font-weight: 700;
        border-radius: 8px;
        text-decoration: none;
        cursor: pointer;
    }

    @media (max-width: 768px) {
        .audit-filters-form {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            padding: 12px;
            gap: 10px;
        }

        .audit-filter-item.filter-admin { order: 1; }
        .audit-filter-item.filter-action { order: 2; }
        .audit-filter-item.filter-from { order: 3; }
        .audit-filter-item.filter-to { order: 4; }
        .audit-filter-item.filter-entity { order: 5; }
        .audit-filter-actions { 
            order: 6; 
            grid-column: auto;
            margin-top: 0;
            display: flex;
            gap: 6px;
        }

        .audit-filter-item .form-control {
            font-size: 12px;
            padding: 8px 6px;
            min-width: 0;
        }

        .audit-filter-actions .btn {
            font-size: 12px;
            padding: 0 6px;
            min-width: 0;
        }
    }

    /* Mobile Audit Log Card */
    .audit-card-item {
        background: color-mix(in srgb, var(--panel-soft) 45%, transparent);
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 14px 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        transition: border-color 0.2s ease;
    }

    html[data-theme="light"] .audit-card-item,
    [data-theme="light"] .audit-card-item {
        background: #ffffff;
        border-color: #e2e8f0;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
    }

    .audit-card-item:hover {
        border-color: color-mix(in srgb, var(--lime) 30%, transparent);
    }

    .audit-card-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
        padding-bottom: 10px;
        border-bottom: 1px solid color-mix(in srgb, var(--line) 60%, transparent);
    }

    .audit-card-action-group {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .audit-action-badge {
        display: inline-block;
        padding: 2px 10px;
        border-radius: 99px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.3px;
        text-transform: capitalize;
    }

    .audit-entity-tag {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 12px;
        font-weight: 600;
        color: var(--ink);
    }

    .audit-entity-id {
        font-size: 11px;
        color: var(--muted);
        font-family: ui-monospace, monospace;
        background: var(--panel-soft, rgba(255, 255, 255, 0.05));
        padding: 1px 5px;
        border-radius: 4px;
        border: 1px solid var(--line);
    }

    .audit-card-time {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 12px;
        color: var(--muted);
        margin-left: auto;
    }

    .audit-card-body {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .audit-card-admin-row {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .audit-admin-avatar {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        background: color-mix(in srgb, var(--lime) 15%, transparent);
        color: var(--lime);
        border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 12px;
        flex-shrink: 0;
    }

    html[data-theme="light"] .audit-admin-avatar,
    [data-theme="light"] .audit-admin-avatar {
        background: rgba(132, 204, 22, 0.15);
        color: #4d7c0f;
        border-color: rgba(132, 204, 22, 0.3);
    }

    .audit-admin-info {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .audit-admin-label {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--muted);
        font-weight: 700;
    }

    .audit-admin-name {
        font-size: 13px;
        font-weight: 600;
        color: var(--ink);
    }

    .audit-card-details-box {
        background: var(--panel-soft, rgba(255, 255, 255, 0.03));
        border: 1px solid var(--line);
        border-radius: 8px;
        padding: 8px 10px;
    }

    .audit-details-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .audit-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 8px;
        border-radius: 6px;
        background: var(--panel, #10131b);
        border: 1px solid var(--line);
        font-size: 11px;
    }

    .audit-chip-key {
        color: var(--muted);
        font-weight: 500;
        text-transform: capitalize;
    }

    .audit-chip-val {
        color: var(--ink);
        font-weight: 600;
    }

    .audit-details-raw {
        font-size: 12px;
        color: var(--ink);
        word-break: break-word;
    }

    /* Pagination Bar */
    .audit-pagination-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 14px;
        margin-top: 20px;
        padding-top: 14px;
        border-top: 1px solid var(--line);
    }

    .audit-pagination-bar .pagination {
        margin-top: 0;
    }

    .audit-pagination-info {
        font-size: 13px;
        color: var(--muted);
    }

    .audit-pagination-info strong {
        color: var(--ink);
        font-weight: 600;
    }

    @media (max-width: 600px) {
        .audit-pagination-bar {
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
    }
    </style>

    <section class="panel">
        <div class="page-header" style="flex-wrap: wrap; gap: 12px;">
            <div>
                <h1>Audit Logs</h1>
                <p>Track every admin action for accountability and security.</p>
            </div>
            <span class="badge badge-active"><?= $total ?> record<?= $total !== 1 ? 's' : '' ?></span>
        </div>

        <!-- Filters Form -->
        <form method="get" class="audit-filters-form">
            <input type="hidden" name="page" value="audit_logs">
            
            <label class="audit-filter-item filter-admin">
                <span>Admin</span>
                <select name="admin_id" class="form-control">
                    <option value="">All admins</option>
                    <?php foreach ($admins as $a): ?>
                        <option value="<?= (int) $a['admin_user_id'] ?>" <?= selected((string) $a['admin_user_id'], $filterAdmin) ?>><?= h($a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="audit-filter-item filter-action">
                <span>Action</span>
                <select name="action_filter" class="form-control">
                    <option value="">All actions</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?= h($a['action']) ?>" <?= selected($a['action'], $filterAction) ?>><?= h(ucwords(str_replace('_', ' ', $a['action']))) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="audit-filter-item filter-entity">
                <span>Entity</span>
                <select name="entity_filter" class="form-control">
                    <option value="">All entities</option>
                    <?php foreach ($entities as $e): ?>
                        <option value="<?= h($e['entity_type']) ?>" <?= selected($e['entity_type'], $filterEntity) ?>><?= h(ucwords(str_replace('_', ' ', $e['entity_type']))) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="audit-filter-item filter-from">
                <span>From</span>
                <input type="date" name="from" class="form-control" value="<?= h($filterFrom) ?>">
            </label>

            <label class="audit-filter-item filter-to">
                <span>To</span>
                <input type="date" name="to" class="form-control" value="<?= h($filterTo) ?>">
            </label>

            <div class="audit-filter-actions">
                <button type="submit" class="btn btn-primary" style="background:var(--lime);color:var(--bg);font-weight:700;">Filter</button>
                <a href="index.php?page=audit_logs" class="btn btn-secondary">Reset</a>
            </div>
        </form>

        <?php if (!$rows): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <p>No audit log entries found.</p>
            </div>
        <?php else: ?>
            <!-- Desktop Table View (> 768px) -->
            <div class="audit-desktop-table table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="min-width: 140px;">Time</th>
                            <th>Admin</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>Entity ID</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): 
                        $color = $actionColors[$row['action']] ?? '#94a3b8';
                        $chips = [];
                        $rawDetails = '';
                        if (!empty($row['details'])) {
                            $decoded = json_decode($row['details'], true);
                            if (is_array($decoded)) {
                                foreach ($decoded as $k => $v) {
                                    if (is_bool($v)) $v = $v ? 'yes' : 'no';
                                    elseif (is_array($v)) $v = json_encode($v);
                                    $chips[] = ['key' => str_replace('_', ' ', (string)$k), 'val' => (string)$v];
                                }
                            } else {
                                $rawDetails = $row['details'];
                            }
                        }
                    ?>
                        <tr>
                            <td style="white-space:nowrap;font-size:13px;color:var(--muted);">
                                <?= date('M j, Y g:i A', strtotime($row['created_at'])) ?>
                            </td>
                            <td>
                                <strong><?= h($row['admin_name'] ?? 'Unknown Admin') ?></strong>
                            </td>
                            <td>
                                <span class="audit-action-badge" style="background:<?= $color ?>22;color:<?= $color ?>;border:1px solid <?= $color ?>44;">
                                    <?= h(ucwords(str_replace('_', ' ', $row['action']))) ?>
                                </span>
                            </td>
                            <td style="font-size:13px; font-weight: 500;">
                                <?= h(ucwords(str_replace('_', ' ', $row['entity_type']))) ?>
                            </td>
                            <td style="font-size:13px;color:var(--muted);">
                                <?= !empty($row['entity_id']) ? '#' . h((string)$row['entity_id']) : '—' ?>
                            </td>
                            <td style="font-size:12px;color:var(--muted);max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($row['details'] ?? '') ?>">
                                <?php
                                if (!empty($chips)) {
                                    $parts = array_map(fn($c) => $c['key'] . ': ' . $c['val'], $chips);
                                    echo h(implode(' · ', $parts));
                                } elseif ($rawDetails !== '') {
                                    echo h($rawDetails);
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

            <!-- Mobile Card View (<= 768px) -->
            <div class="audit-mobile-cards">
                <?php foreach ($rows as $row): 
                    $color = $actionColors[$row['action']] ?? '#94a3b8';
                    $chips = [];
                    $rawDetails = '';
                    if (!empty($row['details'])) {
                        $decoded = json_decode($row['details'], true);
                        if (is_array($decoded)) {
                            foreach ($decoded as $k => $v) {
                                if (is_bool($v)) $v = $v ? 'yes' : 'no';
                                elseif (is_array($v)) $v = json_encode($v);
                                $chips[] = ['key' => str_replace('_', ' ', (string)$k), 'val' => (string)$v];
                            }
                        } else {
                            $rawDetails = $row['details'];
                        }
                    }
                ?>
                    <div class="audit-card-item">
                        <div class="audit-card-header">
                            <div class="audit-card-action-group">
                                <span class="audit-action-badge" style="background:<?= $color ?>22;color:<?= $color ?>;border:1px solid <?= $color ?>44;">
                                    <?= h(ucwords(str_replace('_', ' ', $row['action']))) ?>
                                </span>
                                <span class="audit-entity-tag">
                                    <?= h(ucwords(str_replace('_', ' ', $row['entity_type']))) ?>
                                    <?php if (!empty($row['entity_id'])): ?>
                                        <span class="audit-entity-id">#<?= h((string)$row['entity_id']) ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="audit-card-time">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polyline points="12 6 12 12 16 14"></polyline>
                                </svg>
                                <span><?= date('M j, Y g:i A', strtotime($row['created_at'])) ?></span>
                            </div>
                        </div>

                        <div class="audit-card-body">
                            <div class="audit-card-admin-row">
                                <div class="audit-admin-avatar">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                </div>
                                <div class="audit-admin-info">
                                    <span class="audit-admin-label">Admin</span>
                                    <span class="audit-admin-name"><?= h($row['admin_name'] ?? 'Unknown Admin') ?></span>
                                </div>
                            </div>

                            <?php if (!empty($chips)): ?>
                                <div class="audit-card-details-box">
                                    <div class="audit-details-chips">
                                        <?php foreach ($chips as $c): ?>
                                            <span class="audit-chip">
                                                <span class="audit-chip-key"><?= h($c['key']) ?>:</span>
                                                <span class="audit-chip-val"><?= h($c['val']) ?></span>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php elseif ($rawDetails !== ''): ?>
                                <div class="audit-card-details-box">
                                    <div class="audit-details-raw"><?= h($rawDetails) ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination Bar -->
            <?php if ($total > 0): ?>
                <div class="audit-pagination-bar">
                    <div class="audit-pagination-info">
                        Showing <strong><?= $offset + 1 ?></strong> to <strong><?= min($offset + $limit, $total) ?></strong> of <strong><?= $total ?></strong> records
                    </div>
                    <?php render_pagination($page, $totalPages, $paginationBaseUrl); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
    render_footer();
}
