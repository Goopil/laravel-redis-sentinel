<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Workbench\App\Jobs\HorizonTestJob;

describe('Horizon Integration with Orchestra', function () {
    beforeEach(function () {
        // Configure Horizon to use phpredis-sentinel
        config()->set('horizon.use', 'phpredis-sentinel');
        config()->set('horizon.prefix', 'horizon-test:');
        config()->set('queue.default', 'phpredis-sentinel');

        // Try to flush cache if available
        try {
            Cache::flush();
        } catch (\Exception $e) {
            // Ignore flush errors - cache might not be ready yet
        }
    });

    test('horizon uses redis sentinel connection', function () {
        if (! class_exists(\Laravel\Horizon\Horizon::class)) {
            $this->markTestSkipped('Horizon is not installed');
        }

        $connection = config('horizon.use');
        expect($connection)->toBe('phpredis-sentinel');

        $redisManager = app('redis');
        $horizonConnection = $redisManager->connection($connection);

        expect($horizonConnection)->toBeARedisSentinelConnection();
    });

    test('horizon job has correct tags', function () {
        $jobId = 'horizon_job_'.time();
        $metadata = [
            'user_id' => 123,
            'priority' => 'high',
            'tags' => ['custom-tag', 'test-tag'],
        ];

        $job = new HorizonTestJob($jobId, $metadata);
        $tags = $job->tags();

        expect($tags)->toBeArray()
            ->and($tags)->toContain('horizon-test')
            ->and($tags)->toContain("job:{$jobId}")
            ->and($tags)->toContain('user:123')
            ->and($tags)->toContain('priority:high')
            ->and($tags)->toContain('custom-tag')
            ->and($tags)->toContain('test-tag');
    });

    test('horizon job executes and stores metadata', function () {
        $jobId = 'exec_horizon_'.time();
        $metadata = [
            'type' => 'test',
            'user_id' => 456,
            'data' => ['key' => 'value'],
        ];

        $job = new HorizonTestJob($jobId, $metadata);
        $job->handle();

        expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue()
            ->and(Cache::get("horizon:job:{$jobId}:metadata"))->toBe($metadata)
            ->and((int) Cache::get("horizon:job:{$jobId}:timestamp"))->toBeInt();
    });

    test('horizon job can be dispatched to specific queue', function () {
        Queue::fake();

        $jobId = 'queue_test_'.time();
        $queueName = 'high-priority';
        HorizonTestJob::dispatch($jobId, [], $queueName);

        Queue::assertPushedOn($queueName, HorizonTestJob::class);
    });

    test('horizon job can be delayed', function () {
        Queue::fake();

        $jobId = 'delay_test_'.time();
        $delay = 300; // 5 minutes

        HorizonTestJob::dispatch($jobId, [], null, $delay);

        Queue::assertPushed(HorizonTestJob::class, function ($job) use ($jobId) {
            return $job->jobId === $jobId;
        });
    });

    test('horizon job retry configuration is correct', function () {
        $job = new HorizonTestJob('test_job');

        $retryUntil = $job->retryUntil();

        expect($retryUntil)->toBeInstanceOf(\DateTime::class);

        $now = now();
        $expectedRetryTime = $now->copy()->addMinutes(5);

        // Allow 1 second difference for test execution time
        expect($retryUntil->getTimestamp())->toBeGreaterThanOrEqual($expectedRetryTime->timestamp - 1)
            ->and($retryUntil->getTimestamp())->toBeLessThanOrEqual($expectedRetryTime->timestamp + 1);
    });

    test('horizon job middleware is configured', function () {
        $job = new HorizonTestJob('middleware_test');
        $middleware = $job->middleware();

        expect($middleware)->toBeArray();
    });

    test('multiple horizon jobs with different tags can be tracked', function () {
        Queue::fake();

        $jobs = [
            new HorizonTestJob('job1', ['user_id' => 1, 'priority' => 'high']),
            new HorizonTestJob('job2', ['user_id' => 2, 'priority' => 'low']),
            new HorizonTestJob('job3', ['user_id' => 1, 'priority' => 'medium']),
        ];

        foreach ($jobs as $job) {
            dispatch($job);
        }

        Queue::assertPushed(HorizonTestJob::class, 3);

        // Verify tags
        expect($jobs[0]->tags())->toContain('user:1', 'priority:high')
            ->and($jobs[1]->tags())->toContain('user:2', 'priority:low')
            ->and($jobs[2]->tags())->toContain('user:1', 'priority:medium');
    });

    test('horizon job execution records queue information', function () {
        $jobId = 'queue_info_'.time();
        $queueName = 'test-queue';

        $job = new HorizonTestJob($jobId, [], $queueName);

        // Simulate job execution context
        $job->onConnection('phpredis-sentinel');
        $job->onQueue($queueName);

        $job->handle();

        expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue()
            ->and(Cache::get("horizon:job:{$jobId}:queue"))->toBe($queueName);
    });

    test('horizon jobs execute under load', function () {
        $testId = 'horizon_load_'.time();
        $jobCount = 15;

        for ($i = 1; $i <= $jobCount; $i++) {
            $job = new HorizonTestJob("{$testId}_{$i}", [
                'iteration' => $i,
                'priority' => $i % 2 === 0 ? 'high' : 'low',
            ]);
            $job->handle();
        }

        // Verify all jobs executed
        for ($i = 1; $i <= $jobCount; $i++) {
            expect(Cache::get("horizon:job:{$testId}_{$i}:executed"))->toBeTrue();
        }
    });

    test('horizon configuration uses sentinel connection', function () {
        $config = config('horizon');

        expect($config['use'])->toBe('phpredis-sentinel')
            ->and($config['prefix'])->toBe('horizon-test:');

        // Verify waits configuration
        expect($config['waits'])->toHaveKey('phpredis-sentinel:default');
    });

    test('horizon job preserves metadata through execution', function () {
        $jobId = 'metadata_test_'.time();
        $complexMetadata = [
            'user_id' => 789,
            'nested' => [
                'level1' => [
                    'level2' => 'deep_value',
                ],
            ],
            'array_data' => [1, 2, 3, 4, 5],
            'boolean_flag' => true,
            'null_value' => null,
        ];

        $job = new HorizonTestJob($jobId, $complexMetadata);
        $job->handle();

        $storedMetadata = Cache::get("horizon:job:{$jobId}:metadata");

        expect($storedMetadata)->toBe($complexMetadata)
            ->and($storedMetadata['user_id'])->toBe(789)
            ->and($storedMetadata['nested']['level1']['level2'])->toBe('deep_value')
            ->and($storedMetadata['array_data'])->toBe([1, 2, 3, 4, 5])
            ->and($storedMetadata['boolean_flag'])->toBeTrue()
            ->and($storedMetadata['null_value'])->toBeNull();
    });

    test('horizon redis connection is stable across multiple job executions', function () {
        $testId = 'stability_test_'.time();

        // Execute jobs in batches to test connection stability
        for ($batch = 1; $batch <= 3; $batch++) {
            for ($i = 1; $i <= 5; $i++) {
                $jobId = "{$testId}_batch{$batch}_job{$i}";
                $job = new HorizonTestJob($jobId, ['batch' => $batch, 'job' => $i]);
                $job->handle();

                expect(Cache::get("horizon:job:{$jobId}:executed"))->toBeTrue();
            }

            // Small delay between batches
            usleep(100000); // 100ms
        }

        // Verify all 15 jobs (3 batches × 5 jobs) executed successfully
        $executedCount = 0;
        for ($batch = 1; $batch <= 3; $batch++) {
            for ($i = 1; $i <= 5; $i++) {
                $jobId = "{$testId}_batch{$batch}_job{$i}";
                if (Cache::get("horizon:job:{$jobId}:executed")) {
                    $executedCount++;
                }
            }
        }

        expect($executedCount)->toBe(15);
    });
});
