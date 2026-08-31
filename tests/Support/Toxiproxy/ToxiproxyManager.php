<?php

namespace Goopil\LaravelRedisSentinel\Tests\Support\Toxiproxy;

use RuntimeException;

final class ToxiproxyManager
{
    /**
     * @var array<string, array{listenPort: int, upstreamHost: string, upstreamPort: int}>
     */
    private array $specs = [];

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $apiPort = 8474,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            host: getenv('TOXIPROXY_HOST') ?: '127.0.0.1',
            apiPort: (int) (getenv('TOXIPROXY_API') ?: 8474),
        );
    }

    public function isAvailable(): bool
    {
        return $this->request('GET', '/version', null, 1)['status'] === 200;
    }

    public function ensureProxy(string $name, int $listenPort, string $upstreamHost, int $upstreamPort): void
    {
        $this->specs[$name] = [
            'listenPort' => $listenPort,
            'upstreamHost' => $upstreamHost,
            'upstreamPort' => $upstreamPort,
        ];

        $this->request('DELETE', "/proxies/{$name}", null, 1);

        $response = $this->request('POST', '/proxies', [
            'name' => $name,
            'listen' => "0.0.0.0:{$listenPort}",
            'upstream' => "{$upstreamHost}:{$upstreamPort}",
            'enabled' => true,
        ]);

        if ($response['status'] >= 400) {
            throw new RuntimeException("Toxiproxy: failed to create proxy {$name}: {$response['body']}");
        }
    }

    public function disable(string $name): void
    {
        $this->request('POST', "/proxies/{$name}", ['enabled' => false]);
    }

    public function enable(string $name): void
    {
        $this->request('POST', "/proxies/{$name}", ['enabled' => true]);
    }

    public function resetProxy(string $name): void
    {
        if (isset($this->specs[$name])) {
            $spec = $this->specs[$name];

            $this->ensureProxy($name, $spec['listenPort'], $spec['upstreamHost'], $spec['upstreamPort']);
        }
    }

    public function resetAll(): void
    {
        $defaults = [
            'main' => ['env' => 'MAIN', 'listenPort' => 16380, 'upstreamPort' => 6380],
            'replica1' => ['env' => 'REPLICA1', 'listenPort' => 16381, 'upstreamPort' => 6381],
            'replica2' => ['env' => 'REPLICA2', 'listenPort' => 16382, 'upstreamPort' => 6382],
        ];

        foreach ($defaults as $name => $default) {
            $spec = [
                'listenPort' => (int) (getenv("REDIS_{$default['env']}_PROXY_PORT") ?: $default['listenPort']),
                'upstreamHost' => '127.0.0.1',
                'upstreamPort' => (int) (getenv("REDIS_{$default['env']}_PORT") ?: $default['upstreamPort']),
            ];

            $this->specs[$name] = $spec;

            if ($this->pristine($name, $spec)) {
                continue;
            }

            $this->ensureProxy($name, $spec['listenPort'], $spec['upstreamHost'], $spec['upstreamPort']);
        }
    }

    public function addTimeout(string $name, int $timeoutMs, float $toxicity = 1.0): string
    {
        return $this->addToxic($name, 'timeout', 'downstream', $toxicity, ['timeout' => $timeoutMs]);
    }

    public function addLatency(string $name, int $latencyMs, float $jitterMs = 0, float $toxicity = 1.0): string
    {
        return $this->addToxic($name, 'latency', 'downstream', $toxicity, [
            'latency' => $latencyMs,
            'jitter' => (int) $jitterMs,
        ]);
    }

    public function addResetPeer(string $name, string $stream = 'downstream'): string
    {
        return $this->addToxic($name, 'reset_peer', $stream, 1.0, []);
    }

    public function addSlicer(string $name, int $averageSize, int $sizeVariation = 0, int $delayMs = 0): string
    {
        return $this->addToxic($name, 'slicer', 'downstream', 1.0, [
            'average_size' => $averageSize,
            'size_variation' => $sizeVariation,
            'delay' => $delayMs,
        ]);
    }

    public function removeToxic(string $proxy, string $toxic): void
    {
        $this->request('DELETE', "/proxies/{$proxy}/toxics/{$toxic}", null, 1);
    }

    private function addToxic(string $proxy, string $type, string $stream, float $toxicity, array $attributes): string
    {
        $response = $this->request('POST', "/proxies/{$proxy}/toxics", [
            'name' => '',
            'type' => $type,
            'stream' => $stream,
            'toxicity' => $toxicity,
            'attributes' => (object) $attributes,
        ]);

        if ($response['status'] >= 400) {
            throw new RuntimeException("Toxiproxy: failed to add {$type} toxic to {$proxy}: {$response['body']}");
        }

        $created = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);

        return $created['name'];
    }

    /**
     * A pristine proxy must not be deleted/recreated: each listener bounce severs
     * Sentinel's monitoring links through it and bootstraps sdown/odown flapping.
     * The listen address is compared by port only, because toxiproxy normalizes
     * configured "0.0.0.0:{port}" to "[::]:{port}" in its API responses.
     *
     * @param  array{listenPort: int, upstreamHost: string, upstreamPort: int}  $spec
     */
    private function pristine(string $name, array $spec): bool
    {
        $response = $this->request('GET', "/proxies/{$name}", null, 1);

        if ($response['status'] !== 200) {
            return false;
        }

        try {
            $proxy = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return is_array($proxy)
            && ($proxy['name'] ?? null) === $name
            && str_ends_with((string) ($proxy['listen'] ?? ''), ':'.$spec['listenPort'])
            && ($proxy['upstream'] ?? null) === "{$spec['upstreamHost']}:{$spec['upstreamPort']}"
            && ($proxy['enabled'] ?? null) === true
            && ($proxy['toxics'] ?? null) === [];
    }

    /**
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $path, ?array $body, int $timeoutSeconds = 5): array
    {
        $handle = curl_init("http://{$this->host}:{$this->apiPort}{$path}");
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutSeconds * 1000,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }

        $raw = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return ['status' => $status, 'body' => $raw];
    }
}
