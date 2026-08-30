<?php

use Illuminate\Cache\RedisStore;

test('the provider does not eagerly resolve the cache, queue and session managers', function () {
    expect(app()->resolved('cache'))->toBeFalse()
        ->and(app()->resolved('queue'))->toBeFalse()
        ->and(app()->resolved('session'))->toBeFalse();
});

test('the sentinel drivers are registered once the managers are resolved', function () {
    expect(app('cache')->getStore('phpredis-sentinel'))->toBeInstanceOf(
        RedisStore::class
    );
});
