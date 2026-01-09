<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;

test('wrote to master is reset with reset stickiness', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    $connector = fn () => $masterClient;
    $readConnector = fn () => $replicaClient;

    $connection = new RedisSentinelConnection($masterClient, $connector, [], $readConnector);

    // Simulation d'une écriture
    $masterClient->expects('set')->once()->andReturn(true);
    $connection->set('foo', 'bar');

    $reflection = new ReflectionClass($connection);
    $property = $reflection->getProperty('wroteToMaster');
    $property->setAccessible(true);

    expect($property->getValue($connection))->toBeTrue();

    // Appel du reset
    $connection->resetStickiness();

    expect($property->getValue($connection))->toBeFalse();
});
