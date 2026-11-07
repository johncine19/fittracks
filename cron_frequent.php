<?php
/**
 * Dedicated endpoint for frequent background sweeps (e.g. executed every 10-15 minutes).
 * Runs lightweight tasks:
 *  - 1-hour pre-class reminders for enrolled members and instructors
 *  - Geofence exit grace period sweeps
 *  - Background email/notification queue worker
 */
$_GET['mode'] = 'frequent';
require __DIR__ . '/cron.php';

