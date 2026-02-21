<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionFailed;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionMaxRetryFailed;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelMasterFailed;
use Illuminate\Support\Facades\Event;

test('onSentinelFail dispatches RedisSentinelMasterFailed', function () {
    Event::fake();
    $connector = new RedisSentinelConnector(app(NodeAddressCache::class));
    // Access protected method via reflection or just make a public wrapper in a test class
    $reflection = new ReflectionClass($connector);
    $method = $reflection->getMethod('onSentinelFail');

    $closure = $method->invoke($connector, 'my-service', 'my-method');
    $closure(new Exception('test'), 1);

    Event::assertDispatched(RedisSentinelMasterFailed::class, function ($event) {
        return $event->service === 'my-service' && $event->context === 'my-method';
    });
});

test('RedisSentinelConnectionFailed is dispatched when connection fails', function () {
    Event::fake();

    $client = Mockery::mock(Redis::class);
    $client->allows('get')->andThrow(new RedisException('connection closed'));

    $connector = function () use ($client) {
        return $client;
    };

    $config = [
        'sentinel' => [
            'retry' => [
                'attempts' => 1,
                'delay' => 1,
            ],
        ],
    ];

    config(['phpredis-sentinel.retry.redis.messages' => ['connection closed']]);

    $connection = new RedisSentinelConnection(
        $client,
        $connector,
        $config
    );
    $connection->setRetryMessages(['connection closed']);
    $connection->setRetryLimit(1);

    try {
        $connection->get('foo');
    } catch (Throwable $e) {
        // Expected
    }

    Event::assertDispatched(RedisSentinelConnectionFailed::class);
    Event::assertDispatched(RedisSentinelConnectionMaxRetryFailed::class);
});
