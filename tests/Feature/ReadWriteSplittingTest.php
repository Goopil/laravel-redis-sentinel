<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;

beforeEach(function () {
    // Flush the cache before each test to avoid interference
    app(NodeAddressCache::class)->flush();
});

test('it dispatches read commands to replica', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    // Expect GET on replica
    $replicaClient->expects('get')->with('foo')->once()->andReturn('bar');

    // Expect SET on master. Laravel passes 3 arguments to set()
    $masterClient->expects('set')->with('foo', 'bar', Mockery::any())->once()->andReturn(true);

    $connector = function () use ($masterClient) {
        return $masterClient;
    };
    $readConnector = function () use ($replicaClient) {
        return $replicaClient;
    };

    $connection = new RedisSentinelConnection($masterClient, $connector, [], $readConnector);

    expect($connection->get('foo'))->toBe('bar');
    expect($connection->set('foo', 'bar'))->toBeTrue();
});

test('it stays on master during transaction', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    // All commands should go to master because of transaction
    $masterClient->expects('multi')->once()->andReturn($masterClient);
    $masterClient->expects('get')->with('foo')->andReturn($masterClient);
    $masterClient->expects('exec')->once()->andReturn(['bar']);

    // Replica should NOT be called
    $replicaClient->shouldNotReceive('get');

    $connector = function () use ($masterClient) {
        return $masterClient;
    };
    $readConnector = function () use ($replicaClient) {
        return $replicaClient;
    };

    $connection = new RedisSentinelConnection($masterClient, $connector, [], $readConnector);

    $result = $connection->transaction(function ($redis) {
        $redis->get('foo');
    });

    expect($result)->toBe(['bar']);
});

test('it stays on master during pipeline', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    // All commands should go to master because of pipeline
    $masterClient->expects('pipeline')->once()->andReturn($masterClient);
    $masterClient->expects('get')->with('foo')->andReturn($masterClient);
    $masterClient->expects('exec')->once()->andReturn(['bar']);

    // Replica should NOT be called
    $replicaClient->shouldNotReceive('get');

    $connector = function () use ($masterClient) {
        return $masterClient;
    };
    $readConnector = function () use ($replicaClient) {
        return $replicaClient;
    };

    $connection = new RedisSentinelConnection($masterClient, $connector, [], $readConnector);

    $result = $connection->pipeline(function ($redis) {
        $redis->get('foo');
    });

    expect($result)->toBe(['bar']);
});

test('it refreshes read client on failure', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient1 = Mockery::mock(Redis::class);
    $replicaClient2 = Mockery::mock(Redis::class);

    // First replica call fails
    $replicaClient1->expects('get')->with('foo')->once()->andThrow(new RedisException('connection lost'));

    // Second replica call (after refresh) succeeds
    $replicaClient2->expects('get')->with('foo')->once()->andReturn('bar');

    $masterConnector = function () use ($masterClient) {
        return $masterClient;
    };

    $callCount = 0;
    $readConnector = function ($refresh = false) use (&$callCount, $replicaClient1, $replicaClient2) {
        $callCount++;

        return $refresh ? $replicaClient2 : $replicaClient1;
    };

    $connection = new RedisSentinelConnection($masterClient, $masterConnector, [], $readConnector);
    $connection->setRetryMessages(['connection lost']);
    $connection->setRetryLimit(1);
    $connection->setRetryDelay(1);

    expect($connection->get('foo'))->toBe('bar');
    expect($callCount)->toBe(2);
});

test('it routes various read only commands to replica', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    $readCommands = [
        'exists' => ['key'],
        'hlen' => ['hash'],
        'smembers' => ['set'],
        'zrange' => ['zset', 0, -1],
        'ttl' => ['key'],
        'type' => ['key'],
    ];

    foreach ($readCommands as $method => $args) {
        // Return empty array or integer as expected by phpredis
        $returnValue = match ($method) {
            'smembers', 'zrange' => [],
            'exists', 'hlen', 'ttl' => 1,
            'type' => 1,
            default => true,
        };
        $replicaClient->expects($method)->with(...$args)->once()->andReturn($returnValue);
    }

    $connector = function () use ($masterClient) {
        return $masterClient;
    };
    $readConnector = function () use ($replicaClient) {
        return $replicaClient;
    };

    $connection = new RedisSentinelConnection($masterClient, $connector, [], $readConnector);

    foreach ($readCommands as $method => $args) {
        $connection->$method(...$args);
    }

    expect(true)->toBeTrue();
});

test('it is always sticky when read only replicas is active', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    // First SET on master
    $masterClient->expects('set')->with('foo', 'bar', Mockery::any())->once()->andReturn(true);

    // Subsequent GET should ALSO go to master because of stickiness (now default)
    $masterClient->expects('get')->with('foo')->once()->andReturn('bar');

    // Replica should NOT be called
    $replicaClient->shouldNotReceive('get');

    $connector = function () use ($masterClient) {
        return $masterClient;
    };
    $readConnector = function () use ($replicaClient) {
        return $replicaClient;
    };

    $connection = new RedisSentinelConnection($masterClient, $connector, [], $readConnector);

    $connection->set('foo', 'bar');

    $reflection = new ReflectionClass($connection);
    $property = $reflection->getProperty('wroteToMaster');
    $property->setAccessible(true);
    expect($property->getValue($connection))->toBeTrue();

    expect($connection->get('foo'))->toBe('bar');
});

test('it stays on master if read only replicas is disabled', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    $masterClient->expects('get')->with('foo')->once()->andReturn('master-bar');
    $replicaClient->shouldNotReceive('get');

    $connector = function () use ($masterClient) {
        return $masterClient;
    };
    $readConnector = null; // No read connector if disabled

    $connection = new RedisSentinelConnection($masterClient, $connector, [], $readConnector);

    expect($connection->get('foo'))->toBe('master-bar');
});

test('it returns data from replica for read commands', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    // Master should NOT be called for read commands
    $masterClient->shouldNotReceive('get');

    // Only replica should be called
    $replicaClient->expects('get')->with('foo')->once()->andReturn('replica-val');

    $connector = function () use ($masterClient) {
        return $masterClient;
    };
    $readConnector = function () use ($replicaClient) {
        return $replicaClient;
    };

    $connection = new RedisSentinelConnection($masterClient, $connector, [], $readConnector);

    expect($connection->get('foo'))->toBe('replica-val');
});

test('it falls back to master if no replicas found', function () {
    $sentinelMock = Mockery::mock(RedisSentinel::class);
    $sentinelMock->shouldReceive('ping')->andReturn(true);
    $sentinelMock->shouldReceive('master')->with('mymaster')->andReturn(['ip' => '127.0.0.1', 'port' => 6380]);

    // Sentinel returns empty list for slaves
    $sentinelMock->shouldReceive('slaves')->with('mymaster')->andReturn([]);

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

        protected function createClient(array $config, bool $refresh = false, bool $readOnly = false): Redis
        {
            ['ip' => $ip] = $readOnly
                ? $this->getReplicaAddress($config, $refresh)
                : $this->getMasterAddress($config, $refresh);

            $mock = Mockery::mock(Redis::class);
            $mock->shouldReceive('getHost')->andReturn($ip);

            return $mock;
        }
    };

    $connection = $connector->connect([
        'sentinel' => ['service' => 'mymaster', 'host' => '127.0.0.1'],
        'read_only_replicas' => true,
    ], []);

    // Since no replicas, both clients should point to master
    expect($connection->getReadClient()->getHost())->toBe('127.0.0.1');
});

test('it filters out unhealthy replicas', function () {
    $sentinelMock = Mockery::mock(RedisSentinel::class);
    $sentinelMock->shouldReceive('ping')->andReturn(true);
    $sentinelMock->shouldReceive('master')->with('mymaster')->andReturn(['ip' => '127.0.0.1', 'port' => 6379]);

    // One healthy, one down
    $sentinelMock->shouldReceive('slaves')->with('mymaster')->andReturn([
        ['ip' => '127.0.0.2', 'port' => 6379, 'flags' => 'slave,s_down'],
        ['ip' => '127.0.0.3', 'port' => 6379, 'flags' => 'slave'],
    ]);

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

        protected function createClient(array $config, bool $refresh = false, bool $readOnly = false): Redis
        {
            ['ip' => $ip] = $readOnly
                ? $this->getReplicaAddress($config, $refresh)
                : $this->getMasterAddress($config, $refresh);

            $mock = Mockery::mock(Redis::class);
            $mock->shouldReceive('getHost')->andReturn($ip);

            return $mock;
        }
    };

    $connection = $connector->connect([
        'sentinel' => ['service' => 'mymaster', 'host' => '127.0.0.1'],
        'read_only_replicas' => true,
    ], []);

    expect($connection->getReadClient()->getHost())->toBe('127.0.0.3');
});

test('it falls back to master if all replicas are unhealthy', function () {
    $sentinelMock = Mockery::mock(RedisSentinel::class);
    $sentinelMock->shouldReceive('ping')->andReturn(true);
    $sentinelMock->shouldReceive('master')->with('mymaster')->andReturn(['ip' => '127.0.0.1', 'port' => 6379]);

    // All replicas down
    $sentinelMock->shouldReceive('slaves')->with('mymaster')->andReturn([
        ['ip' => '127.0.0.2', 'port' => 6379, 'flags' => 'slave,s_down'],
        ['ip' => '127.0.0.3', 'port' => 6379, 'flags' => 'slave,o_down'],
        ['ip' => '127.0.0.4', 'port' => 6379, 'flags' => 'slave,disconnected'],
    ]);

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

        protected function createClient(array $config, bool $refresh = false, bool $readOnly = false): Redis
        {
            ['ip' => $ip] = $readOnly
                ? $this->getReplicaAddress($config, $refresh)
                : $this->getMasterAddress($config, $refresh);

            $mock = Mockery::mock(Redis::class);
            $mock->shouldReceive('getHost')->andReturn($ip);

            return $mock;
        }
    };

    $connection = $connector->connect([
        'sentinel' => ['service' => 'mymaster', 'host' => '127.0.0.1'],
        'read_only_replicas' => true,
    ], []);

    expect($connection->getReadClient()->getHost())->toBe('127.0.0.1');
});

test('it discovers replicas using secondary sentinel if primary is down', function () {
    $sentinel1 = Mockery::mock(RedisSentinel::class);
    $sentinel1->shouldReceive('ping')->andThrow(new RedisException('Connection refused'));

    $sentinel2 = Mockery::mock(RedisSentinel::class);
    $sentinel2->shouldReceive('ping')->andReturn(true);
    $sentinel2->shouldReceive('master')->with('mymaster')->andReturn(['ip' => '127.0.0.1', 'port' => 6379]);
    $sentinel2->shouldReceive('slaves')->with('mymaster')->andReturn([
        ['ip' => '127.0.0.2', 'port' => 6379, 'flags' => 'slave'],
    ]);

    $connector = new class(['sentinel1' => $sentinel1, 'sentinel2' => $sentinel2]) extends RedisSentinelConnector
    {
        public function __construct(private $mocks)
        {
            parent::__construct(app(NodeAddressCache::class));
            $this->setRetryDelay(1);
        }

        protected function createSentinelInstance(array $options): RedisSentinel
        {
            return $this->mocks[$options['host']];
        }

        protected function createClient(array $config, bool $refresh = false, bool $readOnly = false): Redis
        {
            ['ip' => $ip] = $readOnly
                ? $this->getReplicaAddress($config, $refresh)
                : $this->getMasterAddress($config, $refresh);

            $mock = Mockery::mock(Redis::class);
            $mock->shouldReceive('getHost')->andReturn($ip);

            return $mock;
        }
    };

    $connection = $connector->connect([
        'sentinel' => [
            'service' => 'mymaster',
            'sentinels' => [
                ['host' => 'sentinel1'],
                ['host' => 'sentinel2'],
            ],
        ],
        'read_only_replicas' => true,
    ], []);

    expect($connection->getReadClient()->getHost())->toBe('127.0.0.2');
});
