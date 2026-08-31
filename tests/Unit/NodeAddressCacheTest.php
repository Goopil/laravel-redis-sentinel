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

test('entries expire after the configured ttl', function () {
    $cache = new NodeAddressCache(0.01);

    $cache->set('svc', '127.0.0.1', 6379);
    $cache->setReplicas('svc', [['ip' => '127.0.0.1', 'port' => 6380]]);

    expect($cache->get('svc'))->toBe(['ip' => '127.0.0.1', 'port' => 6379])
        ->and($cache->getReplicas('svc'))->toBe([['ip' => '127.0.0.1', 'port' => 6380]]);

    usleep(20000);

    expect($cache->get('svc'))->toBeNull()
        ->and($cache->getReplicas('svc'))->toBe([]);
});

test('ttl zero keeps entries forever (backwards compatible)', function () {
    $cache = new NodeAddressCache(0.0);

    $cache->set('svc', '127.0.0.1', 6379);
    $cache->setReplicas('svc', [['ip' => '127.0.0.1', 'port' => 6380]]);

    usleep(20000);

    expect($cache->get('svc'))->toBe(['ip' => '127.0.0.1', 'port' => 6379])
        ->and($cache->getReplicas('svc'))->toBe([['ip' => '127.0.0.1', 'port' => 6380]]);
});

test('expiring one entry type keeps the other', function () {
    $cache = new NodeAddressCache(0.01);

    $cache->set('svc', '127.0.0.1', 6379);
    usleep(20000);
    $cache->setReplicas('svc', [['ip' => '127.0.0.2', 'port' => 6380]]);

    expect($cache->get('svc'))->toBeNull()
        ->and($cache->getReplicas('svc'))->toBe([['ip' => '127.0.0.2', 'port' => 6380]]);
});
