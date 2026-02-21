<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;

test('wrote to master is reset with reset stickiness', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    $connector = fn () => $masterClient;
    $readConnector = fn () => $replicaClient;

    $connection = new RedisSentinelConnection($masterClient, $connector, [], $readConnector);

    // Simulate a write
    $masterClient->expects('set')->once()->andReturn(true);
    $connection->set('foo', 'bar');

    $reflection = new ReflectionClass($connection);
    $property = $reflection->getProperty('wroteToMaster');

    expect($property->getValue($connection))->toBeTrue();

    // Call reset
    $connection->resetStickiness();

    expect($property->getValue($connection))->toBeFalse();
});
