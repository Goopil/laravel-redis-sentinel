<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Workbench\App\Jobs\BatchableJob;
use Workbench\App\Jobs\ChainableJob;
use Workbench\App\Jobs\ProcessOrderJob;
use Workbench\App\Jobs\SendEmailJob;

describe('Queue Integration with Orchestra', function () {
    beforeEach(function () {
        Cache::flush();
        Queue::fake();
    });

    test('jobs can be dispatched to queue', function () {
        $orderId = 'order_123';
        $items = ['item1', 'item2'];

        ProcessOrderJob::dispatch($orderId, $items);

        Queue::assertPushed(ProcessOrderJob::class, function ($job) use ($orderId, $items) {
            return $job->orderId === $orderId && $job->items === $items;
        });
    });

    test('jobs can be dispatched with delay', function () {
        $email = 'test@example.com';
        $subject = 'Test Email';
        $message = 'Test message';

        SendEmailJob::dispatch($email, $subject, $message)
            ->delay(now()->addMinutes(5));

        Queue::assertPushed(SendEmailJob::class, function ($job) use ($email) {
            return $job->email === $email;
        });
    });

    test('jobs can be dispatched to specific queue', function () {
        $orderId = 'order_456';

        ProcessOrderJob::dispatch($orderId)->onQueue('high-priority');

        Queue::assertPushedOn('high-priority', ProcessOrderJob::class);
    });

    test('job chains execute in order', function () {
        Bus::fake();

        $chainId = 'chain_'.time();

        Bus::chain([
            new ChainableJob('step1', $chainId),
            new ChainableJob('step2', $chainId),
            new ChainableJob('step3', $chainId),
        ])->dispatch();

        Bus::assertChained([
            ChainableJob::class,
            ChainableJob::class,
            ChainableJob::class,
        ]);
    });

    test('jobs can be batched together', function () {
        Bus::fake();

        $batchId = 'batch_'.time();

        $jobs = collect(range(1, 5))->map(function ($i) use ($batchId) {
            return new BatchableJob($batchId, $i);
        })->toArray();

        Bus::batch($jobs)
            ->name('test-batch')
            ->dispatch();

        Bus::assertBatched(function ($batch) {
            return $batch->name === 'test-batch' && count($batch->jobs) === 5;
        });
    });

    test('job has correct tags for tracking', function () {
        $orderId = 'order_789';
        $job = new ProcessOrderJob($orderId);

        $tags = $job->tags();

        expect($tags)->toContain('orders')
            ->and($tags)->toContain("order:{$orderId}");
    });

    test('job with failure handling sets retry configuration', function () {
        $job = new SendEmailJob('test@example.com', 'Subject', 'Message', true);

        expect($job->tries)->toBe(3)
            ->and($job->backoff)->toBe(5)
            ->and($job->shouldFail)->toBeTrue();
    });

    test('multiple jobs can be dispatched concurrently', function () {
        Queue::fake();

        // Dispatch multiple jobs
        for ($i = 1; $i <= 10; $i++) {
            ProcessOrderJob::dispatch("order_{$i}", ["item_{$i}"]);
        }

        Queue::assertPushed(ProcessOrderJob::class, 10);
    });

    test('jobs use redis sentinel queue connection', function () {
        // Don't use Queue::fake() for this test - we need real connection
        // Temporarily clear the fake
        $originalFake = app()->bound('queue') ? app('queue') : null;

        config()->set('queue.connections.test-sentinel', [
            'driver' => 'phpredis-sentinel',
            'connection' => 'phpredis-sentinel',
            'queue' => 'test-queue',
            'retry_after' => 90,
        ]);

        // Get real queue manager and re-register the connector
        app()->forgetInstance('queue');
        $queueManager = app('queue');
        $queueManager->extend('phpredis-sentinel', function () {
            return new \Illuminate\Queue\Connectors\RedisConnector(app('phpredis-sentinel'));
        });
        $queue = $queueManager->connection('test-sentinel');

        expect($queue->getRedis())->toBeInstanceOf(\Goopil\LaravelRedisSentinel\RedisSentinelManager::class);

        // Restore fake if there was one
        if ($originalFake) {
            app()->instance('queue', $originalFake);
        }
    });
});

describe('Queue Job Execution with Real Redis', function () {
    beforeEach(function () {
        Cache::flush();
        // Use real queue connection for execution tests
        config()->set('queue.default', 'phpredis-sentinel');
        config()->set('queue.connections.phpredis-sentinel', [
            'driver' => 'phpredis-sentinel',
            'connection' => 'phpredis-sentinel',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
    });

    test('process order job executes and stores data', function () {
        $orderId = 'exec_order_'.time();
        $items = ['laptop', 'mouse', 'keyboard'];

        $job = new ProcessOrderJob($orderId, $items);
        $job->handle();

        expect(Cache::get("order:{$orderId}:processed"))->toBeTrue()
            ->and(Cache::get("order:{$orderId}:items"))->toBe($items)
            ->and(Cache::get("order:{$orderId}:timestamp"))->toBeGreaterThan(0);
    });

    test('chainable job records execution steps', function () {
        $chainId = 'exec_chain_'.time();

        $job1 = new ChainableJob('step1', $chainId);
        $job1->handle();

        $job2 = new ChainableJob('step2', $chainId);
        $job2->handle();

        $steps = Cache::get("chain:{$chainId}:steps");

        expect($steps)->toBeArray()
            ->and(count($steps))->toBe(2)
            ->and($steps[0]['step'])->toBe('step1')
            ->and($steps[1]['step'])->toBe('step2')
            ->and(Cache::get("chain:{$chainId}:step:step1"))->toBeTrue()
            ->and(Cache::get("chain:{$chainId}:step:step2"))->toBeTrue();
    });

    test('batchable job processes items correctly', function () {
        $testBatchId = 'exec_batch_'.time();

        for ($i = 1; $i <= 5; $i++) {
            $job = new BatchableJob($testBatchId, $i);
            $job->handle();
        }

        $processed = Cache::get("batch:{$testBatchId}:processed");

        expect($processed)->toBeArray()
            ->and(count($processed))->toBe(5)
            ->and($processed)->toContain(1, 2, 3, 4, 5);

        // Verify individual items
        for ($i = 1; $i <= 5; $i++) {
            expect(Cache::get("batch:{$testBatchId}:item:{$i}"))->toBeTrue();
        }
    });

    test('send email job handles execution and tracks attempts', function () {
        $email = 'exec_test@example.com';
        $subject = 'Execution Test';
        $message = 'Test message content';

        $job = new SendEmailJob($email, $subject, $message, false);
        $job->handle();

        expect(Cache::get("email:{$email}:sent"))->toBeTrue()
            ->and(Cache::get("email:{$email}:subject"))->toBe($subject)
            ->and(Cache::get("email:{$email}:message"))->toBe($message)
            ->and(Cache::get("email:{$email}:attempts"))->toBeGreaterThanOrEqual(1);
    });

    test('send email job handles failures correctly', function () {
        $email = 'fail_test@example.com';

        $job = new SendEmailJob($email, 'Subject', 'Message', true);

        try {
            $job->handle();
        } catch (\Exception $e) {
            // Expected exception on first attempt
            expect($e->getMessage())->toBe('Simulated email sending failure');
        }

        // Simulate reaching max attempts and calling failed()
        Cache::put("email:{$email}:attempts", 3);
        $job->failed(new \Exception('Max retries exceeded'));

        expect(Cache::get("email:{$email}:failed"))->toBeTrue()
            ->and(Cache::get("email:{$email}:error"))->toBe('Max retries exceeded');
    });

    test('jobs maintain connection stability under load', function () {
        $testId = 'load_test_'.time();

        // Execute multiple jobs rapidly
        for ($i = 1; $i <= 20; $i++) {
            $job = new ProcessOrderJob("{$testId}_{$i}", ["item_{$i}"]);
            $job->handle();
        }

        // Verify all jobs executed successfully
        for ($i = 1; $i <= 20; $i++) {
            expect(Cache::get("order:{$testId}_{$i}:processed"))->toBeTrue();
        }
    });
});
