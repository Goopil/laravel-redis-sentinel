<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;

function normalizePortConnector(): RedisSentinelConnector
{
    return new class(app(NodeAddressCache::class), app('config')) extends RedisSentinelConnector
    {
        public function testNormalizePort(mixed $port, int $default = 26379): ?int
        {
            return $this->normalizePort($port, $default);
        }
    };
}

test('normalizePort accepts valid ports', function () {
    $connector = normalizePortConnector();

    expect($connector->testNormalizePort(80))->toBe(80)
        ->and($connector->testNormalizePort(443))->toBe(443)
        ->and($connector->testNormalizePort(6379))->toBe(6379)
        ->and($connector->testNormalizePort(26379))->toBe(26379)
        ->and($connector->testNormalizePort(65535))->toBe(65535);
});

test('normalizePort returns default for null', function () {
    $connector = normalizePortConnector();

    expect($connector->testNormalizePort(null))->toBe(26379)
        ->and($connector->testNormalizePort(null, 6379))->toBe(6379);
});

test('normalizePort accepts string numeric ports', function () {
    $connector = normalizePortConnector();

    expect($connector->testNormalizePort('6379'))->toBe(6379)
        ->and($connector->testNormalizePort('26379'))->toBe(26379)
        ->and($connector->testNormalizePort('80'))->toBe(80);
});

test('normalizePort rejects out-of-range ports', function () {
    $connector = normalizePortConnector();

    expect($connector->testNormalizePort(0))->toBeNull()
        ->and($connector->testNormalizePort(-1))->toBeNull()
        ->and($connector->testNormalizePort(65536))->toBeNull()
        ->and($connector->testNormalizePort(100000))->toBeNull();
});

test('normalizePort rejects non-numeric string values', function () {
    $connector = normalizePortConnector();

    expect($connector->testNormalizePort('abc'))->toBeNull()
        ->and($connector->testNormalizePort('12abc'))->toBe(12);
});

test('normalizePort accepts port 1 as minimum', function () {
    $connector = normalizePortConnector();

    expect($connector->testNormalizePort(1))->toBe(1);
});
