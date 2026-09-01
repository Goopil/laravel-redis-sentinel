<?php

use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

test('horizon:ready returns 0 when master is running for this host', function () {
    $master = new stdClass;
    $master->name = gethostname().':1';
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
    $master->name = gethostname().':1';
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
    $realHostname = gethostname();

    $otherMaster = new stdClass;
    $otherMaster->name = $realHostname.'0:1';
    $otherMaster->status = 'running';

    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->allows('all')->andReturns([$otherMaster]);
    app()->instance(MasterSupervisorRepository::class, $repository);

    $status = Artisan::call('horizon:ready');

    expect($status)->toBe(1);
});
