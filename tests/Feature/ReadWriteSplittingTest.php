<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;

beforeEach(function () {
    // Flush the cache before each test to avoid interference
    app(NodeAddressCache::class)->flush();
});

function redisSentinelConnection(Redis $masterClient, ?Redis $replicaClient = null): RedisSentinelConnection
{
    return new RedisSentinelConnection(
        $masterClient,
        fn () => $masterClient,
        [],
        $replicaClient ? fn () => $replicaClient : null,
    );
}

function readOnlyCommandDataset(): array
{
    return [
        'bitcount' => ['bitcount', ['foo'], 8],
        'bitpos' => ['bitpos', ['foo', 1], 3],
        'get' => ['get', ['foo'], 'bar'],
        'getbit' => ['getbit', ['foo', 1], 1],
        'getrange' => ['getrange', ['foo', 0, 2], 'bar'],
        'strlen' => ['strlen', ['foo'], 3],
        'mget' => ['mget', [['foo', 'bar']], ['baz', 'qux']],
        'hscan' => ['hscan', ['foo', null], ['bar' => 'baz']],
        'hexists' => ['hexists', ['foo', 'bar'], true],
        'hget' => ['hget', ['foo', 'bar'], 'baz'],
        'hgetall' => ['hgetall', ['foo'], ['bar' => 'baz']],
        'hkeys' => ['hkeys', ['foo'], ['bar']],
        'hlen' => ['hlen', ['foo'], 1],
        'hmget' => ['hmget', ['foo', ['bar', 'baz']], ['bar' => 'qux']],
        'hstrlen' => ['hstrlen', ['foo', 'bar'], 3],
        'hvals' => ['hvals', ['foo'], ['baz']],
        'lindex' => ['lindex', ['foo', 0], 'bar'],
        'llen' => ['llen', ['foo'], 1],
        'lrange' => ['lrange', ['foo', 0, -1], ['bar']],
        'scard' => ['scard', ['foo'], 1],
        'sismember' => ['sismember', ['foo', 'bar'], true],
        'smismember' => ['smismember', ['foo', 'bar', 'baz'], [true, false]],
        'smembers' => ['smembers', ['foo'], ['bar']],
        'srandmember' => ['srandmember', ['foo'], 'bar'],
        'sscan' => ['sscan', ['foo', null], ['bar']],
        'zcard' => ['zcard', ['foo'], 1],
        'zcount' => ['zcount', ['foo', 0, 10], 1],
        'zlexcount' => ['zlexcount', ['foo', '-', '+'], 1],
        'zrange' => ['zrange', ['foo', 0, -1], ['bar']],
        'zrank' => ['zrank', ['foo', 'bar'], 0],
        'zrevrange' => ['zrevrange', ['foo', 0, -1], ['bar']],
        'zrevrank' => ['zrevrank', ['foo', 'bar'], 0],
        'zscore' => ['zscore', ['foo', 'bar'], 1.0],
        'zscan' => ['zscan', ['foo', null], ['bar' => 1.0]],
        'zrangebyscore' => ['zrangebyscore', ['foo', 0, 10], ['bar']],
        'zrevrangebyscore' => ['zrevrangebyscore', ['foo', 10, 0], ['bar']],
        'zrangebylex' => ['zrangebylex', ['foo', '-', '+'], ['bar']],
        'zrevrangebylex' => ['zrevrangebylex', ['foo', '+', '-'], ['bar']],
        'exists' => ['exists', ['foo'], 1],
        'scan' => ['scan', [null], ['foo']],
        'type' => ['type', ['foo'], Redis::REDIS_STRING],
        'pttl' => ['pttl', ['foo'], 1000],
        'ttl' => ['ttl', ['foo'], 60],
    ];
}

test('it dispatches read commands to replica', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    // Expect GET on replica
    $replicaClient->expects('get')->with('foo')->once()->andReturn('bar');

    // Expect SET on master. Laravel passes 3 arguments to set()
    $masterClient->expects('set')->with('foo', 'bar', Mockery::any())->once()->andReturn(true);

    $connection = redisSentinelConnection($masterClient, $replicaClient);

    expect($connection->get('foo'))->toBe('bar');
    expect($connection->set('foo', 'bar'))->toBeTrue();
});

test('it classifies exposed read-only commands as replica-safe', function (string $command, array $parameters, mixed $result) {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    $masterClient->shouldNotReceive($command);
    $replicaClient->expects($command)->with(...$parameters)->once()->andReturn($result);

    $connection = redisSentinelConnection($masterClient, $replicaClient);

    expect($connection->command($command, $parameters))->toBe($result);
})->with(readOnlyCommandDataset());

test('it has a dataset entry for every exposed read-only command', function () {
    $reflection = new ReflectionClass(RedisSentinelConnection::class);
    $datasetCommands = array_keys(readOnlyCommandDataset());
    $readOnlyCommands = $reflection->getConstant('READ_ONLY_COMMAND');

    sort($datasetCommands);
    sort($readOnlyCommands);

    expect($datasetCommands)->toBe($readOnlyCommands);
});

test('it routes scan commands to replicas', function (string $command, array $parameters, mixed $result) {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    $masterClient->shouldNotReceive($command);
    $expectedParameters = [...array_slice($parameters, 0, -1), '*', 10];
    $replicaClient->expects($command)->with(...$expectedParameters)->once()->andReturn($result);

    $connection = redisSentinelConnection($masterClient, $replicaClient);

    expect($connection->{$command}(...$parameters))->toBe([null, $result]);
})->with([
    'scan' => ['scan', [null, []], ['foo']],
    'hscan' => ['hscan', ['hash', null, []], ['field' => 'value']],
    'sscan' => ['sscan', ['set', null, []], ['member']],
    'zscan' => ['zscan', ['zset', null, []], ['member' => 1.0]],
]);

test('it keeps dangerous or operational commands on master', function (string $command, array $parameters, mixed $result) {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    $masterClient->expects($command)->with(...$parameters)->once()->andReturn($result);
    $replicaClient->shouldNotReceive($command);

    $connection = redisSentinelConnection($masterClient, $replicaClient);

    expect($connection->{$command}(...$parameters))->toBe($result);
})->with([
    'keys' => ['keys', ['*'], ['foo']],
    'info' => ['info', [], ['redis_version' => '7.0.0']],
    'pubsub' => ['pubsub', ['channels', 'user-*'], ['user-registrations']],
]);

test('it keeps subscription commands on master', function (string $command, array $parameters) {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);
    $callback = fn () => null;

    $masterClient->expects($command)->with($parameters[0], Mockery::type(Closure::class))->once();
    $replicaClient->shouldNotReceive($command);

    $connection = redisSentinelConnection($masterClient, $replicaClient);

    $connection->{$command}($parameters[0], $callback);
})->with([
    'subscribe' => ['subscribe', [['events']]],
    'psubscribe' => ['psubscribe', [['events.*']]],
]);

test('it resets stickiness after flushing databases', function (string $command, array $parameters, mixed $result) {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    $masterClient->expects('set')->with('foo', 'bar', Mockery::any())->once()->andReturn(true);
    $expectedParameters = $command === 'flushdb'
        ? array_filter($parameters, fn ($parameter) => $parameter !== null)
        : $parameters;

    $masterClient->expects($command)->with(...$expectedParameters)->once()->andReturn($result);
    $replicaClient->expects('get')->with('foo')->once()->andReturn('replica-bar');

    $connection = redisSentinelConnection($masterClient, $replicaClient);

    expect($connection->set('foo', 'bar'))->toBeTrue();
    expect($connection->{$command}(...$parameters))->toBe($result);
    expect($connection->get('foo'))->toBe('replica-bar');
})->with([
    'flushdb' => ['flushdb', [null], true],
    'flushall' => ['flushall', [null], true],
]);

test('it stays on master during transaction', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    // All commands should go to master because of transaction
    $masterClient->expects('multi')->once()->andReturn($masterClient);
    $masterClient->expects('get')->with('foo')->andReturn($masterClient);
    $masterClient->expects('exec')->once()->andReturn(['bar']);

    // Replica should NOT be called
    $replicaClient->shouldNotReceive('get');

    $connection = redisSentinelConnection($masterClient, $replicaClient);

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

    $connection = redisSentinelConnection($masterClient, $replicaClient);

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

test('it is always sticky when read only replicas is active', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    // First SET on master
    $masterClient->expects('set')->with('foo', 'bar', Mockery::any())->once()->andReturn(true);

    // Subsequent GET should ALSO go to master because of stickiness (now default)
    $masterClient->expects('get')->with('foo')->once()->andReturn('bar');

    // Replica should NOT be called
    $replicaClient->shouldNotReceive('get');

    $connection = redisSentinelConnection($masterClient, $replicaClient);

    $connection->set('foo', 'bar');

    $reflection = new ReflectionClass($connection);
    $property = $reflection->getProperty('wroteToMaster');
    expect($property->getValue($connection))->toBeTrue();

    expect($connection->get('foo'))->toBe('bar');
});

test('it stays on master if read only replicas is disabled', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    $masterClient->expects('get')->with('foo')->once()->andReturn('master-bar');
    $replicaClient->shouldNotReceive('get');

    $connection = redisSentinelConnection($masterClient);

    expect($connection->get('foo'))->toBe('master-bar');
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

test('it keeps cached master address when refreshing replicas', function () {
    $sentinelMock = Mockery::mock(RedisSentinel::class);
    $sentinelMock->shouldReceive('ping')->andReturn(true);
    $sentinelMock->shouldReceive('master')->with('mymaster')->once()->andReturn(['ip' => '127.0.0.1', 'port' => 6379]);
    $sentinelMock->shouldReceive('slaves')->with('mymaster')->twice()->andReturn(
        [['ip' => '127.0.0.2', 'port' => 6379, 'flags' => 'slave']],
        [['ip' => '127.0.0.3', 'port' => 6379, 'flags' => 'slave']],
    );

    $cache = app(NodeAddressCache::class);

    $connector = new class($sentinelMock, $cache) extends RedisSentinelConnector
    {
        public function __construct(private $sentinelMock, NodeAddressCache $cache)
        {
            parent::__construct($cache);
        }

        protected function connectToSentinel(array $config): RedisSentinel
        {
            return $this->sentinelMock;
        }

        public function master(array $config): array
        {
            return $this->getMasterAddress($config);
        }

        public function replica(array $config, bool $refresh = false): array
        {
            return $this->getReplicaAddress($config, $refresh);
        }
    };

    $config = ['sentinel' => ['service' => 'mymaster', 'host' => '127.0.0.1']];

    expect($connector->master($config))->toBe(['ip' => '127.0.0.1', 'port' => 6379]);
    expect($connector->replica($config))->toBe(['ip' => '127.0.0.2', 'port' => 6379]);
    expect($connector->replica($config, true))->toBe(['ip' => '127.0.0.3', 'port' => 6379]);
    expect($cache->get('mymaster'))->toBe(['ip' => '127.0.0.1', 'port' => 6379]);
});

test('it reindexes healthy replicas after filtering unhealthy replicas', function () {
    $sentinelMock = Mockery::mock(RedisSentinel::class);
    $sentinelMock->shouldReceive('ping')->andReturn(true);
    $sentinelMock->shouldReceive('master')->with('mymaster')->andReturn(['ip' => '127.0.0.1', 'port' => 6379]);
    $sentinelMock->shouldReceive('slaves')->with('mymaster')->andReturn([
        ['ip' => '127.0.0.2', 'port' => 6379, 'flags' => 'slave,s_down'],
        ['ip' => '127.0.0.3', 'port' => 6379, 'flags' => 'slave'],
        ['ip' => '127.0.0.4', 'port' => 6379, 'flags' => 'slave'],
    ]);

    $cache = app(NodeAddressCache::class);

    $connector = new class($sentinelMock, $cache) extends RedisSentinelConnector
    {
        public function __construct(private $sentinelMock, NodeAddressCache $cache)
        {
            parent::__construct($cache);
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

    expect($connection->getReadClient()->getHost())->toBeIn(['127.0.0.3', '127.0.0.4']);
    expect(array_keys($cache->getReplicas('mymaster')))->toBe([0, 1]);
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

test('write commands always use master client even after failures', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    $attempts = 0;

    // First attempt fails, second succeeds
    $masterClient->expects('set')
        ->with('key', 'value', Mockery::any())
        ->twice()
        ->andReturnUsing(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw new RedisException('Connection lost');
            }

            return true;
        });

    // Replica should NEVER be called for write commands
    $replicaClient->shouldNotReceive('set');

    $connector = function ($refresh = false) use ($masterClient) {
        return $masterClient;
    };
    $readConnector = function () use ($replicaClient) {
        return $replicaClient;
    };

    $connection = new RedisSentinelConnection($masterClient, $connector, [], $readConnector);
    $connection->setRetryLimit(3)
        ->setRetryMessages(['connection lost']);

    // Execute write command - should retry and succeed, always on master
    expect($connection->set('key', 'value'))->toBeTrue();
    expect($attempts)->toBe(2);
});

test('master client reference is never corrupted', function () {
    $masterClient = Mockery::mock(Redis::class);
    $replicaClient = Mockery::mock(Redis::class);

    // Setup read command on replica
    $replicaClient->expects('get')->with('key')->once()->andReturn('value');

    // Setup write command on master
    $masterClient->expects('set')->with('key', 'newvalue', Mockery::any())->once()->andReturn(true);

    // Setup another read command - should go to master due to stickiness
    $masterClient->expects('get')->with('key2')->once()->andReturn('value2');

    $connector = function () use ($masterClient) {
        return $masterClient;
    };
    $readConnector = function () use ($replicaClient) {
        return $replicaClient;
    };

    $connection = new RedisSentinelConnection($masterClient, $connector, [], $readConnector);

    // Use reflection to verify internal state
    $reflection = new ReflectionClass($connection);
    $masterClientProp = $reflection->getProperty('masterClient');

    // First read goes to replica
    expect($connection->get('key'))->toBe('value');

    // Verify master client reference is unchanged
    expect($masterClientProp->getValue($connection))->toBe($masterClient);

    // Write goes to master
    expect($connection->set('key', 'newvalue'))->toBeTrue();

    // Verify master client reference is STILL unchanged
    expect($masterClientProp->getValue($connection))->toBe($masterClient);

    // Subsequent read goes to master due to stickiness
    expect($connection->get('key2'))->toBe('value2');

    // Verify master client reference is STILL unchanged
    expect($masterClientProp->getValue($connection))->toBe($masterClient);
});
