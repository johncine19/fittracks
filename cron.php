<?php
if (php_sapi_name() !== 'cli') {
    if (empty($_GET['key']) || $_GET['key'] !== 'fittracks_secret_cron_2026') {
        http_response_code(403);
        die('Forbidden');
    }
}
require __DIR__ . '/core/bootstrap.php';

echo "Starting Fittracks Background Tasks...\n";

// Schedule jobs instead of running them synchronously
echo "Scheduling engagement score computation...\n";
Queue::push('recompute_all_engagement_scores_batch');

echo "Scheduling automated at-risk notifications...\n";
Queue::push('process_automated_at_risk_notifications');

echo "Jobs scheduled. Starting worker to process jobs...\n";

// Process up to 100 jobs in the queue
Queue::work(100);

echo "All tasks completed successfully.\n";
