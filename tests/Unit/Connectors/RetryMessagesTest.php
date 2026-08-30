<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelMasterFailed;
use Goopil\LaravelRedisSentinel\Exceptions\ConfigurationException;
use Illuminate\Support\Facades\Event;

test('per-connection retry messages override the global config', function () {
    config(['phpredis-sentinel.retry.redis.messages' => ['global-message']]);

    $connector = new class(app(NodeAddressCache::class)) extends RedisSentinelConnector
    {
        protected function getMasterAddress(array $config, bool $refresh = false): array
        {
            return ['ip' => '127.0.0.1', 'port' => 6379];
        }

        protected function createClient(array $config, bool $refresh = false, bool $readOnly = false): Redis
        {
            return Mockery::mock(Redis::class);
        }
    };

    $connection = $connector->connect([
        'sentinel' => ['service' => 'master', 'host' => '127.0.0.1'],
        'retry' => ['redis' => ['messages' => ['per-connection']]],
    ], []);

    $messages = (new ReflectionProperty($connection, 'retryMessages'))->getValue($connection);

    expect($messages)->toBe(['per-connection']);
});

test('a transient total sentinel outage is retried, not failed immediately', function () {
    Event::fake();

    config([
        'phpredis-sentinel.retry.sentinel.attempts' => 1,
        'phpredis-sentinel.retry.sentinel.delay' => 1,
        'phpredis-sentinel.retry.sentinel.messages' => [
            'No master found for service',
            'No reachable Redis Sentinel host found',
        ],
        'database.redis.my-sentinel' => [
            'sentinels' => [
                ['host' => '127.0.0.1', 'port' => 26379],
                ['host' => '127.0.0.2', 'port' => 26379],
            ],
            'sentinel' => ['service' => 'master'],
        ],
    ]);

    $connector = new class(app(NodeAddressCache::class)) extends RedisSentinelConnector
    {
        protected function createSentinelInstance(array $options): RedisSentinel
        {
            throw new RedisException('Connection refused');
        }
    };

    try {
        $connector->createSentinel('my-sentinel');
        $this->fail('ConfigurationException expected');
    } catch (ConfigurationException $exception) {
        expect($exception->getMessage())->toContain('No reachable Redis Sentinel host found');
    }

    Event::assertDispatchedTimes(RedisSentinelMasterFailed::class, 2);
});
