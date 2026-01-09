<?php

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

function validateCache(Repository $cache): void
{
    $cache->set('foo', 'bar');

    expect($cache->has('foo'))->toBeTrue()
        ->and($cache->get('foo'))->toEqual('bar');

    $cache->delete('foo');

    expect($cache->has('foo'))->toBeFalse();
}

describe('Cache', function () {
    test('Sentinel Store from facade is working', function () {
        $cache = Cache::driver('phpredis-sentinel');
        validateCache($cache);

        expect($cache->getStore()->connection())
            ->toBeARedisSentinelConnection()
            ->toBeAWorkingRedisConnection();
    });

    test('Sentinel Store from app()->make is working', function () {
        $cache = app()->make('cache')->driver('phpredis-sentinel');
        validateCache($cache);

        expect($cache->getStore()->connection())
            ->toBeARedisSentinelConnection()
            ->toBeAWorkingRedisConnection();
    });

    test('Redis Store from facade is working', function () {
        $cache = Cache::driver('redis');
        validateCache($cache);

        expect($cache->getStore()->connection())
            ->toBeARedisConnection()
            ->toBeAWorkingRedisConnection();
    });

    test('Redis Store from app()->make is working', function () {
        $cache = app()->make('cache')->driver('redis');
        validateCache($cache);

        expect($cache->getStore()->connection())
            ->toBeARedisConnection()
            ->toBeAWorkingRedisConnection();
    });
});
