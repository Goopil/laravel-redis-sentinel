<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\RedisSentinelServiceProvider;
use Illuminate\Contracts\Events\Dispatcher;

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

test('resetStickiness also resets transaction level', function () {
    $masterClient = Mockery::mock(Redis::class);

    $connection = new RedisSentinelConnection($masterClient, fn () => $masterClient, []);

    $reflection = new ReflectionClass($connection);
    $transactionProp = $reflection->getProperty('transactionLevel');
    $transactionProp->setAccessible(true);
    $transactionProp->setValue($connection, 3);

    $connection->resetStickiness();

    expect($transactionProp->getValue($connection))->toBe(0);
});

test('bootOctane listens to all Octane lifecycle events', function () {
    app()->instance('octane', new stdClass);

    $events = Mockery::mock(Dispatcher::class);
    $listenedEvents = [];

    $events->shouldReceive('listen')->andReturnUsing(function ($event, $callback) use (&$listenedEvents) {
        $listenedEvents[] = $event;
    });

    app()->instance('events', $events);

    $provider = new RedisSentinelServiceProvider(app());
    $method = new ReflectionMethod($provider, 'bootOctane');
    $method->setAccessible(true);
    $method->invoke($provider);

    $expectedEvents = [
        'Laravel\Octane\Events\RequestReceived',
        'Laravel\Octane\Events\TickReceived',
        'Laravel\Octane\Events\TaskReceived',
        'Laravel\Octane\Events\OperationTerminated',
    ];

    foreach ($expectedEvents as $event) {
        expect($listenedEvents)->toContain($event);
    }
});
