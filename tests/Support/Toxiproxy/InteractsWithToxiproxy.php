<?php

namespace Goopil\LaravelRedisSentinel\Tests\Support\Toxiproxy;

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Redis;
use RedisException;
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
        $sentinel = new Redis;

        try {
            $sentinel->connect('127.0.0.1', (int) (getenv('REDIS_SENTINEL_PORT') ?: 26379));
            $sentinel->auth(getenv('REDIS_SENTINEL_PASSWORD') ?: 'test');

            $reply = $sentinel->rawCommand('SENTINEL', 'get-master-addr-by-name', 'master');
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

        while (microtime(true) < $deadline) {
            try {
                $address = $this->sentinelMasterAddress();

                if ($address !== $oldAddress) {
                    return $address;
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
}
