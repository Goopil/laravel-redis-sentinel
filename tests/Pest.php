<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\Context\ConnectionState;
use Goopil\LaravelRedisSentinel\Context\ExecutionContext;
use Goopil\LaravelRedisSentinel\Tests\Support\Toxiproxy\InteractsWithToxiproxy;
use Goopil\LaravelRedisSentinel\Tests\TestCase;
use Illuminate\Redis\Connections\PhpRedisConnection;

uses(TestCase::class)->in(__DIR__);

// Pest closure test files accept at most one test-case class (TestCaseAlreadyInUse), and
// Pest's Testable trait shadows inherited setUp() hooks, so the chaos suite gets its
// members via the InteractsWithToxiproxy trait plus beforeEach/afterEach hooks.
uses(InteractsWithToxiproxy::class)
    ->group('toxiproxy')
    ->beforeEach(fn () => $this->bootToxiproxy())
    ->afterEach(fn () => $this->cleanupToxiproxy())
    ->in(__DIR__.'/Feature/Toxiproxy');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeARedisConnection', function () {
    return $this->toBeInstanceOf(PhpRedisConnection::class);
});

expect()->extend('toBeARedisSentinelConnection', function () {
    return $this->toBeInstanceOf(RedisSentinelConnection::class);
});

expect()->extend('toBeAWorkingRedisConnection', function () {
    $key = 'foo';
    $value = 'bar';

    return $this
        ->toBeARedisConnection()
        ->and($this->value->ping())->toBeTrue()
        ->and($this->value->set($key, $value))->toBeTrue()
        ->and($this->value->get($key))->toEqual($value)
        ->and($this->value->del($key))->toEqual(1)
        ->and($this->value->get($key))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function getRedisSentinelConnection()
{
    return app()->get('redis')->connection('phpredis-sentinel');
}

/**
 * The live ConnectionState of the connection's current execution slot.
 * State moved into per-context storage, so tests assert stickiness
 * (and seed it) through this accessor instead of instance properties.
 */
function connectionState(RedisSentinelConnection $connection): ConnectionState
{
    // Mirrors the connection's lazy context init: non-split connections only
    // build their context on first use, and partially mocked instances never run
    // the constructor at all. The property is resolved from its declaring class
    // because Mockery proxies hide inherited private properties.
    $property = new ReflectionProperty(RedisSentinelConnection::class, 'context');
    $context = $property->getValue($connection);

    if ($context === null) {
        $context = new ExecutionContext;
        $property->setValue($connection, $context);
    }

    return $context->storage()['state'] ??= new ConnectionState;
}

/**
 * The namespaced NodeAddressCache key the connector derives for the
 * phpredis-sentinel connection, so tests read the exact keys production writes.
 */
function sentinelNodeCacheKey(): string
{
    return app(RedisSentinelConnector::class)
        ->getNodeCacheKey((array) config('database.redis.phpredis-sentinel'));
}

function getRedisConnection()
{
    return app()->get('redis')->connection('redis');
}

function waitFor(callable $condition, int $timeoutMs = 5000, int $intervalMs = 50): mixed
{
    $deadline = microtime(true) + $timeoutMs / 1000;

    while (microtime(true) < $deadline) {
        $result = $condition();

        if ($result) {
            return $result;
        }

        usleep($intervalMs * 1000);
    }

    throw new RuntimeException("Condition not met within {$timeoutMs}ms");
}
