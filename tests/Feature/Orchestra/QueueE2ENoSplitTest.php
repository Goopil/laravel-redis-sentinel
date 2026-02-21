<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Workbench\App\Jobs\ProcessOrderJob;

describe('Queue E2E Tests WITHOUT Read/Write Splitting - Master Only', function () {
    beforeEach(function () {
        // Configure WITHOUT read/write splitting BEFORE flush
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', false);
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
        $configProp->setValue($manager, config('database.redis'));

        $manager->purge('phpredis-sentinel');

        // Now safe to flush
        try {
            Cache::flush();
        } catch (\Exception $e) {
            // Ignore flush errors in setup
        }
    });

    test('queue operations in master-only mode', function () {
        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        $reflection = new ReflectionClass($connection);
        $readConnectorProp = $reflection->getProperty('readConnector');

        // No read connector in master-only mode
        expect($readConnectorProp->getValue($connection))->toBeNull('No read connector in master-only mode');

        // All queue operations go to master
        $queueKey = 'queues:master:test';
        $connection->rpush($queueKey, 'job1');
        $connection->lpop($queueKey);

        $wroteToMasterProp = $reflection->getProperty('wroteToMaster');
        expect($wroteToMasterProp->getValue($connection))->toBeTrue('All operations use master');
    });

    test('queue processes jobs correctly in master-only mode', function () {
        $testId = 'queue_master_'.time();
        $jobCount = 30;

        // Execute jobs
        for ($i = 1; $i <= $jobCount; $i++) {
            $orderId = "{$testId}_order_{$i}";
            $job = new ProcessOrderJob($orderId, ['item' => "item_{$i}", 'quantity' => $i]);
            $job->handle();

            expect(Cache::get("order:{$orderId}:processed"))->toBeTrue()
                ->and(Cache::get("order:{$orderId}:items"))->toBe(['item' => "item_{$i}", 'quantity' => $i]);
        }
    });

    test('queue handles high volume in master-only mode', function () {
        $testId = 'queue_volume_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $queueKey = "queues:volume:{$testId}";
        $jobCount = 150;

        $startTime = microtime(true);

        // Push many jobs
        for ($i = 1; $i <= $jobCount; $i++) {
            $payload = json_encode([
                'orderId' => "{$testId}_{$i}",
                'data' => ['item' => "product_{$i}"],
            ]);
            $connection->rpush($queueKey, $payload);
        }

        $pushDuration = microtime(true) - $startTime;

        // Verify queue
        expect($connection->llen($queueKey))->toBe($jobCount);

        // Process all jobs
        $processStart = microtime(true);
        $processed = 0;

        while ($connection->llen($queueKey) > 0) {
            $payload = $connection->lpop($queueKey);
            if ($payload) {
                $processed++;
            }
        }

        $processDuration = microtime(true) - $processStart;

        expect($processed)->toBe($jobCount)
            ->and($pushDuration)->toBeLessThan(10)
            ->and($processDuration)->toBeLessThan(10);
    });

    test('queue survives connection reset in master-only mode', function () {
        $testId = 'queue_master_reset_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $queueKey = "queues:reset:{$testId}";

        // Push jobs
        for ($i = 1; $i <= 15; $i++) {
            $connection->rpush($queueKey, "job_{$i}");
        }

        expect($connection->llen($queueKey))->toBe(15);

        // Process half
        for ($i = 1; $i <= 7; $i++) {
            $payload = $connection->lpop($queueKey);
            expect($payload)->toBe("job_{$i}");
        }

        // Disconnect
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Process remaining
        $remaining = [];
        while ($connection->llen($queueKey) > 0) {
            $payload = $connection->lpop($queueKey);
            if ($payload) {
                $remaining[] = $payload;
            }
        }

        expect($remaining)->toHaveCount(8)
            ->and($remaining[0])->toBe('job_8')
            ->and($remaining[7])->toBe('job_15');
    });

    test('queue handles failover during processing in master-only mode', function () {
        $testId = 'queue_master_failover_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $queueKey = "queues:failover:{$testId}";
        $totalJobs = 80;

        // Push all jobs
        for ($i = 1; $i <= $totalJobs; $i++) {
            $payload = json_encode(['orderId' => "{$testId}_{$i}", 'index' => $i]);
            $connection->rpush($queueKey, $payload);
        }

        // Process with failover
        $processed = [];
        for ($i = 1; $i <= $totalJobs; $i++) {
            try {
                if ($i === 40) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    sleep(2);
                }

                $payload = $connection->lpop($queueKey);
                if ($payload) {
                    $jobData = json_decode($payload, true);
                    $processed[] = $jobData;

                    // Execute job
                    $job = new ProcessOrderJob($jobData['orderId'], ['index' => $jobData['index']]);
                    $job->handle();
                }
            } catch (\Exception $e) {
                // Expected during failover
            }
        }

        expect(count($processed))->toBeGreaterThan(75, 'Most jobs should process');
    });

    test('queue FIFO order in master-only mode', function () {
        $testId = 'queue_fifo_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $queueKey = "queues:fifo:{$testId}";

        // Push ordered jobs
        for ($i = 1; $i <= 30; $i++) {
            $connection->rpush($queueKey, "ordered_job_{$i}");
        }

        // Process all
        $processedOrder = [];
        while ($connection->llen($queueKey) > 0) {
            $job = $connection->lpop($queueKey);
            if ($job) {
                $processedOrder[] = $job;
            }
        }

        // Verify order
        expect($processedOrder)->toHaveCount(30)
            ->and($processedOrder[0])->toBe('ordered_job_1')
            ->and($processedOrder[14])->toBe('ordered_job_15')
            ->and($processedOrder[29])->toBe('ordered_job_30');
    });

    test('queue delayed jobs in master-only mode', function () {
        $testId = 'queue_delayed_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $delayedKey = "queues:delayed:{$testId}";

        // Add delayed jobs
        $futureTime = now()->addMinutes(10)->timestamp;
        for ($i = 1; $i <= 15; $i++) {
            $connection->zadd($delayedKey, $futureTime + $i, "delayed_{$i}");
        }

        expect($connection->zcard($delayedKey))->toBe(15);

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Verify persistence
        expect($connection->zcard($delayedKey))->toBe(15);

        $jobs = $connection->zrange($delayedKey, 0, -1);
        expect($jobs)->toHaveCount(15)
            ->and($jobs[0])->toBe('delayed_1')
            ->and($jobs[14])->toBe('delayed_15');
    });

    test('queue concurrent processing in master-only mode', function () {
        $testId = 'queue_concurrent_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $queueKey = "queues:concurrent:{$testId}";
        $jobCount = 40;

        // Push jobs
        for ($i = 1; $i <= $jobCount; $i++) {
            $connection->rpush($queueKey, "concurrent_{$i}");
        }

        // Process with intermittent disconnects
        $processed = [];
        $disconnectAt = [10, 20, 30];

        for ($i = 1; $i <= $jobCount; $i++) {
            try {
                if (in_array($i, $disconnectAt)) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    usleep(500000);
                }

                $payload = $connection->lpop($queueKey);
                if ($payload) {
                    $processed[] = $payload;
                }
            } catch (\Exception $e) {
                usleep(100000);
                $i--;
            }
        }

        expect(count($processed))->toBe($jobCount);
    });

    test('queue metrics tracking in master-only mode', function () {
        $testId = 'queue_metrics_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $metricsKey = "queues:metrics:{$testId}";

        // Initialize
        $connection->hset($metricsKey, 'total', 0);
        $connection->hset($metricsKey, 'processed', 0);
        $connection->hset($metricsKey, 'failed', 0);

        // Process with metrics
        for ($i = 1; $i <= 30; $i++) {
            $connection->hincrby($metricsKey, 'total', 1);
            $connection->hincrby($metricsKey, 'processed', 1);

            if ($i === 15) {
                try {
                    $connection->disconnect();
                } catch (\Exception $e) {
                    // Expected
                }
                sleep(1);
            }
        }

        // Verify metrics
        $metrics = $connection->hgetall($metricsKey);
        expect((int) $metrics['total'])->toBe(30)
            ->and((int) $metrics['processed'])->toBe(30);
    });

    test('queue priority queues in master-only mode', function () {
        $testId = 'queue_priority_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $highQueue = "queues:high:{$testId}";
        $lowQueue = "queues:low:{$testId}";

        // Add to both queues
        for ($i = 1; $i <= 15; $i++) {
            $connection->rpush($highQueue, "high_priority_{$i}");
            $connection->rpush($lowQueue, "low_priority_{$i}");
        }

        // Process high priority first
        $processed = [];
        while ($connection->llen($highQueue) > 0) {
            $job = $connection->lpop($highQueue);
            if ($job) {
                $processed[] = $job;
            }
        }

        // Failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Process low priority
        while ($connection->llen($lowQueue) > 0) {
            $job = $connection->lpop($lowQueue);
            if ($job) {
                $processed[] = $job;
            }
        }

        expect(count($processed))->toBe(30)
            ->and($processed[0])->toContain('high_priority')
            ->and($processed[15])->toContain('low_priority');
    });

    test('queue job retry in master-only mode', function () {
        $testId = 'queue_retry_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $queueKey = "queues:retry:{$testId}";

        // Push jobs with retry metadata
        for ($i = 1; $i <= 8; $i++) {
            $connection->rpush($queueKey, json_encode([
                'id' => "{$testId}_{$i}",
                'attempts' => 0,
                'max' => 3,
            ]));
        }

        // Process with retries
        while ($connection->llen($queueKey) > 0) {
            $payload = $connection->lpop($queueKey);
            if ($payload) {
                $data = json_decode($payload, true);
                $data['attempts']++;

                if ($data['attempts'] < $data['max']) {
                    // Retry
                    $connection->rpush($queueKey, json_encode($data));
                } else {
                    // Complete
                    Cache::put("queue:completed:{$data['id']}", true, 3600);
                }
            }

            // Failover during retries
            if ($connection->llen($queueKey) === 5) {
                try {
                    $connection->disconnect();
                } catch (\Exception $e) {
                    // Expected
                }
                sleep(1);
            }
        }

        // Verify all completed
        for ($i = 1; $i <= 8; $i++) {
            expect(Cache::get("queue:completed:{$testId}_{$i}"))->toBeTrue();
        }
    });
});
