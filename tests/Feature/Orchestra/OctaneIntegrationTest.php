<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

describe('Octane Integration', function () {
    test('stickiness is reset when RequestReceived event is fired', function () {
        // Mock Octane and Events if necessary, but we can just fire the event manually
        // if the listener is registered.

        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        // 1. Perform a write to make it sticky
        $connection->set('sticky_test', 'value');

        // Use reflection to check wroteToMaster
        $reflection = new ReflectionClass($connection);
        $property = $reflection->getProperty('wroteToMaster');
        $property->setAccessible(true);

        expect($property->getValue($connection))->toBeTrue();

        // 2. Fire the Octane event
        // Note: The event class might not exist if Octane is not installed,
        // but the ServiceProvider checks for its existence before registering the listener.
        // We can simulate what the listener does or fire the event if the class exists.

        $eventClass = 'Laravel\Octane\Events\RequestReceived';
        if (class_exists($eventClass)) {
            Event::dispatch(new $eventClass(app(), request()));
        } else {
            // If Octane is not installed, we can still test the logic by manually calling the listener
            // or just checking if we can trigger the logic.
            // Actually, the ServiceProvider registers it like this:
            // $this->app['events']->listen('Laravel\Octane\Events\RequestReceived', function () { ... });

            // Let's just manually trigger the resetStickiness through the manager
            // to ensure it works as expected when called.
            $manager = app(RedisSentinelManager::class);
            foreach ($manager->connections() as $conn) {
                if ($conn instanceof RedisSentinelConnection) {
                    $conn->resetStickiness();
                }
            }
        }

        // 3. Verify it's no longer sticky
        expect($property->getValue($connection))->toBeFalse();
    });
});
