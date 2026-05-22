<?php

use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Goopil\LaravelRedisSentinel\RedisSentinelServiceProvider;
use Laravel\Horizon\RedisQueue;

test('core classes do not import Horizon classes directly', function (string $class) {
    $reflection = new ReflectionClass($class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->not->toContain('use Laravel\\Horizon\\');
})->with([
    RedisSentinelManager::class,
    RedisSentinelServiceProvider::class,
]);

test('Horizon context is disabled when Horizon is not configured for Sentinel', function () {
    config()->set('horizon.driver', 'redis');

    $provider = new RedisSentinelServiceProvider(app());

    expect($provider->isHorizonContext())->toBeFalse();
});

test('Horizon queue connector is used when Horizon is configured for Sentinel', function () {
    config()->set('horizon.driver', 'phpredis-sentinel');
    config()->set('queue.connections.phpredis-sentinel', [
        'driver' => 'phpredis-sentinel',
        'connection' => 'phpredis-sentinel',
        'queue' => 'default',
    ]);

    $provider = new RedisSentinelServiceProvider(app());
    $method = new ReflectionMethod($provider, 'bootQueue');
    $method->setAccessible(true);
    $method->invoke($provider);

    expect($provider->isHorizonContext())->toBeTrue()
        ->and(app()->make('queue')->connection('phpredis-sentinel'))->toBeInstanceOf(RedisQueue::class);
});
