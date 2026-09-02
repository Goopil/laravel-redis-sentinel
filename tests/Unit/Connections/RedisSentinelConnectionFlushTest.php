<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;

test('flushall sends ASYNC only when sync is false', function (?bool $sync, array $expectedArgs) {
    $connection = Mockery::mock(RedisSentinelConnection::class)->makePartial();
    $connection->shouldReceive('command')->once()->with('flushall', $expectedArgs)->andReturnTrue();

    expect($connection->flushall($sync))->toBeTrue();
})->with([
    'default sync' => [null, []],
    'explicit sync' => [true, []],
    'async' => [false, ['ASYNC']],
]);

test('flushdb sends ASYNC only when async is true', function ($async, array $expectedArgs) {
    $connection = Mockery::mock(RedisSentinelConnection::class)->makePartial();
    $connection->shouldReceive('command')->once()->with('flushdb', $expectedArgs)->andReturnTrue();

    expect($connection->flushdb($async))->toBeTrue();
})->with([
    'default sync' => [null, []],
    'async' => [true, ['ASYNC']],
    'explicit sync' => [false, []],
]);

test('flush methods reset master stickiness even when the command fails', function () {
    $connection = Mockery::mock(RedisSentinelConnection::class)->makePartial();
    $connection->shouldReceive('command')->once()->with('flushall', [])->andThrow(new RuntimeException('boom'));

    expect(fn () => $connection->flushall())->toThrow(RuntimeException::class);

    $stickiness = new ReflectionProperty(RedisSentinelConnection::class, 'wroteToMaster');

    expect($stickiness->getValue($connection))->toBeFalse();
});
