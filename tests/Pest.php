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
use Goopil\LaravelRedisSentinel\Tests\Feature\Toxiproxy\ToxiproxyTestCase;
use Goopil\LaravelRedisSentinel\Tests\TestCase;
use Illuminate\Redis\Connections\PhpRedisConnection;

// Per-directory bindings: Pest rejects two different test-case classes matching the same
// file (TestCaseAlreadyInUse), so the toxiproxy folder cannot inherit the global TestCase
// binding and is bound to ToxiproxyTestCase exclusively.
uses(TestCase::class)->in(__DIR__.'/Unit', __DIR__.'/Feature/Horizon', __DIR__.'/Feature/Orchestra');

uses(ToxiproxyTestCase::class)
    ->group('toxiproxy')
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

function getRedisConnection()
{
    return app()->get('redis')->connection('redis');
}
