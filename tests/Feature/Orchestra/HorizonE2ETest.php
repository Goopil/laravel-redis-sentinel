<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Workbench\App\Jobs\HorizonTestJob;

describe('Horizon E2E Tests with Read/Write Mode and Failover', function () {
    beforeEach(function () {
        Cache::flush();

        // Configure read/write splitting for Horizon
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);
        config()->set('horizon.use', 'phpredis-sentinel');
        config()->set('horizon.prefix', 'horizon-e2e:');
        config()->set('queue.default', 'phpredis-sentinel');
        config()->set('queue.connections.phpredis-sentinel.connection', 'phpredis-sentinel');

        // Purge Redis connections to apply new config
        $manager = app(\Goopil\LaravelRedisSentinel\RedisSentinelManager::class);

        // Update manager config via reflection
        $reflection = new ReflectionClass($manager);
        $configProp = $reflection->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($manager, config('database.redis'));

        $manager->purge('phpredis-sentinel');
    });

    test('horizon uses read/write splitting correctly', function () {
        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        $reflection = new ReflectionClass($connection);
        $wroteToMasterProp = $reflection->getProperty('wroteToMaster');
        $wroteToMasterProp->setAccessible(true);

        // Test read operation (should not trigger stickiness if read/write splitting is enabled)
        $connection->get('horizon:test:read');

        $readConnectorProp = $reflection->getProperty('readConnector');
        $readConnectorProp->setAccessible(true);

        // Check if read connector exists (indicates read/write splitting is available)
        $hasReadConnector = $readConnectorProp->getValue($connection) !== null;

        // Test write operation (should use master and activate stickiness)
        $connection->set('horizon:test:write', 'value');

        expect($wroteToMasterProp->getValue($connection))->toBeTrue('Stickiness should be activated after write');

        // If read/write splitting is available, verify it was initialized
        if ($hasReadConnector) {
            expect($readConnectorProp->getValue($connection))->not->toBeNull('Read connector should be initialized');
        }
    });

    test('horizon jobs execute correctly with read/write splitting', function () {
        $testId = 'rw_test_'.time();
        $jobCount = 10;

        // Dispatch jobs
        for ($i = 1; $i <= $jobCount; $i++) {
            $job = new HorizonTestJob("{$testId}_{$i}", [
                'iteration' => $i,
                'mode' => 'read-write-test',
            ]);
            $job->handle();
        }

        // Verify all jobs executed successfully
        for ($i = 1; $i <= $jobCount; $i++) {
            $jobId = "{$testId}_{$i}";
            expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue()
                ->and(Cache::get("horizon:job:{$jobId}:metadata"))->toHaveKey('mode', 'read-write-test');
        }
    });

    test('horizon handles high load with read/write splitting', function () {
        $testId = 'load_test_'.time();
        $jobCount = 50;
        $startTime = microtime(true);

        // Simulate high load - dispatch many jobs
        for ($i = 1; $i <= $jobCount; $i++) {
            $job = new HorizonTestJob("{$testId}_{$i}", [
                'iteration' => $i,
                'priority' => $i % 3 === 0 ? 'high' : 'normal',
                'user_id' => ($i % 5) + 1, // Distribute across 5 users
            ]);
            $job->handle();
        }

        $executionTime = microtime(true) - $startTime;

        // Verify all jobs completed
        $successCount = 0;
        for ($i = 1; $i <= $jobCount; $i++) {
            if (Cache::get("horizon:job:{$testId}_{$i}:executed")) {
                $successCount++;
            }
        }

        expect($successCount)->toBe($jobCount, "All {$jobCount} jobs should complete successfully")
            ->and($executionTime)->toBeLessThan(30, 'Load test should complete in reasonable time');
    });

    test('redis sentinel connection remains stable during continuous job processing', function () {
        $connection = Redis::connection('phpredis-sentinel');
        $testId = 'stability_'.time();
        $rounds = 5;
        $jobsPerRound = 10;

        for ($round = 1; $round <= $rounds; $round++) {
            // Process a batch of jobs
            for ($i = 1; $i <= $jobsPerRound; $i++) {
                $jobId = "{$testId}_r{$round}_j{$i}";
                $job = new HorizonTestJob($jobId, ['round' => $round, 'job' => $i]);
                $job->handle();

                expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue();
            }

            // Verify connection is still healthy between rounds
            $pingResult = $connection->ping();
            expect($pingResult)->toBeTrue('Connection should remain healthy');

            // Small delay between rounds
            usleep(200000); // 200ms
        }

        // Verify total job count
        $totalJobs = $rounds * $jobsPerRound;
        $executedCount = 0;

        for ($round = 1; $round <= $rounds; $round++) {
            for ($i = 1; $i <= $jobsPerRound; $i++) {
                $jobId = "{$testId}_r{$round}_j{$i}";
                if (Cache::get("horizon:job:{$jobId}:executed")) {
                    $executedCount++;
                }
            }
        }

        expect($executedCount)->toBe($totalJobs, "All {$totalJobs} jobs should execute successfully");
    });

    test('horizon job processing with mixed read/write operations', function () {
        $testId = 'mixed_ops_'.time();
        $jobCount = 20;
        $connection = Redis::connection('phpredis-sentinel');
        $counterKey = "horizon:counter:test:{$testId}";

        // Initialize counter to ensure clean state
        $connection->del($counterKey);

        for ($i = 1; $i <= $jobCount; $i++) {
            // Alternate between read-heavy and write-heavy operations
            if ($i % 2 === 0) {
                // Read-heavy: multiple reads
                $connection->get("horizon:read:test:{$i}");
                $connection->get('horizon:read:test:'.($i - 1));
                $connection->get('horizon:read:test:'.($i + 1));
            } else {
                // Write-heavy: multiple writes
                $connection->set("horizon:write:test:{$i}", "value_{$i}");
                $connection->incr($counterKey);
            }

            // Execute job
            $jobId = "{$testId}_{$i}";
            $job = new HorizonTestJob($jobId, [
                'operation_type' => $i % 2 === 0 ? 'read-heavy' : 'write-heavy',
            ]);
            $job->handle();

            expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue();
        }

        // Verify counter was incremented correctly (10 times for odd numbers: 1,3,5,7,9,11,13,15,17,19)
        $counterValue = (int) $connection->get($counterKey);
        expect($counterValue)->toBe(10, 'Counter should be incremented 10 times');
    });

    test('horizon jobs recover after connection reset', function () {
        $testId = 'recovery_'.time();
        $connection = Redis::connection('phpredis-sentinel');

        // Execute some jobs before reset
        for ($i = 1; $i <= 5; $i++) {
            $jobId = "{$testId}_before_{$i}";
            $job = new HorizonTestJob($jobId, ['phase' => 'before-reset']);
            $job->handle();
            expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue();
        }

        // Force a connection reset (simulate network issue)
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Ignore disconnect errors
        }

        // Wait a moment
        usleep(500000); // 500ms

        // Connection should automatically reconnect
        // Execute jobs after reset
        for ($i = 1; $i <= 5; $i++) {
            $jobId = "{$testId}_after_{$i}";
            $job = new HorizonTestJob($jobId, ['phase' => 'after-reset']);
            $job->handle();
            expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue();
        }

        // Verify all 10 jobs completed
        $beforeCount = 0;
        $afterCount = 0;

        for ($i = 1; $i <= 5; $i++) {
            if (Cache::get("horizon:job:{$testId}_before_{$i}:executed")) {
                $beforeCount++;
            }
            if (Cache::get("horizon:job:{$testId}_after_{$i}:executed")) {
                $afterCount++;
            }
        }

        expect($beforeCount)->toBe(5, 'All jobs before reset should succeed')
            ->and($afterCount)->toBe(5, 'All jobs after reset should succeed');
    });

    test('horizon maintains job order with read/write splitting', function () {
        $testId = 'order_test_'.time();
        $jobCount = 15;
        $timestamps = [];

        // Execute jobs sequentially
        for ($i = 1; $i <= $jobCount; $i++) {
            $jobId = "{$testId}_{$i}";
            $job = new HorizonTestJob($jobId, ['sequence' => $i]);
            $job->handle();

            $timestamp = Cache::get("horizon:job:{$jobId}:timestamp");
            expect($timestamp)->not->toBeNull();
            $timestamps[] = $timestamp;

            // Small delay to ensure different timestamps
            usleep(50000); // 50ms
        }

        // Verify timestamps are in ascending order
        $sortedTimestamps = $timestamps;
        sort($sortedTimestamps);

        expect($timestamps)->toBe($sortedTimestamps, 'Job execution order should be maintained');
    });

    test('horizon handles concurrent job processing with read/write splitting', function () {
        $testId = 'concurrent_'.time();
        $batchSize = 25;
        $connection = Redis::connection('phpredis-sentinel');

        // Simulate concurrent job execution by rapidly dispatching jobs
        $startTime = microtime(true);

        for ($i = 1; $i <= $batchSize; $i++) {
            $jobId = "{$testId}_{$i}";

            // Alternate between different operations to test concurrency
            if ($i % 3 === 0) {
                $connection->incr('horizon:concurrent:counter');
            } elseif ($i % 3 === 1) {
                $connection->lpush('horizon:concurrent:list', $i);
            } else {
                $connection->sadd('horizon:concurrent:set', $i);
            }

            $job = new HorizonTestJob($jobId, ['batch' => 'concurrent', 'index' => $i]);
            $job->handle();
        }

        $duration = microtime(true) - $startTime;

        // Verify all jobs completed
        $completedJobs = 0;
        for ($i = 1; $i <= $batchSize; $i++) {
            if (Cache::get("horizon:job:{$testId}_{$i}:executed")) {
                $completedJobs++;
            }
        }

        expect($completedJobs)->toBe($batchSize, 'All concurrent jobs should complete')
            ->and($duration)->toBeLessThan(15, 'Concurrent processing should be efficient');

        // Verify concurrent operations
        $counterValue = (int) $connection->get('horizon:concurrent:counter');
        $listLength = (int) $connection->llen('horizon:concurrent:list');
        $setSize = (int) $connection->scard('horizon:concurrent:set');

        expect($counterValue)->toBeGreaterThan(0)
            ->and($listLength)->toBeGreaterThan(0)
            ->and($setSize)->toBeGreaterThan(0);
    });

    test('horizon read operations use replicas and write operations use master', function () {
        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        $reflection = new ReflectionClass($connection);
        $wroteToMasterProp = $reflection->getProperty('wroteToMaster');
        $wroteToMasterProp->setAccessible(true);

        // Start with no stickiness
        expect($wroteToMasterProp->getValue($connection))->toBeFalse();

        // Perform multiple read operations
        for ($i = 1; $i <= 5; $i++) {
            $connection->get("horizon:read:key:{$i}");
        }

        // Should still not have stickiness
        expect($wroteToMasterProp->getValue($connection))->toBeFalse('Reads should not trigger stickiness');

        // Perform a write operation
        $connection->set('horizon:write:key', 'value');

        // Now stickiness should be active
        expect($wroteToMasterProp->getValue($connection))->toBeTrue('Write should trigger stickiness');

        // Subsequent reads should use master due to stickiness
        for ($i = 1; $i <= 5; $i++) {
            $connection->get("horizon:read:after:write:{$i}");
        }

        // Stickiness should remain
        expect($wroteToMasterProp->getValue($connection))->toBeTrue('Stickiness should persist');
    });
});
