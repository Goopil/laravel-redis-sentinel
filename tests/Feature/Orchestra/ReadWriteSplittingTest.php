<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;

describe('Read/Write', function () {
    beforeEach(function () {
        // Configure a connection with replicas enabled
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);

        // Get the current manager
        $manager = app(\Goopil\LaravelRedisSentinel\RedisSentinelManager::class);

        // Inject the new config via reflection because the manager keeps an internal copy
        $reflection = new ReflectionClass($manager);
        $configProp = $reflection->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($manager, config('database.redis'));

        // Purge the connection so it can be re-resolved with the new config
        $manager->purge('phpredis-sentinel');
    });

    function getInternalState($connection)
    {
        $reflection = new ReflectionClass($connection);

        $wroteToMasterProp = $reflection->getProperty('wroteToMaster');
        $wroteToMasterProp->setAccessible(true);

        $readClientProp = $reflection->getProperty('readClient');
        $readClientProp->setAccessible(true);

        $readConnectorProp = $reflection->getProperty('readConnector');
        $readConnectorProp->setAccessible(true);

        return [
            'wroteToMaster' => $wroteToMasterProp->getValue($connection),
            'hasReadClient' => ! is_null($readClientProp->getValue($connection)),
            'hasReadConnector' => ! is_null($readConnectorProp->getValue($connection)),
        ];
    }

    test('read operations use replicas and write operations trigger stickiness', function () {
        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        // Debug: see the config used by the connection
        $reflection = new ReflectionClass($connection);
        $configProp = $reflection->getProperty('config');
        $configProp->setAccessible(true);
        $connConfig = $configProp->getValue($connection);

        // expect($connConfig)->toHaveKey('read_only_replicas', true);

        // Verify that the read connector is present
        $state = getInternalState($connection);
        expect($state['hasReadConnector'])->toBeTrue('Read connector should be present')
            ->and($state['wroteToMaster'])->toBeFalse();

        // A read should initialize the readClient
        $connection->get('test-read');
        $state = getInternalState($connection);
        expect($state['hasReadClient'])->toBeTrue()
            ->and($state['wroteToMaster'])->toBeFalse();

        // A write should activate stickiness
        $connection->set('test-write', 'value');
        $state = getInternalState($connection);
        expect($state['wroteToMaster'])->toBeTrue();
    });

    test('stickiness in Cache context', function () {
        config()->set('cache.default', 'phpredis-sentinel');
        $connection = Redis::connection('phpredis-sentinel');

        // Read: no stickiness
        Cache::get('cache-key');
        expect(getInternalState($connection)['wroteToMaster'])->toBeFalse();

        // Write: stickiness activated
        Cache::put('cache-key', 'value', 10);
        expect(getInternalState($connection)['wroteToMaster'])->toBeTrue();
    });

    test('stickiness in Session context', function () {
        config()->set('session.driver', 'phpredis-sentinel');
        config()->set('session.connection', 'phpredis-sentinel');

        // Starting the session may involve a read
        Session::start();
        $connection = Redis::connection('phpredis-sentinel');

        // Read-only at first
        Session::get('user_id');
        expect(getInternalState($connection)['wroteToMaster'])->toBeFalse();

        // Write to session
        Session::put('user_id', 123);
        // Note: The session only writes to Redis during save() or at the end of the request
        Session::save();

        expect(getInternalState($connection)['wroteToMaster'])->toBeTrue();
    });

    test('stickiness in Broadcast context', function () {
        config()->set('broadcasting.default', 'phpredis-sentinel');
        config()->set('broadcasting.connections.phpredis-sentinel', [
            'driver' => 'phpredis-sentinel',
            'connection' => 'phpredis-sentinel',
        ]);

        // Purge the broadcast manager to force config reload
        app()->forgetInstance(\Illuminate\Contracts\Broadcasting\Factory::class);

        $broadcaster = Broadcast::driver('phpredis-sentinel');
        expect($broadcaster)->toBeInstanceOf(\Illuminate\Broadcasting\Broadcasters\RedisBroadcaster::class);

        // A direct broadcast (PUBLISH) is a write
        $broadcaster->broadcast(['test-channel'], 'test-event', ['data' => 'value']);

        $connection = Redis::connection('phpredis-sentinel');
        expect(getInternalState($connection)['wroteToMaster'])->toBeTrue('Broadcast direct should trigger stickiness');
    });

    test('stickiness reset between Queue jobs', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Simulate a write from a previous job
        $connection->set('prev-job', 'done');
        expect(getInternalState($connection)['wroteToMaster'])->toBeTrue();

        // Simulate the start of a new job
        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn([]);
        Event::dispatch(new JobProcessing('phpredis-sentinel', $job));

        // Stickiness should be reset
        expect(getInternalState($connection)['wroteToMaster'])->toBeFalse();
    });

    test('stickiness reset in Octane context', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Simulate a write from a previous request
        $connection->set('prev-req', 'done');
        expect(getInternalState($connection)['wroteToMaster'])->toBeTrue();

        // Simulate a new Octane request
        $eventClass = 'Laravel\Octane\Events\RequestReceived';
        if (class_exists($eventClass)) {
            Event::dispatch(new $eventClass(app(), request()));
        } else {
            // Manual fallback if Octane is not present for the test
            foreach (app('redis')->connections() as $conn) {
                if ($conn instanceof RedisSentinelConnection) {
                    $conn->resetStickiness();
                }
            }
        }

        expect(getInternalState($connection)['wroteToMaster'])->toBeFalse();
    });

    test('Horizon context uses stickiness correctly', function () {
        // Horizon often uses the 'horizon' connection
        config()->set('horizon.use', 'phpredis-sentinel');
        config()->set('horizon.driver', 'phpredis-sentinel');

        // Force the manager into Horizon context
        // Note: RedisSentinelManager::isHorizonContext depends on config

        $connection = Redis::connection('phpredis-sentinel');

        // Horizon read
        $connection->get('horizon:status');
        expect(getInternalState($connection)['wroteToMaster'])->toBeFalse();

        // Horizon write (simulated)
        $connection->set('horizon:last-heartbeat', time());
        expect(getInternalState($connection)['wroteToMaster'])->toBeTrue();
    });
});
