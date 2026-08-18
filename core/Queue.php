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
     */
    public static function work(int $limit = 50): void
    {
        $redis = function_exists('redis') ? redis() : null;

        echo "Starting worker loop...\n";

        // 1. Process Redis queue first if available
        if ($redis !== null) {
            try {
                $processed = 0;
                while ($processed < $limit) {
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
                            echo "Processed Redis job: $jobClass\n";
                        } else {
                            echo "Job handler not found: $jobClass\n";
                        }
                    } catch (Throwable $e) {
                        echo "Failed Redis job: $jobClass - Error: " . $e->getMessage() . "\n";
                    }

                    $processed++;
                }

                if ($processed > 0) {
                    echo "Processed $processed Redis jobs.\n";
                }
            } catch (Throwable $e) {
                echo "Redis worker error: " . $e->getMessage() . "\n";
            }
        }

        // 2. Process MySQL database jobs if any exist
        $pdo = db();
        for ($i = 0; $i < $limit; $i++) {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT * FROM jobs WHERE available_at <= ? AND attempts < 3 ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED');
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
                echo "Processed DB job ID: {$job['id']}\n";
            } catch (Throwable $e) {
                $pdo->rollBack();
                echo "Failed DB job ID: {$job['id']} - Error: " . $e->getMessage() . "\n";
            }
        }
    }
}
