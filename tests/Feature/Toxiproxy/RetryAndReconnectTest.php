<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionFailed;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

describe('Retries and reconnections under network faults', function () {
    test('timeout toxic triggers retries then recovers when removed', function () {
        // The master-* events only cover Sentinel-side retries; a faulted master
        // command emits the connection-level failure event (RedisSentinelConnection.php:320)
        Event::fake([RedisSentinelConnectionFailed::class]);

        $connection = chaosSentinelConnectionWithRetry();
        $masterProxy = chaosProxyNameForNodePort(chaosConnectedMasterPort());

        expect($connection->set('chaos_timeout', 'ok'))->toBeTrue();

        $toxic = $this->toxiproxy->addTimeout($masterProxy, 800);

        try {
            $connection->set('chaos_timeout', 'during');
        } catch (Throwable) {
            // Acceptable: the attempt aborted while the toxic stalled the master
        }

        Event::assertDispatched(RedisSentinelConnectionFailed::class);

        $this->toxiproxy->removeToxic($masterProxy, $toxic);

        expect($connection->set('chaos_timeout', 'recovered'))->toBeTrue()
            ->and($connection->get('chaos_timeout'))->toBe('recovered');
    });

    test('connection reset by peer is transparently re-established', function () {
        $connection = chaosSentinelConnectionWithRetry();
        $masterProxy = chaosProxyNameForNodePort(chaosConnectedMasterPort());

        expect($connection->set('chaos_reset', 'before'))->toBeTrue();

        $toxic = $this->toxiproxy->addResetPeer($masterProxy);

        try {
            $connection->get('chaos_reset');
        } catch (Throwable) {
            // Expected: the first command rides the connection killed by the toxic
        }

        // reset_peer is not one-shot: it stays attached and resets every new
        // connection through the proxy, so remove it to make recovery deterministic
        $this->toxiproxy->removeToxic($masterProxy, $toxic);

        expect($connection->set('chaos_reset', 'after'))->toBeTrue()
            ->and($connection->get('chaos_reset'))->toBe('after');
    });

    test('master outage surfaces the connection failure event and exception then recovers', function () {
        // With the master proxy disabled, the mandatory reconnect inside the retry
        // onFail hook throws before onMaxFail can run (Retryable.php:69-89), so no
        // MaxRetryFailed event can fire: the per-attempt failure event is the
        // genuine proof that a retry was attempted during the outage
        Event::fake([RedisSentinelConnectionFailed::class]);

        config()->set('phpredis-sentinel.retry.redis.attempts', 2);
        config()->set('phpredis-sentinel.retry.sentinel.attempts', 2);

        // A failover set off by an earlier test's outage may still be in election:
        // Sentinel keeps reporting the down master while it elects a successor, so
        // require the reported master to carry traffic and its address to hold
        // steady, otherwise a promotion completing mid-test would hand the command
        // a reachable new master and void the outage
        $stable = null;
        $deadline = microtime(true) + 20;

        while (microtime(true) < $deadline) {
            $address = $this->sentinelMasterAddress();

            if ($address === $stable && chaosProxyCarriesTraffic($address['port'])) {
                break;
            }

            $stable = $address;
            usleep(1_000_000);
        }

        $this->purgeSentinelConnection();

        $connection = chaosSentinelConnectionWithRetry();
        $masterProxy = chaosProxyNameForNodePort(chaosConnectedMasterPort());

        $this->toxiproxy->disable($masterProxy);

        // toxiproxy's link teardown on disable is asynchronous under connection churn
        // and can leave the established socket alive, so sever the client side
        // explicitly: the asserted command must then reconnect, and new connections
        // through the disabled proxy are refused deterministically
        $connection->disconnect();

        expect(fn () => $connection->set('chaos_max_retry', 'x'))->toThrow(RedisException::class);
        Event::assertDispatched(RedisSentinelConnectionFailed::class);

        $this->toxiproxy->enable($masterProxy);
        chaosWaitForProxyReady(chaosConnectedMasterPort());

        expect($connection->set('chaos_max_retry', 'recovered'))->toBeTrue();
    });
});

/**
 * Sentinel announces the proxied node ports (replica-announce-port), so the master
 * address port doubles as the toxiproxy listen port of the cuttable proxy. Distinct
 * from FailoverTest's chaosProxyForMasterPort because Pest only includes the test
 * files matching the invoked path, leaving sibling file-level helpers undefined on
 * single-file runs.
 */
function chaosProxyNameForNodePort(int $port): string
{
    $proxies = [
        (int) (getenv('REDIS_MAIN_PROXY_PORT') ?: 16380) => 'main',
        (int) (getenv('REDIS_REPLICA1_PROXY_PORT') ?: 16381) => 'replica1',
        (int) (getenv('REDIS_REPLICA2_PROXY_PORT') ?: 16382) => 'replica2',
    ];

    if (! isset($proxies[$port])) {
        throw new RuntimeException("No toxiproxy proxy listens for node port {$port}.");
    }

    return $proxies[$port];
}

/**
 * The beforeEach resetAll tears down and recreates every proxy listener, and the
 * very first connect through a just-recreated listener can be refused through the
 * host port forwarding, so establish the connection with a bounded retry.
 */
function chaosSentinelConnectionWithRetry(int $attempts = 5): Connection
{
    $lastException = null;

    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        try {
            return tap(Redis::connection('phpredis-sentinel'), fn (Connection $connection) => $connection->ping());
        } catch (Throwable $exception) {
            $lastException = $exception;
            usleep(100_000);
        }
    }

    throw $lastException;
}

/**
 * The proxy must be derived from the address the established connection actually
 * resolved (NodeAddressCache is set at connect time and refreshed by its reconnects):
 * reading Sentinel separately before connecting races against in-flight failovers
 * left over from sibling tests, and would cut a proxy the connection never traverses.
 */
function chaosConnectedMasterPort(): int
{
    $service = (string) config('database.redis.phpredis-sentinel.sentinel.service', 'master');
    $cached = app(NodeAddressCache::class)->get($service);

    if ($cached === null) {
        throw new RuntimeException('NodeAddressCache holds no master address for the established connection.');
    }

    return $cached['port'];
}

/**
 * Re-enabling a toxiproxy proxy recreates its listener, and the very first connects
 * through the host port forwarding can be refused, so wait until the proxy carries
 * traffic before asserting recovery behavior.
 */
function chaosWaitForProxyReady(int $listenPort, int $timeoutSeconds = 5): void
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        if (chaosProxyCarriesTraffic($listenPort)) {
            return;
        }

        usleep(100_000);
    }

    throw new RuntimeException("Proxy on port {$listenPort} did not carry traffic within {$timeoutSeconds}s.");
}

/**
 * One-shot health probe against the proxy listen port with a raw client.
 */
function chaosProxyCarriesTraffic(int $listenPort): bool
{
    try {
        $redis = new \Redis;
        $redis->connect('127.0.0.1', $listenPort, 1.0);
        $redis->auth(getenv('REDIS_PASSWORD') ?: 'test');

        return $redis->ping() !== false;
    } catch (RedisException) {
        return false;
    }
}
