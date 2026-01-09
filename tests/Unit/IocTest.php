<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\RedisSentinelManager;
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
