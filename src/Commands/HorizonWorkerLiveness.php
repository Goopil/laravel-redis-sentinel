<?php

namespace Goopil\LaravelRedisSentinel\Commands;

use Carbon\Carbon;
use Goopil\LaravelRedisSentinel\Concerns\EmitsWorkerEvents;
use Goopil\LaravelRedisSentinel\Concerns\Loggable;
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
        $service = config(sprintf('database.redis.%s.sentinel.service', $connectionName));
        $client = config(
            sprintf('database.redis.%s.client', $connectionName),
            config('database.redis.client')
        );

        try {
            $data = $manager
                ->resolveConnector($connectionName)
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
