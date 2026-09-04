<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Goopil\LaravelRedisSentinel\RedisSentinelServiceProvider;
use Illuminate\Contracts\Events\Dispatcher;

test('wrote to master is reset with reset stickiness', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    $connector = fn () => $masterClient;
    $readConnector = fn () => $replicaClient;

    $connection = new RedisSentinelConnection($masterClient, $connector, [], $readConnector);

    $masterClient->shouldReceive('set')->once()->with('foo', 'bar', null)->andReturn(true);
    $masterClient->shouldReceive('get')->once()->with('foo')->andReturn('from-master');
    $replicaClient->shouldReceive('get')->once()->with('foo')->andReturn('from-replica');

    // Simulate a write
    $connection->set('foo', 'bar');

    // Reads stick to the master after a write
    expect($connection->get('foo'))->toBe('from-master');

    // Call reset
    $connection->resetStickiness();

    // Reads go back to the replica
    expect($connection->get('foo'))->toBe('from-replica');
});

test('resetStickiness also resets transaction level', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    $masterClient->shouldReceive('multi')->once()->andReturnSelf();
    $masterClient->shouldReceive('exec')->once()->andReturn([]);
    $masterClient->shouldReceive('get')->once()->with('in-tx')->andReturn('from-master');
    $replicaClient->shouldReceive('get')->once()->with('in-tx')->andReturn('from-replica');

    $connection = new RedisSentinelConnection($masterClient, fn () => $masterClient, [], fn () => $replicaClient);

    $connection->transaction(function () use ($connection): void {
        // Inside an open transaction reads stay on the master
        expect($connection->get('in-tx'))->toBe('from-master');

        $connection->resetStickiness();

        // The reset dropped the transaction level: reads reach the replica
        expect($connection->get('in-tx'))->toBe('from-replica');
    });
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

test('octane lifecycle callback resets stickiness on all sentinel connections', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);
    $connection = new RedisSentinelConnection($masterClient, fn () => $masterClient, [], fn () => $replicaClient);

    $masterClient->shouldReceive('set')->once()->with('foo', 'bar', null)->andReturn(true);
    $masterClient->shouldReceive('get')->once()->with('foo')->andReturn('from-master');
    $replicaClient->shouldReceive('get')->once()->with('foo')->andReturn('from-replica');

    $connection->set('foo', 'bar');

    // Reads stick to the master after the write
    expect($connection->get('foo'))->toBe('from-master');

    $manager = app(RedisSentinelManager::class);
    $connectionsProp = new ReflectionProperty(RedisSentinelManager::class, 'connections');
    $connectionsProp->setAccessible(true);
    $connectionsProp->setValue($manager, ['phpredis-sentinel' => $connection]);

    $captured = [];
    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('listen')->andReturnUsing(function ($event, $callback) use (&$captured) {
        $captured[$event] = $callback;
    });

    app()->instance('octane', new stdClass);
    app()->instance('events', $events);

    $provider = new RedisSentinelServiceProvider(app());
    $method = new ReflectionMethod($provider, 'bootOctane');
    $method->invoke($provider);

    ($captured['Laravel\Octane\Events\TaskReceived'])();

    // Reads go back to the replica: the lifecycle callback reset stickiness
    expect($connection->get('foo'))->toBe('from-replica');
});
