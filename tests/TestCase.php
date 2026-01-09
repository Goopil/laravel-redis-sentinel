<?php

namespace Goopil\LaravelRedisSentinel\Tests;

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\RedisSentinelServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            RedisSentinelServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Flush the master address cache between tests
        if ($app->bound(NodeAddressCache::class)) {
            $app->make(NodeAddressCache::class)->flush();
        }

        // Load workbench routes
        $this->loadWorkbenchRoutes($app);

        tap($app['config'], static function (Repository $config) {
            $config->set('database.redis.client', 'phpredis');
            $config->set('queue.default', 'redis');
            $config->set('session.driver', 'redis');
            $config->set('cache.default', 'redis');

            $config->set('database.redis.phpredis-sentinel', [
                'client' => 'phpredis-sentinel',
                'sentinel' => [
                    'host' => env('REDIS_SENTINEL_HOST', '127.0.0.1'),
                    'port' => env('REDIS_SENTINEL_PORT', 26379),
                    'service' => env('REDIS_SENTINEL_SERVICE', 'master'),
                    'password' => env('REDIS_SENTINEL_PASSWORD', 'test'),
                ],
                'timeout' => 1,
                'read_timeout' => 1,
                'retry_interval' => 200,
                'persistent' => false,
                'database' => env('REDIS_SENTINEL_DATABASE', '0'),
                'options' => [
                    'prefix' => ('phpredis-sentinel'.env('REDIS_PREFIX', '')),
                ],
            ]);

            $config->set('database.redis.redis', [
                'host' => env('REDIS_HOST', '127.0.0.1'),
                'password' => env('REDIS_PASSWORD', null),
                'port' => env('REDIS_PORT', 6379),
                'database' => env('REDIS_DATABASE', '0'),
                'options' => [
                    'prefix' => ('redis'.env('REDIS_PREFIX', '')),
                ],
            ]);

            $config->set('cache.stores.phpredis-sentinel', [
                'driver' => 'redis',
                'connection' => 'phpredis-sentinel',
                'lock_connection' => 'phpredis-sentinel',
            ]);

            $config->set('phpredis-sentinel.retry.sentinel.delay', 1);
            $config->set('phpredis-sentinel.retry.redis.delay', 1);

            if (file_exists(__DIR__.'/../workbench/config/horizon.php')) {
                $horizonConfig = require __DIR__.'/../workbench/config/horizon.php';
                $config->set('horizon', array_merge($config->get('horizon', []), $horizonConfig));
            }
        });
    }

    protected function loadWorkbenchRoutes($app): void
    {
        $routesPath = __DIR__.'/../workbench/routes/web.php';
        if (file_exists($routesPath)) {
            $app['router']->middleware('web')
                ->group($routesPath);
        }
    }
}
