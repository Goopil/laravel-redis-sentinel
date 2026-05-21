<?php

use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Goopil\LaravelRedisSentinel\RedisSentinelServiceProvider;

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
