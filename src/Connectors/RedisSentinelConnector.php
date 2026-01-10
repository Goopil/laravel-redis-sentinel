<?php

namespace Goopil\LaravelRedisSentinel\Connectors;

use Closure;
use Goopil\LaravelRedisSentinel\Concerns\Loggable;
use Goopil\LaravelRedisSentinel\Concerns\Retryable;
use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelMasterFailed;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelMasterMaxRetryFailed;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelMasterReconnected;
use Goopil\LaravelRedisSentinel\Exceptions\ConfigurationException;
use Goopil\LaravelRedisSentinel\Exceptions\NotImplementedException;
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

    protected ?string $phpredisVersion = null;

    public function __construct(NodeAddressCache $masterCache)
    {
        $this->masterCache = $masterCache;

        $this->setRetryLimit(config('phpredis-sentinel.retry.sentinel.attempts'))
            ->setRetryDelay(config('phpredis-sentinel.retry.sentinel.delay'))
            ->setRetryMessages(config('phpredis-sentinel.retry.sentinel.messages', []));
    }

    /**
     * {@inheritdoc}
     *
     * @throws RedisException|Throwable
     */
    public function connect(array $config, array $options): RedisSentinelConnection
    {
        $connectionConfig = $this->mergeConnectionOptions($config, $options);
        $connector = fn ($refresh = false) => $this->createClient($connectionConfig, $refresh);

        $readConnector = null;
        if (Arr::get($config, 'read_only_replicas', false)) {
            $readConnector = fn ($refresh = false) => $this->createClient($connectionConfig, $refresh, true);
        }

        return (new RedisSentinelConnection($connector(), $connector, $config, $readConnector))
            ->setRetryLimit(Arr::get(
                $config,
                'retry.redis.attempts',
                config('phpredis-sentinel.retry.redis.attempts', $this->retryLimit)
            ))
            ->setRetryDelay(Arr::get(
                $config,
                'retry.redis.delay',
                config('phpredis-sentinel.retry.redis.delay', $this->retryDelay)
            ))
            ->setRetryMessages(config('phpredis-sentinel.retry.redis.messages', []));
    }

    /**
     * {@inheritdoc}
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

        return $this->retryOnFailure(
            fn () => $this->connectToSentinel($config),
            onFail: $this->onSentinelFail($service, 'connectToSentinel'),
            onReconnect: $this->onSentinelReconnect($service, 'connectToSentinel'),
            onMaxFail: $this->onSentinelMaxFail($service, 'connectToSentinel')
        );
    }

    /**
     * Create the PhpRedis client instance which connects to Redis Sentinel.
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

        $clientConfig = array_merge(
            Arr::get($config, 'options', []),
            [
                'host' => $ip,
                'port' => $port,
                'password' => Arr::get($config, 'password') ?? Arr::get($config, 'sentinel.password', ''),
                'timeout' => Arr::get($config, 'timeout') ?? Arr::get($config, 'sentinel.timeout', 0.2),
                'read_timeout' => Arr::get($config, 'read_timeout') ?? Arr::get($config, 'sentinel.read_timeout', 0),
                'retry_interval' => Arr::get($config, 'retry_interval') ?? Arr::get($config, 'sentinel.retry_interval', 0),
                'persistent' => Arr::get($config, 'persistent') ?? Arr::get($config, 'sentinel.persistent', 0),
                'database' => Arr::get($config, 'database') ?? Arr::get($config, 'sentinel.database', 0),
            ]
        );

        return parent::createClient($clientConfig);
    }

    /**
     * Get the master address from Sentinel.
     *
     * @throws Throwable
     */
    protected function getMasterAddress(array $config, bool $refresh = false): array
    {
        $service = $this->getService($config);

        if ($refresh) {
            $this->masterCache->forget($service);
        }

        if ($master = $this->masterCache->get($service)) {
            return $master;
        }

        ['ip' => $ip, 'port' => $port] = $this->retryOnFailure(
            function () use ($config, $service) {
                if ($master = $this->connectToSentinel($config)->master($service)) {
                    return $master;
                }

                throw new RedisException(sprintf("No master found for service '%s'.", $service));
            },
            onFail: $this->onSentinelFail($service, 'getMasterAddress'),
            onReconnect: $this->onSentinelReconnect($service, 'getMasterAddress'),
            onMaxFail: $this->onSentinelMaxFail($service, 'getMasterAddress')
        );

        $this->masterCache->set($service, $ip, $port);

        return ['ip' => $ip, 'port' => $port];
    }

    /**
     * Get a replica address from Sentinel.
     *
     * @throws Throwable
     */
    protected function getReplicaAddress(array $config, bool $refresh = false): array
    {
        $service = $this->getService($config);

        if ($refresh) {
            $this->masterCache->forget($service);
        }

        $replicas = $this->masterCache->getReplicas($service);

        if (empty($replicas)) {
            $slaves = $this->retryOnFailure(
                function () use ($config, $service) {
                    $result = $this->connectToSentinel($config)->slaves($service);
                    if ($result === false) {
                        throw new RedisException(sprintf("No replicas found for service '%s'.", $service));
                    }

                    return $result;
                },
                onFail: $this->onSentinelFail($service, 'getReplicaAddress'),
                onReconnect: $this->onSentinelReconnect($service, 'getReplicaAddress'),
                onMaxFail: $this->onSentinelMaxFail($service, 'getReplicaAddress')
            );

            // Filter healthy replicas
            $replicas = array_filter($slaves, static function ($s) {
                $flags = $s['flags'] ?? $s['role-reported'] ?? '';

                return ! str_contains($flags, 's_down') &&
                       ! str_contains($flags, 'o_down') &&
                       ! str_contains($flags, 'disconnected');
            });

            if (empty($replicas)) {
                return $this->getMasterAddress($config, $refresh);
            }

            $this->masterCache->setReplicas($service, $replicas);
        }

        $replica = $replicas[array_rand($replicas)];

        return [
            'ip' => $replica['ip'] ?? $replica[0],
            'port' => $replica['port'] ?? $replica[1],
        ];
    }

    /**
     * Connect to the configured Redis Sentinel instance.
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
                'connectTimeout' => $config['sentinel']['timeout'] ?? $config['timeout'] ?? 0.2,
                'persistent' => $config['sentinel']['persistent'] ?? $config['persistent'] ?? null,
                'retryInterval' => $config['sentinel']['retry_interval'] ?? $config['retry_interval'] ?? 0,
                'readTimeout' => $config['sentinel']['read_timeout'] ?? $config['read_timeout'] ?? 0,
            ];

            if (($password = $config['sentinel']['password'] ?? $config['password'] ?? '') !== '') {
                $options['auth'] = $password;
            }

            try {
                $instance = $this->createSentinelInstance($options);

                if ($instance->ping()) {
                    return $instance;
                }
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

    protected function getService(array $config): ?string
    {
        return $config['sentinel']['service'] ?? $config['service'] ?? null;
    }

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

    protected function createSentinelInstance(array $options): RedisSentinel
    {
        return $this->needParamsAsArray()
            ? new RedisSentinel($options)
            : new RedisSentinel(...array_values($options));
    }

    protected function mergeConnectionOptions(array $config, array $options): array
    {
        $configOptions = Arr::get($config, 'options', []);

        if (isset($config['prefix'])) {
            $configOptions['prefix'] = $config['prefix'];
        }

        $config['options'] = array_merge($options, $configOptions);

        return $config;
    }

    private function needParamsAsArray(): bool
    {
        if ($this->phpredisVersion === null) {
            $this->phpredisVersion = phpversion('redis');
        }

        return version_compare($this->phpredisVersion, '6.0', '>=');
    }

    protected function onSentinelFail(string $service, string $method): Closure
    {
        return function ($exception, $attempts) use ($service, $method) {
            RedisSentinelMasterFailed::dispatch($service, $exception, $method, $attempts);

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
            RedisSentinelMasterReconnected::dispatch($service, $method, $attempts);

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
            RedisSentinelMasterMaxRetryFailed::dispatch($service, $exception, $method, $attempts);

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

        // Validate IP address
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        // Validate domain name
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false) {
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
