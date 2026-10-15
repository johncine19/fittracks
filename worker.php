<?php
/**
 * FitTracks Background Queue Worker
 *
 * Designed for horizontal scaling. Run multiple instances concurrently via:
 *   - Docker: docker-compose up --scale worker=4
 *   - Supervisord: numprocs=4
 *   - CLI / Systemd: php worker.php --limit=20 --sleep=2
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Access denied. Queue workers can only be executed via the command line.\n");
}

require __DIR__ . '/core/bootstrap.php';

// Parse CLI flags (e.g. php worker.php --limit=20 --sleep=1 --max-jobs=500 --max-iterations=10)
$options = getopt('', ['limit::', 'sleep::', 'max-jobs::', 'max-iterations::', 'memory-limit::', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
FitTracks Horizontal Queue Worker
Usage: php worker.php [options]

Options:
  --limit=N          Maximum jobs to process per iteration (default: 20)
  --sleep=N          Seconds to sleep when queue is empty (default: 2)
  --max-jobs=N       Restart worker after processing N jobs (default: 0 / unlimited)
  --max-iterations=N Stop worker after N loop iterations (useful for testing, default: 0)
  --memory-limit=N   Restart worker if memory usage exceeds N megabytes (default: 128)
  --help             Show this help screen

HELP;
    exit(0);
}

$limitPerBatch   = isset($options['limit']) ? max(1, (int)$options['limit']) : 20;
$idleSleepSec    = isset($options['sleep']) ? max(1, (int)$options['sleep']) : 2;
$maxJobs         = isset($options['max-jobs']) ? max(0, (int)$options['max-jobs']) : 0;
$maxIterations   = isset($options['max-iterations']) ? max(0, (int)$options['max-iterations']) : 0;
$memoryLimitMb   = isset($options['memory-limit']) ? max(32, (int)$options['memory-limit']) : 128;

$workerPid = getmypid();
$shouldRun = true;
$totalJobsProcessed = 0;
$iterationCount = 0;

// POSIX Signal handling for graceful termination (SIGTERM, SIGINT) on Linux / Docker / Render
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $shutdownHandler = function (int $signo) use (&$shouldRun, $workerPid): void {
        $sigName = $signo === SIGTERM ? 'SIGTERM' : 'SIGINT';
        echo "[" . date('Y-m-d H:i:s') . "] [Worker #{$workerPid}] {$sigName} received. Shutting down gracefully after current job...\n";
        $shouldRun = false;
    };
    pcntl_signal(SIGTERM, $shutdownHandler);
    pcntl_signal(SIGINT, $shutdownHandler);
}

echo "[" . date('Y-m-d H:i:s') . "] [Worker #{$workerPid}] FitTracks Queue Worker online.\n";
echo "[" . date('Y-m-d H:i:s') . "] [Worker #{$workerPid}] Batch limit: {$limitPerBatch} | Idle sleep: {$idleSleepSec}s | Max memory: {$memoryLimitMb}MB\n";

while ($shouldRun) {
    $iterationCount++;
    $processedCount = 0;

    try {
        // Run queue work batch
        $processedCount = Queue::work($limitPerBatch, 10);
        if ($processedCount > 0) {
            $totalJobsProcessed += $processedCount;
            echo "[" . date('Y-m-d H:i:s') . "] [Worker #{$workerPid}] Processed {$processedCount} job(s) (Total: {$totalJobsProcessed}).\n";
        }
    } catch (Throwable $e) {
        error_log("[" . date('Y-m-d H:i:s') . "] [Worker #{$workerPid}] Queue work error: " . $e->getMessage());
    }

    // Memory protection check
    $memUsageMb = round(memory_get_usage(true) / 1024 / 1024, 2);
    if ($memUsageMb >= $memoryLimitMb) {
        echo "[" . date('Y-m-d H:i:s') . "] [Worker #{$workerPid}] Memory threshold exceeded ({$memUsageMb}MB >= {$memoryLimitMb}MB). Exiting cleanly for supervisor restart.\n";
        break;
    }

    if ($maxJobs > 0 && $totalJobsProcessed >= $maxJobs) {
        echo "[" . date('Y-m-d H:i:s') . "] [Worker #{$workerPid}] Processed {$totalJobsProcessed} jobs. Max jobs reached, exiting cleanly.\n";
        break;
    }

    if ($maxIterations > 0 && $iterationCount >= $maxIterations) {
        echo "[" . date('Y-m-d H:i:s') . "] [Worker #{$workerPid}] Reached {$maxIterations} iteration(s). Exiting.\n";
        break;
    }

    // If no jobs were available, sleep to prevent CPU thrashing; otherwise loop immediately for next batch
    if ($processedCount === 0) {
        sleep($idleSleepSec);
    }
}

echo "[" . date('Y-m-d H:i:s') . "] [Worker #{$workerPid}] Worker stopped cleanly.\n";
exit(0);
