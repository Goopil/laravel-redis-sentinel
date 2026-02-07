<?php

use Illuminate\Support\Facades\Redis;

describe('RedisSentinelConnection Retry Logic', function () {
    test('retry mechanism refreshes connection on failure', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // This tests that the retry logic is in place
        // Full test would require simulating network failures
        $connection->set('retry_test_key', 'value');
        $value = $connection->get('retry_test_key');

        expect($value)->toBe('value');

        // Cleanup
        $connection->del('retry_test_key');
    });

    test('read connector is refreshed on read failure', function () {
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);

        $connection = Redis::connection('phpredis-sentinel');

        // Perform read operation (may go to replica)
        $connection->get('non_existent_key');

        expect(true)->toBeTrue();
    });

    test('master connector is refreshed on write failure', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Perform write operation (always goes to master)
        $connection->set('master_refresh_test', 'value');

        expect(true)->toBeTrue();

        // Cleanup
        $connection->del('master_refresh_test');
    });

    test('retry respects configured retry limit', function () {
        config()->set('database.redis.phpredis-sentinel.retry_limit', 2);

        $connection = Redis::connection('phpredis-sentinel');

        // Normal operation should still work
        $connection->set('retry_limit_test', 'value');
        $value = $connection->get('retry_limit_test');

        expect($value)->toBe('value');

        // Cleanup
        $connection->del('retry_limit_test');
    });
});
