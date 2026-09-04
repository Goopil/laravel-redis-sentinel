<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Tests\Support\FakeContext;

// ponytail: sequential slot switches stand in for Swoole coroutine interleaving

test('stickiness does not leak between execution contexts', function () {
    $masterA = Mockery::mock(Redis::class);
    $masterB = Mockery::mock(Redis::class);
    $replica = Mockery::mock(Redis::class);

    $masters = ['a' => $masterA, 'b' => $masterB];
    $context = new FakeContext;

    $connection = new RedisSentinelConnection(
        $masterA,
        fn () => $masters[$context->currentSlot()], // resolves per current slot
        [],
        fn () => $replica,
        $context,
    );

    $context->use('a');
    $masterA->shouldReceive('set')->once()->with('k', 'v', null)->andReturn(true);
    $connection->set('k', 'v');

    $context->use('b');
    // B reads first: A's stickiness must not leak into B's state
    $replica->shouldReceive('get')->once()->with('k')->andReturn('from-replica');
    $masterB->shouldReceive('get')->never();
    expect($connection->get('k'))->toBe('from-replica');

    // B writes on ITS own master, not masterA
    $masterB->shouldReceive('set')->once()->with('k', 'v', null)->andReturn(true);
    $masterA->shouldReceive('set')->never();
    expect($connection->set('k', 'v'))->toBeTrue();

    // And now B is sticky on ITS master
    $masterB->shouldReceive('get')->once()->with('k')->andReturn('from-master-b');
    expect($connection->get('k'))->toBe('from-master-b');
});

test('each execution context gets its own master client in split mode', function () {
    $context = new FakeContext;
    $created = [];

    $connection = new RedisSentinelConnection(
        Mockery::mock(Redis::class), // the worker client: contexts never reuse it
        function () use (&$created, $context) {
            return $created[$context->currentSlot()] ??= Mockery::mock(Redis::class);
        },
        [],
        fn () => Mockery::mock(Redis::class),
        $context,
    );

    $context->use('a');
    $masterA = $connection->client();

    $context->use('b');
    $masterB = $connection->client();

    expect($masterA)->not->toBe($masterB)
        ->and(spl_object_id($masterA))->not->toBe(spl_object_id($masterB))
        // Stable within the same slot
        ->and($connection->client())->toBe($masterB);
});

test('transaction level is scoped to the current execution context', function () {
    $master = Mockery::mock(Redis::class);
    $replica = Mockery::mock(Redis::class);
    $context = new FakeContext;

    $master->shouldReceive('multi')->once()->andReturnSelf();
    $master->shouldReceive('exec')->once()->andReturn([]);

    $connection = new RedisSentinelConnection($master, fn () => $master, [], fn () => $replica, $context);

    $context->use('tx');
    $connection->transaction(function () use ($connection, $context, $master, $replica): void {
        // While the transaction is open in slot 'tx', another context is unaffected
        $context->use('other');
        $replica->shouldReceive('get')->once()->with('peek')->andReturn('from-replica');
        $master->shouldReceive('get')->never();
        expect($connection->get('peek'))->toBe('from-replica');

        $context->use('tx');
    });
});
