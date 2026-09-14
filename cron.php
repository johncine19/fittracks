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

// Schedule jobs instead of running them synchronously
echo "Scheduling engagement score computation...\n";
Queue::push('recompute_all_engagement_scores_batch');

echo "Scheduling automated at-risk notifications...\n";
Queue::push('process_automated_at_risk_notifications');

echo "Jobs scheduled. Starting worker to process jobs...\n";

// Process jobs with a safe 15-second time limit to avoid HTTP timeouts (cron-job.org default is 30s)
// Any remaining jobs will be picked up by the shutdown worker or subsequent runs
Queue::work(200, 15);

echo "All tasks completed successfully.\n";
