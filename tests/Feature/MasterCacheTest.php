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

    $mockRedisClient = Mockery::mock(Redis::class);

    $connector = new class($sentinelMock, $mockRedisClient) extends RedisSentinelConnector
    {
        private $mockSentinel;

        private $mockRedis;

        public function __construct($sentinelMock, $mockRedis)
        {
            parent::__construct(app(NodeAddressCache::class));
            $this->mockSentinel = $sentinelMock;
            $this->mockRedis = $mockRedis;
        }

        protected function connectToSentinel(array $config): RedisSentinel
        {
            return $this->mockSentinel;
        }

        public function exposeCreateClient(array $config)
        {
            return $this->createClient($config);
        }

        // Override to call getMasterAddress but return mock Redis client
        protected function createClient(array $config, bool $refresh = false, bool $readOnly = false): Redis
        {
            if (! \Illuminate\Support\Arr::has($config, 'sentinel') && ! \Illuminate\Support\Arr::has($config, 'sentinels')) {
                return $this->mockRedis;
            }

            // This triggers the sentinel call and cache logic
            $readOnly ? $this->getReplicaAddress($config, $refresh) : $this->getMasterAddress($config, $refresh);

            // Return mock instead of real Redis client
            return $this->mockRedis;
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

    // Expect TWO calls to master() because we will force a refresh
    $sentinelMock->expects('master')
        ->with('mymaster')
        ->twice()
        ->andReturn(
            ['ip' => '127.0.0.1', 'port' => 6379],
            ['ip' => '127.0.0.2', 'port' => 6379]
        );

    $mockRedisClient = Mockery::mock(Redis::class);

    $connector = new class($sentinelMock, $mockRedisClient) extends RedisSentinelConnector
    {
        private $mockSentinel;

        private $mockRedis;

        public function __construct($sentinelMock, $mockRedis)
        {
            parent::__construct(app(NodeAddressCache::class));
            $this->mockSentinel = $sentinelMock;
            $this->mockRedis = $mockRedis;
        }

        protected function connectToSentinel(array $config): RedisSentinel
        {
            return $this->mockSentinel;
        }

        public function exposeCreateClient(array $config, $refresh = false)
        {
            return $this->createClient($config, $refresh);
        }

        protected function createClient(array $config, bool $refresh = false, bool $readOnly = false): Redis
        {
            if (! \Illuminate\Support\Arr::has($config, 'sentinel') && ! \Illuminate\Support\Arr::has($config, 'sentinels')) {
                return $this->mockRedis;
            }

            // This triggers the sentinel call and cache logic
            $readOnly ? $this->getReplicaAddress($config, $refresh) : $this->getMasterAddress($config, $refresh);

            // Return mock instead of real Redis client
            return $this->mockRedis;
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
