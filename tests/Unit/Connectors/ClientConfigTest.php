<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;

function clientConfigConnector(): RedisSentinelConnector
{
    return new class(app(NodeAddressCache::class), app('config')) extends RedisSentinelConnector
    {
        public function testBuildClientConfig(array $config, string $ip, int $port): array
        {
            return $this->buildClientConfig($config, $ip, $port);
        }
    };
}

afterEach(function () {
    RedisSentinelConnector::$forceCoroutineDetection = false;
});

test('persistent is preserved for worker-created clients', function () {
    $connector = clientConfigConnector();
    $config = ['persistent' => 30, 'timeout' => 1];

    $result = $connector->testBuildClientConfig($config, '127.0.0.1', 6380);

    expect($result['persistent'])->toBe(30);
});

test('persistent is forced to zero for coroutine-created clients', function () {
    $connector = clientConfigConnector();
    RedisSentinelConnector::$forceCoroutineDetection = true;

    $result = $connector->testBuildClientConfig(['persistent' => 30], '127.0.0.1', 6380);

    expect($result['persistent'])->toBe(0);
});

test('sentinel.persistent fallback is preserved for workers and forced to zero in coroutines', function () {
    $config = ['sentinel' => ['persistent' => 30]];

    expect(clientConfigConnector()->testBuildClientConfig($config, '127.0.0.1', 6380)['persistent'])->toBe(30);

    RedisSentinelConnector::$forceCoroutineDetection = true;

    expect(clientConfigConnector()->testBuildClientConfig($config, '127.0.0.1', 6380)['persistent'])->toBe(0);
});

test('TLS scheme and stream context from options survive the client config merge', function () {
    $built = clientConfigConnector()->testBuildClientConfig([
        'options' => [
            'scheme' => 'tls',
            'context' => ['stream' => ['cafile' => '/tmp/ca.crt']],
        ],
    ], '10.0.0.1', 6380);

    expect($built['scheme'])->toBe('tls')
        ->and($built['context'])->toBe(['stream' => ['cafile' => '/tmp/ca.crt']])
        ->and($built['host'])->toBe('10.0.0.1')
        ->and($built['port'])->toBe(6380);
});
