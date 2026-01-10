<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Horizon\HorizonServiceBindings;
use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Goopil\LaravelRedisSentinel\RedisSentinelServiceProvider;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Connectors\RedisConnector;
use Laravel\Horizon\Horizon;
use Symfony\Component\Process\Process;
use Workbench\App\Jobs\HorizonTestJob;

describe('Horizon Real Process E2E Tests', function () {
    beforeEach(function () {
        // Configure for Horizon context
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);
        config()->set('horizon.use', 'phpredis-sentinel');
        config()->set('horizon.driver', 'phpredis-sentinel');
        config()->set('horizon.prefix', 'horizon-process:');
        config()->set('queue.default', 'phpredis-sentinel');
        config()->set('queue.connections.phpredis-sentinel.connection', 'phpredis-sentinel');

        // Configure cache
        config()->set('cache.default', 'phpredis-sentinel');
        config()->set('cache.stores.phpredis-sentinel', [
            'driver' => 'phpredis-sentinel',
            'connection' => 'phpredis-sentinel',
            'lock_connection' => 'phpredis-sentinel',
        ]);

        // Purge and flush
        $manager = app(RedisSentinelManager::class);
        $reflection = new ReflectionClass($manager);
        $configProp = $reflection->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($manager, config('database.redis'));
        $manager->purge('phpredis-sentinel');

        try {
            Cache::flush();
        } catch (\Exception $e) {
            // Ignore flush errors
        }
    });

    test('horizon context detection works correctly', function () {
        config()->set('horizon.driver', 'phpredis-sentinel');

        $provider = new RedisSentinelServiceProvider(app());

        expect($provider->isHorizonContext())->toBeTrue('Should detect Horizon context when driver matches');
    });

    test('horizon uses HorizonRedisConnector in horizon context', function () {
        if (! class_exists(RedisConnector::class)) {
            $this->markTestSkipped('Horizon not installed');
        }

        config()->set('horizon.driver', 'phpredis-sentinel');
        config()->set('queue.connections.phpredis-sentinel.driver', 'phpredis-sentinel');
        config()->set('queue.connections.phpredis-sentinel.connection', 'phpredis-sentinel');
        config()->set('queue.connections.phpredis-sentinel.queue', 'default');

        // Re-register provider to apply horizon context
        app()->forgetInstance('queue');
        $provider = new RedisSentinelServiceProvider(app());
        $provider->boot();

        $queue = Queue::connection('phpredis-sentinel');

        // The queue should be using Horizon's RedisConnector
        expect($queue)->toBeInstanceOf(RedisQueue::class);

        // Verify it's connected to our sentinel manager
        expect($queue->getRedis())->toBeInstanceOf(RedisSentinelManager::class);
    });

    test('horizon service bindings iterate correctly', function () {
        if (! class_exists(\Laravel\Horizon\ServiceBindings::class)) {
            $this->markTestSkipped('Horizon not installed');
        }

        $bindings = new HorizonServiceBindings;

        $bindingArray = iterator_to_array($bindings);

        expect($bindingArray)->toBeArray()
            ->and(count($bindingArray))->toBeGreaterThan(0, 'Should have Horizon service bindings');
    });

    test('horizon daemon starts and processes jobs', function () {
        if (! class_exists(Horizon::class)) {
            $this->markTestSkipped('Horizon not installed');
        }

        //        // Skip in CI environments where long-running processes might be problematic
        //        if (getenv('CI') === 'true') {
        //            $this->markTestSkipped('Skipping long-running process test in CI');
        //        }

        $testId = 'daemon_test_'.time();

        // Start Horizon process in background using workbench
        $horizonProcess = new Process(
            [PHP_BINARY, 'vendor/bin/testbench', 'horizon'],
            dirname(__DIR__, 3), // Project root
            [
                'APP_ENV' => 'testing',
                'QUEUE_CONNECTION' => 'phpredis-sentinel',
            ],
            null,
            60 // 60 second timeout
        );

        $horizonProcess->start();

        try {
            // Wait for Horizon to start (max 15 seconds)
            $ready = false;
            for ($i = 0; $i < 15; $i++) {
                sleep(1);

                // Check if process is still running
                if (! $horizonProcess->isRunning()) {
                    $this->fail('Horizon process died: '.$horizonProcess->getErrorOutput());
                }

                // Check readiness via command
                try {
                    $exitCode = Artisan::call('horizon:ready');
                    if ($exitCode === 0) {
                        $ready = true;
                        break;
                    }
                } catch (\Exception $e) {
                    // Keep waiting
                }
            }

            expect($ready)->toBeTrue('Horizon should become ready within 15 seconds');

            // Dispatch real jobs through the queue
            $jobCount = 5;
            for ($i = 1; $i <= $jobCount; $i++) {
                HorizonTestJob::dispatch("{$testId}_{$i}", [
                    'process_test' => true,
                    'index' => $i,
                ]);
            }

            // Wait for jobs to be processed (max 20 seconds)
            $maxWait = 20;
            $allProcessed = false;

            for ($i = 0; $i < $maxWait; $i++) {
                sleep(1);

                $processedCount = 0;
                for ($j = 1; $j <= $jobCount; $j++) {
                    if (Cache::get("horizon:job:{$testId}_{$j}:executed")) {
                        $processedCount++;
                    }
                }

                if ($processedCount === $jobCount) {
                    $allProcessed = true;
                    break;
                }
            }

            expect($allProcessed)->toBeTrue("All {$jobCount} jobs should be processed");

            // Verify each job was executed
            for ($i = 1; $i <= $jobCount; $i++) {
                $jobId = "{$testId}_{$i}";
                expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue("Job {$jobId} should be executed")
                    ->and(Cache::get("horizon:job:{$jobId}:metadata"))->toBeArray()
                    ->and(Cache::get("horizon:job:{$jobId}:metadata")['process_test'])->toBeTrue();
            }

            // Verify Horizon is still alive
            expect(Artisan::call('horizon:alive'))->toBe(0, 'Horizon should still be alive');

        } finally {
            // Graceful shutdown
            try {
                Artisan::call('horizon:terminate');
                sleep(2);
            } catch (\Exception $e) {
                // Ignore
            }

            // Force stop if still running
            if ($horizonProcess->isRunning()) {
                $horizonProcess->signal(SIGTERM);
                $horizonProcess->wait();
            }
        }
    });

    test('horizon survives redis connection reset during job processing', function () {
        if (! class_exists(Horizon::class)) {
            $this->markTestSkipped('Horizon not installed');
        }

        if (getenv('CI') === 'true') {
            $this->markTestSkipped('Skipping long-running process test in CI');
        }

        $testId = 'reset_test_'.time();

        $horizonProcess = new Process(
            [PHP_BINARY, 'vendor/bin/testbench', 'horizon'],
            dirname(__DIR__, 3), // Project root
            ['APP_ENV' => 'testing'],
            null,
            60
        );

        $horizonProcess->start();

        try {
            // Wait for startup
            $ready = false;
            for ($i = 0; $i < 15; $i++) {
                sleep(1);
                if (! $horizonProcess->isRunning()) {
                    $this->fail('Horizon died during startup');
                }
                try {
                    if (Artisan::call('horizon:ready') === 0) {
                        $ready = true;
                        break;
                    }
                } catch (\Exception $e) {
                    // Continue
                }
            }

            expect($ready)->toBeTrue('Horizon should start');

            // Process initial jobs
            for ($i = 1; $i <= 3; $i++) {
                HorizonTestJob::dispatch("{$testId}_before_{$i}");
            }

            sleep(5);

            // Verify initial jobs
            for ($i = 1; $i <= 3; $i++) {
                expect(Cache::get("horizon:job:{$testId}_before_{$i}:executed"))->toBeTrue();
            }

            // Force connection reset
            $connection = Redis::connection('phpredis-sentinel');
            try {
                $connection->disconnect();
            } catch (\Exception $e) {
                // Expected
            }

            sleep(2);

            // Dispatch jobs after reset
            for ($i = 1; $i <= 3; $i++) {
                HorizonTestJob::dispatch("{$testId}_after_{$i}");
            }

            // Wait for processing
            sleep(8);

            // Verify jobs after reset were processed
            $afterCount = 0;
            for ($i = 1; $i <= 3; $i++) {
                if (Cache::get("horizon:job:{$testId}_after_{$i}:executed")) {
                    $afterCount++;
                }
            }

            expect($afterCount)->toBeGreaterThan(0, 'Some jobs should process after connection reset')
                ->and($horizonProcess->isRunning())->toBeTrue('Horizon should still be running');

        } finally {
            try {
                Artisan::call('horizon:terminate');
                sleep(2);
            } catch (\Exception $e) {
                // Ignore
            }

            if ($horizonProcess->isRunning()) {
                $horizonProcess->signal(SIGTERM);
                $horizonProcess->wait();
            }
        }
    });

    test('horizon stores metrics in sentinel redis', function () {
        if (! class_exists(Horizon::class)) {
            $this->markTestSkipped('Horizon not installed');
        }

        if (getenv('CI') === 'true') {
            $this->markTestSkipped('Skipping long-running process test in CI');
        }

        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        $horizonProcess = new Process(
            [PHP_BINARY, 'vendor/bin/testbench', 'horizon'],
            dirname(__DIR__, 3), // Project root
            ['APP_ENV' => 'testing'],
            null,
            60
        );

        $horizonProcess->start();

        try {
            // Wait for startup
            $ready = false;
            for ($i = 0; $i < 15; $i++) {
                sleep(1);
                if (! $horizonProcess->isRunning()) {
                    $this->fail('Horizon died: '.$horizonProcess->getErrorOutput());
                }
                try {
                    if (Artisan::call('horizon:ready') === 0) {
                        $ready = true;
                        break;
                    }
                } catch (\Exception $e) {
                    // Continue
                }
            }

            expect($ready)->toBeTrue();

            // Dispatch some jobs
            $testId = 'metrics_test_'.time();
            for ($i = 1; $i <= 5; $i++) {
                HorizonTestJob::dispatch("{$testId}_{$i}");
            }

            sleep(8);

            // Check for Horizon keys in Redis (metrics, masters, etc.)
            $prefix = config('horizon.prefix', 'horizon-process:');
            $horizonKeys = $connection->keys("{$prefix}*");

            expect($horizonKeys)->toBeArray()
                ->and(count($horizonKeys))->toBeGreaterThan(0, 'Horizon should store data in Redis');

        } finally {
            try {
                Artisan::call('horizon:terminate');
                sleep(2);
            } catch (\Exception $e) {
                // Ignore
            }

            if ($horizonProcess->isRunning()) {
                $horizonProcess->signal(SIGTERM);
                $horizonProcess->wait();
            }
        }
    });
});
