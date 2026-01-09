<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;

test('it calls sentinel only once when cache is enabled', function () {
    $sentinelMock = Mockery::mock(RedisSentinel::class);

    // We expect only ONE call to master() even if we create multiple clients
    $sentinelMock->expects('master')
        ->with('mymaster')
        ->once()
        ->andReturn(['ip' => '127.0.0.1', 'port' => 6379]);

    $connector = new class($sentinelMock) extends RedisSentinelConnector
    {
        public function __construct(private $sentinelMock)
        {
            parent::__construct(app(NodeAddressCache::class));
        }

        protected function connectToSentinel(array $config): RedisSentinel
        {
            return $this->sentinelMock;
        }

        public function exposeCreateClient(array $config)
        {
            return $this->createClient($config);
        }

        protected function establishConnection($client, array $config): void
        {
            // bypass actual connection
        }
    };

    $config = [
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

    // Expect TWO calls to master() because we will force a refresh
    $sentinelMock->expects('master')
        ->with('mymaster')
        ->twice()
        ->andReturn(
            ['ip' => '127.0.0.1', 'port' => 6379],
            ['ip' => '127.0.0.2', 'port' => 6379]
        );

    $connector = new class($sentinelMock) extends RedisSentinelConnector
    {
        public function __construct(private $sentinelMock)
        {
            parent::__construct(app(NodeAddressCache::class));
        }

        protected function connectToSentinel(array $config): RedisSentinel
        {
            return $this->sentinelMock;
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
