<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionFailed;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionReconnected;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;

describe('Reconnect', function () {
    /**
     * dirty fix to retrieve the message from the not yet loaded config file...
     */
    $messages = Arr::get(require __DIR__.'/../../config/phpredis-sentinel.php', 'retry.redis.messages');

    foreach ($messages as $message) {
        test(sprintf('Reconnecting after [%s] in exception message', $message), function () use ($message) {
            Event::fake();

            $connection = getRedisSentinelConnection();
            $clientId = spl_object_hash($connection->client());

            expect($connection)
                ->toBeARedisSentinelConnection()
                ->toBeAWorkingRedisConnection();

            // Force an exception, but avoid aborting the test case.
            try {
                $connection->transaction(fn (Redis $redis) => throw new RedisException($message));
            } catch (RedisException $exception) {
                // Ignored on purpose.
            }

            // Connect a second time and compare the object hash of this and the old connection.
            $connection = getRedisSentinelConnection();
            $clientId2 = spl_object_hash($connection->client());

            expect($connection)
                ->toBeARedisSentinelConnection()
                ->toBeAWorkingRedisConnection()
                ->and($clientId)->not()->toEqual($clientId2);

            Event::assertDispatched(RedisSentinelConnectionFailed::class);
        });
    }

    test('it refreshes the client during retry on failed command', function () {
        Event::fake();

        $client1 = Mockery::mock(Redis::class);
        $client2 = Mockery::mock(Redis::class);

        // First call to get() will fail, second (after refresh) will succeed
        $client1->expects('get')->with('foo')->andThrow(new RedisException('broken pipe'));
        $client2->expects('get')->with('foo')->andReturns('bar');

        $callCount = 0;
        $connector = function () use (&$callCount, $client2) {
            $callCount++;

            return $client2;
        };

        $connection = new RedisSentinelConnection(
            $client1,
            $connector,
            ['sentinel' => ['retry' => ['attempts' => 1, 'delay' => 1]]]
        );
        $connection->setRetryMessages(['broken pipe']);
        $connection->setRetryLimit(1);

        expect($connection->get('foo'))->toBe('bar')
            ->and($callCount)->toBeGreaterThanOrEqual(1);

        Event::assertDispatched(RedisSentinelConnectionFailed::class);
        Event::assertDispatched(RedisSentinelConnectionReconnected::class);
    });

    test('flushdb is retried exactly retryLimit + 1 times and resets stickiness', function () {
        Event::fake();

        $client1 = Mockery::mock(Redis::class);
        $client2 = Mockery::mock(Redis::class);
        $client1->expects('flushdb')->once()->andThrow(new RedisException('broken pipe'));
        $client2->expects('flushdb')->once()->andThrow(new RedisException('broken pipe'));

        $connection = new RedisSentinelConnection($client1, fn () => $client2, []);
        $connection->setRetryLimit(1);
        $connection->setRetryDelay(1);
        $connection->setRetryMessages(['broken pipe']);

        $property = (new ReflectionClass($connection))->getProperty('wroteToMaster');
        $property->setValue($connection, true);

        expect(fn () => $connection->flushdb())->toThrow(RedisException::class)
            ->and($property->getValue($connection))->toBeFalse();
    });

    test('flushall routes once through the client and resets stickiness', function () {
        Event::fake();

        $client = Mockery::mock(Redis::class);
        $client->expects('flushall')->withNoArgs()->once()->andReturn(true);

        $connection = new RedisSentinelConnection($client, fn () => $client, []);

        $property = (new ReflectionClass($connection))->getProperty('wroteToMaster');
        $property->setValue($connection, true);

        expect($connection->flushall())->toBeTrue()
            ->and($property->getValue($connection))->toBeFalse();
    });

    test('Reconnecting after a manual fail over', function () {
        Event::fake();

        expect(getRedisSentinelConnection())
            ->toBeARedisSentinelConnection()
            ->toBeAWorkingRedisConnection();

        /**
         * @var $sentinel RedisSentinel
         */
        $sentinel = app()->make('redis.sentinel')->createSentinel('phpredis-sentinel');
        $host = implode('', $sentinel->getMasterAddrByName('master'));

        // Attempt failover - it may fail if a failover is already in progress or replicas aren't ready
        // In CI environments (cold runners), replicas may take a while to become "suitable",
        // so we retry generously before giving up (1s between attempts, 20s deadline).
        $failoverTriggered = (bool) waitFor(
            fn () => $sentinel->failover('master'),
            timeoutMs: 20000,
            intervalMs: 1000,
        );

        expect($failoverTriggered)->toBeTrue('Failover command should succeed after retries');

        // Invalidate the cache after failover to ensure the package will fetch the new master
        app(NodeAddressCache::class)->forget(sentinelNodeCacheKey());

        // Wait for Sentinel to converge on the new master (failover can take
        // up to ~30s on a fresh CI setup, so poll up to 30s).
        $host2 = waitFor(function () use ($sentinel, $host) {
            $currentMaster = $sentinel->getMasterAddrByName('master');
            $newHost = $currentMaster ? implode('', $currentMaster) : '';

            return $newHost !== $host && $newHost !== '' ? $newHost : null;
        }, timeoutMs: 30000, intervalMs: 100);

        expect($host2)->not()->toEqual($host);

        for ($i = 0; $i < 10; $i++) {
            expect(getRedisSentinelConnection())
                ->toBeARedisSentinelConnection()
                ->toBeAWorkingRedisConnection();

            Event::assertNotDispatched(RedisSentinelConnectionFailed::class);

            usleep(500); // retained: no observable condition (soak pacing)
        }
    });
});
