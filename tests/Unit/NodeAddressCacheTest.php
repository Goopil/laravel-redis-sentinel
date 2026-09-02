<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;

const NODE_CACHE_TEST_HOST = '127.0.0.1';

test('it can store and retrieve master', function () {
    $cache = new NodeAddressCache;
    $cache->set('mymaster', NODE_CACHE_TEST_HOST, 6379);

    expect($cache->get('mymaster'))->toBe(['ip' => NODE_CACHE_TEST_HOST, 'port' => 6379]);
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
    $cache->set('mymaster', NODE_CACHE_TEST_HOST, 6379);
    $cache->forget('mymaster');

    expect($cache->get('mymaster'))->toBeNull();
});

test('it can flush cache', function () {
    $cache = new NodeAddressCache;
    $cache->set('mymaster', NODE_CACHE_TEST_HOST, 6379);
    $cache->flush();

    expect($cache->get('mymaster'))->toBeNull();
});

test('forgetMaster on non-existent service does not error', function () {
    $cache = new NodeAddressCache;

    expect(fn () => $cache->forgetMaster('nonexistent'))->not->toThrow(Exception::class);
});

test('forgetMaster after flush does not error', function () {
    $cache = new NodeAddressCache;
    $cache->set('mymaster', '127.0.0.1', 6379);
    $cache->flush();

    expect(fn () => $cache->forgetMaster('mymaster'))->not->toThrow(Exception::class);
    expect($cache->get('mymaster'))->toBeNull();
});

test('forgetMaster preserves replicas', function () {
    $cache = new NodeAddressCache;
    $cache->set('mymaster', '127.0.0.1', 6379);
    $cache->setReplicas('mymaster', [['ip' => '127.0.0.2', 'port' => 6379]]);

    $cache->forgetMaster('mymaster');

    expect($cache->get('mymaster'))->toBeNull()
        ->and($cache->getReplicas('mymaster'))->toHaveCount(1);
});

test('forgetMaster removes service entry when no replicas remain', function () {
    $cache = new NodeAddressCache;
    $cache->set('mymaster', '127.0.0.1', 6379);

    $cache->forgetMaster('mymaster');

    $ref = new ReflectionProperty($cache, 'nodes');
    $ref->setAccessible(true);

    expect($ref->getValue($cache))->not->toHaveKey('mymaster');
});

test('entries expire after the configured ttl', function () {
    $now = 1000.0;
    $cache = new NodeAddressCache(0.01, function () use (&$now) {
        return $now;
    });

    $cache->set('svc', '127.0.0.1', 6379);
    $cache->setReplicas('svc', [['ip' => '127.0.0.1', 'port' => 6380]]);

    expect($cache->get('svc'))->toBe(['ip' => '127.0.0.1', 'port' => 6379])
        ->and($cache->getReplicas('svc'))->toBe([['ip' => '127.0.0.1', 'port' => 6380]]);

    $now = 1000.011;

    expect($cache->get('svc'))->toBeNull()
        ->and($cache->getReplicas('svc'))->toBe([]);
});

test('ttl zero keeps entries forever (backwards compatible)', function () {
    $now = 1000.0;
    $cache = new NodeAddressCache(0.0, function () use (&$now) {
        return $now;
    });

    $cache->set('svc', '127.0.0.1', 6379);
    $cache->setReplicas('svc', [['ip' => '127.0.0.1', 'port' => 6380]]);

    $now = 2000.0;

    expect($cache->get('svc'))->toBe(['ip' => '127.0.0.1', 'port' => 6379])
        ->and($cache->getReplicas('svc'))->toBe([['ip' => '127.0.0.1', 'port' => 6380]]);
});

test('expiring one entry type keeps the other', function () {
    $now = 1000.0;
    $cache = new NodeAddressCache(0.01, function () use (&$now) {
        return $now;
    });

    $cache->set('svc', '127.0.0.1', 6379);
    $now = 1000.011;
    $cache->setReplicas('svc', [['ip' => '127.0.0.2', 'port' => 6380]]);

    expect($cache->get('svc'))->toBeNull()
        ->and($cache->getReplicas('svc'))->toBe([['ip' => '127.0.0.2', 'port' => 6380]]);
});

test('negative ttl behaves like zero (no expiry)', function () {
    $now = 1000.0;
    $cache = new NodeAddressCache(-5.0, function () use (&$now) {
        return $now;
    });

    $cache->set('svc', '127.0.0.1', 6379);
    $now = 2000.0;

    expect($cache->get('svc'))->toBe(['ip' => '127.0.0.1', 'port' => 6379]);
});
