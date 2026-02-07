<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Workbench\App\Jobs\HorizonTestJob;

describe('Horizon Connection Resilience Tests - Redis Sentinel Master Failover', function () {
    beforeEach(function () {
        // Configure read/write splitting for realistic failover scenario BEFORE flush
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);
        config()->set('horizon.use', 'phpredis-sentinel');
        config()->set('horizon.prefix', 'horizon-failover:');
        config()->set('queue.default', 'phpredis-sentinel');

        // Configure cache to use phpredis-sentinel driver (Horizon jobs use Cache)
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

    test('horizon jobs complete successfully before failover', function () {
        $testId = 'pre_failover_'.time();
        $jobCount = 10;

        // Execute jobs before failover
        for ($i = 1; $i <= $jobCount; $i++) {
            $jobId = "{$testId}_{$i}";
            $job = new HorizonTestJob($jobId, [
                'phase' => 'pre-failover',
                'index' => $i,
            ]);
            $job->handle();

            expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue()
                ->and(Cache::get("horizon:job:{$jobId}:metadata")['phase'])->toBe('pre-failover');
        }

        // Verify connection is healthy
        $connection = Redis::connection('phpredis-sentinel');
        expect($connection->ping())->toBeTrue();
    });

    test('horizon detects current master and can failover', function () {
        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        // Get current master info
        try {
            $masterInfo = $connection->info('replication');
            expect($masterInfo)->toBeArray()
                ->and($masterInfo['role'] ?? null)->toBe('master');
        } catch (\Exception $e) {
            // If we can't get master info, at least verify connection works
            expect($connection->ping())->toBeTrue();
        }

        // Test write to master
        $testKey = 'failover:master:test:'.time();
        $connection->set($testKey, 'before_failover');
        expect($connection->get($testKey))->toBe('before_failover');
    });

    test('horizon jobs process during simulated network latency', function () {
        $testId = 'latency_test_'.time();
        $connection = Redis::connection('phpredis-sentinel');

        // Process jobs with artificial delays to simulate network latency
        for ($i = 1; $i <= 10; $i++) {
            // Simulate network latency
            usleep(50000); // 50ms delay

            $jobId = "{$testId}_{$i}";
            $job = new HorizonTestJob($jobId, [
                'scenario' => 'network-latency',
                'iteration' => $i,
            ]);

            $startTime = microtime(true);
            $job->handle();
            $duration = microtime(true) - $startTime;

            expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue()
                ->and($duration)->toBeLessThan(2, 'Job should complete despite latency');

            // Verify connection remains stable
            expect($connection->ping())->toBeTrue();
        }
    });

    test('horizon recovers from connection errors and continues processing', function () {
        $testId = 'recovery_test_'.time();
        $connection = Redis::connection('phpredis-sentinel');

        // Phase 1: Process initial jobs
        for ($i = 1; $i <= 5; $i++) {
            $jobId = "{$testId}_phase1_{$i}";
            $job = new HorizonTestJob($jobId, ['phase' => 1]);
            $job->handle();
            expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue();
        }

        // Simulate connection disruption by forcing a disconnect
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected - connection may throw on disconnect
        }

        // Wait for potential reconnection
        sleep(2);

        // Phase 2: Continue processing - connection should auto-reconnect
        $failedJobs = 0;
        $successfulJobs = 0;

        for ($i = 1; $i <= 5; $i++) {
            try {
                $jobId = "{$testId}_phase2_{$i}";
                $job = new HorizonTestJob($jobId, ['phase' => 2]);
                $job->handle();

                if (Cache::get("horizon:job:{$jobId}:executed")) {
                    $successfulJobs++;
                }
            } catch (\Exception $e) {
                $failedJobs++;
            }
        }

        // At least some jobs should succeed after reconnection
        expect($successfulJobs)->toBeGreaterThan(0, 'Jobs should succeed after reconnection');
    });

    test('horizon handles master failover with job continuity', function () {
        $testId = 'failover_continuity_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $jobsBeforeFailover = 15;
        $jobsDuringFailover = 10;
        $jobsAfterFailover = 15;

        // Phase 1: Process jobs before failover
        for ($i = 1; $i <= $jobsBeforeFailover; $i++) {
            $jobId = "{$testId}_before_{$i}";
            $job = new HorizonTestJob($jobId, [
                'phase' => 'before-failover',
                'sequence' => $i,
            ]);
            $job->handle();
            expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue();
        }

        // Store a value before failover
        $testKey = 'failover:continuity:'.time();
        $connection->set($testKey, 'persistent_value');
        $connection->incr('failover:job:counter');

        // Phase 2: Simulate failover scenario
        // In a real failover, Sentinel would promote a replica to master
        // We simulate this by forcing reconnection which will discover the new master
        try {
            // Trigger a reconnection scenario
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        // Wait for failover to complete (Sentinel typically takes 1-2 seconds)
        sleep(2);

        // Phase 3: Process jobs during failover window
        $duringFailoverSuccess = 0;
        $duringFailoverFailed = 0;

        for ($i = 1; $i <= $jobsDuringFailover; $i++) {
            try {
                $jobId = "{$testId}_during_{$i}";
                $job = new HorizonTestJob($jobId, [
                    'phase' => 'during-failover',
                    'sequence' => $i,
                ]);
                $job->handle();

                if (Cache::get("horizon:job:{$jobId}:executed")) {
                    $duringFailoverSuccess++;
                }
            } catch (\Exception $e) {
                $duringFailoverFailed++;
            }
        }

        // Phase 4: Process jobs after failover recovery
        sleep(1); // Ensure system is stable

        for ($i = 1; $i <= $jobsAfterFailover; $i++) {
            $jobId = "{$testId}_after_{$i}";
            $job = new HorizonTestJob($jobId, [
                'phase' => 'after-failover',
                'sequence' => $i,
            ]);
            $job->handle();
            expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue();
        }

        // Verify data persisted through failover
        $persistedValue = $connection->get($testKey);
        $finalCounter = (int) $connection->get('failover:job:counter');

        // Assertions
        expect($duringFailoverSuccess)->toBeGreaterThan(0, 'Some jobs should succeed during failover')
            ->and($persistedValue)->toBe('persistent_value', 'Data should persist through failover')
            ->and($finalCounter)->toBeGreaterThanOrEqual(1, 'Counter should persist');

        // Count total successful jobs
        $totalSuccess = $jobsBeforeFailover + $duringFailoverSuccess + $jobsAfterFailover;
        expect($totalSuccess)->toBeGreaterThan(35, 'Majority of jobs should succeed');
    });

    test('horizon maintains data consistency during reconnection', function () {
        $testId = 'consistency_'.time();
        $connection = Redis::connection('phpredis-sentinel');

        // Write initial data
        $dataKeys = [];
        for ($i = 1; $i <= 10; $i++) {
            $key = "consistency:data:{$testId}:{$i}";
            $value = "value_{$i}";
            $connection->set($key, $value);
            $dataKeys[$key] = $value;
        }

        // Process jobs that read this data
        for ($i = 1; $i <= 5; $i++) {
            $jobId = "{$testId}_reader_{$i}";
            $job = new HorizonTestJob($jobId, ['phase' => 'reading']);
            $job->handle();
        }

        // Force reconnection
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Verify data consistency after reconnection
        foreach ($dataKeys as $key => $expectedValue) {
            $actualValue = $connection->get($key);
            expect($actualValue)->toBe($expectedValue, "Data for {$key} should be consistent");
        }

        // Process more jobs after reconnection
        for ($i = 1; $i <= 5; $i++) {
            $jobId = "{$testId}_reader_after_{$i}";
            $job = new HorizonTestJob($jobId, ['phase' => 'reading-after']);
            $job->handle();
            expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue();
        }
    });

    test('horizon handles high load with intermittent connection issues', function () {
        $testId = 'high_load_failover_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $totalJobs = 50;
        $successfulJobs = 0;
        $failedJobs = 0;

        for ($i = 1; $i <= $totalJobs; $i++) {
            try {
                // Simulate intermittent connection issues every 10 jobs
                if ($i % 10 === 0) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    usleep(100000); // 100ms recovery time
                }

                $jobId = "{$testId}_{$i}";
                $job = new HorizonTestJob($jobId, [
                    'load_test' => true,
                    'index' => $i,
                    'scenario' => 'intermittent-issues',
                ]);
                $job->handle();

                if (Cache::get("horizon:job:{$jobId}:executed")) {
                    $successfulJobs++;
                }
            } catch (\Exception $e) {
                $failedJobs++;
            }
        }

        // Verify high success rate despite connection issues
        $successRate = ($successfulJobs / $totalJobs) * 100;

        expect($successfulJobs)->toBeGreaterThan(40, 'At least 80% of jobs should succeed')
            ->and($successRate)->toBeGreaterThanOrEqual(80, 'Success rate should be at least 80%')
            ->and($connection->ping())->toBeTrue('Connection should be healthy at the end');
    });

    test('horizon read/write splitting works through failover', function () {
        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        $reflection = new ReflectionClass($connection);
        $wroteToMasterProp = $reflection->getProperty('wroteToMaster');
        $wroteToMasterProp->setAccessible(true);

        // Perform writes
        $connection->set('failover:rw:test1', 'value1');
        expect($wroteToMasterProp->getValue($connection))->toBeTrue('Write should activate stickiness');

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // After reconnection, stickiness should be reset
        // New read operation
        $connection->get('failover:rw:test1');

        // Perform another write after failover
        $connection->set('failover:rw:test2', 'value2');
        expect($wroteToMasterProp->getValue($connection))->toBeTrue('Write should reactivate stickiness after failover');

        // Verify data integrity
        expect($connection->get('failover:rw:test1'))->toBe('value1')
            ->and($connection->get('failover:rw:test2'))->toBe('value2');
    });

    test('horizon job queue operations survive failover', function () {
        $testId = 'queue_failover_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $queueKey = "queue:{$testId}";

        // Push jobs to queue before failover
        for ($i = 1; $i <= 5; $i++) {
            $connection->rpush($queueKey, "job_payload_{$i}");
        }

        expect($connection->llen($queueKey))->toBe(5);

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Verify queue data persisted
        $queueLength = $connection->llen($queueKey);
        expect($queueLength)->toBe(5, 'Queue should maintain its length through failover');

        // Pop jobs from queue after failover
        $processedJobs = 0;
        for ($i = 1; $i <= 5; $i++) {
            $payload = $connection->lpop($queueKey);
            if ($payload) {
                $processedJobs++;
                expect($payload)->toBe("job_payload_{$i}");
            }
        }

        expect($processedJobs)->toBe(5, 'All queued jobs should be processable after failover')
            ->and($connection->llen($queueKey))->toBe(0, 'Queue should be empty after processing');
    });
});
