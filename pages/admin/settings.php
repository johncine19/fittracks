<?php

declare(strict_types=1);

function settings_page(): void
{
    $user = require_roles(['platform_admin']);
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $keys = [
            'platform_name', 
            'contact_email', 
            'registration_enabled',
            'at_risk_inactivity_days',
            'at_risk_notification_cooldown'
        ];
        foreach ($keys as $key) {
            if (isset($_POST[$key])) {
                $pdo->prepare('REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)')
                    ->execute([$key, trim((string)$_POST[$key])]);
            }
        }
        flash('Settings updated successfully.', 'success');
        redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard');
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
                <p>Manage global configuration, member engagement thresholds, and notification schedules.</p>
            </div>
        </div>

        <form method="post" class="form grid-form" style="max-width: 650px;">
            <?= csrf_field() ?>
            
            <h3 style="grid-column: 1 / -1; margin-top: 10px; font-size: 1.1rem; color: var(--ink); border-bottom: 1px solid var(--line); padding-bottom: 8px;">General Platform</h3>

            <label>Platform Name
                <input type="text" name="platform_name" value="<?= h($settings['platform_name'] ?? 'FITTRACKS') ?>" required>
            </label>

            <label>Contact Email (For Support)
                <input type="email" name="contact_email" value="<?= h($settings['contact_email'] ?? 'support@fittracks.com') ?>" required>
            </label>

            <label style="grid-column: 1 / -1;">Registration Enabled
                <select name="registration_enabled">
                    <option value="1" <?= ($settings['registration_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Enabled (Allow new gyms and members to register)</option>
                    <option value="0" <?= ($settings['registration_enabled'] ?? '1') === '0' ? 'selected' : '' ?>>Disabled</option>
                </select>
            </label>

            <h3 style="grid-column: 1 / -1; margin-top: 20px; font-size: 1.1rem; color: var(--ink); border-bottom: 1px solid var(--line); padding-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                <span>🔔 Inactive Member Notifications</span>
            </h3>

            <label>Inactivity Threshold (Days)
                <input type="number" min="1" max="90" name="at_risk_inactivity_days" value="<?= h($settings['at_risk_inactivity_days'] ?? '3') ?>" required>
                <span class="muted" style="font-size: 12px; display: block; margin-top: 4px;">
                    Days of absence/no check-ins before a member is flagged as inactive and eligible for an automated reminder.
                </span>
            </label>

            <label>Re-send Cooldown (Days)
                <input type="number" min="1" max="180" name="at_risk_notification_cooldown" value="<?= h($settings['at_risk_notification_cooldown'] ?? '14') ?>" required>
                <span class="muted" style="font-size: 12px; display: block; margin-top: 4px;">
                    Minimum days to wait before sending another "We miss you!" reminder to the same member.
                </span>
            </label>

            <button type="submit" class="btn-primary" style="grid-column: 1 / -1; margin-top: 15px;">Save Settings</button>
        </form>
    </section>
<?php
    render_footer();
}
