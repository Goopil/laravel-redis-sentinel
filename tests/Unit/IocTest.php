<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Goopil\LaravelRedisSentinel\RedisSentinelServiceProvider;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\RedisManager;

describe('Ioc bindings', function () {
    test('RedisSentinelManager is bound', function () {
        expect(app()->make(RedisSentinelManager::class))->toBeInstanceOf(RedisSentinelManager::class)
            ->and(app()->make('phpredis-sentinel'))->toBeInstanceOf(RedisSentinelManager::class);
    });
    test('RedisSentinelManager override global redis alias', function () {
        expect(app()->make('redis'))->toBeInstanceOf(RedisSentinelManager::class);
    });

    test('RedisSentinelManager does not override global redis alias when disabled', function () {
        config()->set('phpredis-sentinel.override_laravel_redis', false);

        app()->singleton('redis', fn () => 'native redis manager');
        app()->bind('redis.connection', fn () => 'native redis connection');

        $provider = new RedisSentinelServiceProvider(app());
        $method = new ReflectionMethod($provider, 'bootOverrides');
        $method->setAccessible(true);
        $method->invoke($provider);

        expect(app()->make('redis'))->toBe('native redis manager')
            ->and(app()->make('redis.connection'))->toBe('native redis connection');
    });

    test('bootOverrides flushes already resolved redis instances', function () {
        app()->forgetInstance('redis');
        app()->forgetInstance('redis.connection');

        app()->singleton('redis', fn ($app) => new RedisManager(
            $app,
            'phpredis',
            $app['config']->get('database.redis', [])
        ));

        $originalInstance = app()->make('redis');
        expect($originalInstance)->toBeInstanceOf(RedisManager::class)
            ->not->toBeInstanceOf(RedisSentinelManager::class);

        $provider = new RedisSentinelServiceProvider(app());
        $method = new ReflectionMethod($provider, 'bootOverrides');
        $method->setAccessible(true);
        $method->invoke($provider);

        $newInstance = app()->make('redis');

        expect($newInstance)->toBeInstanceOf(RedisSentinelManager::class)
            ->and(spl_object_id($newInstance))->not->toBe(spl_object_id($originalInstance));
    });

    test('explicit Sentinel integrations keep working without global redis override', function () {
        config()->set('phpredis-sentinel.override_laravel_redis', false);
        config()->set('cache.stores.phpredis-sentinel.driver', 'phpredis-sentinel');

        app()->forgetInstance('redis');
        app()->forgetInstance('redis.connection');

        app()->singleton('redis', fn ($app) => new RedisManager(
            $app,
            $app['config']->get('database.redis.client', 'phpredis'),
            $app['config']->get('database.redis', [])
        ));

        $provider = new RedisSentinelServiceProvider(app());
        $method = new ReflectionMethod($provider, 'bootOverrides');
        $method->setAccessible(true);
        $method->invoke($provider);

        expect(app()->make('redis'))->toBeInstanceOf(RedisManager::class)
            ->not->toBeInstanceOf(RedisSentinelManager::class)
            ->and(app()->make('cache')->store('phpredis-sentinel')->getStore()->getRedis())->toBeInstanceOf(RedisSentinelManager::class)
            ->and(app()->make('cache')->store('phpredis-sentinel')->getStore()->connection())
            ->toBeInstanceOf(RedisSentinelConnection::class)
            ->toBeARedisSentinelConnection();
    });

    test('RedisSentinelManager is bound to queue', function () {
        expect(app()->make('queue')->connection('redis')->getRedis())->toBeInstanceOf(RedisSentinelManager::class)
            ->and(app()->make('queue')->connection('redis')->getConnection())->toBeInstanceOf(PhpRedisConnection::class)
            ->toBeAWorkingRedisConnection();
    });

    test('RedisSentinelManager is bound to cache', function () {
        expect(app()->make('cache')->store('phpredis-sentinel')->getStore()->getRedis())->toBeInstanceOf(RedisSentinelManager::class)
            ->and(app()->make('cache')->store('phpredis-sentinel')->getStore()->connection())
            ->toBeInstanceOf(RedisSentinelConnection::class)
            ->toBeARedisSentinelConnection()
            ->and(app()->make('cache')->store('redis')->getStore()->getRedis())->toBeInstanceOf(RedisManager::class)
            ->and(app()->make('cache')->store('redis')->getStore()->connection())->toBeInstanceOf(PhpRedisConnection::class)
            ->toBeAWorkingRedisConnection();
    });

    test('RedisSentinelManager is bound to session', function () {
        config()->set('session.connection', 'phpredis-sentinel');

        expect(app()->make('session')->driver('phpredis-sentinel')->getHandler()->getCache()->getStore()->getRedis())->toBeInstanceOf(RedisSentinelManager::class)
            ->and(app()->make('session')->driver('phpredis-sentinel')->getHandler()->getCache()->getStore()->connection())
            ->toBeInstanceOf(RedisSentinelConnection::class)
            ->toBeARedisSentinelConnection();

        config()->set('session.connection', 'default');

        expect(app()->make('session')->driver('redis')->getHandler()->getCache()->getStore()->getRedis())->toBeInstanceOf(RedisManager::class)
            ->and(app()->make('cache')->store('redis')->getStore()->connection())->toBeInstanceOf(PhpRedisConnection::class)
            ->toBeAWorkingRedisConnection();
    });

    test('session handler does not corrupt cache store connection', function () {
        config()->set('session.connection', 'redis');
        config()->set('session.lifetime', 120);

        $cacheStore = app()->make('cache')->driver('phpredis-sentinel');
        $store = $cacheStore->getStore();

        $ref = new ReflectionProperty($store, 'connection');
        $ref->setAccessible(true);
        $originalConnection = $ref->getValue($store);

        $provider = new RedisSentinelServiceProvider(app());
        $bootMethod = new ReflectionMethod($provider, 'bootSessionHandler');
        $bootMethod->setAccessible(true);
        $bootMethod->invoke($provider);

        $sessionManager = app()->make('session');
        $driversRef = new ReflectionProperty($sessionManager, 'drivers');
        $driversRef->setAccessible(true);
        $driversRef->setValue($sessionManager, []);

        $handler = app()->make('session')->driver('phpredis-sentinel')->getHandler();
        $handlerStore = $handler->getCache()->getStore();

        $handlerConnectionRef = new ReflectionProperty($handlerStore, 'connection');
        $handlerConnectionRef->setAccessible(true);
        $handlerConnection = $handlerConnectionRef->getValue($handlerStore);

        $afterConnection = $ref->getValue($store);

        expect($handlerConnection)->toBe('redis')
            ->and($afterConnection)->toBe($originalConnection);
    });

    test('RedisSentinelConnector is bound', function () {
        expect(app()->make(RedisSentinelConnector::class))->toBeInstanceOf(RedisSentinelConnector::class)
            ->and(app()->make('redis.sentinel'))->toBeInstanceOf(RedisSentinelConnector::class);
    });

    test('RedisConnection should work', function () {
        $redisConnection = getRedisConnection();

        expect($redisConnection)
            ->toBeARedisConnection()
            ->not->toBeARedisSentinelConnection()
            ->toBeAWorkingRedisConnection();

        $redisConnection->close();
    });

    test('RedisSentinelConnection should work', function () {
        $redisConnection = getRedisSentinelConnection();

        expect($redisConnection)
            ->toBeARedisSentinelConnection()
            ->toBeAWorkingRedisConnection();

        // Test incr via __call
        $key = 'test_incr';
        $redisConnection->del($key);
        expect($redisConnection->incr($key))->toBe(1)
            ->and($redisConnection->incr($key))->toBe(2);
        $redisConnection->del($key);

        $redisConnection->close();
    });
});
