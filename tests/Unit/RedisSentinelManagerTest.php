<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\Exceptions\ConfigurationException;
use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Illuminate\Contracts\Redis\Connector;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Laravel\Horizon\Connectors\RedisConnector;

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

test('sentinel resolution throws ConfigurationException when no connector is registered for the driver', function () {
    $config = [
        'default' => [
            'client' => 'phpredis-sentinel',
            'sentinel' => [
                'host' => '127.0.0.1',
                'service' => 'master',
            ],
        ],
    ];

    $manager = new RedisSentinelManager(null, 'phpredis', $config);
    $manager->resolveConnector('default');
})->throws(ConfigurationException::class, 'No connector registered for the [phpredis-sentinel] driver.');

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

test('resolving a sentinel connection does not mutate the manager driver', function () {
    $manager = app(RedisSentinelManager::class);
    $before = (new ReflectionProperty(RedisManager::class, 'driver'))->getValue($manager);

    $manager->connection('phpredis-sentinel');

    $after = (new ReflectionProperty(RedisManager::class, 'driver'))->getValue($manager);

    expect($after)->toBe($before);
});

test('sentinel resolution never exposes a swapped driver to the registered creator', function () {
    $config = [
        'default' => [
            'client' => 'phpredis-sentinel',
            'sentinel' => [
                'host' => '127.0.0.1',
                'service' => 'master',
            ],
        ],
    ];

    $manager = new RedisSentinelManager(null, 'phpredis', $config);
    $driverProp = new ReflectionProperty(RedisManager::class, 'driver');

    $observed = [];
    $connection = new class extends Connection
    {
        public function createSubscription($channels, Closure $callback, $method = 'subscribe') {}
    };
    $connector = new class($connection) implements Connector
    {
        public function __construct(private readonly Connection $connection) {}

        public function connect(array $config, array $options)
        {
            return $this->connection;
        }

        public function connectToCluster(array $config, array $clusterOptions, array $options)
        {
            return $this->connection;
        }
    };

    $manager->extend('phpredis-sentinel', function () use ($manager, $driverProp, &$observed, $connector) {
        $observed[] = $driverProp->getValue($manager);

        return $connector;
    });

    expect($manager->resolveConnector('default'))->toBe($connector)
        ->and($manager->resolve('default'))->toBe($connection)
        ->and($observed)->each->toBe('phpredis');
});

if (class_exists(RedisConnector::class)) {
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

    test('patchHorizonPrefix sets horizon prefix when in horizon context', function () {
        config()->set('horizon.driver', 'phpredis-sentinel');
        config()->set('horizon.prefix', 'horizon:');

        $manager = new RedisSentinelManager(app(), 'phpredis', []);
        $method = new ReflectionMethod($manager, 'patchHorizonPrefix');
        $method->setAccessible(true);

        $result = $method->invoke($manager, 'horizon', ['options' => ['prefix' => 'old:']]);

        expect($result['options']['prefix'])->toBe('horizon:');
    });

    test('patchHorizonPrefix keeps existing prefix when horizon.prefix is not set', function () {
        config()->set('horizon', ['driver' => 'phpredis-sentinel']);

        $manager = new RedisSentinelManager(app(), 'phpredis', []);
        $method = new ReflectionMethod($manager, 'patchHorizonPrefix');
        $method->setAccessible(true);

        $result = $method->invoke($manager, 'horizon', ['options' => ['prefix' => 'existing:']]);

        expect($result['options']['prefix'])->toBe('existing:');
    });

    test('patchHorizonPrefix does nothing when name is not horizon', function () {
        config()->set('horizon.driver', 'phpredis-sentinel');
        config()->set('horizon.prefix', 'horizon:');

        $manager = new RedisSentinelManager(app(), 'phpredis', []);
        $method = new ReflectionMethod($manager, 'patchHorizonPrefix');
        $method->setAccessible(true);

        $result = $method->invoke($manager, 'default', ['options' => ['prefix' => 'default:']]);

        expect($result['options']['prefix'])->toBe('default:');
    });

    test('patchHorizonPrefix does nothing when not in horizon context', function () {
        config()->offsetUnset('horizon.driver');

        $manager = new RedisSentinelManager(app(), 'phpredis', []);
        $method = new ReflectionMethod($manager, 'patchHorizonPrefix');
        $method->setAccessible(true);

        $result = $method->invoke($manager, 'horizon', ['options' => ['prefix' => 'default:']]);

        expect($result['options']['prefix'])->toBe('default:');
    });

    test('patchHorizonConnectionName returns horizon.use value when in horizon context', function () {
        config()->set('horizon', ['driver' => 'phpredis-sentinel', 'use' => 'my-sentinel']);

        $manager = new RedisSentinelManager(app(), 'phpredis', []);
        $method = new ReflectionMethod($manager, 'patchHorizonConnectionName');
        $method->setAccessible(true);

        expect($method->invoke($manager, 'horizon'))->toBe('my-sentinel');
    });
}

test('patchHorizonConnectionName returns original name when not horizon', function () {
    config()->set('horizon', ['driver' => 'phpredis-sentinel', 'use' => 'my-sentinel']);

    $manager = new RedisSentinelManager(app(), 'phpredis', []);
    $method = new ReflectionMethod($manager, 'patchHorizonConnectionName');
    $method->setAccessible(true);

    expect($method->invoke($manager, 'default'))->toBe('default')
        ->and($method->invoke($manager, 'cache'))->toBe('cache');
});

test('patchHorizonConnectionName returns original name when not in horizon context', function () {
    config()->offsetUnset('horizon.driver');

    $manager = new RedisSentinelManager(app(), 'phpredis', []);
    $method = new ReflectionMethod($manager, 'patchHorizonConnectionName');
    $method->setAccessible(true);

    expect($method->invoke($manager, 'horizon'))->toBe('horizon');
});

test('patchHorizonConnectionName uses default when name is null', function () {
    config()->set('horizon', ['driver' => 'phpredis-sentinel', 'use' => 'my-sentinel']);

    $manager = new RedisSentinelManager(app(), 'phpredis', []);
    $method = new ReflectionMethod($manager, 'patchHorizonConnectionName');
    $method->setAccessible(true);

    expect($method->invoke($manager))->toBe('default');
});
