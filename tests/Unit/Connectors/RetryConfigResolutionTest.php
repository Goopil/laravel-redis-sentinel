<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;

function resolvingConnector(): RedisSentinelConnector
{
    return new class(app(NodeAddressCache::class), app('config')) extends RedisSentinelConnector
    {
        protected function createClient(array $config, bool $refresh = false, bool $readOnly = false): Redis
        {
            return Mockery::mock(Redis::class);
        }
    };
}

function retrySettings(RedisSentinelConnection $connection): array
{
    return [
        'limit' => (new ReflectionProperty($connection, 'retryLimit'))->getValue($connection),
        'delay' => (new ReflectionProperty($connection, 'retryDelay'))->getValue($connection),
        'messages' => (new ReflectionProperty($connection, 'retryMessages'))->getValue($connection),
    ];
}

test('explicit null in retry config falls back to documented defaults', function () {
    config([
        'phpredis-sentinel.retry.redis.attempts' => 5,
        'phpredis-sentinel.retry.redis.delay' => 1000,
    ]);

    $connection = resolvingConnector()->connect([
        'sentinel' => ['service' => 'master', 'host' => '127.0.0.1'],
        'retry' => ['redis' => ['attempts' => null, 'delay' => null, 'messages' => null]],
    ], []);

    expect(retrySettings($connection))->toBe([
        'limit' => 5,
        'delay' => 1000,
        'messages' => config('phpredis-sentinel.retry.redis.messages'),
    ]);
});

test('explicit zeros and empty message list are respected', function () {
    $connection = resolvingConnector()->connect([
        'sentinel' => ['service' => 'master', 'host' => '127.0.0.1'],
        'retry' => ['redis' => ['attempts' => 0, 'delay' => 0, 'messages' => []]],
    ], []);

    expect(retrySettings($connection))->toBe([
        'limit' => 0,
        'delay' => 0,
        'messages' => [],
    ]);
});

test('invalid scalars in retry config fall back to defaults', function () {
    config([
        'phpredis-sentinel.retry.redis.attempts' => 5,
        'phpredis-sentinel.retry.redis.delay' => 1000,
    ]);

    $connection = resolvingConnector()->connect([
        'sentinel' => ['service' => 'master', 'host' => '127.0.0.1'],
        'retry' => ['redis' => ['attempts' => 'many', 'delay' => -50, 'messages' => 'socket']],
    ], []);

    expect(retrySettings($connection))->toBe([
        'limit' => 5,
        'delay' => 1000,
        'messages' => config('phpredis-sentinel.retry.redis.messages'),
    ]);
});

test('a per-connection null falls through to a distinct global value before the default', function () {
    config([
        'phpredis-sentinel.retry.redis.attempts' => 7,
    ]);

    $connection = resolvingConnector()->connect([
        'sentinel' => ['service' => 'master', 'host' => '127.0.0.1'],
        'retry' => ['redis' => ['attempts' => null]],
    ], []);

    expect((new ReflectionProperty($connection, 'retryLimit'))->getValue($connection))->toBe(7);
});

test('global sentinel retry config falls back to connector defaults on null or invalid values', function () {
    config([
        'phpredis-sentinel.retry.sentinel.attempts' => null,
        'phpredis-sentinel.retry.sentinel.delay' => 'soon',
        'phpredis-sentinel.retry.sentinel.messages' => null,
    ]);

    $connector = resolvingConnector();

    expect((new ReflectionProperty($connector, 'retryLimit'))->getValue($connector))->toBe(5)
        ->and((new ReflectionProperty($connector, 'retryDelay'))->getValue($connector))->toBe(1000)
        ->and((new ReflectionProperty($connector, 'retryMessages'))->getValue($connector))->toBe([]);
});
