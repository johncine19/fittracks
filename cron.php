<?php
require __DIR__ . '/core/bootstrap.php';

if (php_sapi_name() !== 'cli') {
    $expectedKey = (string) (app_env('CRON_SECRET') ?: 'fittracks_secret_cron_2026');
    $providedKey = (string) ($_GET['key'] ?? '');
    if ($expectedKey === '' || $providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
        http_response_code(403);
        die('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// Determine execution mode: 'all' (default, runs everything / daily midnight tasks) vs 'frequent' (lightweight 10-15m tasks like class reminders & geofence sweeps)
$mode = 'all';
if (!empty($_GET['mode'])) {
    $mode = strtolower(trim((string) $_GET['mode']));
} elseif (php_sapi_name() === 'cli') {
    foreach ($argv ?? [] as $arg) {
        if ($arg === 'frequent' || $arg === '--frequent' || strpos($arg, '--mode=frequent') !== false) {
            $mode = 'frequent';
            break;
        }
    }
}

$isFrequentOnly = ($mode === 'frequent' || $mode === 'fast' || $mode === 'reminders');

// Prevent overlapping cron executions from exhausting database/worker resources
$redisLock = function_exists('redis') ? redis() : null;
$lockKey = $isFrequentOnly ? 'lock:cron_frequent' : 'lock:cron_execution';
if ($redisLock !== null) {
    try {
        if (!$redisLock->set($lockKey, '1', 'EX', 60, 'NX')) {
            echo "Cron execution ({$mode}) already in progress. Exiting.\n";
            exit;
        }
    } catch (Throwable) {}
}

echo "Starting Fittracks Background Tasks [Mode: {$mode}]...\n";

if (!$isFrequentOnly) {
    // 1. Auto check-out unclosed sessions from previous days (PH Time midnight task)
    echo "Checking for unclosed attendance records from past days...\n";
    $autoCheckedOutCount = auto_checkout_past_attendance(null, true);
    echo "Auto check-out completed: {$autoCheckedOutCount} session(s) closed at 23:59:59.\n";
}

// 1b. Auto check-out visits whose 20-minute geofence exit grace period expired (Runs on all & frequent)
echo "Sweeping expired geofence exit grace periods...\n";
require_once __DIR__ . '/core/attendance_geofence_service.php';
$geofenceExpiredCount = process_expired_geofence_sessions();
echo "Geofence exit sweep completed: {$geofenceExpiredCount} session(s) closed.\n";

if (!$isFrequentOnly) {
    // 2. Queue the previous day's EOD summary for eligible Business gyms.
    echo "Queueing automated End-of-Day summaries for yesterday...\n";
    $eodResults = gym_queue_automated_eod_summaries(date('Y-m-d', strtotime('yesterday')));
    echo "EOD summaries: {$eodResults['queued']} queued, {$eodResults['already_queued']} already queued, {$eodResults['disabled_or_ineligible']} disabled/ineligible, {$eodResults['failed']} failed.\n";

    // 3. Smart Tiered Notification Cleanup (Read > 30 days, Unread/System > 60 days)
    echo "Purging old notifications (Smart Tiered: 30d read, 60d unread)...\n";
    $cleanupResults = cleanup_old_notifications(30, 60);
    echo "Notification cleanup completed: {$cleanupResults['read_deleted']} read and {$cleanupResults['unread_deleted']} unread ({$cleanupResults['total']} total purged).\n";

    // 3b. Document Retention & Disposal (Purge rejected gym verification docs after retention window)
    $docRetentionDays = max(1, (int)(app_env('DOC_RETENTION_DAYS') ?: 30));
    echo "Purging expired verification documents (Rejected apps > {$docRetentionDays} days)...\n";
    $docCleanup = cleanup_expired_verification_documents($docRetentionDays);
    echo "Document retention cleanup completed: {$docCleanup['purged']} file(s) purged.\n";

    // 4. Auto-expire lapsed partner gym subscriptions and member plans
    echo "Checking for expired partner gym subscriptions...\n";
    $expiredGymsCount = auto_expire_lapsed_gym_subscriptions();
    echo "Gym subscription expiration check completed: {$expiredGymsCount} gym(s) transitioned to expired.\n";

    echo "Checking for expired member memberships...\n";
    $expiredMembersCount = auto_expire_lapsed_member_subscriptions();
    echo "Member membership expiration check completed: {$expiredMembersCount} membership(s) transitioned to expired.\n";

    // 5. Auto-send renewal reminders and expiration notice emails (members + gym owners)
    echo "Processing automated renewal and expiration email notices...\n";
    $emailNotices = process_all_renewal_email_notices();
    echo "Renewal emails: {$emailNotices['member_reminders']} member reminder(s), {$emailNotices['member_expired']} member expired notice(s), {$emailNotices['gym_reminders']} gym owner reminder(s), {$emailNotices['gym_expired']} gym owner expired notice(s).\n";

    // 6. Auto-sync trainer assignment end dates with member active plans and renewals
    echo "Syncing trainer assignments with member plans...\n";
    $assignSync = sync_trainer_assignments_with_memberships();
    echo "Trainer assignments synced: {$assignSync['extended']} extended, {$assignSync['ended']} ended.\n";
}

// 7. Auto-send 1-hour pre-class reminders to booked members and instructors (Runs on all & frequent)
echo "Sweeping upcoming class sessions (1-hour reminders)...\n";
$classReminders = send_upcoming_class_reminders();
echo "Class reminders: {$classReminders['members_reminded']} member(s), {$classReminders['instructors_reminded']} instructor(s) notified.\n";

// 7b. Auto-send class completion celebrations to attended members once sessions conclude (Runs on all & frequent)
echo "Sweeping concluded class sessions (completion celebrations)...\n";
$classCompletions = send_completed_class_notifications();
echo "Class completions: {$classCompletions['completed_notified']} member(s) celebrated.\n";

if (!$isFrequentOnly) {
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
}

echo "Processing background queue jobs...\n";

// Process jobs with a safe time limit to avoid HTTP timeouts (cron-job.org default is 30s)
// In frequent mode, 10s is plenty; in daily mode, 15s
$workerLimit = $isFrequentOnly ? 10 : 15;
Queue::work(200, $workerLimit);

echo "All tasks completed successfully.\n";
