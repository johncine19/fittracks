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

                    try {
                        if ($jobClass && function_exists($jobClass)) {
                            call_user_func_array($jobClass, [$payload]);
                        } else {
                            error_log("Job handler not found: $jobClass");
                        }
                    } catch (Throwable $e) {
                        error_log("Failed Redis job: $jobClass - Error: " . $e->getMessage());
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
                $totalProcessed++;
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log("Failed DB job ID: {$job['id']} - Error: " . $e->getMessage());
            }
        }

        return $totalProcessed;
    }
}
