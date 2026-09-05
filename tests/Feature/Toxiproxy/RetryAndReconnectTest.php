<?php

use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionFailed;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionMaxRetryFailed;
use Illuminate\Support\Facades\Event;

describe('Retries and reconnections under network faults', function () {
    test('timeout toxic triggers retries then recovers when removed', function () {
        // The master-* events only cover Sentinel-side retries; a faulted master
        // command emits the connection-level failure event instead
        Event::fake([RedisSentinelConnectionFailed::class]);

        $connection = $this->sentinelConnectionWithRetry();
        $masterProxy = $this->masterProxyName();

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
        $connection = $this->sentinelConnectionWithRetry();
        $masterProxy = $this->masterProxyName();

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
        // The mandatory reconnect inside the retry onFail hook is swallowed when
        // the master stays unreachable, so the retry loop runs to exhaustion and
        // both the per-attempt failure event and the max-retry event fire
        Event::fake([RedisSentinelConnectionFailed::class, RedisSentinelConnectionMaxRetryFailed::class]);

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

            if ($address === $stable && $this->proxyCarriesTraffic($address['port'])) {
                break;
            }

            $stable = $address;
            usleep(1_000_000);
        }

        $this->purgeSentinelConnection();

        $connection = $this->sentinelConnectionWithRetry();
        $masterProxy = $this->masterProxyName();

        $this->toxiproxy->disable($masterProxy);

        // toxiproxy's link teardown on disable is asynchronous under connection churn
        // and can leave the established socket alive, so sever the client side
        // explicitly: the asserted command must then reconnect, and new connections
        // through the disabled proxy are refused deterministically
        $connection->disconnect();

        expect(fn () => $connection->set('chaos_max_retry', 'x'))->toThrow(RedisException::class);
        Event::assertDispatched(RedisSentinelConnectionFailed::class);
        Event::assertDispatched(RedisSentinelConnectionMaxRetryFailed::class);

        $this->toxiproxy->enable($masterProxy);
        $this->waitForProxyReady($this->connectedMasterPort());

        expect($connection->set('chaos_max_retry', 'recovered'))->toBeTrue();
    });
});
