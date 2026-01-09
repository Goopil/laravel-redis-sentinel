<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;

test('stickiness is reset on job processing', function () {
    $connection = getRedisSentinelConnection();

    $reflection = new ReflectionClass($connection);
    $property = $reflection->getProperty('wroteToMaster');
    $property->setAccessible(true);
    $property->setValue($connection, true);

    $job = Mockery::mock(Job::class);
    $job->expects('payload')->andReturn([]);

    event(new JobProcessing('redis', $job));

    expect($property->getValue($connection))->toBeFalse();
});
