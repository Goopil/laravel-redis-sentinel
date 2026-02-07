<?php

use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Illuminate\Broadcasting\Broadcasters\RedisBroadcaster;
use Illuminate\Cache\RedisStore;
use Illuminate\Queue\RedisQueue;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

test('cache driver is correctly extended', function () {
    $driver = Cache::driver('phpredis-sentinel');
    $store = $driver->getStore();

    expect($store)->toBeInstanceOf(RedisStore::class)
        ->and($store->getRedis())->toBeInstanceOf(RedisSentinelManager::class);
});

test('queue driver is correctly extended', function () {
    // Need to define a queue connection using our driver
    config()->set('queue.connections.test-sentinel', [
        'driver' => 'phpredis-sentinel',
        'connection' => 'default',
        'queue' => 'default',
    ]);

    $queue = Queue::connection('test-sentinel');
    // RedisQueue is returned by the connector
    expect($queue)->toBeInstanceOf(RedisQueue::class)
        ->and($queue->getRedis())->toBeInstanceOf(RedisSentinelManager::class);
});

test('session driver is correctly extended', function () {
    config()->set('session.driver', 'phpredis-sentinel');

    $manager = app('session');
    $driver = $manager->driver('phpredis-sentinel');

    expect($driver->getHandler())->toBeInstanceOf(CacheBasedSessionHandler::class);
});

test('broadcaster driver is correctly extended', function () {
    config()->set('broadcasting.connections.test-sentinel', [
        'driver' => 'phpredis-sentinel',
        'connection' => 'default',
    ]);

    $broadcaster = Broadcast::connection('test-sentinel');

    expect($broadcaster)->toBeInstanceOf(RedisBroadcaster::class);

    $reflection = new ReflectionClass($broadcaster);
    $property = $reflection->getProperty('redis');

    expect($property->getValue($broadcaster))->toBeInstanceOf(RedisSentinelManager::class);
});
