<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

describe('Network degradation toxics', function () {
    test('replica latency keeps read/write splitting functional', function () {
        // Seed on the default write-only connection: on a read-split connection a
        // write sticks later reads to the master, so the timed read must be the first
        // command of a freshly purged read-split connection to traverse a replica proxy
        expect(degradationSentinelConnectionWithRetry()->set('chaos_latency', 'split'))->toBeTrue();

        // The read client is picked at random among Sentinel's healthy replicas, so
        // every proxy except the connected master's must be delayed for the
        // measurement to hold whichever replica serves the read
        foreach (degradationReplicaProxyNames() as $replicaProxy) {
            $this->toxiproxy->addLatency($replicaProxy, 300);
        }

        // The beforeEach resetAll severs Sentinel's monitoring links to the replicas
        // (they are announced through the proxies), and while Sentinel still flags
        // them disconnected the connector silently falls back to the master
        degradationWaitForHealthyReplicas();

        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);
        $this->purgeSentinelConnection();

        $connection = Redis::connection('phpredis-sentinel');

        $start = microtime(true);
        $read = null;
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            try {
                if (($read = $connection->get('chaos_latency')) === 'split') {
                    break;
                }
            } catch (Throwable) {
                $read = null;
            }

            usleep(200_000);
        }

        $elapsed = microtime(true) - $start;

        expect($read)->toBe('split')
            ->and($elapsed)->toBeGreaterThan(0.2, 'Reads should be measurably delayed by the latency toxic')
            ->and($connection->set('chaos_latency', 'still-split'))->toBeTrue('Writes are unaffected by replica latency');
    });

    test('large payload survives slicer toxic', function () {
        $connection = degradationSentinelConnectionWithRetry();

        // The payload only traverses the proxy the established connection actually
        // resolved, so slice that one instead of assuming the 'main' proxy
        $this->toxiproxy->addSlicer(degradationMasterProxyName(), 4096, 512, 0);

        $payload = bin2hex(random_bytes(512 * 1024)); // ~1 MB

        expect($connection->set('chaos_payload', $payload))->toBeTrue()
            ->and($connection->get('chaos_payload'))->toBe($payload);
    });
});

/**
 * The beforeEach resetAll tears down and recreates every proxy listener, and the
 * very first connect through a just-recreated listener can be refused through the
 * host port forwarding, so establish the connection with a bounded retry.
 */
function degradationSentinelConnectionWithRetry(int $attempts = 5): Connection
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
 * Sentinel announces the proxied node ports (replica-announce-port), so the master
 * address cached at connect time doubles as the toxiproxy listen port of the
 * cuttable payload path. Distinct from the sibling files' helpers because Pest only
 * includes the test files matching the invoked path, leaving sibling file-level
 * helpers undefined on single-file runs.
 */
function degradationConnectedMasterPort(): int
{
    $service = (string) config('database.redis.phpredis-sentinel.sentinel.service', 'master');
    $cached = app(NodeAddressCache::class)->get($service);

    if ($cached === null) {
        throw new RuntimeException('NodeAddressCache holds no master address for the established connection.');
    }

    return $cached['port'];
}

function degradationProxyNameForAnnouncedPort(int $port): string
{
    $proxies = [
        (int) (getenv('REDIS_MAIN_PROXY_PORT') ?: 16380) => 'main',
        (int) (getenv('REDIS_REPLICA1_PROXY_PORT') ?: 16381) => 'replica1',
        (int) (getenv('REDIS_REPLICA2_PROXY_PORT') ?: 16382) => 'replica2',
    ];

    if (! isset($proxies[$port])) {
        throw new RuntimeException("No toxiproxy proxy listens for announced port {$port}.");
    }

    return $proxies[$port];
}

function degradationMasterProxyName(): string
{
    return degradationProxyNameForAnnouncedPort(degradationConnectedMasterPort());
}

/**
 * The proxies whose announced ports differ from the connected master's: with a
 * converged Sentinel view these are exactly the proxies the read-splitting read
 * client may be routed through.
 *
 * @return array<int, string>
 */
function degradationReplicaProxyNames(): array
{
    $masterProxy = degradationMasterProxyName();

    $proxies = array_values(array_filter(
        ['main', 'replica1', 'replica2'],
        fn (string $proxy): bool => $proxy !== $masterProxy
    ));

    if (count($proxies) !== 2) {
        throw new RuntimeException("Could not derive replica proxies for master proxy {$masterProxy}.");
    }

    return $proxies;
}

/**
 * Blocks until Sentinel reports two slaves without s_down/o_down/disconnected
 * flags, because a read-split connection created while the links severed by the
 * beforeEach proxy reset are still re-establishing would read from the master
 * (RedisSentinelConnector falls back to the master when no healthy replica exists).
 */
function degradationWaitForHealthyReplicas(int $timeoutSeconds = 20): void
{
    $sentinel = new RedisSentinel([
        'host' => '127.0.0.1',
        'port' => (int) (getenv('REDIS_SENTINEL_PORT') ?: 26379),
        'auth' => getenv('REDIS_SENTINEL_PASSWORD') ?: 'test',
        'connectTimeout' => 0.2,
    ]);

    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        $healthy = array_filter((array) $sentinel->slaves('master'), static fn ($slave): bool => ! str_contains(
            (string) ($slave['flags'] ?? ''),
            'disconnected'
        ) && ! str_contains((string) ($slave['flags'] ?? ''), 's_down'));

        if (count($healthy) >= 2) {
            return;
        }

        usleep(250_000);
    }

    throw new RuntimeException('Sentinel did not report two healthy replicas within '.$timeoutSeconds.'s');
}
