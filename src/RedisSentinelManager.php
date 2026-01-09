<?php

namespace Goopil\LaravelRedisSentinel;

use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\Exceptions\ConfigurationException;
use Illuminate\Contracts\Redis\Connector;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Redis\Connectors\PredisConnector;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Laravel\Horizon\Connectors\RedisConnector;

class RedisSentinelManager extends RedisManager
{
    /**
     * Cache for horizon context.
     */
    protected ?bool $isHorizonContext = null;

    public function resolve($name = null)
    {
        $name = $name ?: 'default';

        $normalizedName = $this->patchHorizonConnectionName($name);

        $client = $this->config[$normalizedName]['client'] ?? $this->driver;

        if ($client !== 'phpredis-sentinel') {
            return parent::resolve($name);
        }

        $config = $this->patchHorizonPrefix(
            $name,
            $this->config[$normalizedName]
        );

        return $this
            ->resolveConnector($name)
            ->connect(
                $config,
                $this->config['options'] ?? []
            );
    }

    public function resolveConnector($name = null): Connector|PhpRedisConnector|PredisConnector|RedisSentinelConnector
    {
        $normalizedName = $this->patchHorizonConnectionName($name);

        if (($this->config[$normalizedName]['client'] ?? null) === 'phpredis-sentinel' && isset($this->config['clusters']['name'])) {
            throw new ConfigurationException(
                'Redis Sentinel connections do not support Redis Cluster.'
            );
        }

        if (! isset($this->config[$normalizedName])) {
            throw new ConfigurationException(
                sprintf('No connection defined with base name %s or overwritten name %s in `database.redis` config', $name, $normalizedName)
            );
        }

        $this->setConnectionDriver($normalizedName);

        if ($connector = $this->connector()) {
            return $connector;
        }

        throw new InvalidArgumentException("Redis connection [$name] not configured.");
    }

    public function setConnectionDriver(string $name): void
    {
        $this->driver = $this->config[$name]['client'] ?? $this->driver;
    }

    protected function isHorizonContext(): bool
    {
        if ($this->isHorizonContext === null) {
            $this->isHorizonContext = isset($this->app['config']) &&
                class_exists(RedisConnector::class) &&
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
