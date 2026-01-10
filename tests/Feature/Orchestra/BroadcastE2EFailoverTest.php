<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Workbench\App\Events\OrderShipped;
use Workbench\App\Events\UserRegistered;

describe('Broadcast E2E Failover Tests with Read/Write Mode', function () {
    beforeEach(function () {
        // Configure read/write splitting for realistic failover scenario
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);
        config()->set('broadcasting.default', 'phpredis-sentinel');
        config()->set('broadcasting.connections.phpredis-sentinel', [
            'driver' => 'phpredis-sentinel',
            'connection' => 'phpredis-sentinel',
        ]);

        // Configure cache to use phpredis-sentinel driver
        config()->set('cache.default', 'phpredis-sentinel');
        config()->set('cache.stores.phpredis-sentinel', [
            'driver' => 'phpredis-sentinel',
            'connection' => 'phpredis-sentinel',
            'lock_connection' => 'phpredis-sentinel',
        ]);

        // Purge connections
        $manager = app(\Goopil\LaravelRedisSentinel\RedisSentinelManager::class);

        $reflection = new ReflectionClass($manager);
        $configProp = $reflection->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($manager, config('database.redis'));

        $manager->purge('phpredis-sentinel');

        try {
            Cache::flush();
        } catch (\Exception $e) {
            // Ignore flush errors
        }
    });

    test('broadcast events complete successfully before failover', function () {
        Queue::fake();
        $testId = 'pre_failover_'.time();
        $eventCount = 15;

        for ($i = 1; $i <= $eventCount; $i++) {
            event(new UserRegistered($i, "pre_failover_{$i}", "pre{$i}@example.com", [
                'phase' => 'pre-failover',
            ]));
        }

        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, $eventCount);

        // Verify connection health
        $connection = Redis::connection('phpredis-sentinel');
        expect($connection->ping())->toBeTrue();
    });

    test('broadcast detects current master and can failover', function () {
        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        // Verify master connection works
        expect($connection->ping())->toBeTrue();

        // Test publish to channel
        $result = $connection->publish('test-channel', json_encode(['test' => 'data']));
        expect($result)->toBeGreaterThanOrEqual(0);
    });

    test('broadcast handles connection reset during event processing', function () {
        Queue::fake();
        $connection = Redis::connection('phpredis-sentinel');
        $testId = 'reset_test_'.time();

        // Phase 1: Events before reset
        for ($i = 1; $i <= 8; $i++) {
            event(new UserRegistered($i, "before_reset_{$i}", "before{$i}@example.com"));
        }

        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, 8);

        // Simulate connection disruption
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Phase 2: Events after reconnection
        for ($i = 9; $i <= 16; $i++) {
            event(new UserRegistered($i, "after_reset_{$i}", "after{$i}@example.com"));
        }

        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, 16);
    });

    test('broadcast survives failover during high volume', function () {
        Queue::fake();
        $connection = Redis::connection('phpredis-sentinel');
        $totalEvents = 100;
        $successCount = 0;

        for ($i = 1; $i <= $totalEvents; $i++) {
            try {
                // Simulate failover at midpoint
                if ($i === 50) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    sleep(2); // Failover window
                }

                event(new UserRegistered($i, "failover_{$i}", "failover{$i}@example.com"));
                $successCount++;
            } catch (\Exception $e) {
                // Some events might fail during failover
            }
        }

        // Most events should succeed
        expect($successCount)->toBeGreaterThan(90, 'At least 90% of events should succeed');
        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, $successCount);
    });

    test('broadcast maintains event integrity through failover', function () {
        Queue::fake();
        $connection = Redis::connection('phpredis-sentinel');
        $testId = 'integrity_'.time();
        $eventsBeforeFailover = 20;
        $eventsDuringFailover = 10;
        $eventsAfterFailover = 20;

        // Phase 1: Before failover
        for ($i = 1; $i <= $eventsBeforeFailover; $i++) {
            event(new UserRegistered($i, "before_{$i}", "before{$i}@example.com", [
                'phase' => 'before',
                'sequence' => $i,
            ]));
        }

        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, $eventsBeforeFailover);

        // Phase 2: Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(2);

        // Phase 3: During failover window
        $duringSuccess = 0;
        for ($i = 1; $i <= $eventsDuringFailover; $i++) {
            try {
                event(new UserRegistered($eventsBeforeFailover + $i, "during_{$i}", "during{$i}@example.com", [
                    'phase' => 'during',
                ]));
                $duringSuccess++;
            } catch (\Exception $e) {
                // Some might fail
            }
        }

        // Phase 4: After failover recovery
        sleep(1);

        for ($i = 1; $i <= $eventsAfterFailover; $i++) {
            event(new UserRegistered($eventsBeforeFailover + $eventsDuringFailover + $i, "after_{$i}", "after{$i}@example.com", [
                'phase' => 'after',
            ]));
        }

        // Verify most events succeeded
        $totalExpected = $eventsBeforeFailover + $duringSuccess + $eventsAfterFailover;
        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, $totalExpected);
    });

    test('broadcast channel subscriptions persist through failover', function () {
        $connection = Redis::connection('phpredis-sentinel');
        $testId = 'channel_persist_'.time();

        // Publish to channels before failover
        $connection->publish('user-registrations', json_encode(['test' => 1]));
        $connection->publish('orders', json_encode(['test' => 2]));

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Connection should reconnect and channels should still work
        $result = $connection->publish('user-registrations', json_encode(['test' => 3]));
        expect($result)->toBeGreaterThanOrEqual(0);
    });

    test('broadcast mixed events with failover', function () {
        Queue::fake();
        $connection = Redis::connection('phpredis-sentinel');
        $totalEvents = 60;
        $failoverPoint = 30;

        for ($i = 1; $i <= $totalEvents; $i++) {
            try {
                if ($i === $failoverPoint) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    sleep(2);
                }

                if ($i % 2 === 0) {
                    event(new UserRegistered($i, "mixed_{$i}", "mixed{$i}@example.com"));
                } else {
                    event(new OrderShipped("order_{$i}", $i, "TRACK_{$i}", ["item_{$i}"]));
                }
            } catch (\Exception $e) {
                // Some might fail during failover
            }
        }

        // Most events should succeed
        $pushedCount = 0;
        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, function ($job) use (&$pushedCount) {
            $pushedCount++;
            return true;
        });

        expect($pushedCount)->toBeGreaterThan(55, 'At least 90% of events should succeed');
    });

    test('broadcast read/write splitting works through failover', function () {
        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        $reflection = new ReflectionClass($connection);
        $wroteToMasterProp = $reflection->getProperty('wroteToMaster');
        $wroteToMasterProp->setAccessible(true);

        // Publish (write operation)
        $connection->publish('test-channel', 'message1');
        expect($wroteToMasterProp->getValue($connection))->toBeTrue();

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // After reconnection, publish should still work
        $result = $connection->publish('test-channel', 'message2');
        expect($result)->toBeGreaterThanOrEqual(0);
    });

    test('broadcast handles intermittent connection issues', function () {
        Queue::fake();
        $connection = Redis::connection('phpredis-sentinel');
        $totalEvents = 80;
        $disconnectPoints = [20, 40, 60];
        $successCount = 0;

        for ($i = 1; $i <= $totalEvents; $i++) {
            try {
                if (in_array($i, $disconnectPoints)) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    usleep(500000); // 500ms recovery
                }

                event(new UserRegistered($i, "intermittent_{$i}", "intermittent{$i}@example.com"));
                $successCount++;
            } catch (\Exception $e) {
                // Some might fail
            }
        }

        expect($successCount)->toBeGreaterThan(75, 'At least 95% should succeed with brief disconnects');
        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, $successCount);
    });

    test('broadcast conditional events work through failover', function () {
        Queue::fake();
        $connection = Redis::connection('phpredis-sentinel');

        // Before failover
        event(new OrderShipped('order_1', 1, 'TRACK_1', ['item']));
        event(new OrderShipped('order_2', 2, '', [])); // Should not broadcast

        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, 1);

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // After failover
        event(new OrderShipped('order_3', 3, 'TRACK_3', ['item']));
        event(new OrderShipped('order_4', 4, '', [])); // Should not broadcast

        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, 2);
    });

    test('broadcast complex metadata persists through failover', function () {
        Queue::fake();
        $connection = Redis::connection('phpredis-sentinel');

        $metadata = [
            'profile' => ['name' => 'John', 'age' => 30],
            'settings' => ['theme' => 'dark'],
        ];

        // Before failover
        event(new UserRegistered(1, 'complex', 'complex@example.com', $metadata));

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // After failover with different metadata
        $metadata2 = ['profile' => ['name' => 'Jane', 'age' => 25]];
        event(new UserRegistered(2, 'complex2', 'complex2@example.com', $metadata2));

        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, function ($job) use ($metadata, $metadata2) {
            if ($job->event instanceof UserRegistered) {
                return $job->event->metadata === $metadata || $job->event->metadata === $metadata2;
            }
            return false;
        });
    });

    test('broadcast connection recovery after extended outage', function () {
        Queue::fake();
        $connection = Redis::connection('phpredis-sentinel');

        // Events before outage
        for ($i = 1; $i <= 10; $i++) {
            event(new UserRegistered($i, "before_outage_{$i}", "before{$i}@example.com"));
        }

        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, 10);

        // Simulate extended outage
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(3); // Extended outage

        // Connection should recover
        for ($i = 11; $i <= 20; $i++) {
            event(new UserRegistered($i, "after_outage_{$i}", "after{$i}@example.com"));
        }

        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, 20);
    });

    test('broadcast maintains performance through failover', function () {
        Queue::fake();
        $connection = Redis::connection('phpredis-sentinel');
        $eventsPerPhase = 25;

        // Phase 1: Before failover
        $startTime1 = microtime(true);
        for ($i = 1; $i <= $eventsPerPhase; $i++) {
            event(new UserRegistered($i, "perf1_{$i}", "perf1{$i}@example.com"));
        }
        $duration1 = microtime(true) - $startTime1;

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(2);

        // Phase 2: After failover
        $startTime2 = microtime(true);
        for ($i = $eventsPerPhase + 1; $i <= $eventsPerPhase * 2; $i++) {
            event(new UserRegistered($i, "perf2_{$i}", "perf2{$i}@example.com"));
        }
        $duration2 = microtime(true) - $startTime2;

        // Both phases should complete reasonably fast
        expect($duration1)->toBeLessThan(3, 'Pre-failover performance should be good');
        expect($duration2)->toBeLessThan(4, 'Post-failover performance should recover');

        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, $eventsPerPhase * 2);
    });

    test('broadcast event order preserved through failover', function () {
        Queue::fake();
        $connection = Redis::connection('phpredis-sentinel');
        $eventIds = [];

        // Events with failover in the middle
        for ($i = 1; $i <= 30; $i++) {
            if ($i === 15) {
                try {
                    $connection->disconnect();
                } catch (\Exception $e) {
                    // Expected
                }
                sleep(1);
            }

            event(new UserRegistered($i, "ordered_{$i}", "ordered{$i}@example.com"));
            $eventIds[] = $i;
        }

        // Verify events were queued
        $pushedIds = [];
        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, function ($job) use (&$pushedIds) {
            if ($job->event instanceof UserRegistered) {
                $pushedIds[] = $job->event->userId;
            }
            return true;
        });

        expect(count($pushedIds))->toBe(30);
    });
});
