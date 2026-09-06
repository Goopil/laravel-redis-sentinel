<?php

use Goopil\LaravelRedisSentinel\Commands\HorizonWorkerLiveness;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\Exceptions\ConfigurationException;
use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Illuminate\Support\Arr;

/**
 * Both 1.9.0 fixes shipped because two readers of the same configuration
 * disagreed on its shape (service key location, uninitialized connections).
 * These tests pin the schema contract: every reader must resolve documented
 * keys identically, and the documented precedence must hold.
 */
test('service name resolves identically across every documented config shape', function (array $config, ?string $expected) {
    expect(RedisSentinelConnector::serviceFromConfig($config))->toBe($expected);
})->with([
    'nested sentinel.service' => [['sentinel' => ['service' => 'nested']], 'nested'],
    'connection-level service' => [['service' => 'level'], 'level'],
    'both set: nested wins (documented precedence)' => [
        ['sentinel' => ['service' => 'nested'], 'service' => 'level'],
        'nested',
    ],
    'neither set: null' => [['sentinel' => ['host' => '127.0.0.1']], null],
]);

test('createSentinel refuses a connection without a service name before any sentinel call', function () {
    config([
        'database.redis.phpredis-sentinel' => [
            'sentinel' => ['host' => '127.0.0.1', 'port' => 26379],
        ],
    ]);

    $connector = app(RedisSentinelConnector::class);

    // The bug-2 repro: getMasterAddrByName(null) must never be reachable.
    expect(fn () => $connector->createSentinel('phpredis-sentinel'))
        ->toThrow(ConfigurationException::class);
});

test('liveness resolves the same service name as the connector for every config shape', function (array $config, string $expectedService) {
    config([
        'horizon.use' => 'phpredis-sentinel',
        'database.redis.phpredis-sentinel' => $config,
    ]);

    $sentinel = Mockery::mock(RedisSentinel::class);
    $sentinel->expects('getMasterAddrByName')
        ->with($expectedService)
        ->andReturns(['ip' => '127.0.0.1', 'port' => 26379]);

    $connector = Mockery::mock(RedisSentinelConnector::class);
    $connector->expects('createSentinel')->andReturns($sentinel);

    $manager = Mockery::mock(RedisSentinelManager::class);
    $manager->allows('resolveConnector')->andReturns($connector);

    $command = new HorizonWorkerLiveness;
    $command->setLaravel(app());

    expect($command->checkSentinel($manager))->toBe(0);
})->with([
    'nested sentinel.service' => [
        ['sentinel' => ['service' => 'nested', 'host' => '127.0.0.1']],
        'nested',
    ],
    'connection-level service' => [
        ['service' => 'level', 'sentinel' => ['host' => '127.0.0.1']],
        'level',
    ],
    'both set: nested wins' => [
        ['sentinel' => ['service' => 'nested', 'host' => '127.0.0.1'], 'service' => 'level'],
        'nested',
    ],
]);

test('liveness exits 1 without a service name instead of querying a null service', function () {
    config([
        'horizon.use' => 'phpredis-sentinel',
        'database.redis.phpredis-sentinel' => [
            'sentinel' => ['host' => '127.0.0.1', 'port' => 26379],
        ],
    ]);

    $manager = Mockery::mock(RedisSentinelManager::class);
    $manager->allows('resolveConnector')->andReturns(app(RedisSentinelConnector::class));

    $command = new HorizonWorkerLiveness;
    $command->setLaravel(app());

    expect($command->checkSentinel($manager))->toBe(1);
});

test('the published config documents every global key the package reads', function () {
    foreach ([
        'override_laravel_redis',
        'node_cache.ttl',
        'read_commands',
        'log.channel',
        'log.notify_swallowed',
        'commands.events.emit_success',
        'retry.sentinel.attempts',
        'retry.sentinel.delay',
        'retry.sentinel.messages',
        'retry.redis.attempts',
        'retry.redis.delay',
        'retry.redis.messages',
    ] as $key) {
        expect(Arr::has(config('phpredis-sentinel'), $key))->toBeTrue(
            "phpredis-sentinel.{$key} is read by the package but missing from the published config."
        );
    }
});
