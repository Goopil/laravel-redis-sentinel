<?php

namespace Goopil\LaravelRedisSentinel\Tests\Unit\Events;

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelReplicaFallback;
use Illuminate\Support\Facades\Event;
use Mockery;
use Redis;
use RedisSentinel;
use RuntimeException;

const HOST_1 = '127.0.0.1';
const HOST_2 = '127.0.0.2';

test('a throwing listener on the replica fallback event does not break the master fallback', function () {
    $listenerInvoked = false;
    Event::listen(RedisSentinelReplicaFallback::class, function () use (&$listenerInvoked): void {
        $listenerInvoked = true;

        throw new RuntimeException('listener down');
    });

    $sentinelMock = Mockery::mock(RedisSentinel::class);
    $sentinelMock->shouldReceive('ping')->andReturn(true);
    $sentinelMock->shouldReceive('master')->with('mymaster')->andReturn(['ip' => HOST_1, 'port' => 6379]);
    $sentinelMock->shouldReceive('slaves')->with('mymaster')->andReturn([
        ['ip' => HOST_2, 'port' => 6379, 'flags' => 'slave,disconnected'],
    ]);

    $connector = new class($sentinelMock) extends RedisSentinelConnector
    {
        public function __construct(private $sentinelMock)
        {
            parent::__construct(app(NodeAddressCache::class), app('config'));
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
        'sentinel' => ['service' => 'mymaster', 'host' => HOST_1],
        'read_only_replicas' => true,
    ], []);

    expect($connection->getReadClient()->getHost())->toBe(HOST_1)
        ->and($listenerInvoked)->toBeTrue();
});

test('a failing exception handler does not break the dispatch guard either', function () {
    Event::listen(RedisSentinelReplicaFallback::class, function (): void {
        throw new RuntimeException('listener down');
    });

    $previousHandler = app()->instance(
        Illuminate\Contracts\Debug\ExceptionHandler::class,
        Mockery::mock(Illuminate\Contracts\Debug\ExceptionHandler::class, function ($mock): void {
            $mock->shouldReceive('report')->andThrow(new RuntimeException('handler down too'));
        })
    );

    $sentinelMock = Mockery::mock(RedisSentinel::class);
    $sentinelMock->shouldReceive('ping')->andReturn(true);
    $sentinelMock->shouldReceive('master')->with('mymaster')->andReturn(['ip' => HOST_1, 'port' => 6379]);
    $sentinelMock->shouldReceive('slaves')->with('mymaster')->andReturn([
        ['ip' => HOST_2, 'port' => 6379, 'flags' => 'slave,disconnected'],
    ]);

    $connector = new class($sentinelMock) extends RedisSentinelConnector
    {
        public function __construct(private $sentinelMock)
        {
            parent::__construct(app(NodeAddressCache::class), app('config'));
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
        'sentinel' => ['service' => 'mymaster', 'host' => HOST_1],
        'read_only_replicas' => true,
    ], []);

    expect($connection->getReadClient()->getHost())->toBe(HOST_1);

    app()->instance(Illuminate\Contracts\Debug\ExceptionHandler::class, $previousHandler);
});
