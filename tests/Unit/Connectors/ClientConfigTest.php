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
