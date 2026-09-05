<?php

use Goopil\LaravelRedisSentinel\Events\HorizonWorkerAlive;
use Goopil\LaravelRedisSentinel\Events\HorizonWorkerNotAlive;
use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;

beforeEach(function () {
    if (! interface_exists(MasterSupervisorRepository::class)) {
        $this->markTestSkipped('laravel/horizon is not installed.');
    }
});

/**
 * Horizon's own provider is not booted in the testbench app, so the repository
 * interface has no concrete binding. The chaos variable under test is the
 * Redis path, not Horizon's supervisor registry: the alive checks bind a
 * running-master fixture, while ready/pre-stop bind a repository whose all()
 * performs a real Redis read through the sentinel connection, reproducing
 * Horizon's failure mode when the connection is down.
 */
function bindRunningHorizonMaster(): void
{
    $master = new stdClass;
    $master->name = MasterSupervisor::basename().'-tst1';
    $master->status = 'running';

    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->allows('all')->andReturns([$master]);
    app()->instance(MasterSupervisorRepository::class, $repository);
}

function bindRedisBackedHorizonRepository(): void
{
    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->allows('all')->andReturnUsing(function (): array {
        return (array) app('redis')->connection('phpredis-sentinel')->hgetall('master:supervisors');
    });
    app()->instance(MasterSupervisorRepository::class, $repository);
}

function purgeHorizonConnection(): void
{
    $manager = app(RedisSentinelManager::class);

    $reflection = new ReflectionClass($manager);
    $configProperty = $reflection->getProperty('config');
    $configProperty->setValue($manager, config('database.redis'));

    $manager->purge('phpredis-sentinel');
}

describe('Horizon probe commands under chaos', function () {
    test('horizon:alive fails gracefully and bounded during a hard master outage', function () {
        bindRunningHorizonMaster();
        Event::fake([HorizonWorkerAlive::class, HorizonWorkerNotAlive::class]);
        config()->set('phpredis-sentinel.retry.redis.attempts', 2);
        purgeHorizonConnection();

        $connection = $this->sentinelConnectionWithRetry();
        $this->toxiproxy->disable($this->masterProxyName());

        $start = microtime(true);
        $status = Artisan::call('horizon:alive');
        $duration = microtime(true) - $start;

        expect($status)->toBe(1)
            ->and($duration)->toBeLessThan(15, 'Probe must return well before any sane k8s probe deadline')
            ->and(Event::dispatched(HorizonWorkerNotAlive::class)->count())->toBe(1)
            ->and(Event::dispatched(HorizonWorkerAlive::class)->count())->toBe(0);
    });

    test('horizon:alive fails fast during a promotion window and self-heals once Sentinel promotes', function () {
        bindRunningHorizonMaster();
        // Success events are opt-in; failure events always fire.
        config()->set('phpredis-sentinel.commands.events.emit_success', true);
        Event::fake([HorizonWorkerAlive::class, HorizonWorkerNotAlive::class]);
        purgeHorizonConnection();

        $connection = $this->sentinelConnectionWithRetry();
        $oldAddress = ['ip' => '127.0.0.1', 'port' => $this->connectedMasterPort()];

        $this->toxiproxy->disable($this->masterProxyName());

        $start = microtime(true);
        $duringWindow = Artisan::call('horizon:alive');
        $windowDuration = microtime(true) - $start;

        expect($duringWindow)->toBe(1, 'While Sentinel still reports the dead master, the probe fails fast')
            ->and($windowDuration)->toBeLessThan(10)
            ->and(Event::dispatched(HorizonWorkerNotAlive::class)->count())->toBe(1);

        $this->waitForMasterChange($oldAddress, timeoutSeconds: 40);

        // Self-healing is bounded by the node address cache TTL: the probe's fresh
        // connection resolves through it, so it may keep failing until the cache
        // expires before picking up the promoted master.
        $healed = waitFor(fn () => Artisan::call('horizon:alive') === 0, timeoutMs: 25000);

        expect($healed)->toBeTrue()
            ->and(Event::dispatched(HorizonWorkerAlive::class)->count())->toBe(1)
            // Every probe that ran during the node-cache TTL window also emitted
            // its failure event, so exactly one is not guaranteed here.
            ->and(Event::dispatched(HorizonWorkerNotAlive::class)->count())->toBeGreaterThanOrEqual(1);
    });

    test('horizon:alive under a black-holing master returns within the documented bound', function () {
        bindRunningHorizonMaster();
        Event::fake([HorizonWorkerAlive::class, HorizonWorkerNotAlive::class]);

        // attempts=2, read_timeout=1 (TestCase): bound = 2 x 1s + backoff, kept
        // under Sentinel's 5s down-after so the outage cannot drift into a promotion.
        config()->set('phpredis-sentinel.retry.redis.attempts', 2);
        purgeHorizonConnection();

        $connection = $this->sentinelConnectionWithRetry();
        $toxic = $this->toxiproxy->addTimeout($this->masterProxyName(), 1500);

        $start = microtime(true);
        $status = Artisan::call('horizon:alive');
        $duration = microtime(true) - $start;

        $this->toxiproxy->removeToxic($this->masterProxyName(), $toxic);

        expect($status)->toBe(1)
            ->and($duration)->toBeLessThan(10, 'Probe runtime must track attempts x read_timeout, not the k8s deadline');
    });

    test('horizon:ready fails fast and bounded when Redis is unreachable', function () {
        bindRedisBackedHorizonRepository();
        config()->set('phpredis-sentinel.retry.redis.attempts', 2);
        purgeHorizonConnection();

        $connection = $this->sentinelConnectionWithRetry();
        $this->toxiproxy->disable($this->masterProxyName());

        $start = microtime(true);

        try {
            $status = Artisan::call('horizon:ready');
        } catch (RedisException) {
            // The artisan CLI catches this and exits 1; Artisan::call's harness
            // rethrows instead. Either way the probe surfaces a bounded failure.
            $status = 1;
        }
        $duration = microtime(true) - $start;

        expect($status)->toBe(1)
            ->and($duration)->toBeLessThan(15);
    });

    test('horizon:pre-stop completes bounded when Redis is unreachable', function () {
        bindRedisBackedHorizonRepository();
        config()->set('phpredis-sentinel.retry.redis.attempts', 2);
        purgeHorizonConnection();

        $connection = $this->sentinelConnectionWithRetry();
        $this->toxiproxy->disable($this->masterProxyName());

        $start = microtime(true);

        try {
            $status = Artisan::call('horizon:pre-stop');
        } catch (RedisException) {
            // The artisan CLI catches this and exits 1; k8s ignores preStop hook
            // failures either way — the contract is the bounded completion.
            $status = 1;
        }
        $duration = microtime(true) - $start;

        expect($status)->toBe(1)
            ->and($duration)->toBeLessThan(15, 'A pre-stop hook must never hang the pod teardown');
    });
});
