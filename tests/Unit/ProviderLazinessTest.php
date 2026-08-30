<?php

use Illuminate\Cache\RedisStore;

test('the provider does not eagerly resolve the cache, queue and session managers', function () {
    expect(app()->resolved('cache'))->toBeFalse()
        ->and(app()->resolved('queue'))->toBeFalse()
        ->and(app()->resolved('session'))->toBeFalse();
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
