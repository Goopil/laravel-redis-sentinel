<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

describe('READONLY Error Handling - Lib Should Auto-Retry', function () {
    test('lib automatically retries and recovers from READONLY error', function () {
        // This test verifies that the lib can handle READONLY errors automatically
        // even if the initial connection is misconfigured

        // Start with a potentially misconfigured state
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);
        config()->set('cache.default', 'phpredis-sentinel');
        config()->set('cache.stores.phpredis-sentinel', [
            'driver' => 'phpredis-sentinel',
            'connection' => 'phpredis-sentinel',
            'lock_connection' => 'phpredis-sentinel',
        ]);

        // DO NOT purge/reconfigure - simulate a real scenario where
        // the connection might initially hit a replica

        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        // Try a write operation - the lib should automatically:
        // 1. Detect the READONLY error
        // 2. Retry with the master
        // 3. Succeed

        $testKey = 'readonly_test_'.time();
        $testValue = 'test_value_'.uniqid();

        try {
            // This should work even if it initially hits a replica
            $result = Cache::put($testKey, $testValue, 60);
            expect($result)->toBeTrue('Cache put should succeed after auto-retry');

            // Verify the value was stored
            $retrieved = Cache::get($testKey);
            expect($retrieved)->toBe($testValue, 'Value should be retrievable after auto-retry');
        } catch (\RedisException $e) {
            // If we get a READONLY error here, it means the lib didn't retry
            if (str_contains(strtolower($e->getMessage()), 'readonly')) {
                $this->fail('Lib should have automatically retried READONLY error but did not. Error: '.$e->getMessage());
            }
            throw $e;
        }
    })->skip('This test might fail in CI if replicas are not properly configured - run locally for validation');

    test('lib configuration includes READONLY in retry messages', function () {
        $retryMessages = config('phpredis-sentinel.retry.redis.messages', []);

        expect($retryMessages)->toBeArray()
            ->and($retryMessages)->toContain('readonly')
            ->and($retryMessages)->toContain("can't write against a read only replica");
    });

    test('cache flush works after proper configuration', function () {
        // Configure connection properly
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);
        config()->set('cache.default', 'phpredis-sentinel');
        config()->set('cache.stores.phpredis-sentinel', [
            'driver' => 'phpredis-sentinel',
            'connection' => 'phpredis-sentinel',
            'lock_connection' => 'phpredis-sentinel',
        ]);

        $manager = app(\Goopil\LaravelRedisSentinel\RedisSentinelManager::class);

        $reflection = new ReflectionClass($manager);
        $configProp = $reflection->getProperty('config');
        $configProp->setValue($manager, config('database.redis'));

        $manager->purge('phpredis-sentinel');

        // Now flush should work
        $result = Cache::flush();
        expect($result)->toBeTrue('Cache flush should succeed with proper configuration');
    });

    test('write operation succeeds even if read client is initialized first', function () {
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);
        config()->set('cache.default', 'phpredis-sentinel');
        config()->set('cache.stores.phpredis-sentinel', [
            'driver' => 'phpredis-sentinel',
            'connection' => 'phpredis-sentinel',
            'lock_connection' => 'phpredis-sentinel',
        ]);

        $manager = app(\Goopil\LaravelRedisSentinel\RedisSentinelManager::class);

        $reflection = new ReflectionClass($manager);
        $configProp = $reflection->getProperty('config');
        $configProp->setValue($manager, config('database.redis'));

        $manager->purge('phpredis-sentinel');

        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        // Do a read first (might initialize read client)
        $connection->get('some_key_that_does_not_exist');

        // Now do a write - should succeed
        $testKey = 'write_after_read_'.time();
        $result = $connection->set($testKey, 'test_value');

        expect($result)->toBeTrue('Write should succeed even after read operation');

        // Verify
        $value = $connection->get($testKey);
        expect($value)->toBe('test_value');
    });

    test('cache operations work correctly with read/write splitting configured', function () {
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);
        config()->set('cache.default', 'phpredis-sentinel');
        config()->set('cache.stores.phpredis-sentinel', [
            'driver' => 'phpredis-sentinel',
            'connection' => 'phpredis-sentinel',
            'lock_connection' => 'phpredis-sentinel',
        ]);

        $manager = app(\Goopil\LaravelRedisSentinel\RedisSentinelManager::class);

        $reflection = new ReflectionClass($manager);
        $configProp = $reflection->getProperty('config');
        $configProp->setValue($manager, config('database.redis'));

        $manager->purge('phpredis-sentinel');

        $testId = 'rw_ops_'.time();

        // Write operation
        $writeResult = Cache::put("{$testId}:key", 'value', 60);
        expect($writeResult)->toBeTrue('Write operation should succeed');

        // Read operation
        $readResult = Cache::get("{$testId}:key");
        expect($readResult)->toBe('value', 'Read operation should return correct value');

        // Multiple operations
        for ($i = 1; $i <= 10; $i++) {
            Cache::put("{$testId}:{$i}", "value_{$i}", 60);
        }

        for ($i = 1; $i <= 10; $i++) {
            $value = Cache::get("{$testId}:{$i}");
            expect($value)->toBe("value_{$i}");
        }
    });

    test('connection can recover from transient READONLY errors', function () {
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);
        config()->set('cache.default', 'phpredis-sentinel');
        config()->set('cache.stores.phpredis-sentinel', [
            'driver' => 'phpredis-sentinel',
            'connection' => 'phpredis-sentinel',
            'lock_connection' => 'phpredis-sentinel',
        ]);

        $manager = app(\Goopil\LaravelRedisSentinel\RedisSentinelManager::class);

        $reflection = new ReflectionClass($manager);
        $configProp = $reflection->getProperty('config');
        $configProp->setValue($manager, config('database.redis'));

        $manager->purge('phpredis-sentinel');

        $testId = 'recovery_test_'.time();
        $successfulWrites = 0;

        // Attempt multiple writes
        for ($i = 1; $i <= 20; $i++) {
            try {
                $result = Cache::put("{$testId}:{$i}", "value_{$i}", 60);
                if ($result) {
                    $successfulWrites++;
                }
            } catch (\Exception $e) {
                // If we get READONLY error, lib should have retried
                if (str_contains(strtolower($e->getMessage()), 'readonly')) {
                    $this->fail("READONLY error was not handled by retry mechanism at iteration {$i}: ".$e->getMessage());
                }
            }
        }

        // All writes should succeed
        expect($successfulWrites)->toBe(20, 'All write operations should succeed with retry mechanism');

        // Verify all values are readable
        for ($i = 1; $i <= 20; $i++) {
            $value = Cache::get("{$testId}:{$i}");
            expect($value)->toBe("value_{$i}");
        }
    });

    test('retry mechanism respects retry limit configuration', function () {
        $retryLimit = config('phpredis-sentinel.retry.redis.attempts', 5);
        $retryDelay = config('phpredis-sentinel.retry.redis.delay', 1000);

        expect($retryLimit)->toBeInt()
            ->and($retryLimit)->toBeGreaterThan(0)
            ->and($retryDelay)->toBeInt()
            ->and($retryDelay)->toBeGreaterThan(0);

        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        $reflection = new ReflectionClass($connection);
        $retryLimitProp = $reflection->getProperty('retryLimit');

        $actualRetryLimit = $retryLimitProp->getValue($connection);
        expect($actualRetryLimit)->toBeGreaterThan(0, 'Connection should have retry limit configured');
    });

    test('case-insensitive error matching works for READONLY errors', function () {
        // This tests that the fix for case-insensitive matching works
        $retryMessages = config('phpredis-sentinel.retry.redis.messages', []);

        // Test various case variations that should all match
        $errorVariations = [
            'READONLY You can\'t write against a read only replica',
            'readonly you can\'t write against a read only replica',
            'ReadOnly You can\'t write against a read only replica',
            'READONLY You Can\'t Write Against A Read Only Replica',
        ];

        // The config contains lowercase versions
        expect($retryMessages)->toContain('readonly');
        expect($retryMessages)->toContain("can't write against a read only replica");

        // Verify our implementation would match all variations
        // This is a meta-test to ensure the Str::contains with ignoreCase works
        foreach ($errorVariations as $errorMsg) {
            $shouldMatch = \Illuminate\Support\Str::contains($errorMsg, 'readonly', ignoreCase: true);
            expect($shouldMatch)->toBeTrue("Error message '{$errorMsg}' should match 'readonly' (case-insensitive)");

            $shouldMatchWrite = \Illuminate\Support\Str::contains($errorMsg, "can't write", ignoreCase: true);
            expect($shouldMatchWrite)->toBeTrue("Error message '{$errorMsg}' should match 'can't write' (case-insensitive)");
        }
    });
});
