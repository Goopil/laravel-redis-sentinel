<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

describe('Cache E2E Tests WITHOUT Read/Write Splitting - Master Only', function () {
    beforeEach(function () {
        // Configure WITHOUT read/write splitting (master only mode) BEFORE flush
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', false);
        config()->set('cache.default', 'phpredis-sentinel');
        config()->set('cache.stores.phpredis-sentinel', [
            'driver' => 'phpredis-sentinel',
            'connection' => 'phpredis-sentinel',
            'lock_connection' => 'phpredis-sentinel',
        ]);

        // Purge connections to ensure fresh config
        $manager = app(\Goopil\LaravelRedisSentinel\RedisSentinelManager::class);

        $reflection = new ReflectionClass($manager);
        $configProp = $reflection->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($manager, config('database.redis'));

        $manager->purge('phpredis-sentinel');

        // Now safe to flush
        try {
            Cache::flush();
        } catch (\Exception $e) {
            // Ignore flush errors in setup
        }
    });

    test('cache operations in master-only mode', function () {
        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        $reflection = new ReflectionClass($connection);
        $readConnectorProp = $reflection->getProperty('readConnector');
        $readConnectorProp->setAccessible(true);

        // No read connector in master-only mode
        expect($readConnectorProp->getValue($connection))->toBeNull('No read connector in master-only mode');

        // All operations go to master
        Cache::get('test_key');
        Cache::put('test_key', 'test_value', 60);

        $wroteToMasterProp = $reflection->getProperty('wroteToMaster');
        $wroteToMasterProp->setAccessible(true);
        expect($wroteToMasterProp->getValue($connection))->toBeTrue('All operations use master');
    });

    test('cache stores and retrieves in master-only mode', function () {
        $testId = 'cache_master_'.time();

        // Store data
        for ($i = 1; $i <= 40; $i++) {
            Cache::put("{$testId}:{$i}", [
                'user_id' => $i,
                'data' => "content_{$i}",
                'timestamp' => now()->timestamp,
            ], 3600);
        }

        // Retrieve and verify
        for ($i = 1; $i <= 40; $i++) {
            $value = Cache::get("{$testId}:{$i}");
            expect($value)->toBeArray()
                ->and($value['user_id'])->toBe($i)
                ->and($value['data'])->toBe("content_{$i}");
        }
    });

    test('cache high volume in master-only mode', function () {
        $testId = 'cache_master_volume_'.time();
        $itemCount = 250;

        $startTime = microtime(true);

        // Write many items
        for ($i = 1; $i <= $itemCount; $i++) {
            Cache::put("{$testId}:{$i}", [
                'id' => $i,
                'data' => str_repeat('x', 50),
            ], 3600);
        }

        $writeDuration = microtime(true) - $startTime;

        // Read all items
        $readStart = microtime(true);
        $hits = 0;

        for ($i = 1; $i <= $itemCount; $i++) {
            if (Cache::get("{$testId}:{$i}") !== null) {
                $hits++;
            }
        }

        $readDuration = microtime(true) - $readStart;

        expect($hits)->toBe($itemCount)
            ->and($writeDuration)->toBeLessThan(15)
            ->and($readDuration)->toBeLessThan(10);
    });

    test('cache survives connection reset in master-only mode', function () {
        $testId = 'cache_master_reset_'.time();
        $connection = Redis::connection('phpredis-sentinel');

        // Store data
        for ($i = 1; $i <= 20; $i++) {
            Cache::put("{$testId}:before:{$i}", "value_{$i}", 3600);
        }

        // Verify
        for ($i = 1; $i <= 20; $i++) {
            expect(Cache::get("{$testId}:before:{$i}"))->toBe("value_{$i}");
        }

        // Disconnect
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Verify persistence
        for ($i = 1; $i <= 20; $i++) {
            expect(Cache::get("{$testId}:before:{$i}"))->toBe("value_{$i}");
        }

        // Store new data
        for ($i = 1; $i <= 20; $i++) {
            Cache::put("{$testId}:after:{$i}", "new_{$i}", 3600);
        }

        // Verify new data
        for ($i = 1; $i <= 20; $i++) {
            expect(Cache::get("{$testId}:after:{$i}"))->toBe("new_{$i}");
        }
    });

    test('cache handles failover in master-only mode', function () {
        $testId = 'cache_master_failover_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $totalItems = 120;
        $successWrites = 0;
        $successReads = 0;

        // Write with failover
        for ($i = 1; $i <= $totalItems; $i++) {
            try {
                if ($i === 60) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    sleep(2);
                }

                Cache::put("{$testId}:{$i}", ['value' => "data_{$i}"], 3600);
                $successWrites++;
            } catch (\Exception $e) {
                // Expected during failover
            }
        }

        sleep(1);

        // Read after failover
        for ($i = 1; $i <= $totalItems; $i++) {
            if (Cache::get("{$testId}:{$i}") !== null) {
                $successReads++;
            }
        }

        expect($successWrites)->toBeGreaterThan(110)
            ->and($successReads)->toBeGreaterThan(110);
    });

    test('cache remember in master-only mode', function () {
        $testId = 'cache_remember_'.time();
        $key = "{$testId}:computation";
        $execCount = 0;

        // First call
        $result1 = Cache::remember($key, 3600, function () use (&$execCount) {
            $execCount++;

            return ['computed' => true, 'time' => now()->timestamp];
        });

        expect($execCount)->toBe(1);

        // Second call - should use cache
        $result2 = Cache::remember($key, 3600, function () use (&$execCount) {
            $execCount++;

            return ['computed' => true, 'time' => now()->timestamp];
        });

        expect($execCount)->toBe(1)
            ->and($result2['time'])->toBe($result1['time']);

        // Failover
        $connection = Redis::connection('phpredis-sentinel');
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Third call - should still use cache
        $result3 = Cache::remember($key, 3600, function () use (&$execCount) {
            $execCount++;

            return ['computed' => true, 'time' => now()->timestamp];
        });

        expect($execCount)->toBe(1)
            ->and($result3['time'])->toBe($result1['time']);
    });

    test('cache tags in master-only mode', function () {
        $testId = 'cache_tags_'.time();

        // Store tagged data
        Cache::tags(['users'])->put("{$testId}:user:1", ['name' => 'Alice'], 3600);
        Cache::tags(['users'])->put("{$testId}:user:2", ['name' => 'Bob'], 3600);
        Cache::tags(['users', 'premium'])->put("{$testId}:user:3", ['name' => 'Charlie'], 3600);

        // Verify
        expect(Cache::tags(['users'])->get("{$testId}:user:1"))->toBe(['name' => 'Alice'])
            ->and(Cache::tags(['users'])->get("{$testId}:user:2"))->toBe(['name' => 'Bob'])
            ->and(Cache::tags(['users', 'premium'])->get("{$testId}:user:3"))->toBe(['name' => 'Charlie']);

        // Failover
        $connection = Redis::connection('phpredis-sentinel');
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Verify after failover
        expect(Cache::tags(['users'])->get("{$testId}:user:1"))->toBe(['name' => 'Alice']);

        // Flush specific tag
        Cache::tags(['premium'])->flush();

        expect(Cache::tags(['premium'])->get("{$testId}:user:3"))->toBeNull()
            ->and(Cache::tags(['users'])->get("{$testId}:user:1"))->toBe(['name' => 'Alice']);
    });

    test('cache atomic operations in master-only mode', function () {
        $testId = 'cache_atomic_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $counterKey = "{$testId}:counter";

        Cache::put($counterKey, 0, 3600);

        // Increment with failover
        $expected = 0;
        for ($i = 1; $i <= 60; $i++) {
            try {
                if ($i === 30) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    sleep(1);
                }

                Cache::increment($counterKey);
                $expected++;
            } catch (\Exception $e) {
                usleep(100000);
                try {
                    Cache::increment($counterKey);
                    $expected++;
                } catch (\Exception $e2) {
                    // Failed retry
                }
            }
        }

        $finalValue = (int) Cache::get($counterKey);
        expect($finalValue)->toBeGreaterThan(55);
    });

    test('cache locks in master-only mode', function () {
        $testId = 'cache_lock_'.time();
        $lockKey = "{$testId}:lock";
        $counter = 0;

        // Acquire lock
        $lock = Cache::lock($lockKey, 10);
        expect($lock->get())->toBeTrue();

        $counter++;

        // Failover while locked
        $connection = Redis::connection('phpredis-sentinel');
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Release after failover
        $lock->release();
        expect($counter)->toBe(1);

        // New lock after failover
        $newLock = Cache::lock($lockKey, 10);
        expect($newLock->get())->toBeTrue();
        $newLock->release();
    });

    test('cache many operations in master-only mode', function () {
        $testId = 'cache_many_'.time();

        $data = [
            "{$testId}:a" => 'value_a',
            "{$testId}:b" => 'value_b',
            "{$testId}:c" => 'value_c',
            "{$testId}:d" => 'value_d',
            "{$testId}:e" => 'value_e',
        ];

        // Store many
        Cache::putMany($data, 3600);

        // Failover
        $connection = Redis::connection('phpredis-sentinel');
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Retrieve many
        $retrieved = Cache::many(array_keys($data));
        expect($retrieved)->toBe($data);
    });

    test('cache TTL in master-only mode', function () {
        $testId = 'cache_ttl_'.time();

        Cache::put("{$testId}:short", 'expires', 2);
        Cache::put("{$testId}:long", 'persists', 3600);

        expect(Cache::has("{$testId}:short"))->toBeTrue()
            ->and(Cache::has("{$testId}:long"))->toBeTrue();

        // Failover
        $connection = Redis::connection('phpredis-sentinel');
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);
        sleep(2);

        expect(Cache::has("{$testId}:short"))->toBeFalse()
            ->and(Cache::has("{$testId}:long"))->toBeTrue();
    });

    test('cache concurrent operations in master-only mode', function () {
        $testId = 'cache_concurrent_'.time();
        $connection = Redis::connection('phpredis-sentinel');
        $ops = 120;

        for ($i = 1; $i <= $ops; $i++) {
            try {
                if ($i % 30 === 0) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    usleep(500000);
                }

                // Mixed operations
                if ($i % 3 === 0) {
                    Cache::increment("{$testId}:counter");
                } elseif ($i % 3 === 1) {
                    Cache::put("{$testId}:item:{$i}", "val_{$i}", 3600);
                } else {
                    Cache::get("{$testId}:item:".($i - 1));
                }
            } catch (\Exception $e) {
                // Expected during failover
            }
        }

        expect($connection->ping())->toBeTrue();
        expect(Cache::get("{$testId}:counter"))->toBeGreaterThan(0);
    });

    test('cache forever in master-only mode', function () {
        $testId = 'cache_forever_'.time();

        Cache::forever("{$testId}:persistent", ['critical' => 'data']);

        expect(Cache::get("{$testId}:persistent"))->toBe(['critical' => 'data']);

        // Multiple failovers
        $connection = Redis::connection('phpredis-sentinel');
        for ($i = 1; $i <= 3; $i++) {
            try {
                $connection->disconnect();
            } catch (\Exception $e) {
                // Expected
            }
            sleep(1);

            expect(Cache::get("{$testId}:persistent"))->toBe(['critical' => 'data']);
        }
    });

    test('cache pull in master-only mode', function () {
        $testId = 'cache_pull_'.time();

        Cache::put("{$testId}:pulltest", 'pull_value', 3600);
        expect(Cache::has("{$testId}:pulltest"))->toBeTrue();

        // Failover
        $connection = Redis::connection('phpredis-sentinel');
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Pull
        $value = Cache::pull("{$testId}:pulltest");
        expect($value)->toBe('pull_value')
            ->and(Cache::has("{$testId}:pulltest"))->toBeFalse();
    });
});
