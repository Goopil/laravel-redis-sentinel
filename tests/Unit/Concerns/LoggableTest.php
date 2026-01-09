<?php

namespace Goopil\LaravelRedisSentinel\Tests\Unit\Concerns;

use Illuminate\Support\Facades\Log;

test('log method sends log to the configured channel', function () {
    config(['phpredis-sentinel.log.channel' => 'my-custom-channel']);

    Log::shouldReceive('channel')
        ->once()
        ->with('my-custom-channel')
        ->andReturnSelf();

    Log::shouldReceive('info')
        ->once()
        ->with('[LoggableTestObject] test message', ['foo' => 'bar']);

    $obj = new LoggableTestObject;
    $obj->testLog('test message', ['foo' => 'bar']);
});

test('log method supports different levels', function () {
    config(['phpredis-sentinel.log.channel' => 'stack']);

    Log::shouldReceive('channel')
        ->once()
        ->with('stack')
        ->andReturnSelf();

    Log::shouldReceive('error')
        ->once()
        ->with('[LoggableTestObject] error message', []);

    $obj = new LoggableTestObject;
    $obj->testLog('error message', [], 'error');
});
