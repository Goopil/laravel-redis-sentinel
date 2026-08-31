<?php

namespace Goopil\LaravelRedisSentinel\Connectors;

final class NodeAddressCache
{
    private const MASTER_KEY = 'master';

    private const REPLICAS_KEY = 'replicas';

    /**
     * @var array<string, array<string, array{value: mixed, expires_at: ?float}>>
     */
    protected array $nodes = [];

    public function __construct(protected float $ttlSeconds = 0.0) {}

    /**
     * Get the cached master address for a service.
     *
     * @return array{ip: string, port: int}|null
     */
    public function get(string $service): ?array
    {
        $entry = $this->nodes[$service][self::MASTER_KEY] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($this->isExpired($entry['expires_at'])) {
            $this->forgetMaster($service);

            return null;
        }

        return $entry['value'];
    }

    public function set(string $service, string $ip, int|string $port): void
    {
        $this->nodes[$service][self::MASTER_KEY] = [
            'value' => ['ip' => $ip, 'port' => (int) $port],
            'expires_at' => $this->expiresAt(),
        ];
    }

    /**
     * Get the cached replica addresses for a service.
     *
     * @return array<int, array{ip: string, port: int}>
     */
    public function getReplicas(string $service): array
    {
        $entry = $this->nodes[$service][self::REPLICAS_KEY] ?? null;

        if ($entry === null) {
            return [];
        }

        if ($this->isExpired($entry['expires_at'])) {
            $this->forgetReplicas($service);

            return [];
        }

        return $entry['value'];
    }

    /**
     * @param  array<int, mixed>  $replicas
     */
    public function setReplicas(string $service, array $replicas): void
    {
        $this->nodes[$service][self::REPLICAS_KEY] = [
            'value' => array_map(static fn ($r) => [
                'ip' => $r['ip'] ?? $r[0],
                'port' => (int) ($r['port'] ?? $r[1]),
            ], $replicas),
            'expires_at' => $this->expiresAt(),
        ];
    }

    public function forgetMaster(string $service): void
    {
        unset($this->nodes[$service][self::MASTER_KEY]);

        if (($this->nodes[$service] ?? []) === []) {
            unset($this->nodes[$service]);
        }
    }

    public function forgetReplicas(string $service): void
    {
        unset($this->nodes[$service][self::REPLICAS_KEY]);

        if (($this->nodes[$service] ?? []) === []) {
            unset($this->nodes[$service]);
        }
    }

    public function forget(string $service): void
    {
        unset($this->nodes[$service]);
    }

    public function flush(): void
    {
        $this->nodes = [];
    }

    private function expiresAt(): ?float
    {
        return $this->ttlSeconds > 0 ? microtime(true) + $this->ttlSeconds : null;
    }

    private function isExpired(?float $expiresAt): bool
    {
        return $expiresAt !== null && microtime(true) >= $expiresAt;
    }
}
