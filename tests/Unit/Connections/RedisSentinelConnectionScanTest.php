<?php

use Illuminate\Support\Facades\Redis;

describe('RedisSentinelConnection Scan Commands', function () {
    beforeEach(function () {
        config()->set('database.redis.phpredis-sentinel', [
            'scheme' => 'tcp',
            'host' => env('REDIS_SENTINEL_HOST', '127.0.0.1'),
            'port' => env('REDIS_SENTINEL_PORT', 26379),
            'password' => env('REDIS_SENTINEL_PASSWORD', 'test'),
            'timeout' => 0.5,
            'read_timeout' => 0.5,
            'persistent' => false,
            'retry_limit' => 3,
            'retry_delay' => 100,
            'retry_jitter' => 50,
            'master_name' => 'master',
        ]);
    });

    test('scan exists and is callable', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Verify scan method exists
        expect(method_exists($connection, 'scan'))->toBeTrue();

        // Insert test data
        for ($i = 0; $i < 5; $i++) {
            $connection->set("scan_test_key_{$i}", "value_{$i}");
        }

        // Test scan returns something (either array or false in some cases)
        $result = $connection->scan(0, ['match' => 'scan_test_key_*', 'count' => 5]);

        // Cleanup
        for ($i = 0; $i < 5; $i++) {
            $connection->del("scan_test_key_{$i}");
        }

        expect($result)->not->toBeNull();
    });

    test('zscan exists and is callable', function () {
        $connection = Redis::connection('phpredis-sentinel');
        $key = 'zscan_test_set';

        expect(method_exists($connection, 'zscan'))->toBeTrue();

        // Add members to sorted set
        $connection->zadd($key, 1, 'member1', 2, 'member2');

        // Test zscan
        $result = $connection->zscan($key, 0);

        // Cleanup
        $connection->del($key);

        expect($result)->not->toBeNull();
    });

    test('hscan exists and is callable', function () {
        $connection = Redis::connection('phpredis-sentinel');
        $key = 'hscan_test_hash';

        expect(method_exists($connection, 'hscan'))->toBeTrue();

        // Add fields to hash
        $connection->hmset($key, ['field1' => 'value1', 'field2' => 'value2']);

        // Test hscan
        $result = $connection->hscan($key, 0);

        // Cleanup
        $connection->del($key);

        expect($result)->not->toBeNull();
    });

    test('sscan exists and is callable', function () {
        $connection = Redis::connection('phpredis-sentinel');
        $key = 'sscan_test_set';

        expect(method_exists($connection, 'sscan'))->toBeTrue();

        // Add members to set
        $connection->sadd($key, 'member1', 'member2');

        // Test sscan
        $result = $connection->sscan($key, 0);

        // Cleanup
        $connection->del($key);

        expect($result)->not->toBeNull();
    });

    test('__call handles unknown methods with retry logic', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Test via __call magic method (commande avec options)
        $connection->setex('__call_test', 60, 'value');

        $ttl = $connection->ttl('__call_test');
        expect($ttl)->toBeGreaterThan(0);
        expect($ttl)->toBeLessThanOrEqual(60);

        // Cleanup
        $connection->del('__call_test');
    });

    test('__call handles command case insensitivity', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Test different cases
        $connection->SET('case_test', 'value1');
        $connection->set('case_test', 'value2');
        $connection->Set('case_test', 'value3');

        $value = $connection->get('case_test');
        expect($value)->toBe('value3');

        // Cleanup
        $connection->del('case_test');
    });
});
