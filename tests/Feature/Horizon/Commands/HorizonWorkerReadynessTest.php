<?php

use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;

test('horizon:ready returns 0 when master is running for this host', function () {
    $master = new stdClass;
    $master->name = MasterSupervisor::basename().'-tst1';
    $master->status = 'running';

    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->allows('all')->andReturns([$master]);
    app()->instance(MasterSupervisorRepository::class, $repository);

    $status = Artisan::call('horizon:ready');

    expect($status)->toBe(0);
});

test('horizon:ready returns 1 when no master is running', function () {
    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->allows('all')->andReturns([]);
    app()->instance(MasterSupervisorRepository::class, $repository);

    $status = Artisan::call('horizon:ready');

    expect($status)->toBe(1);
});

test('horizon:ready returns 1 when master is not running', function () {
    $master = new stdClass;
    $master->name = MasterSupervisor::basename().'-tst1';
    $master->status = 'paused';

    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->allows('all')->andReturns([$master]);
    app()->instance(MasterSupervisorRepository::class, $repository);

    $status = Artisan::call('horizon:ready');

    expect($status)->toBe(1);
});

test('horizon:ready returns 1 when master is for another host', function () {
    $master = new stdClass;
    $master->name = 'other-host:1';
    $master->status = 'running';

    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->allows('all')->andReturns([$master]);
    app()->instance(MasterSupervisorRepository::class, $repository);

    $status = Artisan::call('horizon:ready');

    expect($status)->toBe(1);
});

test('horizon:ready does not match hostname as substring of another host', function () {
    $otherMaster = new stdClass;
    $otherMaster->name = MasterSupervisor::basename().'0-efgh';
    $otherMaster->status = 'running';

    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->allows('all')->andReturns([$otherMaster]);
    app()->instance(MasterSupervisorRepository::class, $repository);

    $status = Artisan::call('horizon:ready');

    expect($status)->toBe(1);
});
