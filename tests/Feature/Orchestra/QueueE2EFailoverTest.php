<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Workbench\App\Jobs\ProcessOrderJob;

describe('Queue E2E Failover Tests with Read/Write Mode', function () {
    beforeEach(function () {
        // Configure read/write splitting BEFORE flush
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);
        config()->set('queue.default', 'phpredis-sentinel');
        config()->set('queue.connections.phpredis-sentinel', [
            'driver' => 'redis',
            'connection' => 'phpredis-sentinel',
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => 90,
            'block_for' => null,
        ]);

        // Configure cache to use phpredis-sentinel driver (jobs use Cache)
        config()->set('cache.default', 'phpredis-sentinel');
        config()->set('cache.stores.phpredis-sentinel', [
            'driver' => 'phpredis-sentinel',
            'connection' => 'phpredis-sentinel',
            'lock_connection' => 'phpredis-sentinel',
        ]);

        // Purge connections
        $manager = app(\Goopil\LaravelRedisSentinel\RedisSentinelManager::class);

        $reflection = new ReflectionClass($manager);
        $configProp = $reflection->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($manager, config('database.redis'));

        $manager->purge('phpredis-sentinel');

        // Now safe to flush
        try {
            Cache::flush();
        } catch (\Exception $e) {
            // Ignore flush errors in setup
        }
    });

    test('queue operations use read/write splitting correctly', function () {
        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        $reflection = new ReflectionClass($connection);
        $wroteToMasterProp = $reflection->getProperty('wroteToMaster');
        $wroteToMasterProp->setAccessible(true);

        // Queue push is a write operation
        $connection->rpush('queues:test', 'job_payload');
        expect($wroteToMasterProp->getValue($connection))->toBeTrue('Queue push should trigger stickiness');

        // Reset for next test
        $connection->resetStickiness();

        // Queue read operations
        $connection->llen('queues:test');
        expect($wroteToMasterProp->getValue($connection))->toBeFalse('Queue length check should not trigger stickiness');

        // Queue pop is a write operation
        $connection->lpop('queues:test');
        expect($wroteToMasterProp->getValue($connection))->toBeTrue('Queue pop should trigger stickiness');
    });

    test('queue jobs are processed correctly with read/write mode', function () {
        $testId = 'queue_rw_'.time();
        $jobCount = 20;

        // Dispatch jobs
        for ($i = 1; $i <= $jobCount; $i++) {
            $orderId = "{$testId}_order_{$i}";
            $job = new ProcessOrderJob($orderId, ['item' => "item_{$i}"]);
            $job->handle(); // Execute directly for testing

            expect(Cache::get("order:{$orderId}:processed"))->toBeTrue()
                ->and(Cache::get("order:{$orderId}:items"))->toBe(['item' => "item_{$i}"]);
        }
    });

    test('queue handles high load with read/write splitting', function () {
        $testId = 'queue_load_'.time();
        $jobCount = 100;
        $connection = Redis::connection('phpredis-sentinel');
        $queueKey = "queues:load_test:{$testId}";

        $startTime = microtime(true);

        // Rapidly push jobs to queue
        for ($i = 1; $i <= $jobCount; $i++) {
            $payload = json_encode([
                'job' => ProcessOrderJob::class,
                'data' => ['orderId' => "{$testId}_{$i}", 'items' => ["item_{$i}"]],
            ]);
            $connection->rpush($queueKey, $payload);
        }

        $pushDuration = microtime(true) - $startTime;

        // Verify queue length
        $queueLength = $connection->llen($queueKey);
        expect($queueLength)->toBe($jobCount);

        // Process all jobs
        $processedCount = 0;
        $processingStart = microtime(true);

        while ($connection->llen($queueKey) > 0) {
            $payload = $connection->lpop($queueKey);
            if ($payload) {
                $processedCount++;
            }
        }

        $processingDuration = microtime(true) - $processingStart;

        expect($processedCount)->toBe($jobCount, 'All jobs should be processed')
            ->and($pushDuration)->toBeLessThan(5, 'Pushing should be fast')
            ->and($processingDuration)->toBeLessThan(5, 'Processing should be fast');
    });

    test('queue survives connection reset during processing', function () {
        $testId = 'queue_reset_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $queueKey = "queues:reset_test:{$testId}";

        // Push jobs before reset
        for ($i = 1; $i <= 10; $i++) {
            $connection->rpush($queueKey, "job_payload_{$i}");
        }

        expect($connection->llen($queueKey))->toBe(10);

        // Process some jobs
        for ($i = 1; $i <= 5; $i++) {
            $payload = $connection->lpop($queueKey);
            expect($payload)->toBe("job_payload_{$i}");
        }

        // Force disconnection
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Continue processing after reconnection
        $remainingJobs = [];
        while ($connection->llen($queueKey) > 0) {
            $payload = $connection->lpop($queueKey);
            if ($payload) {
                $remainingJobs[] = $payload;
            }
        }

        expect($remainingJobs)->toHaveCount(5, 'Remaining 5 jobs should be processed')
            ->and($remainingJobs[0])->toBe('job_payload_6')
            ->and($remainingJobs[4])->toBe('job_payload_10');
    });

    test('queue handles failover during job processing', function () {
        $testId = 'queue_failover_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $queueKey = "queues:failover:{$testId}";
        $totalJobs = 50;

        // Phase 1: Push all jobs to queue
        for ($i = 1; $i <= $totalJobs; $i++) {
            $orderId = "{$testId}_{$i}";
            $payload = json_encode([
                'orderId' => $orderId,
                'phase' => 'initial',
                'index' => $i,
            ]);
            $connection->rpush($queueKey, $payload);
        }

        expect($connection->llen($queueKey))->toBe($totalJobs);

        // Phase 2: Process jobs with failover simulation
        $processedJobs = [];
        $failedAttempts = 0;

        for ($i = 1; $i <= $totalJobs; $i++) {
            try {
                // Simulate failover at midpoint
                if ($i === 25) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    sleep(2); // Failover window
                }

                $payload = $connection->lpop($queueKey);
                if ($payload) {
                    $jobData = json_decode($payload, true);
                    $processedJobs[] = $jobData;

                    // Execute job
                    $job = new ProcessOrderJob($jobData['orderId'], ['phase' => $jobData['phase']]);
                    $job->handle();
                }
            } catch (\Exception $e) {
                $failedAttempts++;
            }
        }

        // Verify most jobs completed
        expect(count($processedJobs))->toBeGreaterThan(45, 'At least 90% of jobs should process')
            ->and($connection->llen($queueKey))->toBeLessThan(5, 'Queue should be mostly empty');

        // Verify job execution
        foreach ($processedJobs as $jobData) {
            expect(Cache::get("order:{$jobData['orderId']}:processed"))->toBeTrue();
        }
    });

    test('queue maintains FIFO order through failover', function () {
        $testId = 'queue_fifo_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $queueKey = "queues:fifo:{$testId}";

        // Push ordered jobs
        for ($i = 1; $i <= 20; $i++) {
            $connection->rpush($queueKey, "job_{$i}");
        }

        // Process some jobs
        $firstBatch = [];
        for ($i = 1; $i <= 8; $i++) {
            $firstBatch[] = $connection->lpop($queueKey);
        }

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Process remaining jobs
        $secondBatch = [];
        while ($connection->llen($queueKey) > 0) {
            $payload = $connection->lpop($queueKey);
            if ($payload) {
                $secondBatch[] = $payload;
            }
        }

        // Verify FIFO order maintained
        expect($firstBatch)->toBe(['job_1', 'job_2', 'job_3', 'job_4', 'job_5', 'job_6', 'job_7', 'job_8'])
            ->and($secondBatch)->toBe(['job_9', 'job_10', 'job_11', 'job_12', 'job_13', 'job_14', 'job_15', 'job_16', 'job_17', 'job_18', 'job_19', 'job_20']);
    });

    test('queue delayed jobs persist through failover', function () {
        $testId = 'queue_delayed_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $delayedKey = "queues:delayed:{$testId}";

        // Add delayed jobs with timestamps
        $futureTimestamp = now()->addMinutes(5)->timestamp;
        for ($i = 1; $i <= 10; $i++) {
            $connection->zadd($delayedKey, $futureTimestamp + $i, "delayed_job_{$i}");
        }

        $delayedCount = $connection->zcard($delayedKey);
        expect($delayedCount)->toBe(10);

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Verify delayed jobs persisted
        $persistedCount = $connection->zcard($delayedKey);
        expect($persistedCount)->toBe(10, 'Delayed jobs should persist through failover');

        // Verify job data integrity
        $jobs = $connection->zrange($delayedKey, 0, -1);
        expect($jobs)->toHaveCount(10)
            ->and($jobs[0])->toBe('delayed_job_1')
            ->and($jobs[9])->toBe('delayed_job_10');
    });

    test('queue concurrent processing with intermittent failures', function () {
        $testId = 'queue_concurrent_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $queueKey = "queues:concurrent:{$testId}";
        $jobCount = 30;

        // Push jobs
        for ($i = 1; $i <= $jobCount; $i++) {
            $connection->rpush($queueKey, "concurrent_job_{$i}");
        }

        // Process with intermittent disconnections
        $processed = [];
        $disconnectPoints = [10, 20]; // Disconnect after job 10 and 20

        for ($i = 1; $i <= $jobCount; $i++) {
            try {
                if (in_array($i, $disconnectPoints)) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    usleep(500000); // 500ms recovery
                }

                $payload = $connection->lpop($queueKey);
                if ($payload) {
                    $processed[] = $payload;
                }
            } catch (\Exception $e) {
                // Retry on failure
                usleep(100000); // 100ms
                $i--; // Retry same job
            }
        }

        expect(count($processed))->toBe($jobCount, 'All jobs should eventually be processed')
            ->and($connection->llen($queueKey))->toBe(0, 'Queue should be empty');
    });

    test('queue metrics and monitoring survive failover', function () {
        $testId = 'queue_metrics_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $metricsKey = "queues:metrics:{$testId}";

        // Initialize metrics
        $connection->hset($metricsKey, 'jobs_processed', 0);
        $connection->hset($metricsKey, 'jobs_failed', 0);
        $connection->hset($metricsKey, 'total_jobs', 0);

        // Process jobs and update metrics
        for ($i = 1; $i <= 20; $i++) {
            $connection->hincrby($metricsKey, 'total_jobs', 1);
            $connection->hincrby($metricsKey, 'jobs_processed', 1);

            if ($i === 10) {
                // Simulate failover
                try {
                    $connection->disconnect();
                } catch (\Exception $e) {
                    // Expected
                }
                sleep(1);
            }
        }

        // Verify metrics persisted
        $metrics = $connection->hgetall($metricsKey);
        expect((int) $metrics['total_jobs'])->toBe(20)
            ->and((int) $metrics['jobs_processed'])->toBe(20)
            ->and((int) $metrics['jobs_failed'])->toBe(0);
    });

    test('queue priority handling with read/write mode', function () {
        $testId = 'queue_priority_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $highPriorityQueue = "queues:high:{$testId}";
        $normalPriorityQueue = "queues:normal:{$testId}";

        // Add jobs to different priority queues
        for ($i = 1; $i <= 10; $i++) {
            $connection->rpush($highPriorityQueue, "high_priority_job_{$i}");
            $connection->rpush($normalPriorityQueue, "normal_priority_job_{$i}");
        }

        // Verify queues
        expect($connection->llen($highPriorityQueue))->toBe(10)
            ->and($connection->llen($normalPriorityQueue))->toBe(10);

        // Process high priority first
        $processed = [];
        while ($connection->llen($highPriorityQueue) > 0) {
            $job = $connection->lpop($highPriorityQueue);
            if ($job) {
                $processed[] = $job;
            }
        }

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Process normal priority after failover
        while ($connection->llen($normalPriorityQueue) > 0) {
            $job = $connection->lpop($normalPriorityQueue);
            if ($job) {
                $processed[] = $job;
            }
        }

        expect(count($processed))->toBe(20)
            ->and($processed[0])->toContain('high_priority')
            ->and($processed[10])->toContain('normal_priority');
    });

    test('queue job retry mechanism works through failover', function () {
        $testId = 'queue_retry_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $queueKey = "queues:retry:{$testId}";
        $failedQueueKey = "queues:failed:{$testId}";

        // Push jobs
        for ($i = 1; $i <= 5; $i++) {
            $connection->rpush($queueKey, json_encode([
                'id' => "{$testId}_{$i}",
                'attempts' => 0,
                'max_attempts' => 3,
            ]));
        }

        // Process with simulated failures and retries
        while ($connection->llen($queueKey) > 0) {
            $payload = $connection->lpop($queueKey);
            if ($payload) {
                $jobData = json_decode($payload, true);
                $jobData['attempts']++;

                if ($jobData['attempts'] < $jobData['max_attempts']) {
                    // Retry
                    $connection->rpush($queueKey, json_encode($jobData));
                } else {
                    // Process successfully
                    $orderId = $jobData['id'];
                    Cache::put("queue:job:{$orderId}:completed", true, 3600);
                }
            }

            // Simulate failover during retry
            if ($connection->llen($queueKey) === 3) {
                try {
                    $connection->disconnect();
                } catch (\Exception $e) {
                    // Expected
                }
                sleep(1);
            }
        }

        // Verify all jobs eventually completed
        for ($i = 1; $i <= 5; $i++) {
            expect(Cache::get("queue:job:{$testId}_{$i}:completed"))->toBeTrue();
        }
    });
});
