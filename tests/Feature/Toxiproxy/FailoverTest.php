<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionReconnected;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

describe('Real Sentinel failover through toxiproxy', function () {
    test('client reconnects to the promoted master after the master proxy is cut', function () {
        Event::fake([RedisSentinelConnectionReconnected::class]);

        $connection = Redis::connection('phpredis-sentinel');
        expect($connection->set('chaos_failover', 'before'))->toBeTrue();

        $oldAddress = $this->sentinelMasterAddress();

        $this->toxiproxy->disable($this->proxyNameForPort($oldAddress['port']));
        $newAddress = $this->waitForMasterChange($oldAddress);

        expect($newAddress['port'])->not->toBe($oldAddress['port']);

        // On CI, host port forwarding can refuse connects for several seconds after a
        // proxy listener is (re)created, even past a first successful probe - the
        // failover below promotes a node whose proxy is freshly recreated, so re-verify
        // traffic and give the retry a wide window before reissuing writes
        $this->waitForProxyReady($newAddress['port'], 10);

        expect($this->reissueUntilSuccess(fn () => $connection->set('chaos_failover', 'after'), attempts: 20))->toBeTrue()
            ->and($connection->get('chaos_failover'))->toBe('after');

        Event::assertDispatched(RedisSentinelConnectionReconnected::class);

        // Sentinel can only demote the old master once its proxy is reachable again
        $this->toxiproxy->enable($this->proxyNameForPort($oldAddress['port']));

        expect($this->waitForReplicaRole($this->nodePortForProxyPort($oldAddress['port']), 'slave', 30))
            ->toBeTrue('Old master should be demoted to replica');
    });

    test('stale master connection retries and lands on the promoted master', function () {
        $connection = Redis::connection('phpredis-sentinel');
        expect($connection->set('chaos_stale', 'v1'))->toBeTrue();

        $oldAddress = $this->sentinelMasterAddress();

        $this->toxiproxy->disable($this->proxyNameForPort($oldAddress['port']));
        $newAddress = $this->waitForMasterChange($oldAddress);

        // Reuse the SAME connection object: its established client still points at the
        // cut master, so the connector must retry the failed write and re-resolve the
        // promoted master through Sentinel. Its proxy listener was freshly recreated by
        // beforeEach resetAll (and by the previous test's re-enable), and CI host port
        // forwarding can refuse connects for seconds - re-verify traffic and widen the
        // retry window, or the reconnect-in-onFail exception exhausts the reissue budget
        $this->waitForProxyReady($newAddress['port'], 10);

        expect($this->reissueUntilSuccess(fn () => $connection->set('chaos_stale', 'v2'), attempts: 20))->toBeTrue()
            ->and($connection->get('chaos_stale'))->toBe('v2');

        $cached = app(NodeAddressCache::class)->get(sentinelNodeCacheKey());
        expect($cached['port'])->toBe($newAddress['port'], 'NodeAddressCache must point at the promoted master');

        app('redis')->purge('phpredis-sentinel');
        expect(Redis::connection('phpredis-sentinel')->get('chaos_stale'))->toBe('v2');
    });

    test('stale node address cache writes to the demoted master, hits READONLY and recovers', function () {
        Event::fake([RedisSentinelConnectionReconnected::class]);

        $connection = Redis::connection('phpredis-sentinel');
        expect($connection->set('chaos_readonly', 'v1'))->toBeTrue();

        $oldAddress = $this->sentinelMasterAddress();

        $this->toxiproxy->disable($this->proxyNameForPort($oldAddress['port']));
        $newAddress = $this->waitForMasterChange($oldAddress);

        // Sentinel can only demote the old master once its proxy is reachable again
        $this->toxiproxy->enable($this->proxyNameForPort($oldAddress['port']));
        expect($this->waitForReplicaRole($this->nodePortForProxyPort($oldAddress['port']), 'slave', 30))
            ->toBeTrue('Old master should be demoted to replica');

        // Prime the in-process address cache with the demoted node, mimicking a
        // long-running process (Octane, queue workers) whose cached master address
        // survived the failover, then force a fresh connection to read that cache
        app(NodeAddressCache::class)->set(sentinelNodeCacheKey(), $oldAddress['ip'], $oldAddress['port']);
        $this->purgeSentinelConnection();

        $stale = Redis::connection('phpredis-sentinel');

        // The write targets the demoted node: phpredis 6 raises the -READONLY server
        // error, a retryable message, so the retry must re-resolve the promoted master
        // through Sentinel instead of surfacing the failure
        expect($stale->set('chaos_readonly', 'v2'))->toBeTrue()
            ->and($stale->get('chaos_readonly'))->toBe('v2');

        Event::assertDispatched(RedisSentinelConnectionReconnected::class);

        $cached = app(NodeAddressCache::class)->get(sentinelNodeCacheKey());
        expect($cached['port'])->toBe($newAddress['port'], 'Cache must be refreshed to the promoted master');
    });

    test('reads keep working from replicas while the master is unreachable', function () {
        $connection = Redis::connection('phpredis-sentinel');
        expect($connection->set('chaos_reads', 'survives'))->toBeTrue();

        // Replication travels over the same proxies the suite cuts and resets between
        // tests, so hold the cut until both replicas acknowledged the seed write,
        // otherwise the promoted master could miss the key entirely.
        // The WAIT timeout (500ms) must stay below the connection's read_timeout (1s):
        // a blocked WAIT can otherwise hit the phpredis read timeout and throw
        // "read error on connection" for as long as replicas are still re-syncing
        // after the previous tests' failovers
        $deadline = microtime(true) + 20;

        while (microtime(true) < $deadline) {
            try {
                if ((int) $connection->wait(2, 500) >= 2) {
                    break;
                }
            } catch (Throwable) {
                // The connection can drop while replicas are still re-syncing - retry
            }

            usleep(100_000);
        }

        // Seed the key on a write-only connection: on a read-split connection a write
        // sticks later reads to the master, which toxiproxy is about to cut
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);
        $this->purgeSentinelConnection();
        $connection = Redis::connection('phpredis-sentinel');

        $oldAddress = $this->sentinelMasterAddress();
        $this->toxiproxy->disable($this->proxyNameForPort($oldAddress['port']));

        $read = null;
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            try {
                if (($read = $connection->get('chaos_reads')) === 'survives') {
                    break;
                }
            } catch (Throwable) {
                $read = null;
            }

            usleep(200_000);
        }

        expect($read)->toBe('survives');

        $this->waitForMasterChange($oldAddress);
        expect($connection->get('chaos_reads'))->toBe('survives');
    });
});
