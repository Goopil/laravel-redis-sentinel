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

        $this->toxiproxy->disable(chaosProxyForMasterPort($oldAddress['port']));
        $newAddress = $this->waitForMasterChange($oldAddress);

        expect($newAddress['port'])->not->toBe($oldAddress['port']);

        expect($connection->set('chaos_failover', 'after'))->toBeTrue()
            ->and($connection->get('chaos_failover'))->toBe('after');

        Event::assertDispatched(RedisSentinelConnectionReconnected::class);

        // Sentinel can only demote the old master once its proxy is reachable again
        $this->toxiproxy->enable(chaosProxyForMasterPort($oldAddress['port']));

        expect($this->waitForReplicaRole(chaosNodePortForProxyPort($oldAddress['port']), 'slave', 30))
            ->toBeTrue('Old master should be demoted to replica');
    });

    test('stale master connection retries and lands on the promoted master', function () {
        $connection = Redis::connection('phpredis-sentinel');
        expect($connection->set('chaos_stale', 'v1'))->toBeTrue();

        $oldAddress = $this->sentinelMasterAddress();

        $this->toxiproxy->disable(chaosProxyForMasterPort($oldAddress['port']));
        $newAddress = $this->waitForMasterChange($oldAddress);

        // Reuse the SAME connection object: its established client still points at the
        // cut master, so the connector must retry the failed write and re-resolve the
        // promoted master through Sentinel
        expect($connection->set('chaos_stale', 'v2'))->toBeTrue()
            ->and($connection->get('chaos_stale'))->toBe('v2');

        $cached = app(NodeAddressCache::class)->get('master');
        expect($cached['port'])->toBe($newAddress['port'], 'NodeAddressCache must point at the promoted master');

        app('redis')->purge('phpredis-sentinel');
        expect(Redis::connection('phpredis-sentinel')->get('chaos_stale'))->toBe('v2');
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
        while (microtime(true) < $deadline && (int) $connection->wait(2, 500) < 2) {
            usleep(100_000);
        }

        // Seed the key on a write-only connection: on a read-split connection a write
        // sticks later reads to the master, which toxiproxy is about to cut
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);
        $this->purgeSentinelConnection();
        $connection = Redis::connection('phpredis-sentinel');

        $oldAddress = $this->sentinelMasterAddress();
        $this->toxiproxy->disable(chaosProxyForMasterPort($oldAddress['port']));

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

function chaosProxyForMasterPort(int $port): string
{
    $proxies = [
        (int) (getenv('REDIS_MAIN_PROXY_PORT') ?: 16380) => 'main',
        (int) (getenv('REDIS_REPLICA1_PROXY_PORT') ?: 16381) => 'replica1',
        (int) (getenv('REDIS_REPLICA2_PROXY_PORT') ?: 16382) => 'replica2',
    ];

    if (! isset($proxies[$port])) {
        throw new RuntimeException("No toxiproxy proxy listens for master port {$port}.");
    }

    return $proxies[$port];
}

function chaosNodePortForProxyPort(int $proxyPort): int
{
    $nodes = [
        (int) (getenv('REDIS_MAIN_PROXY_PORT') ?: 16380) => (int) (getenv('REDIS_MAIN_PORT') ?: 6380),
        (int) (getenv('REDIS_REPLICA1_PROXY_PORT') ?: 16381) => (int) (getenv('REDIS_REPLICA1_PORT') ?: 6381),
        (int) (getenv('REDIS_REPLICA2_PROXY_PORT') ?: 16382) => (int) (getenv('REDIS_REPLICA2_PORT') ?: 6382),
    ];

    if (! isset($nodes[$proxyPort])) {
        throw new RuntimeException("No redis node port maps to proxy port {$proxyPort}.");
    }

    return $nodes[$proxyPort];
}
