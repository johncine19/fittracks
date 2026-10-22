<?php
declare(strict_types=1);

class Queue
{
    private const REDIS_QUEUE_KEY = 'queue:default';

    /**
     * Optional interceptor callback for unit tests or queue mocking.
     * @var null|Closure(string, array): void
     */
    public static ?Closure $interceptor = null;

    /**
     * Push a job onto the queue.
     * @param string $jobClass The name of the function or class to execute.
     * @param array $payload The arguments to pass to the job.
     */
    public static function push(string $jobClass, array $payload = []): void
    {
        if (self::$interceptor !== null) {
            (self::$interceptor)($jobClass, $payload);
            return;
        }

        $redis = function_exists('redis') ? redis() : null;

        if ($redis !== null) {
            try {
                $jobData = json_encode([
                    'job_class' => $jobClass,
                    'payload' => $payload,
                    'created_at' => time(),
                ]);
                $redis->rpush(self::REDIS_QUEUE_KEY, [$jobData]);
                return;
            } catch (Throwable $e) {
                error_log('Redis queue push failed, falling back to DB: ' . $e->getMessage());
            }
        }

        // MySQL Fallback
        $pdo = db();
        $stmt = $pdo->prepare('INSERT INTO jobs (job_class, payload, created_at, available_at) VALUES (?, ?, ?, ?)');
        $now = time();
        $stmt->execute([
            $jobClass,
            json_encode($payload),
            $now,
            $now
        ]);
    }

    /**
     * Process pending jobs in the queue.
     * @param int $limit Maximum number of jobs to process in this run.
     * @param int $timeLimitSeconds Maximum execution time in seconds (0 for no limit).
     * @return int Number of jobs processed in this run.
     */
    public static function work(int $limit = 50, int $timeLimitSeconds = 0): int
    {
        $redis = function_exists('redis') ? redis() : null;
        $startTime = time();
        $totalProcessed = 0;

        // 1. Process Redis queue first if available
        if ($redis !== null) {
            try {
                while ($totalProcessed < $limit) {
                    if ($timeLimitSeconds > 0 && (time() - $startTime) >= $timeLimitSeconds) {
                        return $totalProcessed;
                    }

                    $raw = $redis->lpop(self::REDIS_QUEUE_KEY);
                    if (!$raw) {
                        break;
                    }

                    $item = json_decode($raw, true);
                    $jobClass = $item['job_class'] ?? null;
                    $payload = $item['payload'] ?? [];
                    $attempts = (int)($item['attempts'] ?? 0) + 1;

                    try {
                        if ($jobClass && function_exists($jobClass)) {
                            call_user_func_array($jobClass, [$payload]);
                        } else {
                            throw new Exception("Job class/function '$jobClass' not found.");
                        }
                    } catch (Throwable $e) {
                        error_log("Failed Redis job: $jobClass - Attempt {$attempts}/3 - Error: " . $e->getMessage());
                        if ($attempts < 3) {
                            $item['attempts'] = $attempts;
                            // Push back to redis queue so it is not permanently lost
                            $redis->rpush(self::REDIS_QUEUE_KEY, [json_encode($item)]);
                        } else {
                            error_log("Redis job $jobClass exceeded max attempts (3) and was discarded.");
                        }
                    }

                    $totalProcessed++;
                }
            } catch (Throwable $e) {
                error_log("Redis worker error: " . $e->getMessage());
            }
        }

        // 2. Process MySQL database jobs if any exist
        $pdo = db();
        while ($totalProcessed < $limit) {
            if ($timeLimitSeconds > 0 && (time() - $startTime) >= $timeLimitSeconds) {
                break;
            }

            // Phase 1: Atomically claim and lease the next job with a short transaction (<1ms lock)
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT id, job_class, payload, attempts FROM jobs WHERE available_at <= ? AND attempts < 3 ORDER BY available_at ASC, id ASC LIMIT 1 FOR UPDATE');
                $stmt->execute([time()]);
                $job = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$job) {
                    $pdo->rollBack();
                    break;
                }

                $jobId = (int)$job['id'];
                $attempts = (int)$job['attempts'] + 1;
                // Lease for 300 seconds so concurrent workers don't pick up the same in-flight job
                $leaseUntil = time() + 300;
                $pdo->prepare('UPDATE jobs SET attempts = ?, available_at = ? WHERE id = ?')->execute([$attempts, $leaseUntil, $jobId]);
                $pdo->commit(); // Lock released immediately!
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Queue lease acquisition error: " . $e->getMessage());
                break;
            }

            // Phase 2: Execute the job outside the database transaction (no worker block)
            $jobClass = $job['job_class'];
            $payload = json_decode((string)$job['payload'], true) ?: [];

            try {
                if ($jobClass && function_exists($jobClass)) {
                    call_user_func_array($jobClass, [$payload]);
                } else {
                    throw new Exception("Job class/function '$jobClass' not found.");
                }

                // Succeeded! Remove from queue
                $pdo->prepare('DELETE FROM jobs WHERE id = ?')->execute([$jobId]);
                $totalProcessed++;
            } catch (Throwable $e) {
                error_log("Failed DB job ID: {$jobId} ({$jobClass}) - Attempt {$attempts}/3 - Error: " . $e->getMessage());

                try {
                    if ($attempts >= 3) {
                        // Max retries reached: remove poison job so subsequent queue jobs are not blocked
                        $pdo->prepare('DELETE FROM jobs WHERE id = ?')->execute([$jobId]);
                        error_log("DB job ID {$jobId} exceeded max attempts (3) and was discarded.");
                    } else {
                        // Exponential backoff before next attempt (15s for attempt 1, 60s for attempt 2)
                        $backoff = 15 * ($attempts ** 2);
                        $nextAvailable = time() + $backoff;
                        $pdo->prepare('UPDATE jobs SET available_at = ? WHERE id = ?')->execute([$nextAvailable, $jobId]);
                    }
                } catch (Throwable $cleanupError) {
                    error_log("Error updating failed job state for ID {$jobId}: " . $cleanupError->getMessage());
                }
            }
        }

        return $totalProcessed;
    }
}
