<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;

test('flushall sends the ASYNC selector only when sync is false', function (?bool $sync, array $expectedArgs) {
    $connection = Mockery::mock(RedisSentinelConnection::class)->makePartial();
    $connection->shouldReceive('command')->once()->with('flushall', $expectedArgs)->andReturnTrue();

    expect($connection->flushall($sync))->toBeTrue();
})->with([
    'default sync' => [null, []],
    'explicit sync' => [true, []],
    'async' => [false, expectedAsyncFlushArgs()],
]);

test('flushdb sends the ASYNC selector only when async is true', function ($async, array $expectedArgs) {
    $connection = Mockery::mock(RedisSentinelConnection::class)->makePartial();
    $connection->shouldReceive('command')->once()->with('flushdb', $expectedArgs)->andReturnTrue();

    expect($connection->flushdb($async))->toBeTrue();
})->with([
    'default sync' => [null, []],
    'async' => [true, expectedAsyncFlushArgs()],
    'explicit sync' => [false, []],
]);

test('flush methods reset master stickiness even when the command fails', function () {
    $connection = Mockery::mock(RedisSentinelConnection::class)->makePartial();
    $connection->shouldReceive('command')->once()->with('flushall', [])->andThrow(new RuntimeException('boom'));

    connectionState($connection)->wroteToMaster = true;

    expect(fn () => $connection->flushall())->toThrow(RuntimeException::class)
        ->and(connectionState($connection)->wroteToMaster)->toBeFalse();
});

/**
 * phpredis 6.x inverted flushall/flushdb argument semantics (true = SYNC, false = ASYNC,
 * verified on the wire with MONITOR); 5.x selected ASYNC for any truthy argument. The
 * async rows must assert what the installed runtime sends.
 */
function expectedAsyncFlushArgs(): array
{
    return version_compare((string) phpversion('redis'), '6.0', '>=') ? [false] : ['ASYNC'];
}
