<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Workbench\App\Jobs\HorizonTestJob;

describe('Horizon Integration Tests WITHOUT Read/Write Splitting - Master Only', function () {
    beforeEach(function () {
        // Configure WITHOUT read/write splitting (master only mode) BEFORE flush
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', false);
        config()->set('horizon.use', 'phpredis-sentinel');
        config()->set('horizon.prefix', 'horizon-master:');
        config()->set('queue.default', 'phpredis-sentinel');
        config()->set('queue.connections.phpredis-sentinel.connection', 'phpredis-sentinel');

        // Configure cache to use phpredis-sentinel driver (Horizon jobs use Cache)
        config()->set('cache.default', 'phpredis-sentinel');
        config()->set('cache.stores.phpredis-sentinel', [
            'driver' => 'phpredis-sentinel',
            'connection' => 'phpredis-sentinel',
            'lock_connection' => 'phpredis-sentinel',
        ]);

        // Purge Redis connections to apply new config
        $manager = app(\Goopil\LaravelRedisSentinel\RedisSentinelManager::class);

        // Update manager config via reflection
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

    test('horizon uses master only mode (no read/write splitting)', function () {
        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        $reflection = new ReflectionClass($connection);
        $readConnectorProp = $reflection->getProperty('readConnector');
        $readConnectorProp->setAccessible(true);

        // In master-only mode, read connector should not be initialized
        expect($readConnectorProp->getValue($connection))->toBeNull('Read connector should be null in master-only mode');

        // Both reads and writes go to master
        $connection->get('horizon:master:read');
        $connection->set('horizon:master:write', 'value');

        // No stickiness tracking needed in master-only mode
        $wroteToMasterProp = $reflection->getProperty('wroteToMaster');
        $wroteToMasterProp->setAccessible(true);
        expect($wroteToMasterProp->getValue($connection))->toBeTrue('All operations go to master');
    });

    test('horizon processes jobs correctly in master-only mode', function () {
        $testId = 'master_only_'.time();
        $jobCount = 25;

        // Process jobs
        for ($i = 1; $i <= $jobCount; $i++) {
            $jobId = "{$testId}_{$i}";
            $job = new HorizonTestJob($jobId, [
                'iteration' => $i,
                'mode' => 'master-only',
            ]);
            $job->handle();

            expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue();
        }
    });

    test('horizon handles high load without read/write splitting', function () {
        $testId = 'master_load_'.time();
        $jobCount = 100;
        $startTime = microtime(true);

        // High load test
        for ($i = 1; $i <= $jobCount; $i++) {
            $jobId = "{$testId}_{$i}";
            $job = new HorizonTestJob($jobId, [
                'index' => $i,
                'priority' => $i % 3 === 0 ? 'high' : 'normal',
            ]);
            $job->handle();
        }

        $duration = microtime(true) - $startTime;

        // Verify all jobs completed
        $successCount = 0;
        for ($i = 1; $i <= $jobCount; $i++) {
            if (Cache::get("horizon:job:{$testId}_{$i}:executed")) {
                $successCount++;
            }
        }

        expect($successCount)->toBe($jobCount, 'All jobs should complete in master-only mode')
            ->and($duration)->toBeLessThan(30, 'Processing should be efficient');
    });

    test('horizon survives connection reset in master-only mode', function () {
        $testId = 'master_reset_'.time();
        $connection = Redis::connection('phpredis-sentinel');

        // Process jobs before reset
        for ($i = 1; $i <= 10; $i++) {
            $jobId = "{$testId}_before_{$i}";
            $job = new HorizonTestJob($jobId, ['phase' => 'before']);
            $job->handle();
            expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue();
        }

        // Force disconnection
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Process jobs after reset
        for ($i = 1; $i <= 10; $i++) {
            $jobId = "{$testId}_after_{$i}";
            $job = new HorizonTestJob($jobId, ['phase' => 'after']);
            $job->handle();
            expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue();
        }
    });

    test('horizon handles failover during job processing in master-only mode', function () {
        $testId = 'master_failover_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $totalJobs = 60;
        $successCount = 0;

        for ($i = 1; $i <= $totalJobs; $i++) {
            try {
                // Simulate failover at midpoint
                if ($i === 30) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    sleep(2);
                }

                $jobId = "{$testId}_{$i}";
                $job = new HorizonTestJob($jobId, [
                    'index' => $i,
                    'phase' => $i <= 30 ? 'before-failover' : 'after-failover',
                ]);
                $job->handle();

                if (Cache::get("horizon:job:{$jobId}:executed")) {
                    $successCount++;
                }
            } catch (\Exception $e) {
                // Expected during failover
            }
        }

        expect($successCount)->toBeGreaterThan(55, 'Most jobs should succeed despite failover');
    });

    test('horizon concurrent operations in master-only mode', function () {
        $testId = 'master_concurrent_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $operationCount = 50;

        for ($i = 1; $i <= $operationCount; $i++) {
            // Mix of operations all going to master
            $connection->set("master:key:{$i}", "value_{$i}");
            $connection->get("master:key:{$i}");
            $connection->incr("master:counter:{$testId}");

            // Process job
            $jobId = "{$testId}_{$i}";
            $job = new HorizonTestJob($jobId, ['type' => 'concurrent']);
            $job->handle();
        }

        // Verify counter
        $counterValue = (int) $connection->get("master:counter:{$testId}");
        expect($counterValue)->toBe($operationCount);

        // Verify jobs
        $jobsCompleted = 0;
        for ($i = 1; $i <= $operationCount; $i++) {
            if (Cache::get("horizon:job:{$testId}_{$i}:executed")) {
                $jobsCompleted++;
            }
        }

        expect($jobsCompleted)->toBe($operationCount);
    });

    test('horizon maintains data consistency in master-only mode', function () {
        $testId = 'master_consistency_'.time();
        $connection = Redis::connection('phpredis-sentinel');

        // Write data
        for ($i = 1; $i <= 20; $i++) {
            $connection->set("consistency:{$testId}:{$i}", "data_{$i}");
        }

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Verify data consistency
        for ($i = 1; $i <= 20; $i++) {
            $value = $connection->get("consistency:{$testId}:{$i}");
            expect($value)->toBe("data_{$i}", 'Data should be consistent after failover');
        }
    });

    test('horizon job batches in master-only mode', function () {
        $testId = 'master_batch_'.time();
        $batchCount = 5;
        $jobsPerBatch = 10;

        for ($batch = 1; $batch <= $batchCount; $batch++) {
            for ($job = 1; $job <= $jobsPerBatch; $job++) {
                $jobId = "{$testId}_b{$batch}_j{$job}";
                $jobObj = new HorizonTestJob($jobId, [
                    'batch' => $batch,
                    'job' => $job,
                ]);
                $jobObj->handle();

                expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue();
            }
        }

        // Verify all jobs completed
        $totalJobs = $batchCount * $jobsPerBatch;
        $completedJobs = 0;

        for ($batch = 1; $batch <= $batchCount; $batch++) {
            for ($job = 1; $job <= $jobsPerBatch; $job++) {
                $jobId = "{$testId}_b{$batch}_j{$job}";
                if (Cache::get("horizon:job:{$jobId}:executed")) {
                    $completedJobs++;
                }
            }
        }

        expect($completedJobs)->toBe($totalJobs);
    });

    test('horizon connection stability over time in master-only mode', function () {
        $testId = 'master_stability_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $rounds = 10;
        $jobsPerRound = 5;

        for ($round = 1; $round <= $rounds; $round++) {
            // Process jobs
            for ($i = 1; $i <= $jobsPerRound; $i++) {
                $jobId = "{$testId}_r{$round}_j{$i}";
                $job = new HorizonTestJob($jobId, ['round' => $round]);
                $job->handle();
            }

            // Verify connection health
            expect($connection->ping())->toBeTrue('Connection should remain healthy');

            // Small delay between rounds
            usleep(100000); // 100ms
        }

        // Verify all jobs completed
        $totalJobs = $rounds * $jobsPerRound;
        $completedJobs = 0;

        for ($round = 1; $round <= $rounds; $round++) {
            for ($i = 1; $i <= $jobsPerRound; $i++) {
                $jobId = "{$testId}_r{$round}_j{$i}";
                if (Cache::get("horizon:job:{$jobId}:executed")) {
                    $completedJobs++;
                }
            }
        }

        expect($completedJobs)->toBe($totalJobs);
    });

    test('horizon handles multiple intermittent failures in master-only mode', function () {
        $testId = 'master_intermittent_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $jobCount = 40;
        $successCount = 0;

        for ($i = 1; $i <= $jobCount; $i++) {
            try {
                // Trigger disconnects at multiple points
                if ($i % 10 === 0) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    usleep(500000); // 500ms recovery
                }

                $jobId = "{$testId}_{$i}";
                $job = new HorizonTestJob($jobId, ['index' => $i]);
                $job->handle();

                if (Cache::get("horizon:job:{$jobId}:executed")) {
                    $successCount++;
                }
            } catch (\Exception $e) {
                // Expected during disconnects
            }
        }

        expect($successCount)->toBeGreaterThan(35, 'Most jobs should succeed');
    });
});
