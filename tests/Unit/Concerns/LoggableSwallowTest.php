<?php

namespace Goopil\LaravelRedisSentinel\Tests\Unit\Concerns;

use Goopil\LaravelRedisSentinel\Concerns\Loggable;
use Goopil\LaravelRedisSentinel\Concerns\Retryable;
use Illuminate\Support\Facades\Log;
use Mockery;
use ReflectionProperty;
use RuntimeException;

beforeEach(function () {
    $property = new ReflectionProperty(LoggableTestObject::class, 'swallowedLogNotified');
    $property->setAccessible(true);
    $property->setValue(null, false);
});

test('a swallowed logging failure emits exactly one error_log notice when enabled', function () {
    $originalErrorLog = ini_get('error_log');
    $logFile = tempnam(sys_get_temp_dir(), 'log');
    ini_set('error_log', $logFile);

    try {
        config([
            'phpredis-sentinel.log.channel' => 'stack',
            'phpredis-sentinel.log.notify_swallowed' => true,
        ]);

        Log::shouldReceive('channel')->with('stack')->twice()->andThrow(new RuntimeException('log backend down'));
        Log::shouldReceive('channel')->with('stderr')->twice()->andThrow(new RuntimeException('stderr backend down'));

        $obj = new LoggableTestObject;
        $obj->testLog('test message');
        $obj->testLog('test message');

        $contents = (string) file_get_contents($logFile);

        expect(substr_count($contents, '[laravel-redis-sentinel] logging is failing'))->toBe(1)
            ->and($contents)->toContain('log backend down')
            ->and($contents)->toContain('test message');
    } finally {
        ini_set('error_log', $originalErrorLog);
        @unlink($logFile);
    }
});

test('swallowed logging failures stay silent when the notice is disabled', function () {
    $originalErrorLog = ini_get('error_log');
    $logFile = tempnam(sys_get_temp_dir(), 'log');
    ini_set('error_log', $logFile);

    try {
        config([
            'phpredis-sentinel.log.channel' => 'stack',
            'phpredis-sentinel.log.notify_swallowed' => false,
        ]);

        Log::shouldReceive('channel')->with('stack')->twice()->andThrow(new RuntimeException('log backend down'));
        Log::shouldReceive('channel')->with('stderr')->twice()->andThrow(new RuntimeException('stderr backend down'));

        $obj = new LoggableTestObject;
        $obj->testLog('test message');
        $obj->testLog('test message');

        expect(file_get_contents($logFile))->toBe('');
    } finally {
        ini_set('error_log', $originalErrorLog);
        @unlink($logFile);
    }
});

test('a logging outage cannot break the retry loop', function () {
    config([
        'phpredis-sentinel.log.channel' => 'stack',
        // Exercise the swallowed-failure notice path inside the retry loop as well:
        // the notice itself must never break the loop either.
        'phpredis-sentinel.log.notify_swallowed' => true,
    ]);

    Log::shouldReceive('channel')->with('stack')->andThrow(new RuntimeException('log backend down'));

    // When the configured channel is down, the safe-channel retry must deliver
    // the entry to the 'stderr' channel instead of dropping it.
    $stderr = Mockery::mock();
    $stderr->shouldReceive('info')->atLeast()->once()->with(Mockery::pattern('/attempt/'), []);

    Log::shouldReceive('channel')->with('stderr')->andReturn($stderr);

    $obj = new class
    {
        use Loggable;
        use Retryable;

        public int $callCount = 0;

        public function run(): string
        {
            return $this->retryOnFailure(function () {
                $this->callCount++;
                $this->log('attempt '.$this->callCount);

                if ($this->callCount <= 2) {
                    throw new RuntimeException('connection lost');
                }

                return 'success';
            });
        }

        protected function sleepWithBackoff(int $attempt): void {}
    };

    $obj->setRetryMessages(['connection lost']);

    expect($obj->run())->toBe('success')
        ->and($obj->callCount)->toBe(3);
});

test('even the stderr fallback failing cannot break the retry loop', function () {
    config(['phpredis-sentinel.log.channel' => 'stack']);

    Log::shouldReceive('channel')->with('stack')->andThrow(new RuntimeException('log backend down'));
    Log::shouldReceive('channel')->with('stderr')->andThrow(new RuntimeException('stderr down too'));

    $obj = new class
    {
        use Loggable;
        use Retryable;

        public int $callCount = 0;

        public function run(): string
        {
            return $this->retryOnFailure(function () {
                $this->callCount++;
                $this->log('attempt '.$this->callCount);

                if ($this->callCount <= 2) {
                    throw new RuntimeException('connection lost');
                }

                return 'success';
            });
        }

        protected function sleepWithBackoff(int $attempt): void {}
    };

    $obj->setRetryMessages(['connection lost']);

    expect($obj->run())->toBe('success')
        ->and($obj->callCount)->toBe(3);
});
