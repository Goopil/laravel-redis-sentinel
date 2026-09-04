<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\Exceptions\ConfigurationException;

const BREAKER_TEST_HOST = '127.0.0.1';

function breakerConnector(int &$calls, int $failuresBeforeSuccess = PHP_INT_MAX): RedisSentinelConnector
{
    // failuresBeforeSuccess defaults to PHP_INT_MAX so the connector never succeeds (all Sentinels unreachable);
    // a positive value fails that many resolutions before returning a working Sentinel.
    return new class($calls, $failuresBeforeSuccess) extends RedisSentinelConnector
    {
        public function __construct(public int &$calls, public int $failuresBeforeSuccess)
        {
            parent::__construct(app(NodeAddressCache::class), app('config'));
        }

        public function getMasterAddress(array $config, bool $refresh = false): array
        {
            return parent::getMasterAddress($config, $refresh);
        }

        protected function connectToSentinel(array $config): RedisSentinel
        {
            $this->calls++;

            if ($this->calls > $this->failuresBeforeSuccess) {
                $sentinel = Mockery::mock(RedisSentinel::class);
                $sentinel->shouldReceive('master')->andReturn(['ip' => '127.0.0.1', 'port' => 6379]);

                return $sentinel;
            }

            throw new ConfigurationException('No reachable Redis Sentinel host found.');
        }
    };
}

test('breaker fails fast after threshold failures and records per-attempt count', function () {
    $calls = 0;
    $connector = breakerConnector($calls);

    $master = null;

    try {
        $connector->getMasterAddress(['sentinel' => ['service' => 'master', 'host' => BREAKER_TEST_HOST]]);
    } catch (Throwable $e) {
        $master = $e;
    }

    expect($master)->toBeInstanceOf(ConfigurationException::class)
        ->and($calls)->toBe(2);

    $start = microtime(true);

    try {
        $connector->getMasterAddress(['sentinel' => ['service' => 'master', 'host' => BREAKER_TEST_HOST]]);
    } catch (Throwable $e) {
        $master = $e;
    }

    expect($master)->toBeInstanceOf(ConfigurationException::class)
        ->and($calls)->toBe(2)
        ->and((microtime(true) - $start) * 1000)->toBeLessThan(50.0);
});

test('breaker closes after the cooldown and a full resolution cycle runs again', function () {
    $calls = 0;
    $connector = breakerConnector($calls);

    try {
        $connector->getMasterAddress(['sentinel' => ['service' => 'master', 'host' => BREAKER_TEST_HOST]]);
    } catch (Throwable) {
    }

    expect($calls)->toBe(2);

    $openedAt = new ReflectionProperty(RedisSentinelConnector::class, 'resolutionBreakers');
    $openedAt->setAccessible(true);
    $state = $openedAt->getValue();
    $key = array_key_first($state);
    $state[$key]['openedAt'] = microtime(true) - 10.0;
    $openedAt->setValue(null, $state);

    try {
        $connector->getMasterAddress(['sentinel' => ['service' => 'master', 'host' => BREAKER_TEST_HOST]]);
    } catch (Throwable) {
    }

    expect($calls)->toBe(4);
});

test('a successful resolution resets the breaker counter', function () {
    $calls = 0;
    $connector = breakerConnector($calls, 1);

    $master = $connector->getMasterAddress(['sentinel' => ['service' => 'master', 'host' => BREAKER_TEST_HOST]]);

    expect($master)->toBeArray()
        ->and($calls)->toBe(2);

    $failures = new ReflectionProperty(RedisSentinelConnector::class, 'resolutionBreakers');
    $failures->setAccessible(true);

    expect($failures->getValue())->toBe([]);
});

test('a cluster outage does not block other clusters', function () {
    $calls = 0;
    $connector = breakerConnector($calls);

    // Trip the breaker on cluster A (127.0.0.1 endpoints)
    try {
        $connector->getMasterAddress(['sentinel' => ['service' => 'master', 'host' => BREAKER_TEST_HOST]]);
    } catch (Throwable) {
    }

    expect($calls)->toBe(2);

    // Cluster B (different Sentinel host) must still attempt a real resolution
    try {
        $connector->getMasterAddress(['sentinel' => ['service' => 'master', 'host' => '127.0.0.2']]);
    } catch (Throwable) {
    }

    expect($calls)->toBe(4);
});
