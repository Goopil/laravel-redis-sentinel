<?php

use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

beforeEach(function () {
    config(['horizon.use' => 'phpredis-sentinel']);
});

test('horizon:alive returns 0 when all checks pass', function () {
    // 1. Mock Sentinel check (returns 1 on success)
    $connector = Mockery::mock(RedisSentinelConnector::class);
    $sentinel = Mockery::mock(RedisSentinel::class);
    $sentinel->allows('getMasterAddrByName')->andReturns(['127.0.0.1', 26379]);
    $connector->allows('createSentinel')->andReturns($sentinel);

    $manager = Mockery::mock(RedisSentinelManager::class);
    $manager->allows('resolveConnector')
        ->with('phpredis-sentinel')
        ->andReturns($connector);

    // 2. Mock Connection check (returns 0 on success)
    $connection = Mockery::mock(Connection::class);
    $connection->allows('set')->andReturns(true);
    $manager->allows('resolve')->with('phpredis-sentinel')->andReturns($connection);

    app()->instance(RedisSentinelManager::class, $manager);

    // 3. Mock horizon:ready (returns 0 on success)
    $master = new stdClass;
    $master->name = gethostname().':1';
    $master->status = 'running';
    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->allows('all')->andReturns([$master]);
    app()->instance(MasterSupervisorRepository::class, $repository);

    $status = Artisan::call('horizon:alive');

    expect($status)->toBe(0);
});

test('horizon:alive returns 1 when sentinel check fails (no master)', function () {
    $connector = Mockery::mock(RedisSentinelConnector::class);
    $sentinel = Mockery::mock(RedisSentinel::class);
    $sentinel->allows('getMasterAddrByName')->andReturns(false);
    $connector->allows('createSentinel')->andReturns($sentinel);

    $manager = Mockery::mock(RedisSentinelManager::class);
    $manager->allows('resolveConnector')
        ->with('phpredis-sentinel')
        ->andReturns($connector);

    $connection = Mockery::mock(Connection::class);
    $connection->allows('set')->andReturns(true);
    $manager->allows('resolve')->andReturns($connection);

    app()->instance(RedisSentinelManager::class, $manager);

    $master = new stdClass;
    $master->name = gethostname().':1';
    $master->status = 'running';
    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->allows('all')->andReturns([$master]);
    app()->instance(MasterSupervisorRepository::class, $repository);

    $status = Artisan::call('horizon:alive');

    expect($status)->toBe(1);
});

test('horizon:alive returns 1 when connection check fails', function () {
    $connector = Mockery::mock(RedisSentinelConnector::class);
    $sentinel = Mockery::mock(RedisSentinel::class);
    $sentinel->allows('getMasterAddrByName')->andReturns(['127.0.0.1', 26379]);
    $connector->allows('createSentinel')->andReturns($sentinel);

    $manager = Mockery::mock(RedisSentinelManager::class);
    $manager->allows('resolveConnector')
        ->with('phpredis-sentinel')
        ->andReturns($connector);

    // Connection fails
    $manager->allows('resolve')->andThrow(new Exception('Connection failed'));

    app()->instance(RedisSentinelManager::class, $manager);

    $master = new stdClass;
    $master->name = gethostname().':1';
    $master->status = 'running';
    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->allows('all')->andReturns([$master]);
    app()->instance(MasterSupervisorRepository::class, $repository);

    $status = Artisan::call('horizon:alive');

    // Currently it might return 0 due to the bug
    expect($status)->toBe(1);
});

test('horizon:alive returns 1 when horizon:ready fails', function () {
    $connector = Mockery::mock(RedisSentinelConnector::class);
    $sentinel = Mockery::mock(RedisSentinel::class);
    $sentinel->allows('getMasterAddrByName')->andReturns(['127.0.0.1', 26379]);
    $connector->allows('createSentinel')->andReturns($sentinel);

    $manager = Mockery::mock(RedisSentinelManager::class);
    $manager->allows('resolveConnector')
        ->with('phpredis-sentinel')
        ->andReturns($connector);

    $connection = Mockery::mock(Connection::class);
    $connection->allows('set')->andReturns(true);
    $manager->allows('resolve')->andReturns($connection);

    app()->instance(RedisSentinelManager::class, $manager);

    // horizon:ready fails (no masters)
    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->allows('all')->andReturns([]);
    app()->instance(MasterSupervisorRepository::class, $repository);

    $status = Artisan::call('horizon:alive');

    expect($status)->toBe(1);
});
