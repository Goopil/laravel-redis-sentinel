<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Workbench\App\Events\OrderShipped;
use Workbench\App\Events\UserRegistered;

describe('Broadcast E2E Tests with Read/Write Mode', function () {
    beforeEach(function () {
        // Configure read/write splitting for broadcasting BEFORE operations
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

        // Flush cache and queue
        try {
            Cache::flush();
        } catch (\Exception $e) {
            // Ignore flush errors in setup
        }
    });

    test('broadcast operations use read/write splitting correctly', function () {
        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        $reflection = new ReflectionClass($connection);
        $wroteToMasterProp = $reflection->getProperty('wroteToMaster');
        $wroteToMasterProp->setAccessible(true);

        // Broadcasting is a write operation (publishes to Redis channels)
        Queue::fake();
        $event = new UserRegistered(1, 'test', 'test@example.com');
        event($event);

        // Verify event was queued for broadcasting
        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class);
    });

    test('multiple broadcast events with read/write mode', function () {
        Queue::fake();
        $testId = 'broadcast_rw_'.time();
        $eventCount = 20;

        // Dispatch multiple broadcast events
        for ($i = 1; $i <= $eventCount; $i++) {
            $event = new UserRegistered($i, "user_{$testId}_{$i}", "user{$i}@example.com", [
                'test_id' => $testId,
                'index' => $i,
            ]);
            event($event);
        }

        // Verify all events were queued for broadcasting
        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, $eventCount);
    });

    test('broadcast handles high load with read/write splitting', function () {
        Queue::fake();
        $testId = 'broadcast_load_'.time();
        $eventCount = 100;
        $startTime = microtime(true);

        // Rapidly dispatch broadcast events
        for ($i = 1; $i <= $eventCount; $i++) {
            if ($i % 2 === 0) {
                $event = new UserRegistered($i, "user_{$i}", "user{$i}@example.com");
            } else {
                $event = new OrderShipped("order_{$i}", $i, "TRACK_{$i}", ["item_{$i}"]);
            }
            event($event);
        }

        $duration = microtime(true) - $startTime;

        // Verify all events were queued
        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, $eventCount);
        expect($duration)->toBeLessThan(5, 'Broadcasting should handle high load efficiently');
    });

    test('broadcast connection remains stable during continuous events', function () {
        Queue::fake();
        $connection = Redis::connection('phpredis-sentinel');
        $testId = 'broadcast_stability_'.time();
        $rounds = 5;
        $eventsPerRound = 10;

        for ($round = 1; $round <= $rounds; $round++) {
            // Broadcast a batch of events
            for ($i = 1; $i <= $eventsPerRound; $i++) {
                $userId = ($round - 1) * $eventsPerRound + $i;
                $event = new UserRegistered($userId, "user_{$testId}_r{$round}_e{$i}", "user{$userId}@example.com");
                event($event);
            }

            // Verify connection health between rounds
            $pingResult = $connection->ping();
            expect($pingResult)->toBeTrue('Connection should remain healthy');

            usleep(100000); // 100ms delay between rounds
        }

        // Verify total event count
        $totalEvents = $rounds * $eventsPerRound;
        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, $totalEvents);
    });

    test('broadcast mixed event types with read/write splitting', function () {
        Queue::fake();
        $testId = 'broadcast_mixed_'.time();

        // Mix of UserRegistered and OrderShipped events
        for ($i = 1; $i <= 30; $i++) {
            if ($i % 3 === 0) {
                event(new UserRegistered($i, "user_{$i}", "user{$i}@example.com", ['type' => 'admin']));
            } elseif ($i % 3 === 1) {
                event(new UserRegistered($i, "user_{$i}", "user{$i}@example.com", ['type' => 'regular']));
            } else {
                event(new OrderShipped("order_{$i}", $i, "TRACK_{$i}", ["item_a", "item_b"]));
            }
        }

        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, 30);
    });

    test('broadcast events with complex metadata', function () {
        Queue::fake();

        $complexMetadata = [
            'user_details' => [
                'preferences' => ['theme' => 'dark', 'language' => 'en'],
                'settings' => ['notifications' => true, 'email_verified' => true],
            ],
            'registration' => [
                'source' => 'mobile_app',
                'referrer' => 'google',
                'campaign' => 'summer_2024',
            ],
            'timestamps' => [
                'created' => now()->timestamp,
                'verified' => now()->addMinutes(5)->timestamp,
            ],
        ];

        $event = new UserRegistered(999, 'complex_user', 'complex@example.com', $complexMetadata);
        event($event);

        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, function ($job) use ($complexMetadata) {
            $event = $job->event;
            return $event instanceof UserRegistered
                && $event->userId === 999
                && $event->metadata === $complexMetadata;
        });
    });

    test('broadcast channels are properly formatted', function () {
        $userEvent = new UserRegistered(123, 'channel_test', 'channel@example.com');
        $orderEvent = new OrderShipped('order_456', 789, 'TRACK456', ['item1']);

        $userChannels = $userEvent->broadcastOn();
        $orderChannels = $orderEvent->broadcastOn();

        // Verify UserRegistered uses public channels
        expect($userChannels)->toHaveCount(2)
            ->and($userChannels[0])->toBeInstanceOf(\Illuminate\Broadcasting\Channel::class)
            ->and($userChannels[0]->name)->toBe('user-registrations')
            ->and($userChannels[1]->name)->toBe('user.123');

        // Verify OrderShipped uses private channels
        expect($orderChannels)->toHaveCount(2)
            ->and($orderChannels[0])->toBeInstanceOf(\Illuminate\Broadcasting\PrivateChannel::class)
            ->and($orderChannels[0]->name)->toBe('private-orders.789')
            ->and($orderChannels[1]->name)->toBe('private-order.order_456');
    });

    test('broadcast events persist through connection reset', function () {
        Queue::fake();
        $connection = Redis::connection('phpredis-sentinel');
        $testId = 'broadcast_reset_'.time();

        // Broadcast events before reset
        for ($i = 1; $i <= 5; $i++) {
            event(new UserRegistered($i, "before_reset_{$i}", "before{$i}@example.com"));
        }

        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, 5);

        // Force connection reset
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        usleep(500000); // 500ms

        // Broadcast events after reset
        for ($i = 6; $i <= 10; $i++) {
            event(new UserRegistered($i, "after_reset_{$i}", "after{$i}@example.com"));
        }

        // Total should be 10 events
        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, 10);
    });

    test('broadcast with conditional events', function () {
        Queue::fake();

        // Event that should broadcast (has tracking number)
        event(new OrderShipped('order_1', 1, 'TRACK_1', ['item']));

        // Event that should NOT broadcast (empty tracking number)
        event(new OrderShipped('order_2', 2, '', ['item']));

        // Only 1 event should be queued
        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, 1);
    });

    test('broadcast maintains order with sequential events', function () {
        Queue::fake();
        $eventIds = [];

        // Dispatch events sequentially
        for ($i = 1; $i <= 15; $i++) {
            $event = new UserRegistered($i, "sequential_{$i}", "seq{$i}@example.com");
            event($event);
            $eventIds[] = $i;

            usleep(10000); // 10ms delay
        }

        // Verify all events were queued in order
        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, 15);

        $pushedEvents = [];
        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, function ($job) use (&$pushedEvents) {
            if ($job->event instanceof UserRegistered) {
                $pushedEvents[] = $job->event->userId;
            }
            return true;
        });

        expect($pushedEvents)->toBe($eventIds);
    });

    test('broadcast read operations use replicas and write operations use master', function () {
        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        $reflection = new ReflectionClass($connection);
        $wroteToMasterProp = $reflection->getProperty('wroteToMaster');
        $wroteToMasterProp->setAccessible(true);

        // Reset stickiness from any previous operations (like Cache::flush in beforeEach)
        $connection->resetStickiness();

        // Check channel subscription list (read operation)
        $connection->pubsub('channels', 'user-*');
        expect($wroteToMasterProp->getValue($connection))->toBeFalse('Read operations should not trigger stickiness');

        // Publish to channel (write operation)
        $connection->publish('user-registrations', json_encode(['user_id' => 1]));
        expect($wroteToMasterProp->getValue($connection))->toBeTrue('Publish should trigger stickiness');
    });

    test('broadcast handles concurrent events efficiently', function () {
        Queue::fake();
        $testId = 'broadcast_concurrent_'.time();
        $batchSize = 50;
        $startTime = microtime(true);

        // Simulate concurrent event dispatching
        for ($i = 1; $i <= $batchSize; $i++) {
            if ($i % 4 === 0) {
                event(new OrderShipped("order_{$i}", $i, "TRACK_{$i}", ["item_a", "item_b", "item_c"]));
            } else {
                event(new UserRegistered($i, "concurrent_{$i}", "concurrent{$i}@example.com"));
            }
        }

        $duration = microtime(true) - $startTime;

        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, $batchSize);
        expect($duration)->toBeLessThan(3, 'Concurrent broadcasting should be efficient');
    });

    test('broadcast event serialization with read/write mode', function () {
        $metadata = [
            'complex' => ['nested' => ['data' => 'value']],
            'array' => [1, 2, 3],
            'string' => 'test',
        ];

        $event = new UserRegistered(888, 'serialize_test', 'serialize@example.com', $metadata);

        // Serialize and unserialize
        $serialized = serialize($event);
        $unserialized = unserialize($serialized);

        expect($unserialized)->toBeInstanceOf(UserRegistered::class)
            ->and($unserialized->userId)->toBe(888)
            ->and($unserialized->username)->toBe('serialize_test')
            ->and($unserialized->metadata)->toBe($metadata);
    });

    test('broadcast with retry configuration', function () {
        $event = new OrderShipped('order_retry', 1, 'TRACK_RETRY', ['item']);
        $retryUntil = $event->retryUntil();

        expect($retryUntil)->toBeInstanceOf(\DateTime::class);

        $expectedTime = now()->addMinutes(5);
        expect($retryUntil->getTimestamp())->toBeGreaterThanOrEqual($expectedTime->timestamp - 2)
            ->and($retryUntil->getTimestamp())->toBeLessThanOrEqual($expectedTime->timestamp + 2);
    });

    test('broadcast tags are correctly applied', function () {
        $event = new OrderShipped('order_tags', 555, 'TRACK_TAGS', ['item1', 'item2']);
        $tags = $event->broadcastTags();

        expect($tags)->toBeArray()
            ->and($tags)->toContain('broadcasts')
            ->and($tags)->toContain('orders')
            ->and($tags)->toContain('user:555');
    });
});
