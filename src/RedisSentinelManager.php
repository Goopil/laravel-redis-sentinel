<?php

namespace Goopil\LaravelRedisSentinel;

use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\Exceptions\ConfigurationException;
use Illuminate\Contracts\Redis\Connector;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Redis\Connectors\PredisConnector;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Arr;

class RedisSentinelManager extends RedisManager
{
    private const HORIZON_REDIS_CONNECTOR = 'Laravel\\Horizon\\Connectors\\RedisConnector';

    /**
     * Cache for horizon context.
     */
    protected ?bool $isHorizonContext = null;

    public function resolve($name = null)
    {
        $name = $name ?: 'default';

        $normalizedName = $this->patchHorizonConnectionName($name);
        $driver = $this->config[$normalizedName]['client'] ?? $this->driver;

        if ($driver !== 'phpredis-sentinel') {
            return $this->withDriver($driver, fn () => parent::resolve($normalizedName));
        }

        $config = $this->parseConnectionConfiguration($this->config[$normalizedName]);

        $config = $this->patchHorizonPrefix(
            $name,
            $config
        );

        $options = $this->config['options'] ?? [];

        $options = array_merge(
            Arr::except($options, 'parameters'),
            ['parameters' => Arr::get($options, 'parameters.'.$name, Arr::get($options, 'parameters', []))]
        );

        return $this->connector($driver)->connect($config, $options);
    }

    public function resolveConnector($name = null): Connector|PhpRedisConnector|PredisConnector|RedisSentinelConnector
    {
        $normalizedName = $this->patchHorizonConnectionName($name);

        if (($this->config[$normalizedName]['client'] ?? null) === 'phpredis-sentinel' && isset($this->config['clusters'][$normalizedName])) {
            throw new ConfigurationException(
                'Redis Sentinel connections do not support Redis Cluster.'
            );
        }

        if (! isset($this->config[$normalizedName])) {
            throw new ConfigurationException(
                sprintf('No connection defined with base name %s or overwritten name %s in `database.redis` config', $name, $normalizedName)
            );
        }

        $driver = $this->config[$normalizedName]['client'] ?? $this->driver;

        return $this->connector($driver);
    }

    protected function connector(?string $driver = null): mixed
    {
        $driver = $driver ?? $this->driver;

        $customCreator = $this->customCreators[$driver] ?? null;

        if ($customCreator) {
            return $customCreator();
        }

        return match ($driver) {
            'predis' => new PredisConnector,
            'phpredis' => new PhpRedisConnector,
            'phpredis-sentinel' => $this->app->make(RedisSentinelConnector::class),
            default => null,
        };
    }

    private function withDriver(string $driver, callable $callback): mixed
    {
        $previousDriver = $this->driver;
        $this->driver = $driver;

        try {
            return $callback();
        } finally {
            $this->driver = $previousDriver;
        }
    }

    protected function isHorizonContext(): bool
    {
        if ($this->isHorizonContext === null) {
            $this->isHorizonContext = isset($this->app['config']) &&
                class_exists(self::HORIZON_REDIS_CONNECTOR) &&
                $this->app['config']->get('horizon.driver') === 'phpredis-sentinel';
        }

        return $this->isHorizonContext;
    }

    protected function patchHorizonConnectionName(string $name = 'default'): string
    {
        return $name === 'horizon' && $this->isHorizonContext()
            ? $this->app['config']->get('horizon.use', 'horizon')
            : $name;
    }

    protected function patchHorizonPrefix(string $name, array $clientConfig): array
    {
        if ($name === 'horizon' && $this->isHorizonContext()) {
            $prefix = $this->app['config']->get(
                'horizon.prefix',
                Arr::get($clientConfig, 'options.prefix', '')
            );

            Arr::set($clientConfig, 'options.prefix', $prefix);
        }

        return $clientConfig;
    }
}
