<?php

use Goopil\LaravelRedisSentinel\Tests\Support\ProcessManager;
use Illuminate\Support\Facades\Cache;
use Workbench\App\Jobs\HorizonTestJob;

describe('Horizon E2E Tests', function () {
    beforeEach(function () {
        // Configure Horizon
        config()->set('horizon.use', 'phpredis-sentinel');
        config()->set('horizon.prefix', 'horizon-e2e:');
        config()->set('queue.default', 'phpredis-sentinel');

        // Clear data
        try {
            \Illuminate\Support\Facades\Queue::flush();
        } catch (\Exception $e) {
            // Ignore
        }

        Cache::flush();

        $this->processManager = new ProcessManager;
    });

    afterEach(function () {
        $this->processManager->stopAll();
    });

    test('horizon processes dispatched jobs', function () {
        if (! class_exists(\Laravel\Horizon\Horizon::class)) {
            $this->markTestSkipped('Horizon is not installed');
        }

        $testId = 'e2e_horizon_'.uniqid();
        $jobCount = 10;

        // Dispatch jobs
        for ($i = 1; $i <= $jobCount; $i++) {
            HorizonTestJob::dispatch("{$testId}_{$i}", [
                'iteration' => $i,
                'test_type' => 'horizon_e2e',
            ]);
        }

        // Start Horizon
        $process = $this->processManager->startHorizon(60);

        // Wait for jobs to be processed
        sleep(5);

        // Verify jobs executed
        $successCount = 0;
        for ($i = 1; $i <= $jobCount; $i++) {
            if (Cache::get("horizon:job:{$testId}_{$i}:executed")) {
                $successCount++;
            }
        }

        expect($successCount)->toBe($jobCount, "Horizon should process all {$jobCount} jobs");
    });

    test('horizon handles multiple queues', function () {
        if (! class_exists(\Laravel\Horizon\Horizon::class)) {
            $this->markTestSkipped('Horizon is not installed');
        }

        $testId = 'e2e_multi_queue_'.uniqid();

        // Dispatch to different queues
        HorizonTestJob::dispatch("{$testId}_high_1", ['priority' => 'high'])->onQueue('high');
        HorizonTestJob::dispatch("{$testId}_high_2", ['priority' => 'high'])->onQueue('high');
        HorizonTestJob::dispatch("{$testId}_default_1", ['priority' => 'default'])->onQueue('default');
        HorizonTestJob::dispatch("{$testId}_default_2", ['priority' => 'default'])->onQueue('default');

        // Start Horizon
        $process = $this->processManager->startHorizon(60);

        sleep(5);

        // Verify all jobs from both queues processed
        expect(Cache::get("horizon:job:{$testId}_high_1:executed"))->toBeTrue()
            ->and(Cache::get("horizon:job:{$testId}_high_2:executed"))->toBeTrue()
            ->and(Cache::get("horizon:job:{$testId}_default_1:executed"))->toBeTrue()
            ->and(Cache::get("horizon:job:{$testId}_default_2:executed"))->toBeTrue();
    });
});
