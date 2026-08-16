<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\Exceptions\ConfigurationException;
use Goopil\LaravelRedisSentinel\RedisSentinelManager;

test('resolveConnector throws InvalidArgumentException if clusters are defined', function () {
    $config = [
        'clusters' => [
            'default' => [
                [
                    'host' => '127.0.0.1',
                    'port' => 6379,
                ],
            ],
        ],
        'default' => [
            'client' => 'phpredis-sentinel',
        ],
    ];
    $manager = new RedisSentinelManager(null, 'phpredis', $config);
    $manager->resolveConnector('default');
})->throws(ConfigurationException::class, 'Redis Sentinel connections do not support Redis Cluster.');

test('resolveConnector throws InvalidArgumentException if connection is not defined', function () {
    $config = [
        'default' => [
            'host' => '127.0.0.1',
        ],
    ];
    $manager = new RedisSentinelManager(null, 'phpredis', $config);
    $manager->resolveConnector('other');
})->throws(ConfigurationException::class, 'No connection defined with base name other or overwritten name other in `database.redis` config');

test('resolveConnector throws ConfigurationException when cluster key matches connection name', function () {
    $config = [
        'clusters' => [
            'default' => [
                [
                    'host' => '127.0.0.1',
                    'port' => 6379,
                ],
            ],
        ],
        'default' => [
            'client' => 'phpredis-sentinel',
        ],
    ];
    $manager = new RedisSentinelManager(null, 'phpredis', $config);
    $manager->resolveConnector('default');
})->throws(ConfigurationException::class, 'Redis Sentinel connections do not support Redis Cluster.');

test('resolveConnector returns the correct connector', function () {
    $config = [
        'default' => [
            'client' => 'phpredis-sentinel',
            'sentinel' => [
                'host' => '127.0.0.1',
                'service' => 'master',
            ],
        ],
    ];

    // We need to extend the manager to register our connector
    $manager = new RedisSentinelManager(null, 'phpredis', $config);
    $connector = new RedisSentinelConnector(app(NodeAddressCache::class), app('config'));
    $manager->extend('phpredis-sentinel', fn () => $connector);

    expect($manager->resolveConnector('default'))->toBe($connector);
});

test('resolveConnector does not mutate driver property', function () {
    $config = [
        'default' => [
            'client' => 'phpredis-sentinel',
            'sentinel' => [
                'host' => '127.0.0.1',
                'service' => 'master',
            ],
        ],
        'other' => [
            'client' => 'phpredis',
            'host' => '127.0.0.1',
        ],
    ];

    $manager = new RedisSentinelManager(null, 'phpredis', $config);
    $connector = new RedisSentinelConnector(app(NodeAddressCache::class), app('config'));
    $manager->extend('phpredis-sentinel', fn () => $connector);
    $manager->extend('phpredis', fn () => $connector);

    $driverProp = new ReflectionProperty($manager, 'driver');
    $driverProp->setAccessible(true);
    $originalDriver = $driverProp->getValue($manager);

    $manager->resolveConnector('default');

    expect($driverProp->getValue($manager))->toBe($originalDriver);
});

test('resolve uses normalized name for non-sentinel connections in horizon context', function () {
    config()->set('horizon.use', 'horizon-sentinel');
    config()->set('horizon.driver', 'phpredis-sentinel');

    $config = [
        'horizon-sentinel' => [
            'client' => 'phpredis',
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ],
    ];

    $manager = new RedisSentinelManager(app(), 'phpredis', $config);

    $resolvedName = null;
    $manager->extend('phpredis', function () use (&$resolvedName) {
        return new class
        {
            public function connect($config, $options)
            {
                return new class
                {
                    public function close() {}
                };
            }
        };
    });

    $connection = $manager->resolve('horizon');

    expect($connection)->not->toBeNull();
});

test('patchHorizonConnectionName throws ConfigurationException when horizon.use is missing in horizon context', function () {
    config()->set('horizon.driver', 'phpredis-sentinel');
    config()->offsetUnset('horizon.use');

    $manager = new RedisSentinelManager(app(), 'phpredis', []);
    $manager->resolveConnector('horizon');
})->throws(ConfigurationException::class, 'The "horizon.use" configuration key is required');
