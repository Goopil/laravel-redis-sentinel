<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;

beforeEach(function () {
    $ref = new ReflectionProperty(RedisSentinelConnector::class, 'resolutionBreakers');
    $ref->setAccessible(true);
    $ref->setValue(null, []);
});

function isolatedConnector(): RedisSentinelConnector
{
    return new class(app(NodeAddressCache::class), app('config')) extends RedisSentinelConnector
    {
        public function getMasterAddress(array $config, bool $refresh = false): array
        {
            return parent::getMasterAddress($config, $refresh);
        }

        protected function createSentinelInstance(array $options): RedisSentinel
        {
            $sentinel = Mockery::mock(RedisSentinel::class);
            $sentinel->shouldReceive('ping')->andReturnTrue();
            $sentinel->shouldReceive('master')->with('mymaster')->andReturnUsing(function () use ($options) {
                return ['ip' => $options['host'], 'port' => 6380];
            });

            return $sentinel;
        }
    };
}

test('same service name on different sentinel clusters resolves distinct masters', function () {
    $connector = isolatedConnector();

    $clusterA = [
        'sentinels' => [['host' => '10.0.0.1', 'port' => 26379]],
        'sentinel' => ['service' => 'mymaster'],
    ];
    $clusterB = [
        'sentinels' => [['host' => '10.0.0.2', 'port' => 26379]],
        'sentinel' => ['service' => 'mymaster'],
    ];

    $masterA = $connector->getMasterAddress($clusterA);
    $masterB = $connector->getMasterAddress($clusterB);

    expect($masterA)->toBe(['ip' => '10.0.0.1', 'port' => 6380])
        ->and($masterB)->toBe(['ip' => '10.0.0.2', 'port' => 6380]);
});

test('same cluster with reordered sentinel list hits the same cache key', function () {
    $connector = isolatedConnector();

    $configA = [
        'sentinels' => [
            ['host' => '10.0.0.1', 'port' => 26379],
            ['host' => '10.0.0.2', 'port' => 26379],
        ],
        'sentinel' => ['service' => 'mymaster'],
    ];
    $configB = [
        'sentinels' => [
            ['host' => '10.0.0.2', 'port' => 26379],
            ['host' => '10.0.0.1', 'port' => 26379],
        ],
        'sentinel' => ['service' => 'mymaster'],
    ];

    expect($connector->getMasterAddress($configA))->toBe(['ip' => '10.0.0.1', 'port' => 6380])
        ->and($connector->getMasterAddress($configB))->toBe(['ip' => '10.0.0.1', 'port' => 6380]);
});
