<?php

use Goopil\LaravelRedisSentinel\Commands\SentinelStatus;
use Illuminate\Support\Facades\Artisan;

test('sentinel:status prints the topology of a live connection', function () {
    $status = Artisan::call('sentinel:status', ['--connection' => ['phpredis-sentinel']]);
    $output = Artisan::output();

    expect($status)->toBe(0)
        ->and($output)->toContain('phpredis-sentinel')
        ->and($output)->toContain('127.0.0.1')
        ->and($output)->toContain('master');
});

test('sentinel:status --json emits decodable topology', function () {
    $status = Artisan::call('sentinel:status', ['--connection' => ['phpredis-sentinel'], '--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($status)->toBe(0)
        ->and($payload['phpredis-sentinel']['master']['ip'] ?? '')->toBe('127.0.0.1')
        ->and($payload['phpredis-sentinel']['service'] ?? '')->not->toBe('')
        ->and($payload['phpredis-sentinel']['replicas'] ?? null)->toBeArray()
        ->and($payload['phpredis-sentinel']['sentinels'] ?? null)->toBeArray();
});

test('sentinel:status exits 1 and reports the error when sentinel is unreachable', function () {
    config([
        'phpredis-sentinel.retry.sentinel.attempts' => 1,
        'database.redis.phpredis-sentinel' => [
            'client' => 'phpredis-sentinel',
            'sentinel' => [
                'host' => '127.0.0.1',
                'port' => 59999,
                'service' => 'master',
                'password' => 'test',
            ],
            'password' => 'test',
            'timeout' => 1,
            'read_timeout' => 1,
        ],
    ]);

    $status = Artisan::call('sentinel:status', ['--connection' => ['phpredis-sentinel']]);
    $output = Artisan::output();

    expect($status)->toBe(1)
        ->and($output)->toContain('phpredis-sentinel')
        ->and($output)->not->toContain('Stack trace');
});

test('formatEvent renders switch-master events with a promotion arrow', function () {
    $line = SentinelStatus::formatEvent('+switch-master', 'master 127.0.0.1 6380 127.0.0.1 6381');
    $other = SentinelStatus::formatEvent('+sdown', 'master master 127.0.0.1 6380');

    expect($line)->toBe('[+switch-master] master 127.0.0.1:6380 -> 127.0.0.1:6381')
        ->and($other)->toBe('[+sdown] master master 127.0.0.1 6380');
});
