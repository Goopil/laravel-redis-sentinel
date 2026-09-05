<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;

function readCommandsConnector(): RedisSentinelConnector
{
    return new class(app(NodeAddressCache::class), app('config')) extends RedisSentinelConnector
    {
        protected function createClient(array $config, bool $refresh = false, bool $readOnly = false): Redis
        {
            return Mockery::mock(Redis::class);
        }
    };
}

/** @return array<int, string> */
function readOnlyCommandsOf(RedisSentinelConnection $connection): array
{
    return (new ReflectionProperty($connection, 'readOnlyCommands'))->getValue($connection);
}

test('a connection without read_commands inherits the global package list', function () {
    config(['phpredis-sentinel.read_commands' => ['CustomRead']]);

    $connection = readCommandsConnector()->connect([
        'sentinel' => ['service' => 'master', 'host' => '127.0.0.1'],
    ], []);

    $commands = readOnlyCommandsOf($connection);

    expect($commands)->toContain('customread');
    expect($commands)->toContain('get');
});

test('a per-connection read_commands list overrides the global one', function () {
    config(['phpredis-sentinel.read_commands' => ['GlobalRead']]);

    $connection = readCommandsConnector()->connect([
        'sentinel' => ['service' => 'master', 'host' => '127.0.0.1'],
        'read_commands' => ['ConnectionRead'],
    ], []);

    $commands = readOnlyCommandsOf($connection);

    expect($commands)->toContain('connectionread');
    expect($commands)->not->toContain('globalread');
    expect($commands)->toContain('get');
});

test('without configuration the allowlist is the hardcoded default', function () {
    config(['phpredis-sentinel.read_commands' => null]);

    $connection = readCommandsConnector()->connect([
        'sentinel' => ['service' => 'master', 'host' => '127.0.0.1'],
    ], []);

    expect(readOnlyCommandsOf($connection))->toBe(
        (new ReflectionClass(RedisSentinelConnection::class))->getConstant('READ_ONLY_COMMAND')
    );
});
