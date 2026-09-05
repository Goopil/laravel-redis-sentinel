<?php

namespace Workbench\App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;

/**
 * Boots the Sentinel configuration and the soak endpoint inside the real
 * Octane/Swoole server process (vendor/bin/testbench octane:start).
 *
 * Registered via the root testbench.yaml: the testbench CLI copies it into
 * its skeleton, so the Octane workers re-bootstrapped by Octane's own
 * ApplicationFactory resolve it too. The OCTANE_WORKER env guard keeps the
 * in-process suite (tests/TestCase.php configuration) untouched.
 */
class OctaneSoakProvider extends ServiceProvider
{
    public function register(): void
    {
        if (getenv('OCTANE_WORKER') !== '1') {
            return;
        }

        $this->app['config']->set('database.redis.phpredis-sentinel', [
            'client' => 'phpredis-sentinel',
            'sentinel' => [
                'host' => env('REDIS_SENTINEL_HOST', '127.0.0.1'),
                'port' => (int) env('REDIS_SENTINEL_PORT', 26379),
                'service' => env('REDIS_SENTINEL_SERVICE', 'master'),
                'password' => env('REDIS_SENTINEL_PASSWORD', 'test'),
            ],
            'password' => env('REDIS_PASSWORD', 'test'),
            'timeout' => 5,
            'read_timeout' => 5,
            'retry_interval' => 50,
            'persistent' => false,
            'database' => 0,
            'read_only_replicas' => true,
            'options' => [
                'prefix' => 'octane-soak:',
            ],
        ]);

        $this->app['config']->set('phpredis-sentinel.retry.sentinel.delay', 1);
        $this->app['config']->set('phpredis-sentinel.retry.redis.delay', 1);
    }

    public function boot(): void
    {
        if (getenv('OCTANE_WORKER') !== '1') {
            return;
        }

        // ponytail: no SWOOLE_HOOKS here - phpredis + Swoole hooks crash the
        // worker (status 255). Coroutines still get their own cid-scoped state
        // (the leak surface this soak exercises); their blocking I/O simply
        // serializes instead of interleaving.

        // Read-split request surface: fresh execution context per request;
        // with c>1 the request itself spawns coroutines sharing the worker's
        // connection, exercising the per-coroutine state lifecycle.
        Route::get('/octane-redis-soak', function (Request $request) {
            $concurrency = max(1, (int) $request->query('c', '1'));
            $key = 'key:'.$request->query('k', '0');
            $value = 'v'.$request->query('k', '0');

            $connection = app('redis')->connection('phpredis-sentinel');

            if ($concurrency === 1) {
                $replica = $connection->get($key);
                $connection->setex($key, 300, $value);
                $master = $connection->get($key);

                return response()->json([
                    'replica' => $replica,
                    'master' => $master,
                ]);
            }

            $results = [];
            $waitGroup = new WaitGroup;

            for ($i = 0; $i < $concurrency; $i++) {
                $waitGroup->add();

                Coroutine::create(function () use (&$results, $waitGroup, $connection, $key, $value, $i) {
                    try {
                        // Even coroutines take the write path (sticky master),
                        // odd ones the read path (replica client in split mode).
                        if ($i % 2 === 0) {
                            $connection->setex("{$key}:w{$i}", 300, $value);
                            $results[$i] = ['value' => $connection->get("{$key}:w{$i}"), 'coroutine' => Coroutine::getCid() > 0];
                        } else {
                            $results[$i] = ['value' => $connection->get("{$key}:r{$i}"), 'coroutine' => Coroutine::getCid() > 0];
                        }
                    } finally {
                        $waitGroup->done();
                    }
                });
            }

            $waitGroup->wait(30);

            ksort($results);

            return response()->json([
                'results' => array_values($results),
                'master' => $results[0]['value'] ?? null,
                'coroutine' => count($results) === $concurrency
                    && ! in_array(false, array_column($results, 'coroutine'), true),
            ]);
        });
    }
}
