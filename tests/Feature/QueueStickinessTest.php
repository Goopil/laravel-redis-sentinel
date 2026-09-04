<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;

test('stickiness is reset on job processing', function () {
    $connection = getRedisSentinelConnection();

    connectionState($connection)->wroteToMaster = true;

    $job = Mockery::mock(Job::class);
    $job->allows('payload')->andReturn([]);

    event(new JobProcessing('redis', $job));

    expect(connectionState($connection)->wroteToMaster)->toBeFalse();
});
