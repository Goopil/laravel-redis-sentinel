<?php

namespace Workbench\App\Services;

use Illuminate\Support\Facades\Cache;

class CacheService
{
    /**
     * Simple cache set/get operation
     */
    public function setValue(string $key, mixed $value, ?int $ttl = null): bool
    {
        return Cache::put($key, $value, $ttl);
    }

    /**
     * Get a value from cache
     */
    public function getValue(string $key): mixed
    {
        return Cache::get($key);
    }

    /**
     * Test cache with remember functionality
     */
    public function rememberExpensiveOperation(string $key, int $ttl = 3600): array
    {
        return Cache::remember($key, $ttl, static function () {
            // Simulate expensive operation
            return [
                'data' => 'expensive_result',
                'timestamp' => now()->timestamp,
            ];
        });
    }

    /**
     * Test cache tags functionality
     */
    public function setWithTags(array $tags, string $key, mixed $value): bool
    {
        return Cache::tags($tags)->put($key, $value);
    }

    /**
     * Get value with tags
     */
    public function getWithTags(array $tags, string $key): mixed
    {
        return Cache::tags($tags)->get($key);
    }

    /**
     * Flush specific tags
     */
    public function flushTags(array $tags): bool
    {
        return Cache::tags($tags)->flush();
    }

    /**
     * Test atomic lock functionality to prevent race conditions
     */
    public function executeWithLock(string $lockKey, callable $callback, int $seconds = 10): mixed
    {
        $lock = Cache::lock($lockKey, $seconds);

        if ($lock->get()) {
            try {
                return $callback();
            } finally {
                $lock->release();
            }
        }

        return null;
    }

    /**
     * Test increment/decrement operations
     */
    public function incrementCounter(string $key, int $value = 1): int|false
    {
        return Cache::increment($key, $value);
    }

    public function decrementCounter(string $key, int $value = 1): int|false
    {
        return Cache::decrement($key, $value);
    }

    /**
     * Test cache forever (no TTL)
     */
    public function storeForever(string $key, mixed $value): bool
    {
        return Cache::forever($key, $value);
    }

    /**
     * Test cache pull (get and delete)
     */
    public function pullValue(string $key): mixed
    {
        return Cache::pull($key);
    }

    /**
     * Test multiple cache operations
     */
    public function setMany(array $values, ?int $ttl = null): bool
    {
        return Cache::putMany($values, $ttl);
    }

    public function getMany(array $keys): array
    {
        return Cache::many($keys);
    }

    /**
     * Test cache forget
     */
    public function forgetValue(string $key): bool
    {
        return Cache::forget($key);
    }

    /**
     * Test cache flush
     */
    public function flushAll(): bool
    {
        return Cache::flush();
    }
}
