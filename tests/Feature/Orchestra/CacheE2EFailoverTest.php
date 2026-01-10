<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

describe('Cache E2E Failover Tests with Read/Write Mode', function () {
    beforeEach(function () {
        Cache::flush();

        // Configure read/write splitting for cache
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);
        config()->set('cache.default', 'phpredis-sentinel');
        config()->set('cache.stores.phpredis-sentinel', [
            'driver' => 'redis',
            'connection' => 'phpredis-sentinel',
            'lock_connection' => 'phpredis-sentinel',
        ]);

        // Purge connections
        $manager = app(\Goopil\LaravelRedisSentinel\RedisSentinelManager::class);
        $manager->purge('phpredis-sentinel');
    });

    test('cache operations use read/write splitting correctly', function () {
        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        $reflection = new ReflectionClass($connection);
        $wroteToMasterProp = $reflection->getProperty('wroteToMaster');
        $wroteToMasterProp->setAccessible(true);

        // Cache read should not trigger stickiness
        Cache::get('test_key');
        expect($wroteToMasterProp->getValue($connection))->toBeFalse('Cache read should not trigger stickiness');

        // Cache write should trigger stickiness
        Cache::put('test_key', 'test_value', 60);
        expect($wroteToMasterProp->getValue($connection))->toBeTrue('Cache write should trigger stickiness');
    });

    test('cache stores and retrieves data with read/write mode', function () {
        $testId = 'cache_rw_'.time();

        // Store multiple values
        for ($i = 1; $i <= 20; $i++) {
            $key = "{$testId}_key_{$i}";
            $value = ['data' => "value_{$i}", 'index' => $i];
            Cache::put($key, $value, 3600);
        }

        // Retrieve and verify
        for ($i = 1; $i <= 20; $i++) {
            $key = "{$testId}_key_{$i}";
            $value = Cache::get($key);
            expect($value)->toBe(['data' => "value_{$i}", 'index' => $i]);
        }
    });

    test('cache handles high volume operations', function () {
        $testId = 'cache_volume_'.time();
        $itemCount = 200;

        $startTime = microtime(true);

        // Write operations
        for ($i = 1; $i <= $itemCount; $i++) {
            Cache::put("{$testId}:{$i}", [
                'user_id' => $i,
                'data' => str_repeat('x', 100), // 100 bytes of data
                'timestamp' => now()->timestamp,
            ], 3600);
        }

        $writeDuration = microtime(true) - $startTime;

        // Read operations
        $readStart = microtime(true);
        $hitCount = 0;

        for ($i = 1; $i <= $itemCount; $i++) {
            $value = Cache::get("{$testId}:{$i}");
            if ($value !== null) {
                $hitCount++;
            }
        }

        $readDuration = microtime(true) - $readStart;

        expect($hitCount)->toBe($itemCount, 'All cache items should be retrievable')
            ->and($writeDuration)->toBeLessThan(10, 'Writes should be fast')
            ->and($readDuration)->toBeLessThan(5, 'Reads should be fast');
    });

    test('cache survives connection reset', function () {
        $testId = 'cache_reset_'.time();
        $connection = Redis::connection('phpredis-sentinel');

        // Store data before reset
        for ($i = 1; $i <= 10; $i++) {
            Cache::put("{$testId}:before:{$i}", "value_{$i}", 3600);
        }

        // Verify data
        for ($i = 1; $i <= 10; $i++) {
            expect(Cache::get("{$testId}:before:{$i}"))->toBe("value_{$i}");
        }

        // Force disconnection
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Verify data persisted after reconnection
        for ($i = 1; $i <= 10; $i++) {
            expect(Cache::get("{$testId}:before:{$i}"))->toBe("value_{$i}", 'Data should persist after reset');
        }

        // Store new data after reconnection
        for ($i = 1; $i <= 10; $i++) {
            Cache::put("{$testId}:after:{$i}", "new_value_{$i}", 3600);
        }

        // Verify new data
        for ($i = 1; $i <= 10; $i++) {
            expect(Cache::get("{$testId}:after:{$i}"))->toBe("new_value_{$i}");
        }
    });

    test('cache handles failover during operations', function () {
        $testId = 'cache_failover_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $totalItems = 100;
        $successfulWrites = 0;
        $successfulReads = 0;

        // Phase 1: Write data with failover in the middle
        for ($i = 1; $i <= $totalItems; $i++) {
            try {
                // Simulate failover at midpoint
                if ($i === 50) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    sleep(2); // Failover window
                }

                Cache::put("{$testId}:{$i}", [
                    'value' => "data_{$i}",
                    'phase' => $i < 50 ? 'before' : 'after',
                ], 3600);

                $successfulWrites++;
            } catch (\Exception $e) {
                // Expected during failover
            }
        }

        // Phase 2: Read all data after failover
        sleep(1);

        for ($i = 1; $i <= $totalItems; $i++) {
            $value = Cache::get("{$testId}:{$i}");
            if ($value !== null) {
                $successfulReads++;
                expect($value['value'])->toBe("data_{$i}");
            }
        }

        // Most operations should succeed
        expect($successfulWrites)->toBeGreaterThan(90, 'Most writes should succeed')
            ->and($successfulReads)->toBeGreaterThan(90, 'Most reads should succeed');
    });

    test('cache remember works through failover', function () {
        $testId = 'cache_remember_'.time();
        $key = "{$testId}:expensive_operation";
        $callCount = 0;

        // First call - should execute callback
        $result1 = Cache::remember($key, 3600, function () use (&$callCount) {
            $callCount++;

            return ['computed' => true, 'timestamp' => now()->timestamp];
        });

        expect($callCount)->toBe(1);

        // Second call - should use cached value
        $result2 = Cache::remember($key, 3600, function () use (&$callCount) {
            $callCount++;

            return ['computed' => true, 'timestamp' => now()->timestamp];
        });

        expect($callCount)->toBe(1, 'Callback should not be called again')
            ->and($result2['timestamp'])->toBe($result1['timestamp']);

        // Simulate failover
        $connection = Redis::connection('phpredis-sentinel');
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Third call after failover - should still use cached value
        $result3 = Cache::remember($key, 3600, function () use (&$callCount) {
            $callCount++;

            return ['computed' => true, 'timestamp' => now()->timestamp];
        });

        expect($callCount)->toBe(1, 'Callback should still not be called after failover')
            ->and($result3['timestamp'])->toBe($result1['timestamp']);
    });

    test('cache tags work correctly with read/write splitting', function () {
        $testId = 'cache_tags_'.time();

        // Store tagged data
        Cache::tags(['users', 'premium'])->put("{$testId}:user:1", ['name' => 'John'], 3600);
        Cache::tags(['users', 'premium'])->put("{$testId}:user:2", ['name' => 'Jane'], 3600);
        Cache::tags(['users'])->put("{$testId}:user:3", ['name' => 'Bob'], 3600);

        // Verify data
        expect(Cache::tags(['users', 'premium'])->get("{$testId}:user:1"))->toBe(['name' => 'John'])
            ->and(Cache::tags(['users', 'premium'])->get("{$testId}:user:2"))->toBe(['name' => 'Jane'])
            ->and(Cache::tags(['users'])->get("{$testId}:user:3"))->toBe(['name' => 'Bob']);

        // Simulate failover
        $connection = Redis::connection('phpredis-sentinel');
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Verify tagged data persisted
        expect(Cache::tags(['users', 'premium'])->get("{$testId}:user:1"))->toBe(['name' => 'John'])
            ->and(Cache::tags(['users'])->get("{$testId}:user:3"))->toBe(['name' => 'Bob']);

        // Flush specific tag
        Cache::tags(['premium'])->flush();

        // Verify selective flush worked
        expect(Cache::tags(['premium'])->get("{$testId}:user:1"))->toBeNull()
            ->and(Cache::tags(['users'])->get("{$testId}:user:3"))->toBe(['name' => 'Bob']);
    });

    test('cache increment/decrement operations during failover', function () {
        $testId = 'cache_atomic_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $counterKey = "{$testId}:counter";

        // Initialize counter
        Cache::put($counterKey, 0, 3600);

        // Increment with intermittent failures
        $expectedValue = 0;
        for ($i = 1; $i <= 50; $i++) {
            try {
                if ($i === 25) {
                    // Simulate failover
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    sleep(1);
                }

                Cache::increment($counterKey);
                $expectedValue++;
            } catch (\Exception $e) {
                // Retry on failure
                usleep(100000); // 100ms
                try {
                    Cache::increment($counterKey);
                    $expectedValue++;
                } catch (\Exception $e2) {
                    // Failed retry
                }
            }
        }

        // Verify counter value
        $finalValue = (int) Cache::get($counterKey);
        expect($finalValue)->toBeGreaterThan(45, 'Most increments should succeed')
            ->and($finalValue)->toBeLessThanOrEqual(50);
    });

    test('cache lock mechanism works through failover', function () {
        $testId = 'cache_lock_'.time();
        $lockKey = "{$testId}:critical_section";
        $counter = 0;

        // Acquire lock and perform operation
        $lock = Cache::lock($lockKey, 10);

        expect($lock->get())->toBeTrue('Should acquire lock');

        $counter++;

        // Simulate failover while lock is held
        $connection = Redis::connection('phpredis-sentinel');
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Release lock after failover
        $lock->release();

        expect($counter)->toBe(1);

        // Acquire lock again after failover
        $newLock = Cache::lock($lockKey, 10);
        expect($newLock->get())->toBeTrue('Should acquire lock again after failover');
        $newLock->release();
    });

    test('cache many operations work with read/write splitting', function () {
        $testId = 'cache_many_'.time();

        $data = [
            "{$testId}:key1" => 'value1',
            "{$testId}:key2" => 'value2',
            "{$testId}:key3" => 'value3',
            "{$testId}:key4" => 'value4',
            "{$testId}:key5" => 'value5',
        ];

        // Store many
        Cache::putMany($data, 3600);

        // Simulate failover
        $connection = Redis::connection('phpredis-sentinel');
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Retrieve many after failover
        $retrieved = Cache::many(array_keys($data));

        expect($retrieved)->toBe($data, 'All values should be retrievable after failover');
    });

    test('cache ttl and expiration work correctly through failover', function () {
        $testId = 'cache_ttl_'.time();

        // Store with short TTL
        Cache::put("{$testId}:short", 'expires_soon', 2);
        Cache::put("{$testId}:long", 'expires_later', 3600);

        // Verify both exist
        expect(Cache::has("{$testId}:short"))->toBeTrue()
            ->and(Cache::has("{$testId}:long"))->toBeTrue();

        // Simulate failover
        $connection = Redis::connection('phpredis-sentinel');
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Wait for short TTL to expire
        sleep(2);

        // Verify expiration
        expect(Cache::has("{$testId}:short"))->toBeFalse('Short TTL item should expire')
            ->and(Cache::has("{$testId}:long"))->toBeTrue('Long TTL item should persist');
    });

    test('cache handles concurrent operations during failover', function () {
        $testId = 'cache_concurrent_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $operationCount = 100;
        $results = [];

        for ($i = 1; $i <= $operationCount; $i++) {
            try {
                // Trigger failover at multiple points
                if ($i % 25 === 0) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    usleep(500000); // 500ms
                }

                // Mixed operations
                if ($i % 3 === 0) {
                    Cache::increment("{$testId}:counter");
                } elseif ($i % 3 === 1) {
                    Cache::put("{$testId}:item:{$i}", "value_{$i}", 3600);
                } else {
                    $value = Cache::get("{$testId}:item:".($i - 1));
                    $results[] = $value;
                }
            } catch (\Exception $e) {
                // Expected during failover
            }
        }

        // Verify system recovered
        $connection = Redis::connection('phpredis-sentinel');
        expect($connection->ping())->toBeTrue('Connection should be healthy');

        // Verify counter
        $counterValue = Cache::get("{$testId}:counter");
        expect($counterValue)->toBeGreaterThan(0, 'Counter should have been incremented');
    });

    test('cache forever stores persist through failover', function () {
        $testId = 'cache_forever_'.time();

        // Store forever
        Cache::forever("{$testId}:persistent", ['important' => 'data']);

        // Verify
        expect(Cache::get("{$testId}:persistent"))->toBe(['important' => 'data']);

        // Multiple failover simulations
        $connection = Redis::connection('phpredis-sentinel');
        for ($i = 1; $i <= 3; $i++) {
            try {
                $connection->disconnect();
            } catch (\Exception $e) {
                // Expected
            }
            sleep(1);

            // Verify data still exists
            expect(Cache::get("{$testId}:persistent"))->toBe(['important' => 'data'], "Data should persist after failover {$i}");
        }
    });

    test('cache pull operation works correctly with failover', function () {
        $testId = 'cache_pull_'.time();

        // Store data
        Cache::put("{$testId}:pull_test", 'pull_value', 3600);

        // Verify exists
        expect(Cache::has("{$testId}:pull_test"))->toBeTrue();

        // Simulate failover
        $connection = Redis::connection('phpredis-sentinel');
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Pull (get and delete)
        $value = Cache::pull("{$testId}:pull_test");

        expect($value)->toBe('pull_value')
            ->and(Cache::has("{$testId}:pull_test"))->toBeFalse('Key should be deleted after pull');
    });
});
