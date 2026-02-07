<?php

use Goopil\LaravelRedisSentinel\Tests\Support\ProcessManager;
use Illuminate\Support\Facades\Cache;
use Workbench\App\Jobs\HorizonTestJob;

describe('Queue Worker E2E Tests', function () {
    beforeEach(function () {
        config()->set('queue.default', 'phpredis-sentinel');
        config()->set('queue.connections.phpredis-sentinel.connection', 'phpredis-sentinel');

        // Clear queue before each test
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

    test('dispatched jobs are processed by queue worker', function () {
        $testId = 'e2e_queue_'.uniqid();
        $jobCount = 5;

        // Dispatch jobs to queue
        for ($i = 1; $i <= $jobCount; $i++) {
            HorizonTestJob::dispatch("{$testId}_{$i}", ['index' => $i])
                ->onConnection('phpredis-sentinel');
        }

        // Verify jobs are in queue
        expect(\Illuminate\Support\Facades\Queue::size())->toBe($jobCount);

        // Start worker
        $process = $this->processManager->startQueueWorker('phpredis-sentinel', 30);

        // Wait for jobs to be processed
        $completed = $this->processManager->waitForJobs(30);

        expect($completed)->toBeTrue('Jobs should be processed within timeout');

        // Verify all jobs executed
        $successCount = 0;
        for ($i = 1; $i <= $jobCount; $i++) {
            if (Cache::get("horizon:job:{$testId}_{$i}:executed")) {
                $successCount++;
            }
        }

        expect($successCount)->toBe($jobCount, "All {$jobCount} jobs should be executed by worker");
    });

    test('queue worker handles job failures and retries', function () {
        // Create a job that will fail
        $testId = 'e2e_retry_'.uniqid();

        // For this test, we'd need a job that fails intentionally
        // We'll create a separate test job class that throws exceptions
        // and verify retry behavior

        // TODO: Implement retry test with custom failing job
        $this->markTestSkipped('Retry test with failing job not yet implemented');
    });

    test('queue worker processes jobs in correct order', function () {
        $testId = 'e2e_order_'.uniqid();
        $jobCount = 10;

        // Dispatch jobs sequentially
        for ($i = 1; $i <= $jobCount; $i++) {
            HorizonTestJob::dispatch("{$testId}_{$i}", ['sequence' => $i]);
        }

        // Start worker
        $process = $this->processManager->startQueueWorker('phpredis-sentinel', 30);

        // Wait for completion
        $this->processManager->waitForJobs(30);

        // Verify order by checking timestamps
        $timestamps = [];
        for ($i = 1; $i <= $jobCount; $i++) {
            $timestamps[$i] = Cache::get("horizon:job:{$testId}_{$i}:timestamp");
        }

        // Timestamps should be in ascending order
        $sorted = $timestamps;
        sort($sorted);

        expect($timestamps)->toBe($sorted, 'Jobs should be processed in order');
    });

    test('queue worker handles multiple queues', function () {
        $testId = 'e2e_multi_queue_'.uniqid();

        // Dispatch to different queues
        HorizonTestJob::dispatch("{$testId}_high_1", ['priority' => 'high'])->onQueue('high');
        HorizonTestJob::dispatch("{$testId}_default_1", ['priority' => 'default'])->onQueue('default');

        // Start worker with --queue option to process both
        $process = $this->processManager->startQueueWorker('phpredis-sentinel', 30);

        // Wait for completion
        $this->processManager->waitForJobs(30);

        // Verify all jobs processed
        expect(Cache::get("horizon:job:{$testId}_high_1:executed"))->toBeTrue()
            ->and(Cache::get("horizon:job:{$testId}_default_1:executed"))->toBeTrue();
    });
});
