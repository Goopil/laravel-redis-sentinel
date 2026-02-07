<?php

use Illuminate\Support\Facades\Redis;

describe('RedisSentinelConnection Full Integration', function () {
    beforeEach(function () {
        // Use the standard CI configuration
        config()->set('database.redis.phpredis-sentinel', [
            'scheme' => 'tcp',
            'host' => env('REDIS_SENTINEL_HOST', '127.0.0.1'),
            'port' => env('REDIS_SENTINEL_PORT', 26379),
            'password' => env('REDIS_SENTINEL_PASSWORD', 'test'),
            'timeout' => 0.5,
            'read_timeout' => 0.5,
            'retry_limit' => 3,
            'retry_delay' => 100,
            'read_only_replicas' => true,
        ]);
    });

    test('full lifecycle with read write splitting', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // 1. Write operations
        $connection->set('lifecycle:test:1', 'value1');
        $connection->hset('lifecycle:test:hash', 'field1', 'hashvalue1');

        // 2. Read operations
        expect($connection->get('lifecycle:test:1'))->toBe('value1');
        expect($connection->hget('lifecycle:test:hash', 'field1'))->toBe('hashvalue1');

        // 3. Scan operations
        $keys = [];
        $cursor = 0;
        do {
            $result = $connection->scan($cursor, ['match' => 'lifecycle:test:*']);
            $cursor = $result[0];
            $keys = array_merge($keys, $result[1]);
        } while ($cursor !== 0);

        expect($keys)->toHaveCount(2);

        // 4. Pipeline
        $results = $connection->pipeline(function ($pipe) {
            $pipe->set('lifecycle:test:pipe1', 'p1');
            $pipe->set('lifecycle:test:pipe2', 'p2');
            $pipe->mget(['lifecycle:test:1', 'lifecycle:test:pipe1']);
        });

        expect($results[2])->toBe(['value1', 'p1']);

        // 5. Flush
        $count = $connection->del($keys);
        expect($count)->toBeGreaterThanOrEqual(1);

        // Cleanup all
        $connection->flushdb();
    });
});
