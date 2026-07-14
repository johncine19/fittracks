<?php

declare(strict_types=1);

function settings_page(): void
{
    $user = require_roles(['platform_admin']);
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $keys = ['platform_name', 'contact_email', 'registration_enabled'];
        foreach ($keys as $key) {
            if (isset($_POST[$key])) {
                $pdo->prepare('REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)')
                    ->execute([$key, $_POST[$key]]);
            }
        }
        flash('Settings updated successfully.', 'success');
        redirect('settings');
    }

    $settingsRows = $pdo->query('SELECT * FROM system_settings')->fetchAll();
    $settings = [];
    foreach ($settingsRows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    render_header('Platform Settings', $user);
?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Platform Settings</h1>
                <p>Manage global configuration for the FitTrack platform.</p>
            </div>
        </div>

        <form method="post" class="form grid-form" style="max-width: 600px;">
            <?= csrf_field() ?>
            
            <label>Platform Name
                <input type="text" name="platform_name" value="<?= h($settings['platform_name'] ?? 'FITTRACKS') ?>" required>
            </label>

            <label>Contact Email (For Support)
                <input type="email" name="contact_email" value="<?= h($settings['contact_email'] ?? 'support@fittracks.com') ?>" required>
            </label>

            <label>Registration Enabled
                <select name="registration_enabled">
                    <option value="1" <?= ($settings['registration_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Enabled (Allow new gyms and members to register)</option>
                    <option value="0" <?= ($settings['registration_enabled'] ?? '1') === '0' ? 'selected' : '' ?>>Disabled</option>
                </select>
            </label>

            <button type="submit" class="btn-primary" style="grid-column: 1 / -1; margin-top: 15px;">Save Settings</button>
        </form>
    </section>
<?php
    render_footer();
}
