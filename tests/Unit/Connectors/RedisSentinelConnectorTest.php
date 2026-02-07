<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\Exceptions\ConfigurationException;
use Goopil\LaravelRedisSentinel\Exceptions\NotImplementedException;
use Illuminate\Redis\Connections\PhpRedisConnection;

test('connectToCluster throws NotImplementedException', function () {
    $connector = new RedisSentinelConnector(app(NodeAddressCache::class));
    $connector->connectToCluster([], [], []);
})->throws(NotImplementedException::class, 'The Redis Sentinel driver does not support connecting to clusters.');

test('createSentinel throws ConfigurationException if host is missing', function () {
    config(['database.redis.my-sentinel' => [
        'sentinel' => [
            'service' => 'master',
            'port' => 26379,
        ],
    ]]);

    $connector = new RedisSentinelConnector(app(NodeAddressCache::class));
    $connector->createSentinel('my-sentinel');
})->throws(ConfigurationException::class, 'No reachable Redis Sentinel host found.');

test('createSentinel throws ConfigurationException if service is missing', function () {
    config(['database.redis.my-sentinel' => [
        'sentinel' => [
            'host' => '127.0.0.1',
        ],
    ]]);

    $connector = new RedisSentinelConnector(app(NodeAddressCache::class));
    $connector->createSentinel('my-sentinel');
})->throws(ConfigurationException::class, "No service name has been specified for the Redis Sentinel connection 'my-sentinel'.");

test('createSentinel throws RedisException if sentinel config is missing', function () {
    config(['database.redis.my-sentinel' => []]);

    $connector = new RedisSentinelConnector(app(NodeAddressCache::class));
    $connector->createSentinel('my-sentinel');
})->throws(RedisException::class, 'No sentinel config');

test('connect preserves merged options across reconnects', function () {
    $connector = new class(app(NodeAddressCache::class)) extends RedisSentinelConnector
    {
        public array $configs = [];

        protected function getMasterAddress(array $config, bool $refresh = false): array
        {
            return ['ip' => '127.0.0.1', 'port' => 6379];
        }

        protected function createClient(array $config, bool $refresh = false, bool $readOnly = false): \Redis
        {
            $this->configs[] = $config;

            return Mockery::mock(\Redis::class);
        }
    };

    $config = [
        'sentinel' => [
            'service' => 'master',
            'host' => '127.0.0.1',
        ],
        'options' => [
            'prefix' => 'conn:',
            'serializer' => \Redis::SERIALIZER_PHP,
        ],
    ];

    $options = [
        'prefix' => 'global:',
        'read_timeout' => 5,
    ];

    $connection = $connector->connect($config, $options);

    $property = new \ReflectionProperty(PhpRedisConnection::class, 'connector');
    $reconnector = $property->getValue($connection);
    $reconnector(true);

    expect($connector->configs)->toHaveCount(2);
    foreach ($connector->configs as $captured) {
        expect($captured['options']['prefix'])->toBe('conn:');
        expect($captured['options']['read_timeout'])->toBe(5);
        expect($captured['options']['serializer'])->toBe(\Redis::SERIALIZER_PHP);
    }
});

test('connect applies redis retry config with overrides', function () {
    config([
        'phpredis-sentinel.retry.redis.attempts' => 2,
        'phpredis-sentinel.retry.redis.delay' => 250,
    ]);

    $connector = new class(app(NodeAddressCache::class)) extends RedisSentinelConnector
    {
        protected function getMasterAddress(array $config, bool $refresh = false): array
        {
            return ['ip' => '127.0.0.1', 'port' => 6379];
        }

        protected function createClient(array $config, bool $refresh = false, bool $readOnly = false): \Redis
        {
            return Mockery::mock(\Redis::class);
        }
    };

    $connection = $connector->connect([
        'sentinel' => [
            'service' => 'master',
            'host' => '127.0.0.1',
        ],
        'retry' => [
            'redis' => [
                'attempts' => 4,
                'delay' => 500,
            ],
        ],
    ], []);

    $limitProperty = new \ReflectionProperty(RedisSentinelConnection::class, 'retryLimit');
    $delayProperty = new \ReflectionProperty(RedisSentinelConnection::class, 'retryDelay');

    expect($limitProperty->getValue($connection))->toBe(4);
    expect($delayProperty->getValue($connection))->toBe(500);
});
