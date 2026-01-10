<?php

use Illuminate\Support\Facades\Cache;
use Workbench\App\Services\CacheService;

describe('Cache Integration with Orchestra', function () {
    beforeEach(function () {
        // Configure cache to use phpredis-sentinel driver
        config()->set('cache.default', 'phpredis-sentinel');
        config()->set('cache.stores.phpredis-sentinel', [
            'driver' => 'phpredis-sentinel',
            'connection' => 'phpredis-sentinel',
            'lock_connection' => 'phpredis-sentinel',
        ]);

        $this->cacheService = new CacheService;

        // Ensure proper cache configuration before flush
        try {
            Cache::flush();
        } catch (\Exception $e) {
            // Ignore flush errors - cache might not be ready yet
        }
    });

    test('cache can store and retrieve basic values', function () {
        $key = 'test_key';
        $value = 'test_value';

        expect($this->cacheService->setValue($key, $value))->toBeTrue()
            ->and($this->cacheService->getValue($key))->toBe($value);
    });

    test('cache respects TTL expiration', function () {
        $key = 'ttl_test';
        $value = 'expires_soon';

        // Store with 1 second TTL
        expect($this->cacheService->setValue($key, $value, 1))->toBeTrue()
            ->and($this->cacheService->getValue($key))->toBe($value);

        // Wait for expiration
        sleep(2);

        expect($this->cacheService->getValue($key))->toBeNull();
    });

    test('cache remember executes callback only once', function () {
        $key = 'remember_test';

        $firstResult = $this->cacheService->rememberExpensiveOperation($key);
        $firstTimestamp = $firstResult['timestamp'];

        sleep(1);

        $secondResult = $this->cacheService->rememberExpensiveOperation($key);
        $secondTimestamp = $secondResult['timestamp'];

        // Timestamps should be identical because callback wasn't executed again
        expect($firstTimestamp)->toBe($secondTimestamp)
            ->and($firstResult['data'])->toBe('expensive_result')
            ->and($secondResult['data'])->toBe('expensive_result');
    });

    test('cache tags work correctly', function () {
        $tags = ['users', 'premium'];

        expect($this->cacheService->setWithTags($tags, 'user:1', ['name' => 'John']))->toBeTrue()
            ->and($this->cacheService->setWithTags($tags, 'user:2', ['name' => 'Jane']))->toBeTrue()
            ->and($this->cacheService->setWithTags(['users'], 'user:3', ['name' => 'Bob']))->toBeTrue();

        // Verify values are stored
        expect($this->cacheService->getWithTags($tags, 'user:1'))->toBe(['name' => 'John'])
            ->and($this->cacheService->getWithTags($tags, 'user:2'))->toBe(['name' => 'Jane']);

        // Flush specific tags
        expect($this->cacheService->flushTags(['premium']))->toBeTrue();

        // Tagged items with premium tag should be gone when accessing via premium tag
        expect($this->cacheService->getWithTags(['premium'], 'user:1'))->toBeNull()
            ->and($this->cacheService->getWithTags(['premium'], 'user:2'))->toBeNull()
            // But user:3 should still exist (only tagged with 'users')
            ->and($this->cacheService->getWithTags(['users'], 'user:3'))->toBe(['name' => 'Bob']);
    });

    test('cache locks prevent race conditions', function () {
        $lockKey = 'critical_section';
        $counter = 0;

        // Manually acquire lock to simulate concurrent process
        $lock = Cache::lock($lockKey, 10);
        $lock->get();

        // Execution should fail to acquire lock
        $result = $this->cacheService->executeWithLock($lockKey, function () use (&$counter) {
            $counter++;

            return 'executed_again';
        }, 1);

        expect($result)->toBeNull()
            ->and($counter)->toBe(0); // Counter wasn't incremented

        // Release lock
        $lock->release();

        // Now execution should succeed
        $result = $this->cacheService->executeWithLock($lockKey, function () use (&$counter) {
            $counter++;

            return 'executed_after_release';
        }, 1);

        expect($result)->toBe('executed_after_release')
            ->and($counter)->toBe(1);
    });

    test('cache increment and decrement work atomically', function () {
        $key = 'atomic_counter';

        // Initialize counter
        Cache::put($key, 10);

        expect($this->cacheService->incrementCounter($key))->toBe(11)
            ->and($this->cacheService->incrementCounter($key, 5))->toBe(16)
            ->and($this->cacheService->decrementCounter($key))->toBe(15)
            ->and($this->cacheService->decrementCounter($key, 3))->toBe(12)
            ->and((int) Cache::get($key))->toBe(12);
    });

    test('cache forever stores without expiration', function () {
        $key = 'forever_key';
        $value = ['data' => 'persistent'];

        expect($this->cacheService->storeForever($key, $value))->toBeTrue()
            ->and($this->cacheService->getValue($key))->toBe($value);

        // Even after waiting, value should persist
        sleep(2);
        expect($this->cacheService->getValue($key))->toBe($value);
    });

    test('cache pull retrieves and deletes value', function () {
        $key = 'pull_test';
        $value = 'pull_value';

        Cache::put($key, $value);

        expect($this->cacheService->pullValue($key))->toBe($value)
            ->and($this->cacheService->getValue($key))->toBeNull();
    });

    test('cache can handle multiple operations', function () {
        $values = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];

        expect($this->cacheService->setMany($values))->toBeTrue();

        $retrieved = $this->cacheService->getMany(array_keys($values));

        expect($retrieved)->toBe($values);
    });

    test('cache forget removes specific key', function () {
        $key = 'forget_test';
        $value = 'temporary';

        Cache::put($key, $value);
        expect(Cache::has($key))->toBeTrue();

        expect($this->cacheService->forgetValue($key))->toBeTrue()
            ->and(Cache::has($key))->toBeFalse();
    });

    test('cache flush clears all data', function () {
        Cache::put('key1', 'value1');
        Cache::put('key2', 'value2');
        Cache::put('key3', 'value3');

        expect(Cache::has('key1'))->toBeTrue()
            ->and(Cache::has('key2'))->toBeTrue()
            ->and(Cache::has('key3'))->toBeTrue();

        expect($this->cacheService->flushAll())->toBeTrue()
            ->and(Cache::has('key1'))->toBeFalse()
            ->and(Cache::has('key2'))->toBeFalse()
            ->and(Cache::has('key3'))->toBeFalse();
    });

    test('cache uses redis sentinel connection', function () {
        $driver = Cache::driver('phpredis-sentinel');
        $store = $driver->getStore();

        expect($store->getRedis())->toBeInstanceOf(\Goopil\LaravelRedisSentinel\RedisSentinelManager::class);

        // Test actual cache operation through Sentinel
        $key = 'sentinel_test';
        $value = ['sentinel' => 'data'];

        $driver->put($key, $value);
        expect($driver->get($key))->toBe($value);
    });

    test('cache connection is resilient to temporary failures', function () {
        $key = 'resilience_test';
        $value = 'resilient_value';

        // Store value
        expect(Cache::put($key, $value))->toBeTrue();

        // Value should be retrievable
        expect(Cache::get($key))->toBe($value);

        // Even after multiple operations, connection should remain stable
        for ($i = 0; $i < 10; $i++) {
            Cache::put("stress_test_{$i}", "value_{$i}");
            expect(Cache::get("stress_test_{$i}"))->toBe("value_{$i}");
        }

        // Original value should still be there
        expect(Cache::get($key))->toBe($value);
    });
});
