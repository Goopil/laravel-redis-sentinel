<?php

use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\HorizonServiceProvider;
use Laravel\Horizon\MasterSupervisor;
use Mockery\MockInterface;

const HORIZON_NOT_INSTALLED = 'Horizon not installed';

describe('Horizon Commands', function () {
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
    });

    test('horizon:ready returns 0 when master supervisor is running', function () {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            $this->markTestSkipped(HORIZON_NOT_INSTALLED);
        }

        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) {
            $master = new MasterSupervisor;
            $master->name = MasterSupervisor::basename().'-tst1';
            $master->status = 'running';

            $mock->expects('all')->andReturn([$master]);
        });

        $exitCode = Artisan::call('horizon:ready');
        expect($exitCode)->toBe(0);
    });

    test('horizon:ready returns 1 when no master supervisor is running', function () {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            $this->markTestSkipped(HORIZON_NOT_INSTALLED);
        }

        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) {
            $mock->expects('all')->twice()->andReturn([]);
        });

        $exitCode = Artisan::call('horizon:ready');
        expect($exitCode)->toBe(1);
    });

    test('horizon:alive returns 0 when all checks pass', function () {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            $this->markTestSkipped(HORIZON_NOT_INSTALLED);
        }

        // Mock horizon:ready to return 0
        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) {
            $master = new MasterSupervisor;
            $master->name = MasterSupervisor::basename().'-tst1';
            $master->status = 'running';
            $mock->expects('all')->andReturn([$master]);
        });

        // Config for horizon:alive
        config(['horizon.use' => 'phpredis-sentinel']);
        config(['database.redis.phpredis-sentinel.sentinel.service' => 'master']);

        $exitCode = Artisan::call('horizon:alive');
        expect($exitCode)->toBe(0);
    });

    test('horizon:pre-stop finds PID and sends TERM signal', function () {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            $this->markTestSkipped(HORIZON_NOT_INSTALLED);
        }

        if (! extension_loaded('pcntl') || ! extension_loaded('posix')) {
            $this->markTestSkipped('pcntl or posix extension not loaded');
        }

        // We can't easily test sending a signal to a real process without risk.
        // But we can check if it attempts to find the PID.

        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) {
            $master = new MasterSupervisor;
            $master->name = MasterSupervisor::basename().'-tst1';
            $master->pid = 999999; // Non-existent PID likely
            $mock->expects('all')->andReturn([$master]);
        });

        // Note: posix_kill will fail if PID doesn't exist or no permission.
        // The command reports the failure and returns 1.

        $exitCode = Artisan::call('horizon:pre-stop');
        expect($exitCode)->toBe(1);
    });

    test('horizon:ready does not match a master whose hostname extends ours', function () {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            $this->markTestSkipped(HORIZON_NOT_INSTALLED);
        }

        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) {
            $mine = new MasterSupervisor;
            $mine->name = MasterSupervisor::basename().'-abcd';
            $mine->status = 'running';

            $extended = new MasterSupervisor;
            $extended->name = MasterSupervisor::basename().'1-efgh'; // worker-11 when we are worker-1
            $extended->status = 'running';

            $mock->expects('all')->andReturn([$mine, $extended]);
        });

        $exitCode = Artisan::call('horizon:ready');
        expect($exitCode)->toBe(0);
    });

    test('horizon:ready returns 1 when only an extended-hostname master is running', function () {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            $this->markTestSkipped(HORIZON_NOT_INSTALLED);
        }

        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) {
            $extended = new MasterSupervisor;
            $extended->name = MasterSupervisor::basename().'1-efgh';
            $extended->status = 'running';

            $mock->expects('all')->twice()->andReturn([$extended]);
        });

        $exitCode = Artisan::call('horizon:ready');
        expect($exitCode)->toBe(1);
    });
});
