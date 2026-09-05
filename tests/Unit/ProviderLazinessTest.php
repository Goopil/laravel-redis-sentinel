<?php

use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Illuminate\Cache\RedisStore;

test('connections() returns an array on a fresh manager, before any connection is resolved', function () {
    // Fresh Octane workers fire RequestReceived before any Redis connection is
    // resolved, and the stickiness reset iterates connections(). Upstream
    // RedisManager leaves the underlying property uninitialized, which made
    // connections() return null and the foreach crash.
    expect(app(RedisSentinelManager::class)->connections())->toBeArray();
});

test('the provider does not eagerly resolve the cache, queue and session managers', function () {
    // Laravel 10's Testbench skeleton resolves `cache` during boot, independently of this
    // package; the provider itself stays lazy on every supported version (probed on L10:
    // queue/session remain unresolved with the provider registered).
    $frameworkResolvesCache = version_compare(app()->version(), '11.0', '<');

    expect(app()->resolved('queue'))->toBeFalse()
        ->and(app()->resolved('session'))->toBeFalse();

    if (! $frameworkResolvesCache) {
        expect(app()->resolved('cache'))->toBeFalse();
    }
});

test('the sentinel drivers are registered once the managers are resolved', function () {
    expect(app('cache')->store('phpredis-sentinel')->getStore())->toBeInstanceOf(
        RedisStore::class
    );
});

test('the sentinel cache driver extension is registered once the cache manager is resolved', function () {
    config()->set('cache.stores.via-sentinel-driver', [
        'driver' => 'phpredis-sentinel',
        'connection' => 'phpredis-sentinel',
    ]);

    expect(app('cache')->store('via-sentinel-driver')->getStore())->toBeInstanceOf(
        RedisStore::class
    );
});
