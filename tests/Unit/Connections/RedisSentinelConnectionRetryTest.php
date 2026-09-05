<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionFailed;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionMaxRetryFailed;
use Goopil\LaravelRedisSentinel\Tests\Support\FakeContext;
use Illuminate\Support\Facades\Event;

test('a failing dynamic command is retried exactly retryLimit + 1 times', function () {
    Event::fake();

    $client = Mockery::mock(Redis::class);
    $client->expects('hset')->times(2)->andThrow(new RedisException('broken pipe'));

    $connection = new RedisSentinelConnection($client, fn () => $client, []);
    $connection->setRetryLimit(1);
    $connection->setRetryDelay(1);
    $connection->setRetryMessages(['broken pipe']);

    try {
        $connection->hset('key', 'field', 'value');
        $this->fail('RedisException was not thrown.');
    } catch (RedisException $exception) {
        expect($exception->getMessage())->toBe('broken pipe');
        Event::assertDispatchedTimes(RedisSentinelConnectionFailed::class, 2);
    }
});

test('a dynamic command that succeeds after one failure returns its result', function () {
    Event::fake();

    $client = Mockery::mock(Redis::class);
    $client->expects('hset')->twice()->andReturnUsing(
        fn () => throw new RedisException('broken pipe'),
        fn () => 1,
    );

    $connection = new RedisSentinelConnection($client, fn () => $client, []);
    $connection->setRetryLimit(1);
    $connection->setRetryDelay(1);
    $connection->setRetryMessages(['broken pipe']);

    expect($connection->hset('key', 'field', 'value'))->toBe(1)
        ->and(Event::assertDispatchedTimes(RedisSentinelConnectionFailed::class, 1))->toBeNull();
});

test('a throwing listener on the connection fail event does not abort the retry loop', function () {
    $listenerInvoked = false;
    Event::listen(RedisSentinelConnectionFailed::class, function () use (&$listenerInvoked): void {
        $listenerInvoked = true;

        throw new RuntimeException('listener down');
    });

    $client = Mockery::mock(Redis::class);
    $client->expects('hset')->twice()->andReturnUsing(
        fn () => throw new RedisException('broken pipe'),
        fn () => 1,
    );

    $connection = new RedisSentinelConnection($client, fn () => $client, []);
    $connection->setRetryLimit(1);
    $connection->setRetryDelay(1);
    $connection->setRetryMessages(['broken pipe']);

    expect($connection->hset('key', 'field', 'value'))->toBe(1)
        ->and($listenerInvoked)->toBeTrue();
});

test('a failed sticky read refreshes the master, not the replica', function () {
    Event::fake();

    $master1 = Mockery::mock(Redis::class);
    $master2 = Mockery::mock(Redis::class);
    $replica = Mockery::mock(Redis::class);

    $master1->expects('set')->with('foo', 'bar', null)->once()->andReturn(true);
    $master1->expects('get')->with('foo')->once()->andThrow(new RedisException('broken pipe'));
    $master2->expects('get')->with('foo')->once()->andReturn('baz');
    $replica->shouldNotReceive('get');

    $masterRefreshes = 0;
    $connection = new RedisSentinelConnection(
        $master1,
        function () use (&$masterRefreshes, $master2) {
            $masterRefreshes++;

            return $master2;
        },
        [],
        fn () => $replica,
    );
    $connection->setRetryLimit(2);
    $connection->setRetryDelay(1);
    $connection->setRetryMessages(['broken pipe']);

    $connection->set('foo', 'bar');
    expect($connection->get('foo'))->toBe('baz')
        ->and($masterRefreshes)->toBe(1);
});

test('parent command() cannot reconnect or nest retries on Laravel 13', function () {
    Event::fake();

    $masterClient = Mockery::mock(Redis::class);
    $masterClient->shouldReceive('get')->times(3)->andThrow(new RedisException('went away'));

    $reconnects = 0;
    $connection = new RedisSentinelConnection(
        $masterClient,
        function () use (&$reconnects, $masterClient) {
            $reconnects++;

            return $masterClient;
        },
        [],
    );

    $connection->setRetryLimit(2)->setRetryDelay(0)->setRetryMessages(['went away']);

    try {
        $connection->command('get', ['foo']);
        $this->fail('RedisException was not thrown.');
    } catch (RedisException $exception) {
        expect($exception->getMessage())->toBe('went away')
            ->and($reconnects)->toBe(3);
    }
});

test('a split connection without a master connector falls back to the constructor client', function () {
    Event::fake();

    $master = Mockery::mock(Redis::class);
    $replica = Mockery::mock(Redis::class);
    $context = new FakeContext;

    $master->expects('set')->with('foo', 'bar', null)->twice()->andReturnUsing(
        fn () => throw new RedisException('broken pipe'),
        fn () => true,
    );
    $replica->shouldNotReceive('set');

    $connection = new RedisSentinelConnection($master, null, [], fn () => $replica, $context);
    $connection->setRetryLimit(1);
    $connection->setRetryDelay(1);
    $connection->setRetryMessages(['broken pipe']);

    $context->use('fresh');
    expect($connection->set('foo', 'bar'))->toBeTrue();
});

test('a retried scan restarts its cursor on the refreshed client', function () {
    $master1 = Mockery::mock(Redis::class);
    $master2 = Mockery::mock(Redis::class);

    $master1->expects('scan')->with(5, '*', 10)->once()->andThrow(new RedisException('broken pipe'));
    $master2->expects('scan')->with(null, '*', 10)->once()->andReturn(['key1']);

    $connection = new RedisSentinelConnection($master1, fn () => $master2, []);
    $connection->setRetryLimit(1);
    $connection->setRetryDelay(1);
    $connection->setRetryMessages(['broken pipe']);

    expect($connection->scan(5))->toBe([null, ['key1']]);
});

test('a failed reconnect does not short-circuit the max-retry bookkeeping', function () {
    Event::fake();

    $dead = Mockery::mock(Redis::class);
    $dead->expects('get')->with('foo')->twice()->andThrow(new RedisException('connection lost'));

    $connection = new RedisSentinelConnection(
        $dead,
        fn () => throw new RedisException('master is unreachable, cannot reconnect'),
        [],
    );
    $connection->setRetryLimit(1);
    $connection->setRetryDelay(1);
    $connection->setRetryMessages(['connection lost']);

    try {
        $connection->get('foo');
        $this->fail('RedisException was not thrown.');
    } catch (RedisException $exception) {
        expect($exception->getMessage())->toBe('connection lost');
    }

    Event::assertDispatchedTimes(RedisSentinelConnectionFailed::class, 2);
    Event::assertDispatchedTimes(RedisSentinelConnectionMaxRetryFailed::class, 1);
});

test('a non-transport exception thrown inside transaction() is never replayed', function () {
    Event::fake();

    $master = Mockery::mock(Redis::class);
    $master->expects('multi')->once()->andReturnSelf();
    $master->expects('exec')->never();

    $calls = 0;
    $connection = new RedisSentinelConnection($master, fn () => $master, []);
    $connection->setRetryLimit(5);
    $connection->setRetryDelay(1);
    $connection->setRetryMessages(['went away']);

    try {
        $connection->transaction(function () use (&$calls): void {
            $calls++;
            throw new RuntimeException('MySQL server has gone away');
        });
        $this->fail('RuntimeException was not thrown.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('MySQL server has gone away');
    }

    expect($calls)->toBe(1)
        ->and(Event::assertDispatchedTimes(RedisSentinelConnectionFailed::class, 0))->toBeNull();
});
