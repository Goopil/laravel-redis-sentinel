<?php

use Goopil\LaravelRedisSentinel\Events\HorizonWorkerAlive;
use Goopil\LaravelRedisSentinel\Events\HorizonWorkerNotAlive;
use Goopil\LaravelRedisSentinel\Events\HorizonWorkerNotReady;
use Goopil\LaravelRedisSentinel\Events\HorizonWorkerReady;
use Goopil\LaravelRedisSentinel\Events\HorizonWorkerTerminateFailed;
use Goopil\LaravelRedisSentinel\Events\HorizonWorkerTerminating;
use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\HorizonServiceProvider;
use Laravel\Horizon\MasterSupervisor;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;

const HORIZON_EVENTS_NOT_INSTALLED = 'Horizon not installed';

function horizonEventsRunningMaster(): MasterSupervisor
{
    $master = new MasterSupervisor;
    $master->name = gethostname().':1';
    $master->status = 'running';

    return $master;
}

describe('Horizon Command Events', function () {
    beforeEach(function () {
        if (class_exists(HorizonServiceProvider::class)) {
            app()->register(HorizonServiceProvider::class);
        }

        config(['horizon.driver' => 'phpredis-sentinel']);
        config(['horizon.use' => 'phpredis-sentinel']);
        config(['database.redis.horizon' => config('database.redis.phpredis-sentinel')]);

        app()->forgetInstance(RedisSentinelManager::class);
        app()->forgetInstance('redis');

        // Re-register the connector on the new manager
        $manager = app(RedisSentinelManager::class);
        $manager->extend('phpredis-sentinel', function () {
            return app('redis.sentinel');
        });

        Event::fake();
    });

    test('horizon:ready emits no success event by default', function () {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            $this->markTestSkipped(HORIZON_EVENTS_NOT_INSTALLED);
        }

        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) {
            $mock->expects('all')->andReturn([horizonEventsRunningMaster()]);
        });

        $exitCode = Artisan::call('horizon:ready');

        expect($exitCode)->toBe(0)
            ->and(Event::assertNotDispatched(HorizonWorkerReady::class));
    });

    test('horizon:ready emits HorizonWorkerReady when emit_success is enabled', function () {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            $this->markTestSkipped(HORIZON_EVENTS_NOT_INSTALLED);
        }

        config(['phpredis-sentinel.commands.events.emit_success' => true]);

        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) {
            $mock->expects('all')->andReturn([horizonEventsRunningMaster()]);
        });

        $exitCode = Artisan::call('horizon:ready');

        expect($exitCode)->toBe(0);

        Event::assertDispatched(HorizonWorkerReady::class);
    });

    test('horizon:ready emits HorizonWorkerReady when emit_success is set to "1"', function () {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            $this->markTestSkipped(HORIZON_EVENTS_NOT_INSTALLED);
        }

        config(['phpredis-sentinel.commands.events.emit_success' => '1']);

        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) {
            $mock->expects('all')->andReturn([horizonEventsRunningMaster()]);
        });

        $exitCode = Artisan::call('horizon:ready');

        expect($exitCode)->toBe(0);

        Event::assertDispatched(HorizonWorkerReady::class);
    });

    test('horizon:ready always emits HorizonWorkerNotReady on failure with masters context', function () {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            $this->markTestSkipped(HORIZON_EVENTS_NOT_INSTALLED);
        }

        $master = new MasterSupervisor;
        $master->name = gethostname().':1';
        $master->status = 'paused';

        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) use ($master) {
            $mock->expects('all')->twice()->andReturn([$master]);
        });

        $exitCode = Artisan::call('horizon:ready');

        expect($exitCode)->toBe(1);

        Event::assertDispatched(HorizonWorkerNotReady::class, function (HorizonWorkerNotReady $event) use ($master) {
            return $event->masters === [$master] && $event->running === [];
        });
    });

    test('a throwing listener does not change the probe exit code', function () {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            $this->markTestSkipped(HORIZON_EVENTS_NOT_INSTALLED);
        }

        // Reach real listeners: unwrap the fake dispatcher registered in beforeEach.
        $dispatcher = Event::getFacadeRoot()->dispatcher;
        Event::swap($dispatcher);

        $listenerInvoked = false;
        Event::listen(HorizonWorkerNotReady::class, function (HorizonWorkerNotReady $event) use (&$listenerInvoked): void {
            $listenerInvoked = true;

            throw new RuntimeException('listener failed');
        });

        $master = new MasterSupervisor;
        $master->name = gethostname().':1';
        $master->status = 'paused';

        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) use ($master) {
            $mock->expects('all')->twice()->andReturn([$master]);
        });

        $exitCode = Artisan::call('horizon:ready');

        expect($exitCode)->toBe(1)
            ->and($listenerInvoked)->toBeTrue();
    });

    test('horizon:alive emits no event by default', function () {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            $this->markTestSkipped(HORIZON_EVENTS_NOT_INSTALLED);
        }

        config(['horizon.use' => 'phpredis-sentinel']);
        config(['database.redis.phpredis-sentinel.sentinel.service' => 'master']);

        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) {
            $mock->expects('all')->andReturn([horizonEventsRunningMaster()]);
        });

        $exitCode = Artisan::call('horizon:alive');

        expect($exitCode)->toBe(0)
            ->and(Event::assertNotDispatched(HorizonWorkerAlive::class));
    });

    test('horizon:alive emits HorizonWorkerAlive when emit_success is enabled', function () {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            $this->markTestSkipped(HORIZON_EVENTS_NOT_INSTALLED);
        }

        config([
            'phpredis-sentinel.commands.events.emit_success' => true,
            'horizon.use' => 'phpredis-sentinel',
            'database.redis.phpredis-sentinel.sentinel.service' => 'master',
        ]);

        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) {
            $mock->expects('all')->andReturn([horizonEventsRunningMaster()]);
        });

        $exitCode = Artisan::call('horizon:alive');

        expect($exitCode)->toBe(0);

        Event::assertDispatched(HorizonWorkerAlive::class);
    });

    test('horizon:alive always emits HorizonWorkerNotAlive with failed checks on failure', function () {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            $this->markTestSkipped(HORIZON_EVENTS_NOT_INSTALLED);
        }

        config(['horizon.use' => 'phpredis-sentinel']);
        config(['database.redis.phpredis-sentinel.sentinel.service' => 'master']);

        // Build the master before mocking the manager: MasterSupervisor's
        // constructor flushes Horizon's command queue through the redis manager.
        $master = horizonEventsRunningMaster();

        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->allows('set')->andReturnTrue();

        $this->mock(RedisSentinelManager::class, function (MockInterface $mock) use ($connection) {
            $mock->allows('resolveConnector')->andThrow(new RuntimeException('sentinel unavailable'));
            $mock->allows('resolve')->andReturn($connection);
        });

        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) use ($master) {
            $mock->expects('all')->andReturn([$master]);
        });

        $exitCode = Artisan::call('horizon:alive');

        expect($exitCode)->toBe(1);

        Event::assertDispatched(HorizonWorkerNotAlive::class, function (HorizonWorkerNotAlive $event) {
            return $event->failedChecks === ['sentinel' => 1];
        });
    });

    test('horizon:pre-stop emits HorizonWorkerTerminateFailed when no process is found', function () {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            $this->markTestSkipped(HORIZON_EVENTS_NOT_INSTALLED);
        }

        if (! extension_loaded('pcntl') || ! extension_loaded('posix')) {
            $this->markTestSkipped('pcntl or posix extension not loaded');
        }

        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) {
            $master = new MasterSupervisor;
            $master->name = gethostname().':1';
            $master->pid = 999999; // Non-existent PID likely

            $mock->expects('all')->andReturn([$master]);
        });

        $exitCode = Artisan::call('horizon:pre-stop', ['--start-command' => 'php artisan horizon']);

        expect($exitCode)->toBe(0);

        Event::assertDispatched(HorizonWorkerTerminateFailed::class, function (HorizonWorkerTerminateFailed $event) {
            return $event->pid === 999999
                && $event->startCommand === 'php artisan horizon'
                && $event->reason !== '';
        });

        Event::assertNotDispatched(HorizonWorkerTerminating::class);
    });

    test('horizon:pre-stop emits HorizonWorkerTerminating when TERM signal is sent', function () {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            $this->markTestSkipped(HORIZON_EVENTS_NOT_INSTALLED);
        }

        if (! extension_loaded('pcntl') || ! extension_loaded('posix')) {
            $this->markTestSkipped('pcntl or posix extension not loaded');
        }

        config(['phpredis-sentinel.commands.events.emit_success' => true]);

        $process = Process::fromShellCommandline('sleep 5 & echo $!');
        $process->run();
        $pid = (int) trim($process->getOutput());

        expect($pid)->toBeGreaterThan(0);

        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) use ($pid) {
            $master = new MasterSupervisor;
            $master->name = gethostname().':1';
            $master->pid = $pid;

            $mock->expects('all')->andReturn([$master]);
        });

        $exitCode = Artisan::call('horizon:pre-stop', ['--start-command' => 'sleep 5']);

        expect($exitCode)->toBe(0);

        Event::assertDispatched(HorizonWorkerTerminating::class, function (HorizonWorkerTerminating $event) use ($pid) {
            return $event->pid === $pid && $event->startCommand === 'sleep 5';
        });

        Event::assertNotDispatched(HorizonWorkerTerminateFailed::class);
    });
});
