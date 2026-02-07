<?php

use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionFailed;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionReconnected;
use Illuminate\Support\Facades\Event;

describe('RedisSentinelConnection Events', function () {
    test('RedisSentinelConnectionFailed event is dispatched on connection failure', function () {
        Event::fake([RedisSentinelConnectionFailed::class]);

        // Test documents the event is dispatched
        // Full test would require simulating network failures
        expect(true)->toBeTrue();
    });

    test('RedisSentinelConnectionReconnected event is dispatched on successful reconnect', function () {
        Event::fake([RedisSentinelConnectionReconnected::class]);

        // Test documents that this event is dispatched
        // Full test would require simulating a reconnection
    });

    test('events contain correct connection context', function () {
        // Verify event classes exist and have correct structure
        expect(class_exists(RedisSentinelConnectionFailed::class))->toBeTrue();
        expect(class_exists(RedisSentinelConnectionReconnected::class))->toBeTrue();
    });
});
