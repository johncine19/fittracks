<?php
declare(strict_types=1);

function system_settings_page(): void
{
    $user = require_roles(['admin']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (post('action') === 'settings') {
            $stmt = db()->prepare('UPDATE system_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?');
            foreach ((array) post('settings', []) as $key => $value) {
                $stmt->execute([(string) $value, $user['user_id'], (string) $key]);
                audit_log($user, 'update', 'system_setting', (string) $key, ['value' => (string) $value]);
            }
            flash('System settings updated.');
        }
        redirect('system_settings');
    }

    $settings = query_all('SELECT s.*, CONCAT(u.first_name, " ", u.last_name) AS updated_by_name
        FROM system_settings s
        LEFT JOIN users u ON u.user_id = s.updated_by
        ORDER BY s.setting_key');
    $logs = query_all('SELECT l.*, CONCAT(u.first_name, " ", u.last_name) AS admin_name
        FROM admin_audit_logs l
        LEFT JOIN users u ON u.user_id = l.admin_user_id
        ORDER BY l.created_at DESC
        LIMIT 100');

    render_header('System', $user);
    ?>
    <section class="panel wide">
        <div class="page-header">
            <div>
                <h1>System Settings</h1>
                <p>Configure global nutrition constants and review administrative activity.</p>
            </div>
        </div>

        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="settings">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Setting</th><th>Value</th><th>Description</th><th>Last updated</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($settings as $setting): ?>
                        <tr>
                            <td><strong><?= h($setting['setting_key']) ?></strong></td>
                            <td><input name="settings[<?= h($setting['setting_key']) ?>]" value="<?= h($setting['setting_value']) ?>" style="min-width:120px"></td>
                            <td style="color:var(--muted);font-size:13px"><?= h($setting['description'] ?? '') ?></td>
                            <td style="color:var(--muted);font-size:12px">
                                <?= h($setting['updated_by_name'] ?: 'System') ?><br>
                                <?= h(date('M j, Y g:i A', strtotime($setting['updated_at']))) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button style="margin-top:1rem">Save settings</button>
        </form>
    </section>

    <section class="panel wide">
        <div class="page-header" style="margin-bottom:4px">
            <div>
                <h2>Audit Log</h2>
                <p>Recent administrative overrides and configuration changes.</p>
            </div>
        </div>
        <?php if (!$logs): ?>
            <p class="muted">No audit entries yet.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Time</th><th>Admin</th><th>Action</th><th>Record</th><th>Details</th></tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td style="color:var(--muted);font-size:12px"><?= h(date('M j, Y g:i A', strtotime($log['created_at']))) ?></td>
                        <td><?= h($log['admin_name'] ?: 'System') ?></td>
                        <td><span class="badge badge-active"><?= h($log['action']) ?></span></td>
                        <td><?= h($log['entity_type']) ?><?= $log['entity_id'] !== null ? ' #' . h($log['entity_id']) : '' ?></td>
                        <td style="color:var(--muted);font-size:12px;max-width:360px;white-space:normal"><?= h($log['details'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
    <?php
    render_footer();
}
