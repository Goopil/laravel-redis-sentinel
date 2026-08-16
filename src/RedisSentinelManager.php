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
        $previousDriver = $this->driver;
        $this->driver = $this->config[$normalizedName]['client'] ?? $this->driver;

        try {
            if ($this->driver !== 'phpredis-sentinel') {
                return parent::resolve($normalizedName);
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

            return $this->connector()->connect($config, $options);
        } finally {
            $this->driver = $previousDriver;
        }
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

        $previousDriver = $this->driver;
        $this->driver = $this->config[$normalizedName]['client'] ?? $this->driver;

        try {
            return $this->connector();
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
        if ($name === 'horizon' && $this->isHorizonContext()) {
            $horizonUse = $this->app['config']->get('horizon.use');

            if ($horizonUse === null) {
                throw new ConfigurationException(
                    'The "horizon.use" configuration key is required when using Redis Sentinel with Horizon. '
                    .'Please set it to the name of your Redis Sentinel connection in the horizon config.'
                );
            }

            return $horizonUse;
        }

        return $name;
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
