<?php

use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $ca = __DIR__.'/../tls/ca.crt';

    if (! is_file($ca)) {
        $this->markTestSkipped('TLS test certificates not generated (run ./tests/tls/generate-certs.sh).');
    }

    $socket = @fsockopen(env('REDIS_SENTINEL_TLS_HOST', '127.0.0.1'), (int) env('REDIS_SENTINEL_TLS_PORT', 26383), $errno, $errstr, 0.2);

    if ($socket === false) {
        $this->markTestSkipped('TLS Sentinel not running (docker compose -f docker-compose.yml -f docker-compose.tls.yml up -d).');
    }

    fclose($socket);
});

test('sentinel resolution and data connection both run over TLS', function () {
    expect(Redis::connection('phpredis-sentinel-tls'))->toBeAWorkingRedisConnection();
});
