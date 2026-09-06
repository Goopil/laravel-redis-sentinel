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

test('sentinel:status rejects unknown or non-sentinel connection names', function () {
    $status = Artisan::call('sentinel:status', ['--connection' => ['does-not-exist']]);
    $output = Artisan::output();

    expect($status)->toBe(1)
        ->and($output)->toContain('Unknown or non-Sentinel connection(s): does-not-exist');
});

test('sentinel:status errors when no sentinel connection is configured', function () {
    config([
        'database.redis' => [
            'client' => 'phpredis',
            'redis' => ['host' => '127.0.0.1', 'port' => 6379],
        ],
    ]);

    $status = Artisan::call('sentinel:status');
    $output = Artisan::output();

    expect($status)->toBe(1)
        ->and($output)->toContain('No Redis Sentinel connection defined');
});

test('sentinel:status detects connections via the global database.redis.client', function () {
    // The documented shape: the client driver is set globally and sentinel
    // connections do not re-declare it. Detection must honor the same
    // precedence as the manager and the liveness command.
    config([
        'database.redis' => [
            'client' => 'phpredis-sentinel',
            'phpredis-sentinel' => [
                'sentinel' => [
                    'host' => env('REDIS_SENTINEL_HOST', '127.0.0.1'),
                    'port' => env('REDIS_SENTINEL_PORT', 26379),
                    'service' => env('REDIS_SENTINEL_SERVICE', 'master'),
                    'password' => env('REDIS_SENTINEL_PASSWORD', 'test'),
                ],
                'password' => env('REDIS_PASSWORD', 'test'),
                'timeout' => 1,
                'read_timeout' => 1,
            ],
        ],
    ]);

    $status = Artisan::call('sentinel:status', ['--connection' => ['phpredis-sentinel']]);
    $output = Artisan::output();

    expect($status)->toBe(0)
        ->and($output)->toContain('phpredis-sentinel')
        ->and($output)->toContain('master');
});

test('sentinel:status lets a per-connection client override exclude a connection', function () {
    config([
        'database.redis' => [
            'client' => 'phpredis-sentinel',
            'phpredis-sentinel' => [
                'client' => 'phpredis',
                'sentinel' => ['host' => '127.0.0.1', 'port' => 26379, 'service' => 'master'],
            ],
        ],
    ]);

    $status = Artisan::call('sentinel:status');
    $output = Artisan::output();

    expect($status)->toBe(1)
        ->and($output)->toContain('No Redis Sentinel connection defined');
});

test('sentinel:status --watch refuses to guess between multiple connections', function () {
    // TestCase defines two sentinel connections; without a filter the command
    // must ask the operator to pick one instead of watching silently.
    $status = Artisan::call('sentinel:status', ['--watch' => true]);
    $output = Artisan::output();

    expect($status)->toBe(1)
        ->and($output)->toContain('Pass exactly one --connection');
});

test('sentinel:status --watch refuses TLS sentinels', function () {
    $status = Artisan::call('sentinel:status', [
        '--connection' => ['phpredis-sentinel-tls'],
        '--watch' => true,
    ]);
    $output = Artisan::output();

    expect($status)->toBe(1)
        ->and($output)->toContain('--watch does not support TLS sentinels');
});

test('sentinel:status --watch reports unreachable sentinels', function () {
    config([
        'database.redis.phpredis-sentinel' => [
            'client' => 'phpredis-sentinel',
            'sentinel' => ['host' => '127.0.0.1', 'port' => 59999, 'service' => 'master'],
        ],
    ]);

    $status = Artisan::call('sentinel:status', [
        '--connection' => ['phpredis-sentinel'],
        '--watch' => true,
    ]);
    $output = Artisan::output();

    expect($status)->toBe(1)
        ->and($output)->toContain('Could not connect to sentinel 127.0.0.1:59999');
});

test('sentinel:status --watch reports a missing sentinel host', function () {
    config([
        'database.redis.phpredis-sentinel' => [
            'client' => 'phpredis-sentinel',
            'sentinel' => ['port' => 26379, 'service' => 'master'],
        ],
    ]);

    $status = Artisan::call('sentinel:status', [
        '--connection' => ['phpredis-sentinel'],
        '--watch' => true,
    ]);
    $output = Artisan::output();

    expect($status)->toBe(1)
        ->and($output)->toContain('No sentinel host configured.');
});

test('sentinel:status skips sentinel-driver connections without sentinel endpoints', function () {
    config([
        'database.redis.phpredis-sentinel' => [
            'client' => 'phpredis-sentinel',
            'password' => 'test',
        ],
    ]);

    $status = Artisan::call('sentinel:status', ['--connection' => ['phpredis-sentinel']]);
    $output = Artisan::output();

    expect($status)->toBe(1)
        ->and($output)->toContain('Unknown or non-Sentinel connection(s): phpredis-sentinel');
});

test('formatEvent renders switch-master events with a promotion arrow', function () {
    $line = SentinelStatus::formatEvent('+switch-master', 'master 127.0.0.1 6380 127.0.0.1 6381');
    $other = SentinelStatus::formatEvent('+sdown', 'master master 127.0.0.1 6380');

    expect($line)->toBe('[+switch-master] master 127.0.0.1:6380 -> 127.0.0.1:6381')
        ->and($other)->toBe('[+sdown] master master 127.0.0.1 6380');
});
