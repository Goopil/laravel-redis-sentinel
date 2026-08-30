<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionFailed;
use Illuminate\Support\Facades\Event;

test('a failing dynamic command is retried exactly retryLimit + 1 times', function () {
    Event::fake();

    $client = Mockery::mock(Redis::class);
    $client->expects('hset')->times(2)->andThrow(new RedisException('broken pipe'));

    $connection = new RedisSentinelConnection($client, fn () => $client, []);
    $connection->setRetryLimit(1);
    $connection->setRetryDelay(1);
    $connection->setRetryMessages(['broken pipe']);

    $connection->hset('key', 'field', 'value');
})->throws(RedisException::class, 'broken pipe')
    ->after(function () {
        Event::assertDispatchedTimes(RedisSentinelConnectionFailed::class, 2);
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
