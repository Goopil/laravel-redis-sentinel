<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\Exceptions\ConfigurationException;
use Goopil\LaravelRedisSentinel\RedisSentinelManager;

test('resolveConnector throws InvalidArgumentException if clusters are defined', function () {
    $config = [
        'clusters' => [
            'name' => 'my-cluster',
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
    $connector = new RedisSentinelConnector(app(NodeAddressCache::class));
    $manager->extend('phpredis-sentinel', fn () => $connector);

    expect($manager->resolveConnector('default'))->toBe($connector);
});
