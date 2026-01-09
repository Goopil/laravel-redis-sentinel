<?php

namespace Goopil\LaravelRedisSentinel;

use Goopil\LaravelRedisSentinel\Commands\HorizonWorkerLiveness;
use Goopil\LaravelRedisSentinel\Commands\HorizonWorkerPreStop;
use Goopil\LaravelRedisSentinel\Commands\HorizonWorkerReadiness;
use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\Horizon\HorizonServiceBindings;
use Illuminate\Broadcasting\Broadcasters\RedisBroadcaster;
use Illuminate\Cache\RedisStore;
use Illuminate\Container\Container;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\Connectors\RedisConnector;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Connectors\RedisConnector as HorizonRedisConnector;

class RedisSentinelServiceProvider extends ServiceProvider
{
    protected string $name = 'phpredis-sentinel';

    public function register(): void
    {
        $this->registerAssets();
        $this->registerCustomManager();
        $this->registerHorizonBindings();
    }

    public function boot(): void
    {
        $this->bootAssets();
        $this->bootConnector();
        $this->bootOverrides();
        $this->bootBroadcaster();
        $this->bootCacheStore();
        $this->bootSessionHandler();
        $this->bootQueue();
        $this->bootQueueEvents();
        $this->bootCommands();
        $this->bootOctane();
    }

    protected function bootOctane(): void
    {
        if (! $this->app->bound('octane') || ! $this->app->bound('events')) {
            return;
        }

        $this->app['events']->listen('Laravel\Octane\Events\RequestReceived', function () {
            $app = Facade::getFacadeApplication() ?: Container::getInstance();
            if ($app->bound(RedisSentinelManager::class)) {
                $manager = $app->make(RedisSentinelManager::class);

                foreach ($manager->connections() as $connection) {
                    if ($connection instanceof RedisSentinelConnection) {
                        $connection->resetStickiness();
                    }
                }
            }
        });
    }

    public function isHorizonContext(): bool
    {
        return
            class_exists(HorizonRedisConnector::class) &&
            $this->app['config']->get('horizon.driver') === $this->name;
    }

    protected function registerAssets(): void
    {
        $this->mergeConfigFrom(
            __DIR__."/../config/{$this->name}.php",
            $this->name
        );
    }

    protected function registerCustomManager(): void
    {
        $this->app->alias(RedisSentinelManager::class, $this->name);

        $this->app->singleton(
            RedisSentinelManager::class,
            fn ($app) => new RedisSentinelManager(
                $app,
                $app['config']->get('database.redis.client', 'phpredis'),
                $app['config']->get('database.redis', [])
            )
        );

        $this->app->singleton(NodeAddressCache::class, function ($app) {
            return new NodeAddressCache;
        });
        $this->app->bind('redis.sentinel', RedisSentinelConnector::class);
    }

    protected function bootAssets(): void
    {
        $this->publishes([
            __DIR__."/../config/{$this->name}.php" => $this->app->configPath("{$this->name}.php"),
        ], 'config');
    }

    protected function bootOverrides(): void
    {
        $deferredServices = $this->app->getDeferredServices();

        unset(
            $deferredServices['redis'],
            $deferredServices['redis.connection']
        );

        $this->app->setDeferredServices($deferredServices);

        $this->app->singleton(
            'redis',
            fn () => $this->app->make($this->name)
        );

        $this->app->bind(
            'redis.connection',
            fn () => $this->app->make($this->name)->connection()
        );
    }

    protected function bootConnector(): void
    {

        $this->app->make(RedisSentinelManager::class)->extend(
            $this->name,
            fn () => $this->app->make('redis.sentinel')
        );
    }

    protected function bootBroadcaster(): void
    {
        $this->app->make(BroadcastFactory::class)->extend(
            $this->name,
            fn ($app, $conf) => new RedisBroadcaster(
                $this->app->make($this->name),
                Arr::get($conf, 'connection', 'default')
            )
        );
    }

    protected function bootCacheStore(): void
    {
        $this->app->make('cache')->extend(
            $this->name,
            fn ($app, $conf) => $app->make('cache')->repository(
                new RedisStore(
                    $app->make(RedisSentinelManager::class),
                    $app->make('config')->get('cache.prefix'),
                    Arr::get($conf, 'connection', 'default'))
            )
        );
    }

    protected function bootSessionHandler(): void
    {
        $this->app->make('session')->extend(
            $this->name,
            function () {
                $config = $this->app->make('config');
                $cacheDriver = clone $this->app->make('cache')->driver($this->name);
                $cacheDriver->getStore()->setConnection($config->get('session.connection'));

                return new CacheBasedSessionHandler(
                    $cacheDriver,
                    $config->get('session.lifetime')
                );
            }
        );
    }

    protected function registerHorizonBindings(): void
    {
        if (! $this->isHorizonContext()) {
            return;
        }

        collect(new HorizonServiceBindings)
            ->map(fn ($serviceClass) => $this->app->when($serviceClass)
                ->needs(RedisFactory::class)
                ->give(fn () => $this->app->make($this->name))
            );
    }

    protected function bootQueue(): void
    {
        $connector = $this->isHorizonContext()
            ? HorizonRedisConnector::class
            : RedisConnector::class;

        $this->app->make('queue')->extend(
            $this->name,
            fn () => new $connector($this->app->make($this->name))
        );
    }

    protected function bootQueueEvents(): void
    {
        if (! $this->app->bound('events')) {
            return;
        }

        $this->app['events']->listen(JobProcessing::class, function () {
            if (! $this->app->resolved(RedisSentinelManager::class)) {
                return;
            }

            $manager = $this->app->make(RedisSentinelManager::class);

            foreach ($manager->connections() as $connection) {
                if ($connection instanceof RedisSentinelConnection) {
                    $connection->resetStickiness();
                }
            }
        });
    }

    protected function bootCommands(): void
    {
        $this->commands([
            HorizonWorkerLiveness::class,
            HorizonWorkerReadiness::class,
            HorizonWorkerPreStop::class,
        ]);
    }
}
