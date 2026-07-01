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
            }
            flash('System settings updated.');
        }
        redirect('system_settings');
    }

    $settings = query_all('SELECT s.*, CONCAT(u.first_name, " ", u.last_name) AS updated_by_name
        FROM system_settings s
        LEFT JOIN users u ON u.user_id = s.updated_by
        ORDER BY s.setting_key');

    render_header('System', $user);
    ?>
    <section class="panel wide">
        <div class="page-header">
            <div>
                <h1>System Settings</h1>
                <p>Configure engagement scoring and system constants.</p>
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


    <?php
    render_footer();
}
