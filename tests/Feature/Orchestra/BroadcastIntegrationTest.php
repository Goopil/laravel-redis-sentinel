<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Workbench\App\Events\OrderShipped;
use Workbench\App\Events\UserRegistered;

describe('Broadcast', function () {
    beforeEach(function () {
        // Configure broadcasting to use phpredis-sentinel
        config()->set('broadcasting.default', 'phpredis-sentinel');
        config()->set('broadcasting.connections.phpredis-sentinel', [
            'driver' => 'phpredis-sentinel',
            'connection' => 'phpredis-sentinel',
        ]);
    });

    test('broadcaster uses redis sentinel connection', function () {
        $broadcaster = Broadcast::connection('phpredis-sentinel');

        expect($broadcaster)->toBeInstanceOf(\Illuminate\Broadcasting\Broadcasters\RedisBroadcaster::class);

        // Use reflection to access the private redis property
        $reflection = new ReflectionClass($broadcaster);
        $property = $reflection->getProperty('redis');
        $property->setAccessible(true);

        expect($property->getValue($broadcaster))->toBeInstanceOf(\Goopil\LaravelRedisSentinel\RedisSentinelManager::class);
    });

    test('user registered event has correct structure', function () {
        $userId = 123;
        $username = 'johndoe';
        $email = 'john@example.com';
        $metadata = ['source' => 'web', 'referrer' => 'google'];

        $event = new UserRegistered($userId, $username, $email, $metadata);

        expect($event->userId)->toBe($userId)
            ->and($event->username)->toBe($username)
            ->and($event->email)->toBe($email)
            ->and($event->metadata)->toBe($metadata);
    });

    test('user registered event broadcasts on correct channels', function () {
        $event = new UserRegistered(456, 'janedoe', 'jane@example.com');
        $channels = $event->broadcastOn();

        expect($channels)->toBeArray()
            ->and(count($channels))->toBe(2);

        expect($channels[0])->toBeInstanceOf(\Illuminate\Broadcasting\Channel::class)
            ->and($channels[0]->name)->toBe('user-registrations');

        expect($channels[1])->toBeInstanceOf(\Illuminate\Broadcasting\Channel::class)
            ->and($channels[1]->name)->toBe('user.456');
    });

    test('user registered event has correct broadcast data', function () {
        $event = new UserRegistered(789, 'testuser', 'test@example.com', ['role' => 'admin']);
        $data = $event->broadcastWith();

        expect($data)->toBeArray()
            ->and($data['user_id'])->toBe(789)
            ->and($data['username'])->toBe('testuser')
            ->and($data['email'])->toBe('test@example.com')
            ->and($data['metadata'])->toBe(['role' => 'admin'])
            ->and($data['timestamp'])->toBeInt();
    });

    test('user registered event has correct broadcast name', function () {
        $event = new UserRegistered(1, 'user', 'user@example.com');

        expect($event->broadcastAs())->toBe('user.registered');
    });

    test('user registered event should broadcast', function () {
        $event = new UserRegistered(1, 'user', 'user@example.com');

        expect($event->broadcastWhen())->toBeTrue();
    });

    test('order shipped event uses private channels', function () {
        $event = new OrderShipped('order_123', 999, 'TRACK123', ['item1', 'item2']);
        $channels = $event->broadcastOn();

        expect($channels)->toBeArray()
            ->and(count($channels))->toBe(2);

        expect($channels[0])->toBeInstanceOf(\Illuminate\Broadcasting\PrivateChannel::class)
            ->and($channels[0]->name)->toBe('private-orders.999');

        expect($channels[1])->toBeInstanceOf(\Illuminate\Broadcasting\PrivateChannel::class)
            ->and($channels[1]->name)->toBe('private-order.order_123');
    });

    test('order shipped event has correct broadcast data', function () {
        $orderId = 'order_456';
        $userId = 111;
        $trackingNumber = 'TRACK456';
        $items = ['laptop', 'mouse'];

        $event = new OrderShipped($orderId, $userId, $trackingNumber, $items);
        $data = $event->broadcastWith();

        expect($data)->toBeArray()
            ->and($data['order_id'])->toBe($orderId)
            ->and($data['tracking_number'])->toBe($trackingNumber)
            ->and($data['items_count'])->toBe(2)
            ->and($data['items'])->toBe($items)
            ->and($data['shipped_at'])->toBeString()
            ->and($data['timestamp'])->toBeInt();
    });

    test('order shipped event has correct broadcast name', function () {
        $event = new OrderShipped('order_789', 222, 'TRACK789');

        expect($event->broadcastAs())->toBe('order.shipped');
    });

    test('order shipped event only broadcasts when tracking number exists', function () {
        $eventWithTracking = new OrderShipped('order_1', 1, 'TRACK1');
        expect($eventWithTracking->broadcastWhen())->toBeTrue();

        $eventWithoutTracking = new OrderShipped('order_2', 2, '');
        expect($eventWithoutTracking->broadcastWhen())->toBeFalse();
    });

    test('order shipped event has correct broadcast tags', function () {
        $event = new OrderShipped('order_999', 333, 'TRACK999');
        $tags = $event->broadcastTags();

        expect($tags)->toBeArray()
            ->and($tags)->toContain('broadcasts')
            ->and($tags)->toContain('orders')
            ->and($tags)->toContain('user:333');
    });

    test('order shipped event has retry configuration', function () {
        $event = new OrderShipped('order_123', 1, 'TRACK123');
        $retryUntil = $event->retryUntil();

        expect($retryUntil)->toBeInstanceOf(\DateTime::class);

        $now = now();
        $expectedRetryTime = $now->copy()->addMinutes(5);

        // Allow 1 second difference for test execution time
        expect($retryUntil->getTimestamp())->toBeGreaterThanOrEqual($expectedRetryTime->timestamp - 1)
            ->and($retryUntil->getTimestamp())->toBeLessThanOrEqual($expectedRetryTime->timestamp + 1);
    });

    test('events can be dispatched for broadcasting', function () {
        Event::fake();

        $event = new UserRegistered(555, 'broadcaster', 'broadcast@example.com');
        event($event);

        Event::assertDispatched(UserRegistered::class, function ($e) {
            return $e->userId === 555 && $e->username === 'broadcaster';
        });
    });

    test('multiple events can be broadcast', function () {
        Queue::fake();

        event(new UserRegistered(1, 'user1', 'user1@example.com'));
        event(new UserRegistered(2, 'user2', 'user2@example.com'));
        event(new OrderShipped('order_1', 1, 'TRACK1', ['item1']));
        event(new OrderShipped('order_2', 2, 'TRACK2', ['item2']));

        // Broadcasting jobs are queued
        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, 4);
    });

    test('broadcast event serialization works correctly', function () {
        $event = new UserRegistered(
            userId: 999,
            username: 'serialize_test',
            email: 'serialize@example.com',
            metadata: [
                'complex' => [
                    'nested' => ['data' => 'value'],
                ],
                'array' => [1, 2, 3],
            ]
        );

        // Serialize and unserialize
        $serialized = serialize($event);
        $unserialized = unserialize($serialized);

        expect($unserialized)->toBeInstanceOf(UserRegistered::class)
            ->and($unserialized->userId)->toBe(999)
            ->and($unserialized->username)->toBe('serialize_test')
            ->and($unserialized->metadata)->toBe([
                'complex' => [
                    'nested' => ['data' => 'value'],
                ],
                'array' => [1, 2, 3],
            ]);
    });

    test('broadcast connection handles multiple concurrent events', function () {
        Queue::fake();

        $events = [];
        for ($i = 1; $i <= 20; $i++) {
            $events[] = new UserRegistered($i, "user{$i}", "user{$i}@example.com");
        }

        foreach ($events as $event) {
            event($event);
        }

        Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, 20);
    });

    test('broadcast event with empty items shows correct count', function () {
        $event = new OrderShipped('order_empty', 1, 'TRACK_EMPTY', []);
        $data = $event->broadcastWith();

        expect($data['items_count'])->toBe(0)
            ->and($data['items'])->toBe([]);
    });

    test('broadcast event preserves data types', function () {
        $metadata = [
            'string' => 'text',
            'integer' => 42,
            'float' => 3.14,
            'boolean' => true,
            'null' => null,
            'array' => [1, 2, 3],
            'nested' => ['key' => 'value'],
        ];

        $event = new UserRegistered(1, 'types', 'types@example.com', $metadata);
        $data = $event->broadcastWith();

        expect($data['metadata'])->toBe($metadata)
            ->and($data['metadata']['string'])->toBe('text')
            ->and($data['metadata']['integer'])->toBe(42)
            ->and($data['metadata']['float'])->toBe(3.14)
            ->and($data['metadata']['boolean'])->toBeTrue()
            ->and($data['metadata']['null'])->toBeNull()
            ->and($data['metadata']['array'])->toBe([1, 2, 3])
            ->and($data['metadata']['nested'])->toBe(['key' => 'value']);
    });

    test('broadcast channels can be extracted for authorization', function () {
        $userEvent = new UserRegistered(123, 'user', 'user@example.com');
        $orderEvent = new OrderShipped('order_456', 789, 'TRACK456');

        $userChannels = $userEvent->broadcastOn();
        $orderChannels = $orderEvent->broadcastOn();

        // User event uses public channels
        expect($userChannels[0])->toBeInstanceOf(\Illuminate\Broadcasting\Channel::class);

        // Order event uses private channels (requires authorization)
        expect($orderChannels[0])->toBeInstanceOf(\Illuminate\Broadcasting\PrivateChannel::class)
            ->and($orderChannels[1])->toBeInstanceOf(\Illuminate\Broadcasting\PrivateChannel::class);
    });
});
