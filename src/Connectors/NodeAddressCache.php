<?php

namespace Goopil\LaravelRedisSentinel\Connectors;

final class NodeAddressCache
{
    private const MASTER_KEY = 'master';

    private const REPLICAS_KEY = 'replicas';

    private const DEFAULT_TTL = 300;

    /**
     * @var array<string, array{master: ?array{ip: string, port: int, cached_at: int}, replicas: array<array{ip: string, port: int}>, replicas_cached_at: int}>
     */
    protected array $nodes = [];

    protected int $ttl;

    public function __construct(int $ttl = self::DEFAULT_TTL)
    {
        $this->ttl = $ttl;
    }

    /**
     * Get the cached master address for a service.
     */
    public function get(string $service): ?array
    {
        $entry = $this->nodes[$service][self::MASTER_KEY] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($this->isExpired($entry['cached_at'] ?? 0)) {
            $this->forgetMaster($service);

            return null;
        }

        return ['ip' => $entry['ip'], 'port' => $entry['port']];
    }

    /**
     * Cache the master address for a service.
     */
    public function set(string $service, string $ip, int|string $port): void
    {
        $this->nodes[$service][self::MASTER_KEY] = [
            'ip' => $ip,
            'port' => (int) $port,
            'cached_at' => time(),
        ];
    }

    /**
     * Get the cached replica addresses for a service.
     */
    public function getReplicas(string $service): array
    {
        $replicas = $this->nodes[$service][self::REPLICAS_KEY] ?? [];
        $cachedAt = $this->nodes[$service]['replicas_cached_at'] ?? 0;

        if ($this->isExpired($cachedAt)) {
            $this->forgetReplicas($service);

            return [];
        }

        return $replicas;
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
        $this->nodes[$service]['replicas_cached_at'] = time();
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
        unset($this->nodes[$service][self::REPLICAS_KEY], $this->nodes[$service]['replicas_cached_at']);

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

    private function isExpired(int $cachedAt): bool
    {
        return $this->ttl > 0 && (time() - $cachedAt) > $this->ttl;
    }
}
