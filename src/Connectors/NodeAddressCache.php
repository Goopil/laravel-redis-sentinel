<?php

namespace Goopil\LaravelRedisSentinel\Connectors;

class NodeAddressCache
{
    /**
     * @var array<string, array{master: ?array{ip: string, port: int}, replicas: array<array{ip: string, port: int}>}>
     */
    protected array $nodes = [];

    /**
     * Get the cached master address for a service.
     */
    public function get(string $service): ?array
    {
        return $this->nodes[$service]['master'] ?? null;
    }

    /**
     * Cache the master address for a service.
     */
    public function set(string $service, string $ip, int|string $port): void
    {
        $this->nodes[$service]['master'] = [
            'ip' => $ip,
            'port' => (int) $port,
        ];
    }

    /**
     * Get the cached replica addresses for a service.
     */
    public function getReplicas(string $service): array
    {
        return $this->nodes[$service]['replicas'] ?? [];
    }

    /**
     * Cache the replica addresses for a service.
     */
    public function setReplicas(string $service, array $replicas): void
    {
        $this->nodes[$service]['replicas'] = array_map(static fn ($r) => [
            'ip' => $r['ip'] ?? $r[0],
            'port' => (int) ($r['port'] ?? $r[1]),
        ], $replicas);
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
