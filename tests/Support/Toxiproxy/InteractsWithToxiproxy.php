<?php

namespace Goopil\LaravelRedisSentinel\Tests\Support\Toxiproxy;

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Illuminate\Redis\Connections\Connection;
use Redis;
use RedisException;
use RedisSentinel;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Members for chaos tests against the toxiproxy overlay stack.
 *
 * Pest closure tests cannot extend a custom test case with a working setUp()
 * (Pest's Testable trait shadows it), so the suite is wired through this trait
 * plus beforeEach/afterEach hooks in tests/Pest.php.
 */
trait InteractsWithToxiproxy
{
    protected ToxiproxyManager $toxiproxy;

    public function bootToxiproxy(): void
    {
        $this->toxiproxy = ToxiproxyManager::fromEnv();

        if (! $this->toxiproxy->isAvailable()) {
            $this->markTestSkipped(
                'Toxiproxy is not available. Start the chaos stack: docker compose -f docker-compose.yml -f docker-compose.chaos.yml up -d'
            );
        }

        $this->resetChaosTopology();
    }

    public function cleanupToxiproxy(): void
    {
        if ($this->toxiproxy->isAvailable()) {
            $this->resetChaosTopology();
        }
    }

    protected function resetChaosTopology(): void
    {
        $this->toxiproxy->resetAll();
        app(NodeAddressCache::class)->flush();
    }

    /**
     * @return array{ip: string, port: int}
     */
    protected function sentinelMasterAddress(): array
    {
        $service = (string) config('database.redis.phpredis-sentinel.sentinel.service', 'master');
        $sentinel = new Redis;

        try {
            $sentinel->connect('127.0.0.1', (int) (getenv('REDIS_SENTINEL_PORT') ?: 26379));
            $sentinel->auth(getenv('REDIS_SENTINEL_PASSWORD') ?: 'test');

            $reply = $sentinel->rawCommand('SENTINEL', 'get-master-addr-by-name', $service);
        } catch (RedisException $exception) {
            throw new RuntimeException('Cannot reach Sentinel: '.$exception->getMessage(), 0, $exception);
        } finally {
            try {
                $sentinel->close();
            } catch (RedisException) {
                // close() on a never-connected client throws in phpredis 6+; the original connect error must surface
            }
        }

        if (! is_array($reply) || count($reply) !== 2) {
            throw new RuntimeException('Unexpected SENTINEL reply: '.var_export($reply, true));
        }

        return ['ip' => (string) $reply[0], 'port' => (int) $reply[1]];
    }

    /**
     * @param  array{ip: string, port: int}  $oldAddress
     * @return array{ip: string, port: int}
     */
    protected function waitForMasterChange(array $oldAddress, int $timeoutSeconds = 30): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $candidate = null;
        $candidateSince = 0.0;

        while (microtime(true) < $deadline) {
            try {
                $address = $this->sentinelMasterAddress();

                if ($address !== $oldAddress) {
                    $now = microtime(true);

                    if ($address !== $candidate) {
                        $candidate = $address;
                        $candidateSince = $now;
                    } elseif ($now - $candidateSince >= 1.0 && $this->proxyCarriesTraffic($address['port'])) {
                        return $address;
                    }
                }
            } catch (Throwable) {
                // Sentinel momentarily unreachable - keep polling
            }

            usleep(250_000);
        }

        throw new RuntimeException("Sentinel did not promote a new master within {$timeoutSeconds}s");
    }

    protected function waitForReplicaRole(int $port, string $role, int $timeoutSeconds = 20): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            try {
                $redis = new Redis;
                $redis->connect('127.0.0.1', $port);
                $redis->auth(getenv('REDIS_PASSWORD') ?: 'test');

                preg_match('/^role:(\w+)/m', (string) $redis->rawCommand('INFO', 'replication'), $matches);
                $redis->close();

                if (($matches[1] ?? null) === $role) {
                    return true;
                }
            } catch (RedisException) {
                // Node busy or reconnecting - keep polling
            }

            usleep(250_000);
        }

        return false;
    }

    protected function purgeSentinelConnection(): void
    {
        $manager = app(RedisSentinelManager::class);

        $reflection = new ReflectionClass($manager);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setValue($manager, config('database.redis'));

        $manager->purge('phpredis-sentinel');
    }

    /**
     * Sentinel announces the proxied node ports (replica-announce-port), so an
     * announced master or replica port doubles as the toxiproxy listen port of
     * the cuttable proxy.
     */
    public function proxyNameForPort(int $port): string
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

    /**
     * The proxy listen port is distinct from the node port behind it: some probes
     * must reach the node directly, bypassing toxiproxy and any attached toxic.
     */
    public function nodePortForProxyPort(int $proxyPort): int
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

    /**
     * The proxy must be derived from the address the established connection actually
     * resolved (NodeAddressCache is set at connect time and refreshed by its
     * reconnects): reading Sentinel separately before connecting races against
     * in-flight failovers left over from sibling tests, and would cut a proxy the
     * connection never traverses.
     */
    public function connectedMasterPort(): int
    {
        $cached = app(NodeAddressCache::class)->get(sentinelNodeCacheKey());

        if ($cached === null) {
            throw new RuntimeException('NodeAddressCache holds no master address for the established connection.');
        }

        return $cached['port'];
    }

    public function masterProxyName(): string
    {
        return $this->proxyNameForPort($this->connectedMasterPort());
    }

    /**
     * The proxies whose announced ports differ from the connected master's: with a
     * converged Sentinel view these are exactly the proxies the read-splitting read
     * client may be routed through.
     *
     * @return array<int, string>
     */
    public function replicaProxyNames(): array
    {
        $masterProxy = $this->masterProxyName();

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
     * The beforeEach resetAll tears down and recreates every proxy listener, and the
     * very first connect through a just-recreated listener can be refused through the
     * host port forwarding, so establish the connection with a bounded retry.
     */
    public function sentinelConnectionWithRetry(int $attempts = 5): Connection
    {
        $lastException = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                return tap(
                    app('redis')->connection('phpredis-sentinel'),
                    fn (Connection $connection) => $connection->ping()
                );
            } catch (Throwable $exception) {
                $lastException = $exception;
                usleep(100_000);
            }
        }

        throw $lastException;
    }

    /**
     * One-shot health probe against the proxy listen port with a raw client.
     */
    public function proxyCarriesTraffic(int $listenPort): bool
    {
        try {
            $redis = new Redis;
            $redis->connect('127.0.0.1', $listenPort, 1.0);
            $redis->auth(getenv('REDIS_PASSWORD') ?: 'test');

            return $redis->ping() !== false;
        } catch (RedisException) {
            return false;
        }
    }

    /**
     * Re-enabling a toxiproxy proxy recreates its listener, and the very first
     * connects through the host port forwarding can be refused, so wait until the
     * proxy carries traffic before asserting recovery behavior.
     */
    public function waitForProxyReady(int $listenPort, int $timeoutSeconds = 5): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($this->proxyCarriesTraffic($listenPort)) {
                return;
            }

            usleep(100_000);
        }

        throw new RuntimeException("Proxy on port {$listenPort} did not carry traffic within {$timeoutSeconds}s.");
    }

    /**
     * Re-issue a command after a failover: the connection-level retry aborts on the
     * first refused reconnect by design, and while Sentinel is still settling it can
     * transiently re-serve the disabled master. A real application re-emits the
     * command, so the chaos tests do the same instead of asserting on one shot.
     */
    public function reissueUntilSuccess(callable $command, int $attempts = 5, int $delayMs = 500): mixed
    {
        $lastException = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                return $command();
            } catch (Throwable $exception) {
                $lastException = $exception;
                usleep($delayMs * 1000);
            }
        }

        throw $lastException;
    }

    /**
     * Blocks until Sentinel reports two slaves without s_down/o_down/disconnected
     * flags, because a read-split connection created while the links severed by the
     * beforeEach proxy reset are still re-establishing would read from the master
     * (RedisSentinelConnector falls back to the master when no healthy replica exists).
     */
    public function waitForHealthyReplicas(int $timeoutSeconds = 20): void
    {
        $service = (string) config('database.redis.phpredis-sentinel.sentinel.service', 'master');
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            try {
                $sentinel = new RedisSentinel([
                    'host' => '127.0.0.1',
                    'port' => (int) (getenv('REDIS_SENTINEL_PORT') ?: 26379),
                    'auth' => getenv('REDIS_SENTINEL_PASSWORD') ?: 'test',
                    'connectTimeout' => 0.2,
                ]);

                $healthy = array_filter((array) $sentinel->slaves($service), static fn ($slave): bool => ! str_contains(
                    (string) ($slave['flags'] ?? ''),
                    'disconnected'
                ) && ! str_contains((string) ($slave['flags'] ?? ''), 's_down'));

                if (count($healthy) >= 2) {
                    return;
                }
            } catch (RedisException) {
                // Sentinel busy or momentarily unreachable - keep polling
            }

            usleep(250_000);
        }

        throw new RuntimeException('Sentinel did not report two healthy replicas within '.$timeoutSeconds.'s');
    }
}
