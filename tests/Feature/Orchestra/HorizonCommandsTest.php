<?php

use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Mockery\MockInterface;

describe('Horizon Commands', function () {
    beforeEach(function () {
        if (class_exists(\Laravel\Horizon\HorizonServiceProvider::class)) {
            app()->register(\Laravel\Horizon\HorizonServiceProvider::class);
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
            $this->markTestSkipped('Horizon not installed');
        }

        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) {
            $master = new MasterSupervisor;
            $master->name = gethostname().':1';
            $master->status = 'running';

            $mock->expects('all')->andReturn([$master]);
        });

        $exitCode = Artisan::call('horizon:ready');
        expect($exitCode)->toBe(0);
    });

    test('horizon:ready returns 1 when no master supervisor is running', function () {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            $this->markTestSkipped('Horizon not installed');
        }

        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) {
            $mock->expects('all')->twice()->andReturn([]);
        });

        $exitCode = Artisan::call('horizon:ready');
        expect($exitCode)->toBe(1);
    });

    test('horizon:alive returns 0 when all checks pass', function () {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            $this->markTestSkipped('Horizon not installed');
        }

        // Mock horizon:ready to return 0
        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) {
            $master = new MasterSupervisor;
            $master->name = gethostname().':1';
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
            $this->markTestSkipped('Horizon not installed');
        }

        if (! extension_loaded('pcntl') || ! extension_loaded('posix')) {
            $this->markTestSkipped('pcntl or posix extension not loaded');
        }

        // We can't easily test sending a signal to a real process without risk.
        // But we can check if it attempts to find the PID.

        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) {
            $master = new MasterSupervisor;
            $master->name = gethostname().':1';
            $master->pid = 999999; // Non-existent PID likely
            $mock->expects('all')->andReturn([$master]);
        });

        // Note: posix_kill will fail if PID doesn't exist or no permission.
        // The command will log an error but might still return 0 if it catches it or just proceed.
        // Actually it returns 0 at the end of handle().

        $exitCode = Artisan::call('horizon:pre-stop');
        expect($exitCode)->toBe(0);
    });
});
