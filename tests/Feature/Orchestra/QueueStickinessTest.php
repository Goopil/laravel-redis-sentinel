<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

describe('Queue Stickiness', function () {
    test('stickiness is reset when JobProcessing event is fired', function () {
        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        // 1. Perform a write to make it sticky
        $connection->set('sticky_queue_test', 'value');

        expect(connectionState($connection)->wroteToMaster)->toBeTrue();

        // 2. Fire the JobProcessing event
        // The JobProcessing event constructor requires connection name and job instance.
        // We can just mock the job.
        $job = Mockery::mock(Job::class);
        $job->allows('payload')->andReturn([]);
        Event::dispatch(new JobProcessing('phpredis-sentinel', $job));

        // 3. Verify it's no longer sticky
        expect(connectionState($connection)->wroteToMaster)->toBeFalse();
    });
});
