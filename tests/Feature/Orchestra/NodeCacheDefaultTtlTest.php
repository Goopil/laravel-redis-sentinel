<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;

test('node_cache.ttl defaults to 15 seconds', function () {
    expect(config('phpredis-sentinel.node_cache.ttl'))->toBe(15);
});

test('node cache falls back to a 15s ttl when the config key is missing', function () {
    config()->offsetUnset('phpredis-sentinel.node_cache.ttl');
    app()->forgetInstance(NodeAddressCache::class);

    $cache = app(NodeAddressCache::class);

    $ttl = new ReflectionProperty(NodeAddressCache::class, 'ttlSeconds');

    expect($ttl->getValue($cache))->toBe(15.0);
});
