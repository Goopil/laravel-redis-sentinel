<?php

namespace Goopil\LaravelRedisSentinel\Commands;

use Carbon\Carbon;
use Goopil\LaravelRedisSentinel\Concerns\EmitsWorkerEvents;
use Goopil\LaravelRedisSentinel\Concerns\Loggable;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\Events\HorizonWorkerAlive;
use Goopil\LaravelRedisSentinel\Events\HorizonWorkerNotAlive;
use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Illuminate\Console\Command;
use Throwable;

class HorizonWorkerLiveness extends Command
{
    use EmitsWorkerEvents;
    use Loggable;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'horizon:alive';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'liveness checks for a worker operating horizon';

    /**
     * Execute the console command.
     *
     * Contract under Redis/Sentinel faults (#49): every check swallows transport
     * failures, so the command always terminates. Exit 0 only when Sentinel is
     * reachable, the connection can write and Horizon reports one running master.
     * During a master outage the write check fails fast (resolve() creates a fresh
     * client, and creation itself throws when the reported master is unreachable),
     * so the command exits 1; once Sentinel promotes, probes self-heal within the
     * node_cache TTL. Runtime is bounded by the connection retry settings —
     * roughly (attempts + 1) x read_timeout + backoff — so size the Kubernetes
     * probe (timeoutSeconds, failureThreshold >= 3) above that bound and a
     * promotion window shorter than the retry budget will not restart the pod.
     */
    public function handle(): int
    {
        $checks = [
            'sentinel' => $this->laravel->call([$this, 'checkSentinel']),
            'connection' => $this->laravel->call([$this, 'checkConnection']),
            'ready' => $this->call('horizon:ready'),
        ];

        $failedChecks = array_filter($checks, fn (int $exitCode) => $exitCode !== 0);

        if ($failedChecks === []) {
            $this->emitSuccessEvent(new HorizonWorkerAlive);

            return 0;
        }

        $this->emitFailureEvent(new HorizonWorkerNotAlive(
            failedChecks: $failedChecks,
        ));

        return 1;
    }

    public function checkSentinel(RedisSentinelManager $manager): int
    {
        $connectionName = config('horizon.use');
        // Same resolution order as RedisSentinelConnector::getService():
        // nested sentinel.service first, then the connection-level key.
        $service = config(sprintf('database.redis.%s.sentinel.service', $connectionName))
            ?? config(sprintf('database.redis.%s.service', $connectionName));
        $client = config(
            sprintf('database.redis.%s.client', $connectionName),
            config('database.redis.client')
        );

        try {
            /** @var RedisSentinelConnector $connector */
            $connector = $manager->resolveConnector($connectionName);

            $data = $connector
                ->createSentinel($connectionName)
                ->getMasterAddrByName($service);

            $result = ! is_array($data) || empty($data)
                ? false
                : ($data['ip'] ?? ($data[0] ?? false));

            return $result ? 0 : 1;
        } catch (Throwable $exception) {
            $this->log('could not get master from redis sentinel service', [
                'connection' => $connectionName,
                'service' => $service,
                'client' => $client,
                'exception' => $exception,
            ]);

            return 1;
        }
    }

    public function checkConnection(RedisSentinelManager $manager): int
    {
        $connectionName = config('horizon.use');
        try {
            $manager
                ->resolve($connectionName)
                ->setex('check:'.php_uname(), 300, Carbon::now()->timestamp);

            return 0;
        } catch (Throwable $exception) {
            $this->log('Connection cannot write', [
                'connection' => $connectionName,
                'exception' => $exception,
            ]);

            return 1;
        }
    }
}
