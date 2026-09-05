<?php

namespace Goopil\LaravelRedisSentinel\Connectors;

use Closure;
use Goopil\LaravelRedisSentinel\Concerns\Loggable;
use Goopil\LaravelRedisSentinel\Concerns\Retryable;
use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Context\ExecutionContext;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelMasterFailed;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelMasterMaxRetryFailed;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelMasterReconnected;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelReplicaFallback;
use Goopil\LaravelRedisSentinel\Exceptions\ConfigurationException;
use Goopil\LaravelRedisSentinel\Exceptions\NotImplementedException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Arr;
use Redis;
use RedisException;
use RedisSentinel;
use Throwable;

/**
 * Allows connecting to a Sentinel driven Redis master using the PhpRedis extension.
 */
class RedisSentinelConnector extends PhpRedisConnector
{
    use Loggable;
    use Retryable;

    protected NodeAddressCache $masterCache;

    protected ConfigRepository $config;

    protected ?string $phpredisVersion = null;

    private const BREAKER_THRESHOLD = 2;

    private const BREAKER_COOLDOWN_SECONDS = 5.0;

    /**
     * Per-cluster Sentinel resolution breaker state, keyed by node cache key
     * (service + Sentinel endpoints): one cluster's outage must never block
     * another cluster's resolution. An absent entry means the breaker is closed.
     *
     * @var array<string, array{failures: int, openedAt: ?float, lastException: ?Throwable}>
     */
    private static array $resolutionBreakers = [];

    /**
     * Test-only seam: forces the coroutine branch of buildClientConfig() without
     * a running Swoole/OpenSwoole runtime. Must remain false in production.
     */
    public static bool $forceCoroutineDetection = false;

    public function __construct(NodeAddressCache $masterCache, ConfigRepository $config)
    {
        $this->masterCache = $masterCache;
        $this->config = $config;

        $this->setRetryLimit($this->resolveRetryInt($config->get('phpredis-sentinel.retry.sentinel.attempts'), $this->retryLimit))
            ->setRetryDelay($this->resolveRetryInt($config->get('phpredis-sentinel.retry.sentinel.delay'), $this->retryDelay))
            ->setRetryMessages($this->resolveRetryMessages($config->get('phpredis-sentinel.retry.sentinel.messages'), $this->retryMessages));
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $options
     *
     * @throws RedisException|Throwable
     */
    public function connect(array $config, array $options): RedisSentinelConnection
    {
        $config = Arr::except($config, 'command_retries');
        $config['read_commands'] = Arr::get($config, 'read_commands')
            ?? $this->config->get('phpredis-sentinel.read_commands', []);

        $connectionConfig = $this->mergeConnectionOptions($config, $options);
        $connector = fn ($refresh = false) => $this->createClient($connectionConfig, $refresh);

        $readConnector = null;
        if (Arr::get($config, 'read_only_replicas', false)) {
            $readConnector = fn ($refresh = false) => $this->createClient($connectionConfig, $refresh, true);
        }

        return (new RedisSentinelConnection($connector(), $connector, $config, $readConnector))
            ->setRetryLimit($this->resolveRetryInt(
                Arr::get($config, 'retry.redis.attempts'),
                $this->resolveRetryInt($this->config->get('phpredis-sentinel.retry.redis.attempts'), $this->retryLimit)
            ))
            ->setRetryDelay($this->resolveRetryInt(
                Arr::get($config, 'retry.redis.delay'),
                $this->resolveRetryInt($this->config->get('phpredis-sentinel.retry.redis.delay'), $this->retryDelay)
            ))
            ->setRetryMessages($this->resolveRetryMessages(
                Arr::get($config, 'retry.redis.messages'),
                $this->resolveRetryMessages($this->config->get('phpredis-sentinel.retry.redis.messages'), $this->retryMessages)
            ));
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $clusterOptions
     * @param  array<string, mixed>  $options
     */
    public function connectToCluster(array $config, array $clusterOptions, array $options): PhpRedisClusterConnection
    {
        throw new NotImplementedException('The Redis Sentinel driver does not support connecting to clusters.');
    }

    public function createSentinel(string $name): RedisSentinel
    {
        $config = config('database.redis.'.$name);

        if (! Arr::has($config, 'sentinel') && ! Arr::has($config, 'sentinels')) {
            throw new RedisException(sprintf('No sentinel config'));
        }

        $service = $this->getService($config);

        if (empty($service)) {
            throw new ConfigurationException(sprintf("No service name has been specified for the Redis Sentinel connection '%s'.", $name));
        }

        $key = $this->getNodeCacheKey($config);

        $this->guardSentinelResolution($key);

        return $this->retryOnFailure(
            function () use ($config, $key) {
                $this->guardSentinelResolution($key);

                try {
                    $instance = $this->connectToSentinel($config);
                } catch (Throwable $e) {
                    $this->recordSentinelFailure($key, $e);

                    throw $e;
                }

                $this->recordSentinelSuccess($key);

                return $instance;
            },
            onFail: $this->onSentinelFail($service, 'connectToSentinel'),
            onReconnect: $this->onSentinelReconnect($service, 'connectToSentinel'),
            onMaxFail: $this->onSentinelMaxFail($service, 'connectToSentinel')
        );
    }

    /**
     * Create the PhpRedis client instance which connects to Redis Sentinel.
     *
     *
     * @param  array<string, mixed>  $config
     *
     * @throws ConfigurationException
     * @throws RedisException
     * @throws Throwable
     */
    protected function createClient(array $config, bool $refresh = false, bool $readOnly = false): Redis
    {
        if (! Arr::has($config, 'sentinel') && ! Arr::has($config, 'sentinels')) {
            return parent::createClient($config);
        }

        ['ip' => $ip, 'port' => $port] = $readOnly
            ? $this->getReplicaAddress($config, $refresh)
            : $this->getMasterAddress($config, $refresh);

        return parent::createClient($this->buildClientConfig($config, $ip, $port));
    }

    /**
     * Build the PhpRedis client options for a resolved node address.
     *
     * Clients created inside a coroutine must not use phpredis persistent
     * sockets: the persistent connection table is process-wide, so such a
     * client would share its socket with other coroutines and reintroduce
     * response interleaving.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function buildClientConfig(array $config, string $ip, int $port): array
    {
        $persistent = self::$forceCoroutineDetection || ExecutionContext::inCoroutine()
            ? 0
            : Arr::get($config, 'persistent') ?? Arr::get($config, 'sentinel.persistent', 0);

        return array_merge(
            Arr::get($config, 'options', []),
            [
                'host' => $ip,
                'port' => $port,
                'password' => Arr::get($config, 'password', ''),
                'timeout' => Arr::get($config, 'timeout', 5.0),
                'read_timeout' => Arr::get($config, 'read_timeout', 60.0),
                'retry_interval' => Arr::get($config, 'retry_interval') ?? Arr::get($config, 'sentinel.retry_interval', 0),
                'persistent' => $persistent,
                'database' => Arr::get($config, 'database') ?? Arr::get($config, 'sentinel.database', 0),
            ]
        );
    }

    /**
     * Get the master address from Sentinel.
     *
     * @param  array<string, mixed>  $config
     * @return array{ip: string, port: int}
     *
     * @throws Throwable
     */
    protected function getMasterAddress(array $config, bool $refresh = false): array
    {
        $service = $this->getService($config);

        if ($refresh) {
            $this->masterCache->forgetMaster($this->getNodeCacheKey($config));
        }

        if ($master = $this->masterCache->get($this->getNodeCacheKey($config))) {
            return $master;
        }

        $key = $this->getNodeCacheKey($config);

        $this->guardSentinelResolution($key);

        ['ip' => $ip, 'port' => $port] = $this->retryOnFailure(
            function () use ($config, $service, $key) {
                $this->guardSentinelResolution($key);

                try {
                    if ($master = $this->connectToSentinel($config)->master($service)) {
                        $this->recordSentinelSuccess($key);

                        return $master;
                    }

                    throw new RedisException(sprintf("No master found for service '%s'.", $service));
                } catch (Throwable $e) {
                    $this->recordSentinelFailure($key, $e);

                    throw $e;
                }
            },
            onFail: $this->onSentinelFail($service, 'getMasterAddress'),
            onReconnect: $this->onSentinelReconnect($service, 'getMasterAddress'),
            onMaxFail: $this->onSentinelMaxFail($service, 'getMasterAddress')
        );

        $this->masterCache->set($this->getNodeCacheKey($config), $ip, $port);

        return ['ip' => $ip, 'port' => $port];
    }

    /**
     * Get a replica address from Sentinel.
     *
     * @param  array<string, mixed>  $config
     * @return array{ip: string, port: int}
     *
     * @throws Throwable
     */
    protected function getReplicaAddress(array $config, bool $refresh = false): array
    {
        $service = $this->getService($config);

        if ($refresh) {
            $this->masterCache->forgetReplicas($this->getNodeCacheKey($config));
        }

        $replicas = $this->masterCache->getReplicas($this->getNodeCacheKey($config));

        if (empty($replicas)) {
            $key = $this->getNodeCacheKey($config);

            $this->guardSentinelResolution($key);

            $slaves = $this->retryOnFailure(
                function () use ($config, $service, $key) {
                    $this->guardSentinelResolution($key);

                    try {
                        $result = $this->connectToSentinel($config)->slaves($service);

                        if ($result === false) {
                            throw new RedisException(sprintf("No replicas found for service '%s'.", $service));
                        }

                        $this->recordSentinelSuccess($key);

                        return $result;
                    } catch (Throwable $e) {
                        $this->recordSentinelFailure($key, $e);

                        throw $e;
                    }
                },
                onFail: $this->onSentinelFail($service, 'getReplicaAddress'),
                onReconnect: $this->onSentinelReconnect($service, 'getReplicaAddress'),
                onMaxFail: $this->onSentinelMaxFail($service, 'getReplicaAddress')
            );

            // Filter healthy replicas
            $replicas = array_values(array_filter($slaves, static function ($s) {
                $flags = $s['flags'] ?? $s['role-reported'] ?? '';

                return ! str_contains($flags, 's_down') &&
                       ! str_contains($flags, 'o_down') &&
                       ! str_contains($flags, 'disconnected') &&
                       ($s['master-link-status'] ?? 'ok') !== 'disconnect';
            }));

            if (empty($replicas)) {
                $this->log('No healthy replica, reads fall back to the master', ['service' => $service, 'replicas' => $slaves], 'warning');
                $this->dispatchSafely(new RedisSentinelReplicaFallback($service, $slaves));

                return $this->getMasterAddress($config, $refresh);
            }

            $this->masterCache->setReplicas($this->getNodeCacheKey($config), $replicas);
        }

        $replica = $replicas[random_int(0, count($replicas) - 1)];

        return [
            'ip' => $replica['ip'] ?? $replica[0],
            'port' => $replica['port'] ?? $replica[1],
        ];
    }

    /**
     * Connect to the configured Redis Sentinel instance.
     *
     * When no Sentinel host is reachable the reported cause is the LAST failure
     * observed across the node loop (most recent, most specific). Earlier failures
     * are not preserved — the ConfigurationException carries only this one.
     *
     *
     * @param  array<string, mixed>  $config
     *
     * @throws ConfigurationException
     */
    protected function connectToSentinel(array $config): RedisSentinel
    {
        $sentinels = $this->getSentinels($config);
        $lastException = null;

        foreach ($sentinels as $sentinel) {
            $host = $this->normalizeHost($sentinel['host'] ?? '');
            $port = $this->normalizePort($sentinel['port'] ?? null);

            // Skip invalid hosts
            if ($host === null) {
                $this->log('Invalid sentinel host', ['host' => $sentinel['host'] ?? ''], 'warning');

                continue;
            }

            // Skip invalid ports
            if ($port === null) {
                $this->log('Invalid sentinel port', ['port' => $sentinel['port'] ?? '', 'host' => $host], 'warning');

                continue;
            }

            $options = [
                'host' => $host,
                'port' => $port,
                'connectTimeout' => $config['sentinel']['timeout'] ?? 1.0,
                'persistent' => $config['sentinel']['persistent'] ?? $config['persistent'] ?? null,
                'retryInterval' => $config['sentinel']['retry_interval'] ?? $config['retry_interval'] ?? 0,
                'readTimeout' => $config['sentinel']['read_timeout'] ?? 60.0,
            ];

            if (($password = $config['sentinel']['password'] ?? $config['password'] ?? '') !== '') {
                $options['auth'] = $password;
            }

            try {
                $instance = $this->createSentinelInstance($options);

                if ($instance->ping()) {
                    return $instance;
                }

                $lastException = new RedisException(
                    sprintf('Sentinel %s:%d did not respond to ping.', $host, $port)
                );
            } catch (Throwable $e) {
                $lastException = $e;

                continue;
            }
        }

        throw new ConfigurationException(
            'No reachable Redis Sentinel host found.',
            0,
            $lastException
        );
    }

    /**
     * Sentinel resolution failures surface as a ConfigurationException wrapping
     * the transport cause; the connector only ever retries its own wrapped
     * failures (no user callback crosses this path), so the message-based
     * contract keeps applying to every Throwable here.
     */
    protected function isRetryableException(Throwable $exception): bool
    {
        return true;
    }

    private function guardSentinelResolution(string $key): void
    {
        $breaker = self::$resolutionBreakers[$key] ?? null;

        if ($breaker === null || $breaker['openedAt'] === null) {
            return;
        }

        if ((microtime(true) - $breaker['openedAt']) >= self::BREAKER_COOLDOWN_SECONDS) {
            unset(self::$resolutionBreakers[$key]);

            return;
        }

        $lastException = $breaker['lastException'];

        if ($lastException !== null) {
            throw $lastException;
        }
    }

    private function recordSentinelFailure(string $key, Throwable $exception): void
    {
        // Only Sentinel unreachability (connectToSentinel exhausting all hosts) opens the breaker;
        // a reachable Sentinel that has no master/replicas for the service is not a Sentinel outage.
        if (! $exception instanceof ConfigurationException) {
            return;
        }

        $breaker = self::$resolutionBreakers[$key] ?? ['failures' => 0, 'openedAt' => null, 'lastException' => null];
        $breaker['failures']++;
        $breaker['lastException'] = $exception;

        if ($breaker['failures'] >= self::BREAKER_THRESHOLD) {
            $breaker['openedAt'] = microtime(true);
        }

        self::$resolutionBreakers[$key] = $breaker;
    }

    private function recordSentinelSuccess(string $key): void
    {
        unset(self::$resolutionBreakers[$key]);
    }

    /**
     * Namespace the node cache key by service name and Sentinel endpoints so two
     * connections pointing at different clusters sharing a service name never
     * exchange cached addresses.
     *
     * @param  array<string, mixed>  $config
     */
    public function getNodeCacheKey(array $config): string
    {
        $service = $this->getService($config) ?? '';

        $endpoints = [];

        foreach ($this->getSentinels($config) as $sentinel) {
            $host = $this->normalizeHost($sentinel['host'] ?? '');
            $port = $this->normalizePort($sentinel['port'] ?? null);

            if ($host === null || $port === null) {
                continue;
            }

            $endpoints[] = $host.':'.$port;
        }

        sort($endpoints);

        return $service.'-'.sha1(implode(',', $endpoints));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function getService(array $config): ?string
    {
        return $config['sentinel']['service'] ?? $config['service'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, int|string>>
     */
    protected function getSentinels(array $config): array
    {
        $sentinels = $config['sentinels'] ?? $config['sentinel']['sentinels'] ?? null;

        if ($sentinels) {
            return $sentinels;
        }

        return [
            [
                'host' => $config['sentinel']['host'] ?? $config['host'] ?? '',
                'port' => $config['sentinel']['port'] ?? $config['port'] ?? 26379,
            ],
        ];
    }

    /**
     * The phpredis >= 6 array-form constructor is valid at runtime but the
     * bundled stubs only declare the positional form, so the array branch is
     * triaged here.
     *
     * @param  array<string, int|string|null>  $options
     */
    protected function createSentinelInstance(array $options): RedisSentinel
    {
        return $this->needParamsAsArray()
            /** @phpstan-ignore argument.type, arguments.count (array-form ctor is valid on phpredis >= 6, stubs lag) */
            ? new RedisSentinel($options)
            : new RedisSentinel(...array_values($options));
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function mergeConnectionOptions(array $config, array $options): array
    {
        $configOptions = Arr::get($config, 'options', []);

        if (isset($config['prefix'])) {
            $configOptions['prefix'] = $config['prefix'];
        }

        $config['options'] = array_merge($options, $configOptions);

        return $config;
    }

    /**
     * Resolve an integer retry setting, falling back when the configured value is
     * null (explicit) or otherwise not a usable non-negative integer, instead of
     * casting null/bool to 0. Numeric strings are accepted so env()-wrapped
     * published configs keep working.
     */
    private function resolveRetryInt(mixed $value, int $default): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);

        return $int !== false && $int >= 0 ? $int : $default;
    }

    /**
     * Resolve a retry message list, falling back when the configured value is
     * null or not an array. An explicit empty array stays valid: it means
     * "never retry on message match".
     *
     * @param  array<int, string>  $default
     * @return array<int, string>
     */
    private function resolveRetryMessages(mixed $value, array $default): array
    {
        if ($value === null || ! is_array($value)) {
            return $default;
        }

        return array_values(array_filter($value, 'is_string'));
    }

    private function needParamsAsArray(): bool
    {
        if ($this->phpredisVersion === null) {
            $version = phpversion('redis');

            // @codeCoverageIgnoreStart
            // The test suite cannot run with the extension unloaded.
            if ($version === false) {
                throw new ConfigurationException('PhpRedis extension is not loaded');
            }
            // @codeCoverageIgnoreEnd

            $this->phpredisVersion = $version;
        }

        return version_compare($this->phpredisVersion, '6.0', '>=');
    }

    protected function onSentinelFail(string $service, string $method): Closure
    {
        return function ($exception, $attempts) use ($service, $method) {
            $this->dispatchSafely(new RedisSentinelMasterFailed($service, $exception, $method, $attempts));

            $this->log($method.' - fail', [
                'method' => $method,
                'reason' => $exception->getMessage(),
                'attempts' => $attempts,
                'service' => $service,
            ], 'error');
        };
    }

    protected function onSentinelReconnect(string $service, string $method): Closure
    {
        return function ($attempts) use ($service, $method) {
            $this->dispatchSafely(new RedisSentinelMasterReconnected($service, $method, $attempts));

            $this->log($method.' - reconnected', [
                'method' => $method,
                'attempts' => $attempts,
                'service' => $service,
            ]);
        };
    }

    protected function onSentinelMaxFail(string $service, string $method): Closure
    {
        return function ($exception, $attempts) use ($service, $method) {
            $this->dispatchSafely(new RedisSentinelMasterMaxRetryFailed($service, $exception, $method, $attempts));

            $this->log($method.' - max fail', [
                'method' => $method,
                'reason' => $exception->getMessage(),
                'attempts' => $attempts,
                'service' => $service,
            ], 'error');
        };
    }

    /**
     * Normalize and validate a host value.
     *
     * @return string|null Returns null if the host is invalid
     */
    protected function normalizeHost(mixed $host): ?string
    {
        $host = trim((string) $host);

        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
        ) {
            return $host;
        }

        return null;
    }

    /**
     * Normalize and validate a port value.
     *
     * @return int|null Returns null if the port is invalid
     */
    protected function normalizePort(mixed $port, int $default = 26379): ?int
    {
        // Use default if null
        if ($port === null) {
            return $default;
        }

        // Cast to int
        $port = is_int($port) ? $port : (int) $port;

        // Validate range
        if ($port < 1 || $port > 65535) {
            return null;
        }

        return $port;
    }
}
