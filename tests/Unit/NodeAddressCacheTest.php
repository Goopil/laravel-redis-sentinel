<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;

test('it can store and retrieve master', function () {
    $cache = new NodeAddressCache;
    $cache->set('mymaster', '127.0.0.1', 6379);

    expect($cache->get('mymaster'))->toBe(['ip' => '127.0.0.1', 'port' => 6379]);
});

test('it can store and retrieve replicas', function () {
    $cache = new NodeAddressCache;
    $replicas = [
        ['ip' => '127.0.0.2', 'port' => 6379],
        ['ip' => '127.0.0.3', 'port' => 6379],
    ];
    $cache->setReplicas('mymaster', $replicas);

    expect($cache->getReplicas('mymaster'))->toBe($replicas);
});

test('it can forget service', function () {
    $cache = new NodeAddressCache;
    $cache->set('mymaster', '127.0.0.1', 6379);
    $cache->forget('mymaster');

    expect($cache->get('mymaster'))->toBeNull();
});

test('it can flush cache', function () {
    $cache = new NodeAddressCache;
    $cache->set('mymaster', '127.0.0.1', 6379);
    $cache->flush();

    expect($cache->get('mymaster'))->toBeNull();
});

test('master cache expires after TTL', function () {
    $cache = new NodeAddressCache(ttl: 1);
    $cache->set('mymaster', '127.0.0.1', 6379);

    expect($cache->get('mymaster'))->toBe(['ip' => '127.0.0.1', 'port' => 6379]);

    $ref = new ReflectionProperty($cache, 'nodes');
    $ref->setAccessible(true);
    $nodes = $ref->getValue($cache);
    $nodes['mymaster']['master']['cached_at'] = time() - 10;
    $ref->setValue($cache, $nodes);

    expect($cache->get('mymaster'))->toBeNull();
});

test('replicas cache expires after TTL', function () {
    $cache = new NodeAddressCache(ttl: 1);
    $cache->setReplicas('mymaster', [['ip' => '127.0.0.2', 'port' => 6379]]);

    expect($cache->getReplicas('mymaster'))->toHaveCount(1);

    $ref = new ReflectionProperty($cache, 'nodes');
    $ref->setAccessible(true);
    $nodes = $ref->getValue($cache);
    $nodes['mymaster']['replicas_cached_at'] = time() - 10;
    $ref->setValue($cache, $nodes);

    expect($cache->getReplicas('mymaster'))->toBeEmpty();
});
