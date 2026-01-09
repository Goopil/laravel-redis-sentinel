<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionFailed;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionMaxRetryFailed;
use Illuminate\Support\Facades\Event;

test('RedisSentinelConnection throws exception after max retries on real command', function () {
    Event::fake();

    $client = Mockery::mock(Redis::class);
    // Always throw "broken pipe" to trigger retries
    $client->allows('get')->with('foo')->andThrow(new RedisException('broken pipe'));

    $connectorCallCount = 0;
    $connector = function () use (&$connectorCallCount, $client) {
        $connectorCallCount++;

        return $client;
    };

    $connection = new RedisSentinelConnection(
        $client,
        $connector,
        ['sentinel' => ['retry' => ['attempts' => 2, 'delay' => 1]]]
    );
    $connection->setRetryMessages(['broken pipe']);
    $connection->setRetryLimit(2);
    $connection->setRetryDelay(1);

    // It should throw RedisException after 2 retries (total 3 attempts)
    try {
        $connection->get('foo');
        $this->fail('Expected RedisException was not thrown');
    } catch (RedisException $e) {
        expect($e->getMessage())->toBe('broken pipe');
    }

    // Initial attempt + 2 retries = 3 calls to get()

    // retryOnFailure logic:
    // attempts=0: call command -> catch -> onFail called -> attempts=1.
    // attempts=1: call command -> catch -> onFail called -> attempts=2.
    // attempts=2: call command -> catch -> onMaxFail called -> throw.
    // total calls to get() via command(): 3.

    Event::assertDispatched(RedisSentinelConnectionFailed::class, 3);
    Event::assertDispatched(RedisSentinelConnectionMaxRetryFailed::class, 1);

    // The connector is called in the onFail closure of the retry method in RedisSentinelConnection.
    // Each onFail call triggers a refresh.
    // total connector calls should be 3 (one per failure, including final).
    expect($connectorCallCount)->toBe(3);
});
