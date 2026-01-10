<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;

test('it calls sentinel only once when cache is enabled', function () {
    $sentinelMock = Mockery::mock(RedisSentinel::class);

    // Use actual Redis standalone port from environment (CI uses dynamic ports)
    $redisPort = (int) env('REDIS_STANDALONE_PORT', 6379);

    // We expect only ONE call to master() even if we create multiple clients
    $sentinelMock->expects('master')
        ->with('mymaster')
        ->once()
        ->andReturn(['ip' => '127.0.0.1', 'port' => $redisPort]);

    $connector = new class($sentinelMock) extends RedisSentinelConnector
    {
        private $mockSentinel;

        public function __construct($sentinelMock)
        {
            parent::__construct(app(NodeAddressCache::class));
            $this->mockSentinel = $sentinelMock;
        }

        protected function connectToSentinel(array $config): RedisSentinel
        {
            return $this->mockSentinel;
        }

        public function exposeCreateClient(array $config)
        {
            return $this->createClient($config);
        }

        protected function establishConnection($client, array $config): void
        {
            // bypass actual connection - client will connect to REDIS_STANDALONE_PORT
        }
    };

    $config = [
        'password' => 'test',
        'sentinel' => [
            'service' => 'mymaster',
            'host' => '127.0.0.1',
        ],
    ];

    // First call
    $connector->exposeCreateClient($config);

    // Second call - should use cache (Mockery will fail if master() is called again)
    $connector->exposeCreateClient($config);
});

test('it invalidates cache when refresh is requested', function () {
    $sentinelMock = Mockery::mock(RedisSentinel::class);

    // Use actual Redis standalone port from environment (CI uses dynamic ports)
    $redisPort = (int) env('REDIS_STANDALONE_PORT', 6379);

    // Expect TWO calls to master() because we will force a refresh
    $sentinelMock->expects('master')
        ->with('mymaster')
        ->twice()
        ->andReturn(
            ['ip' => '127.0.0.1', 'port' => $redisPort],
            ['ip' => '127.0.0.1', 'port' => $redisPort]
        );

    $connector = new class($sentinelMock) extends RedisSentinelConnector
    {
        private $mockSentinel;

        public function __construct($sentinelMock)
        {
            parent::__construct(app(NodeAddressCache::class));
            $this->mockSentinel = $sentinelMock;
        }

        protected function connectToSentinel(array $config): RedisSentinel
        {
            return $this->mockSentinel;
        }

        public function exposeCreateClient(array $config, $refresh = false)
        {
            return $this->createClient($config, $refresh);
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
            'host' => '127.0.0.1',
        ],
    ];

    // First call
    $connector->exposeCreateClient($config);

    // Second call with refresh=true
    $connector->exposeCreateClient($config, true);
});
