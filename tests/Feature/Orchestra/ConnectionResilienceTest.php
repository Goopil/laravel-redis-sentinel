<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionFailed;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionReconnected;
use Illuminate\Support\Facades\Event;

describe('Connection Resilience', function () {
    test('connection retries and reconnects on RedisException', function () {
        $mockClient = Mockery::mock(Redis::class);
        $mockClient->expects('get')
            ->andThrow(new RedisException('Connection lost'));

        $mockClient->expects('get')
            ->once()
            ->with('test_key')
            ->andReturn('success');

        $connectorCalled = 0;
        $connector = function ($forceRefresh = false) use (&$connectorCalled, $mockClient) {
            $connectorCalled++;

            return $mockClient;
        };

        $connection = new RedisSentinelConnection($mockClient, $connector);
        $connection->setRetryMessages(['Connection lost']);
        $connection->setRetryDelay(0); // No wait in tests

        Event::fake([
            RedisSentinelConnectionFailed::class,
            RedisSentinelConnectionReconnected::class,
        ]);

        $result = $connection->get('test_key');

        expect($result)->toBe('success')
            ->and($connectorCalled)->toBeGreaterThanOrEqual(1);

        Event::assertDispatched(RedisSentinelConnectionFailed::class);
        Event::assertDispatched(RedisSentinelConnectionReconnected::class);
    });

    test('connection fails after max retries', function () {
        $mockClient = Mockery::mock(Redis::class);
        $mockClient->expects('get')
            ->atLeast()->times(3)
            ->andThrow(new RedisException('Persistent failure'));

        $connector = function ($forceRefresh = false) use ($mockClient) {
            return $mockClient;
        };

        // We need to set max retries in config for Retryable concern
        config(['phpredis-sentinel.retry.redis.attempts' => 3]);
        config(['phpredis-sentinel.retry.redis.delay' => 1]);

        $connection = new RedisSentinelConnection($mockClient, $connector);
        $connection->setRetryLimit(3);
        $connection->setRetryDelay(0);
        $connection->setRetryMessages(['Persistent failure']);

        expect(fn () => $connection->get('test_key'))->toThrow(RedisException::class);
    });
});
