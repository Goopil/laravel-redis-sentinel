<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;

// ponytail: sequential interleave; swap in real Swoole coroutines if ext-swoole lands in CI

test('interleaved reads and writes on one shared connection route to the right client', function () {
    $master = Mockery::mock(Redis::class);
    $replica = Mockery::mock(Redis::class);

    // The framework's set() wrapper appends a null third argument
    $master->shouldReceive('set')->times(3)->with('k1', 'v1', null)->andReturn(true);
    // Reads before the first write must hit the replica
    $replica->shouldReceive('get')->once()->with('before-write')->andReturn('from-replica');
    // After writes, stickiness keeps reads on the master
    $master->shouldReceive('get')->times(2)->with('after-write')->andReturn('from-master-1', 'from-master-2');

    $connection = new RedisSentinelConnection($master, fn () => $master, [], fn () => $replica);

    expect($connection->get('before-write'))->toBe('from-replica');

    $connection->set('k1', 'v1');
    expect($connection->get('after-write'))->toBe('from-master-1');

    $connection->set('k1', 'v1');
    expect($connection->get('after-write'))->toBe('from-master-2');

    $connection->set('k1', 'v1');

    expect((new ReflectionProperty($connection, 'wroteToMaster'))->getValue($connection))->toBeTrue()
        ->and((new ReflectionProperty($connection, 'client'))->getValue($connection))->toBe($master)
        ->and((new ReflectionProperty($connection, 'readClient'))->getValue($connection))->toBe($replica);
});

test('resetting stickiness flips reads back to the replica after a write', function () {
    $master = Mockery::mock(Redis::class);
    $replica = Mockery::mock(Redis::class);

    $master->shouldReceive('set')->once()->with('k', 'v', null)->andReturn(true);
    $master->shouldReceive('get')->once()->with('k')->andReturn('from-master');
    $replica->shouldReceive('get')->once()->with('k')->andReturn('from-replica');

    $connection = new RedisSentinelConnection($master, fn () => $master, [], fn () => $replica);

    $connection->set('k', 'v');

    expect($connection->get('k'))->toBe('from-master');

    $connection->resetStickiness();

    expect($connection->get('k'))->toBe('from-replica');
});

test('reads issued inside a transaction are routed to the master', function () {
    $master = Mockery::mock(Redis::class);
    $replica = Mockery::mock(Redis::class);

    $master->shouldReceive('multi')->once()->andReturnSelf();
    $master->shouldReceive('exec')->once()->andReturn([]);
    $master->shouldReceive('get')->once()->with('in-tx')->andReturn('from-master');
    $replica->shouldReceive('get')->never();

    $connection = new RedisSentinelConnection($master, fn () => $master, [], fn () => $replica);

    $connection->transaction(function () use ($connection): void {
        expect($connection->get('in-tx'))->toBe('from-master');
    });

    expect((new ReflectionProperty($connection, 'transactionLevel'))->getValue($connection))->toBe(0);
});
