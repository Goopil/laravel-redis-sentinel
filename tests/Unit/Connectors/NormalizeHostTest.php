<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;

function normalizeHostConnector(): RedisSentinelConnector
{
    return new class(app(NodeAddressCache::class), app('config')) extends RedisSentinelConnector
    {
        public function testNormalizeHost(mixed $host): ?string
        {
            return $this->normalizeHost($host);
        }
    };
}

test('normalizeHost accepts valid IPv4 addresses', function () {
    $connector = normalizeHostConnector();

    expect($connector->testNormalizeHost('127.0.0.1'))->toBe('127.0.0.1')
        ->and($connector->testNormalizeHost('192.168.1.1'))->toBe('192.168.1.1')
        ->and($connector->testNormalizeHost('10.0.0.1'))->toBe('10.0.0.1');
});

test('normalizeHost accepts valid IPv6 addresses', function () {
    $connector = normalizeHostConnector();

    expect($connector->testNormalizeHost('::1'))->toBe('::1')
        ->and($connector->testNormalizeHost('fe80::1'))->toBe('fe80::1');
});

test('normalizeHost accepts valid domain names', function () {
    $connector = normalizeHostConnector();

    expect($connector->testNormalizeHost('localhost'))->toBe('localhost')
        ->and($connector->testNormalizeHost('redis.example.com'))->toBe('redis.example.com')
        ->and($connector->testNormalizeHost('redis-sentinel.internal'))->toBe('redis-sentinel.internal');
});

test('normalizeHost returns null for empty string', function () {
    $connector = normalizeHostConnector();

    expect($connector->testNormalizeHost(''))->toBeNull();
});

test('normalizeHost returns null for whitespace-only input', function () {
    $connector = normalizeHostConnector();

    expect($connector->testNormalizeHost('   '))->toBeNull();
    expect($connector->testNormalizeHost("\t\n"))->toBeNull();
});

test('normalizeHost trims whitespace', function () {
    $connector = normalizeHostConnector();

    expect($connector->testNormalizeHost('  127.0.0.1  '))->toBe('127.0.0.1')
        ->and($connector->testNormalizeHost(" localhost\n"))->toBe('localhost');
});

test('normalizeHost rejects injection attempts', function () {
    $connector = normalizeHostConnector();

    expect($connector->testNormalizeHost('127.0.0.1; rm -rf /'))->toBeNull()
        ->and($connector->testNormalizeHost('localhost | cat /etc/passwd'))->toBeNull()
        ->and($connector->testNormalizeHost('$(whoami)'))->toBeNull()
        ->and($connector->testNormalizeHost('`whoami`'))->toBeNull()
        ->and($connector->testNormalizeHost('127.0.0.1\n'))->toBeNull();
});

test('normalizeHost rejects invalid formats', function () {
    $connector = normalizeHostConnector();

    expect($connector->testNormalizeHost('-invalid-host'))->toBeNull()
        ->and($connector->testNormalizeHost('host with spaces'))->toBeNull();
});
