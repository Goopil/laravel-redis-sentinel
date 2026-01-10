<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\Exceptions\ConfigurationException;

test('it can connect using multiple sentinels when first is down', function () {
    // Use actual Redis standalone port from environment (CI uses dynamic ports)
    $redisPort = (int) env('REDIS_STANDALONE_PORT', 6379);

    $sentinel1 = Mockery::mock(RedisSentinel::class);
    $sentinel1->expects('ping')->andThrow(new RedisException('Connection refused'));

    $sentinel2 = Mockery::mock(RedisSentinel::class);
    $sentinel2->expects('ping')->andReturn(true);
    $sentinel2->expects('master')->with('mymaster')->andReturn(['ip' => '127.0.0.1', 'port' => $redisPort]);

    $connector = new class([$sentinel1, $sentinel2]) extends RedisSentinelConnector
    {
        private $mocks;

        private $index = 0;

        public function __construct(array $mocks)
        {
            parent::__construct(app(NodeAddressCache::class));
            $this->mocks = $mocks;
            $this->setRetryDelay(1);
        }

        protected function createSentinelInstance(array $options): RedisSentinel
        {
            $mock = $this->mocks[$this->index];
            $this->index++;

            return $mock;
        }

        public function exposeCreateClient(array $config)
        {
            return $this->createClient($config);
        }

        protected function establishConnection($client, array $config): void
        {
            // bypass
        }
    };

    $config = [
        'password' => 'test',
        'sentinel' => [
            'service' => 'mymaster',
            'sentinels' => [
                ['host' => 'sentinel1', 'port' => 26379],
                ['host' => 'sentinel2', 'port' => 26379],
            ],
        ],
    ];

    $connector->exposeCreateClient($config);

    // If we reach this point without exception, it means it worked.
    // Mockery will verify that the methods were called.
    expect(true)->toBeTrue();
});

test('it throws exception if all sentinels are down', function () {
    $sentinel1 = Mockery::mock(RedisSentinel::class);
    $sentinel1->expects('ping')->andReturn(false); // ping returns false

    $sentinel2 = Mockery::mock(RedisSentinel::class);
    $sentinel2->expects('ping')->andThrow(new RedisException('Connection refused'));

    $connector = new class([$sentinel1, $sentinel2]) extends RedisSentinelConnector
    {
        private $mocks;

        private $index = 0;

        public function __construct(array $mocks)
        {
            parent::__construct(app(NodeAddressCache::class));
            $this->mocks = $mocks;
            $this->setRetryDelay(1);
            $this->setRetryLimit(0); // No retries for this test to be fast
        }

        protected function createSentinelInstance(array $options): RedisSentinel
        {
            $mock = $this->mocks[$this->index];
            $this->index++;

            return $mock;
        }

        public function exposeCreateClient(array $config)
        {
            return $this->createClient($config);
        }
    };

    $config = [
        'sentinel' => [
            'service' => 'mymaster',
            'sentinels' => [
                ['host' => 'sentinel1'],
                ['host' => 'sentinel2'],
            ],
        ],
    ];

    expect(fn () => $connector->exposeCreateClient($config))
        ->toThrow(ConfigurationException::class, 'No reachable Redis Sentinel host found.');
});
