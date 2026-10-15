<?php
if (php_sapi_name() !== 'cli') {
    if (empty($_GET['key']) || $_GET['key'] !== 'fittracks_secret_cron_2026') {
        http_response_code(403);
        die('Forbidden');
    }
}
require __DIR__ . '/core/bootstrap.php';

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

echo "Starting Fittracks Background Tasks...\n";

// 1. Auto check-out unclosed sessions from previous days (PH Time midnight task)
echo "Checking for unclosed attendance records from past days...\n";
$autoCheckedOutCount = auto_checkout_past_attendance(null, true);
echo "Auto check-out completed: {$autoCheckedOutCount} session(s) closed at 23:59:59.\n";

// 2. Queue the previous day's EOD summary for eligible Business gyms.
echo "Queueing automated End-of-Day summaries for yesterday...\n";
$eodResults = gym_queue_automated_eod_summaries(date('Y-m-d', strtotime('yesterday')));
echo "EOD summaries: {$eodResults['queued']} queued, {$eodResults['already_queued']} already queued, {$eodResults['disabled_or_ineligible']} disabled/ineligible, {$eodResults['failed']} failed.\n";

// 3. Smart Tiered Notification Cleanup (Read > 30 days, Unread/System > 60 days)
echo "Purging old notifications (Smart Tiered: 30d read, 60d unread)...\n";
$cleanupResults = cleanup_old_notifications(30, 60);
echo "Notification cleanup completed: {$cleanupResults['read_deleted']} read and {$cleanupResults['unread_deleted']} unread ({$cleanupResults['total']} total purged).\n";

// 4. Auto-expire lapsed partner gym subscriptions
echo "Checking for expired partner gym subscriptions...\n";
$expiredGymsCount = auto_expire_lapsed_gym_subscriptions();
echo "Gym subscription expiration check completed: {$expiredGymsCount} gym(s) transitioned to expired.\n";

// 5. Auto-send renewal reminders and expiration notice emails (members + gym owners)
echo "Processing automated renewal and expiration email notices...\n";
$emailNotices = process_all_renewal_email_notices();
echo "Renewal emails: {$emailNotices['member_reminders']} member reminder(s), {$emailNotices['member_expired']} member expired notice(s), {$emailNotices['gym_reminders']} gym owner reminder(s), {$emailNotices['gym_expired']} gym owner expired notice(s).\n";

// 6. Auto-sync trainer assignment end dates with member active plans and renewals
echo "Syncing trainer assignments with member plans...\n";
$assignSync = sync_trainer_assignments_with_memberships();
echo "Trainer assignments synced: {$assignSync['extended']} extended, {$assignSync['ended']} ended.\n";

// Schedule jobs instead of running them synchronously
$activeSweep = function_exists('get_engagement_sweep_lock') ? get_engagement_sweep_lock() : null;
if ($activeSweep && !empty($activeSweep['run_id']) && (time() - (int)($activeSweep['updated_at'] ?? 0)) < 1800) {
    echo "Engagement sweep already in progress (Run: {$activeSweep['run_id']}, Last User ID: {$activeSweep['last_user_id']}). Continuing existing chain...\n";
} else {
    echo "Scheduling engagement score computation...\n";
    Queue::push('recompute_all_engagement_scores_batch');
}

echo "Scheduling automated at-risk notifications...\n";
Queue::push('process_automated_at_risk_notifications');

echo "Jobs scheduled. Starting worker to process jobs...\n";

// Process jobs with a safe 15-second time limit to avoid HTTP timeouts (cron-job.org default is 30s)
// Any remaining jobs will be picked up by the shutdown worker or subsequent runs
Queue::work(200, 15);

echo "All tasks completed successfully.\n";
