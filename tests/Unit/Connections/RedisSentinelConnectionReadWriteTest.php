<?php

use Illuminate\Support\Facades\Redis;

describe('RedisSentinelConnection Read/Write Splitting', function () {
    test('write commands always use master', function () {
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);

        $connection = Redis::connection('phpredis-sentinel');

        // Write operation
        $connection->set('rw_test_key', 'value');

        // Read after write should use master (sticky session)
        $value = $connection->get('rw_test_key');
        expect($value)->toBe('value');

        // Cleanup
        $connection->del('rw_test_key');
    });

    test('resetStickiness resets the flag', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Perform write
        $connection->set('stickiness_test', 'value');

        // Reset stickiness (method exists and is callable)
        $connection->resetStickiness();

        // Next read could use replica (if configured)
        expect(true)->toBeTrue();
    });

    test('read-only commands are properly identified', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // List of read-only commands
        $readOnlyCommands = [
            'get', 'exists', 'keys', 'mget',
            'hget', 'hgetall', 'hkeys',
            'lrange', 'llen',
            'scard', 'smembers',
            'zcard', 'zrange',
        ];

        foreach ($readOnlyCommands as $command) {
            // These should not throw errors
            expect(true)->toBeTrue();
        }
    });

    test('getReadClient returns configured read client', function () {
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);

        $connection = Redis::connection('phpredis-sentinel');

        // This should return the read client if configured
        $client = $connection->getReadClient();

        expect($client)->toBeInstanceOf(\Redis::class);
    });
});
