<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelMasterFailed;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelMasterMaxRetryFailed;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelMasterReconnected;
use Illuminate\Support\Facades\Event;

test('it retries when master not found', function () {
    Event::fake();

    $sentinelMock = Mockery::mock(RedisSentinel::class);
    $sentinelMock->expects('master')
        ->with('mymaster')
        ->times(3)
        ->andReturns(false, false, ['ip' => '127.0.0.1', 'port' => 6379]);

    $connector = new class($sentinelMock) extends RedisSentinelConnector
    {
        public function __construct(private $sentinelMock)
        {
            parent::__construct(app(NodeAddressCache::class));
            $this->setRetryDelay(1);
            $this->setRetryMessages(['No master found for service']);
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
            // mock connection
        }
    };

    $config = [
        'password' => 'test',
        'sentinel' => [
            'service' => 'mymaster',
            'host' => '127.0.0.1',
        ],
    ];

    $connector->exposeCreateClient($config);

    Event::assertDispatched(RedisSentinelMasterFailed::class, 2);
    Event::assertDispatched(RedisSentinelMasterReconnected::class, 1);
});

test('it throws after max retries', function () {
    Event::fake();

    $sentinelMock = Mockery::mock(RedisSentinel::class);
    $sentinelMock->allows('master')
        ->with('mymaster')
        ->andReturns(false);

    $connector = new class($sentinelMock) extends RedisSentinelConnector
    {
        public function __construct(private $sentinelMock)
        {
            parent::__construct(app(NodeAddressCache::class));
            $this->setRetryLimit(2);
            $this->setRetryDelay(1);
            $this->setRetryMessages(['No master found for service']);
        }

        protected function connectToSentinel(array $config): RedisSentinel
        {
            return $this->sentinelMock;
        }

        public function exposeCreateClient(array $config)
        {
            return $this->createClient($config);
        }

        protected function establishConnection($client, array $config): void {}
    };

    $config = [
        'password' => 'test',
        'sentinel' => [
            'service' => 'mymaster',
            'host' => '127.0.0.1',
        ],
    ];

    try {
        $connector->exposeCreateClient($config);
    } catch (RedisException $e) {
        expect($e->getMessage())->toBe("No master found for service 'mymaster'.");
    } finally {
        Event::assertDispatched(RedisSentinelMasterFailed::class, 3);
        Event::assertDispatched(RedisSentinelMasterMaxRetryFailed::class, 1);
    }
});

test('it does not retry unrecognized exceptions', function () {
    Event::fake();

    $sentinelMock = Mockery::mock(RedisSentinel::class);
    $sentinelMock->allows('master')->andThrow(new Exception('something bad happened'));

    $connector = new class($sentinelMock) extends RedisSentinelConnector
    {
        public function __construct(private $sentinelMock)
        {
            parent::__construct(app(NodeAddressCache::class));
            $this->setRetryMessages(['No master found for service']);
        }

        protected function connectToSentinel(array $config): RedisSentinel
        {
            return $this->sentinelMock;
        }

        public function exposeCreateClient(array $config)
        {
            return $this->createClient($config);
        }
    };

    expect(fn () => $connector->exposeCreateClient([
        'password' => 'test',
        'sentinel' => ['service' => 'mymaster', 'host' => '127.0.0.1'],
    ]))->toThrow(Exception::class, 'something bad happened');

    Event::assertNotDispatched(RedisSentinelMasterFailed::class);
});

test('it retries create sentinel on connection failure', function () {
    Event::fake();

    $connector = new class extends RedisSentinelConnector
    {
        public $attempts = 0;

        public function __construct()
        {
            parent::__construct(app(NodeAddressCache::class));
            $this->setRetryLimit(2);
            $this->setRetryDelay(1);
            $this->setRetryMessages(['connection failed']);
        }

        protected function connectToSentinel(array $config): RedisSentinel
        {
            $this->attempts++;
            if ($this->attempts < 3) {
                throw new RedisException('connection failed');
            }

            return Mockery::mock(RedisSentinel::class);
        }
    };

    config(['database.redis.my-sentinel' => [
        'sentinel' => [
            'host' => '127.0.0.1',
            'service' => 'master',
        ],
    ]]);

    $connector->createSentinel('my-sentinel');

    expect($connector->attempts)->toBe(3);
    Event::assertDispatched(RedisSentinelMasterFailed::class, 2);
    Event::assertDispatched(RedisSentinelMasterReconnected::class, 1);
});
