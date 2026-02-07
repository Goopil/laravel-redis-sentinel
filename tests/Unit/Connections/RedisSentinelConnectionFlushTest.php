<?php

use Illuminate\Support\Facades\Redis;

describe('RedisSentinelConnection Flush Commands', function () {
    beforeEach(function () {
        config()->set('database.redis.phpredis-sentinel', [
            'scheme' => 'tcp',
            'host' => env('REDIS_SENTINEL_HOST', '127.0.0.1'),
            'port' => env('REDIS_SENTINEL_PORT', 26379),
            'password' => env('REDIS_SENTINEL_PASSWORD', 'test'),
            'timeout' => 0.5,
            'retry_limit' => 3,
            'read_only_replicas' => true,
        ]);
    });

    test('flushdb resets stickiness flag', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Set a key to trigger write and set stickiness
        $connection->set('test_key', 'value');

        // Flush the database
        $result = $connection->flushdb();

        expect($result)->toBeTrue();

        // Verify database is empty
        $keys = $connection->keys('*');
        expect($keys)->toBeEmpty();
    });

    test('flushall resets stickiness flag', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Set a key to trigger write
        $connection->set('test_key', 'value');

        // Flush all databases
        $result = $connection->flushall();

        expect($result)->toBeTrue();
    });
});
