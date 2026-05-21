<?php

namespace Goopil\LaravelRedisSentinel\Connectors;

final class NodeAddressCache
{
    private const MASTER_KEY = 'master';

    private const REPLICAS_KEY = 'replicas';

    /**
     * @var array<string, array{master: ?array{ip: string, port: int}, replicas: array<array{ip: string, port: int}>}>
     */
    protected array $nodes = [];

    /**
     * Get the cached master address for a service.
     */
    public function get(string $service): ?array
    {
        return $this->nodes[$service][self::MASTER_KEY] ?? null;
    }

    /**
     * Cache the master address for a service.
     */
    public function set(string $service, string $ip, int|string $port): void
    {
        $this->nodes[$service][self::MASTER_KEY] = [
            'ip' => $ip,
            'port' => (int) $port,
        ];
    }

    /**
     * Get the cached replica addresses for a service.
     */
    public function getReplicas(string $service): array
    {
        return $this->nodes[$service][self::REPLICAS_KEY] ?? [];
    }

    /**
     * Cache the replica addresses for a service.
     */
    public function setReplicas(string $service, array $replicas): void
    {
        $this->nodes[$service][self::REPLICAS_KEY] = array_map(static fn ($r) => [
            'ip' => $r['ip'] ?? $r[0],
            'port' => (int) ($r['port'] ?? $r[1]),
        ], $replicas);
    }

    /**
     * Remove the cached master address for a service.
     */
    public function forgetMaster(string $service): void
    {
        unset($this->nodes[$service][self::MASTER_KEY]);

        if (($this->nodes[$service] ?? []) === []) {
            unset($this->nodes[$service]);
        }
    }

    /**
     * Remove the cached replica addresses for a service.
     */
    public function forgetReplicas(string $service): void
    {
        unset($this->nodes[$service][self::REPLICAS_KEY]);

        if (($this->nodes[$service] ?? []) === []) {
            unset($this->nodes[$service]);
        }
    }

    /**
     * Remove a service from the cache.
     */
    public function forget(string $service): void
    {
        unset($this->nodes[$service]);
    }

    /**
     * Clear all cached master addresses.
     */
    public function flush(): void
    {
        $this->nodes = [];
    }
}
