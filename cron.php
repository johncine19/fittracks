<?php
require __DIR__ . '/core/bootstrap.php';

echo "Starting Fittracks Background Tasks...\n";

// 1. Recompute all engagement scores in batch
echo "Recomputing engagement scores...\n";
recompute_all_engagement_scores_batch();
echo "Finished computing engagement scores.\n";

// 2. Process at-risk notifications
echo "Processing automated at-risk notifications...\n";
process_automated_at_risk_notifications();
echo "Finished processing notifications.\n";

echo "All tasks completed successfully.\n";
