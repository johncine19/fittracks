<?php
require __DIR__ . '/core/bootstrap.php';

echo "<h1>Queue Worker Diagnostic</h1>";
echo "<pre>";

$pdo = db();
$jobs = $pdo->query('SELECT * FROM jobs')->fetchAll(PDO::FETCH_ASSOC);

echo "Currently pending jobs in database: " . count($jobs) . "\n";
print_r($jobs);

echo "\n--- Running Queue Worker Manually ---\n";

ob_start();
try {
    Queue::work(5);
    echo "Queue worker executed successfully.\n";
} catch (Throwable $e) {
    echo "Queue Worker Exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
$output = ob_get_clean();
echo $output;

echo "\n--- Re-checking jobs table ---\n";
$jobsAfter = $pdo->query('SELECT * FROM jobs')->fetchAll(PDO::FETCH_ASSOC);
echo "Jobs left: " . count($jobsAfter) . "\n";
print_r($jobsAfter);

echo "</pre>";
