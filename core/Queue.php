<?php
declare(strict_types=1);

class Queue
{
    private const REDIS_QUEUE_KEY = 'queue:default';

    /**
     * Push a job onto the queue.
     * @param string $jobClass The name of the function or class to execute.
     * @param array $payload The arguments to pass to the job.
     */
    public static function push(string $jobClass, array $payload = []): void
    {
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
     */
    public static function work(int $limit = 50, int $timeLimitSeconds = 0): void
    {
        $redis = function_exists('redis') ? redis() : null;
        $startTime = time();

        error_log("Starting worker loop...");

        // 1. Process Redis queue first if available
        if ($redis !== null) {
            try {
                $processed = 0;
                while ($processed < $limit) {
                    if ($timeLimitSeconds > 0 && (time() - $startTime) >= $timeLimitSeconds) {
                        error_log("Worker time limit reached ({$timeLimitSeconds}s). Stopping.");
                        return;
                    }

                    $raw = $redis->lpop(self::REDIS_QUEUE_KEY);
                    if (!$raw) {
                        break;
                    }

                    $item = json_decode($raw, true);
                    $jobClass = $item['job_class'] ?? null;
                    $payload = $item['payload'] ?? [];

                    try {
                        if ($jobClass && function_exists($jobClass)) {
                            call_user_func_array($jobClass, [$payload]);
                        } else {
                            error_log("Job handler not found: $jobClass");
                        }
                    } catch (Throwable $e) {
                        error_log("Failed Redis job: $jobClass - Error: " . $e->getMessage());
                    }

                    $processed++;
                }
            } catch (Throwable $e) {
                error_log("Redis worker error: " . $e->getMessage());
            }
        }

        // 2. Process MySQL database jobs if any exist
        $pdo = db();
        for ($i = 0; $i < $limit; $i++) {
            if ($timeLimitSeconds > 0 && (time() - $startTime) >= $timeLimitSeconds) {
                error_log("Worker time limit reached ({$timeLimitSeconds}s). Stopping.");
                break;
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT * FROM jobs WHERE available_at <= ? AND attempts < 3 ORDER BY id ASC LIMIT 1 FOR UPDATE');
            $stmt->execute([time()]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$job) {
                $pdo->rollBack();
                break;
            }

            try {
                $pdo->prepare('UPDATE jobs SET attempts = attempts + 1 WHERE id = ?')->execute([$job['id']]);

                $jobClass = $job['job_class'];
                $payload = json_decode($job['payload'], true);

                if (function_exists($jobClass)) {
                    call_user_func_array($jobClass, [$payload]);
                } else {
                    throw new Exception("Job class/function '$jobClass' not found.");
                }

                $pdo->prepare('DELETE FROM jobs WHERE id = ?')->execute([$job['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log("Failed DB job ID: {$job['id']} - Error: " . $e->getMessage());
            }
        }
    }
}
