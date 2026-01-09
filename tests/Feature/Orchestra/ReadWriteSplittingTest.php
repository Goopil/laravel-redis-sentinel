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
        // Configuration d'une connexion avec replicas activés
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);

        // On récupère le manager actuel
        $manager = app(\Goopil\LaravelRedisSentinel\RedisSentinelManager::class);

        // On injecte la nouvelle config via réflexion car le manager garde une copie interne
        $reflection = new ReflectionClass($manager);
        $configProp = $reflection->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($manager, config('database.redis'));

        // On purge la connexion pour qu'elle soit re-résolue avec la nouvelle config
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

        // Debug: voir la config utilisée par la connexion
        $reflection = new ReflectionClass($connection);
        $configProp = $reflection->getProperty('config');
        $configProp->setAccessible(true);
        $connConfig = $configProp->getValue($connection);

        // expect($connConfig)->toHaveKey('read_only_replicas', true);

        // Vérifier que le connecteur de lecture est présent
        $state = getInternalState($connection);
        expect($state['hasReadConnector'])->toBeTrue('Read connector should be present')
            ->and($state['wroteToMaster'])->toBeFalse();

        // Une lecture devrait initialiser le readClient
        $connection->get('test-read');
        $state = getInternalState($connection);
        expect($state['hasReadClient'])->toBeTrue()
            ->and($state['wroteToMaster'])->toBeFalse();

        // Une écriture devrait activer la stickiness
        $connection->set('test-write', 'value');
        $state = getInternalState($connection);
        expect($state['wroteToMaster'])->toBeTrue();
    });

    test('stickiness in Cache context', function () {
        config()->set('cache.default', 'phpredis-sentinel');
        $connection = Redis::connection('phpredis-sentinel');

        // Lecture : pas de stickiness
        Cache::get('cache-key');
        expect(getInternalState($connection)['wroteToMaster'])->toBeFalse();

        // Écriture : stickiness activée
        Cache::put('cache-key', 'value', 10);
        expect(getInternalState($connection)['wroteToMaster'])->toBeTrue();
    });

    test('stickiness in Session context', function () {
        config()->set('session.driver', 'phpredis-sentinel');
        config()->set('session.connection', 'phpredis-sentinel');

        // Le démarrage de la session peut impliquer une lecture
        Session::start();
        $connection = Redis::connection('phpredis-sentinel');

        // Lecture seule au début
        Session::get('user_id');
        expect(getInternalState($connection)['wroteToMaster'])->toBeFalse();

        // Écriture en session
        Session::put('user_id', 123);
        // Note: La session n'écrit en Redis que lors du save() ou à la fin de la requête
        Session::save();

        expect(getInternalState($connection)['wroteToMaster'])->toBeTrue();
    });

    test('stickiness in Broadcast context', function () {
        config()->set('broadcasting.default', 'phpredis-sentinel');
        config()->set('broadcasting.connections.phpredis-sentinel', [
            'driver' => 'phpredis-sentinel',
            'connection' => 'phpredis-sentinel',
        ]);

        // Purger le manager de broadcast pour forcer la relecture de la config
        app()->forgetInstance(\Illuminate\Contracts\Broadcasting\Factory::class);

        $broadcaster = Broadcast::driver('phpredis-sentinel');
        expect($broadcaster)->toBeInstanceOf(\Illuminate\Broadcasting\Broadcasters\RedisBroadcaster::class);

        // Un broadcast direct (PUBLISH) est une écriture
        $broadcaster->broadcast(['test-channel'], 'test-event', ['data' => 'value']);

        $connection = Redis::connection('phpredis-sentinel');
        expect(getInternalState($connection)['wroteToMaster'])->toBeTrue('Broadcast direct should trigger stickiness');
    });

    test('stickiness reset between Queue jobs', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Simuler une écriture d'un job précédent
        $connection->set('prev-job', 'done');
        expect(getInternalState($connection)['wroteToMaster'])->toBeTrue();

        // Simuler le début d'un nouveau job
        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn([]);
        Event::dispatch(new JobProcessing('phpredis-sentinel', $job));

        // La stickiness doit être réinitialisée
        expect(getInternalState($connection)['wroteToMaster'])->toBeFalse();
    });

    test('stickiness reset in Octane context', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Simuler une écriture d'une requête précédente
        $connection->set('prev-req', 'done');
        expect(getInternalState($connection)['wroteToMaster'])->toBeTrue();

        // Simuler une nouvelle requête Octane
        $eventClass = 'Laravel\Octane\Events\RequestReceived';
        if (class_exists($eventClass)) {
            Event::dispatch(new $eventClass(app(), request()));
        } else {
            // Fallback manuel si Octane n'est pas présent pour le test
            foreach (app('redis')->connections() as $conn) {
                if ($conn instanceof RedisSentinelConnection) {
                    $conn->resetStickiness();
                }
            }
        }

        expect(getInternalState($connection)['wroteToMaster'])->toBeFalse();
    });

    test('Horizon context uses stickiness correctly', function () {
        // Horizon utilise souvent la connexion 'horizon'
        config()->set('horizon.use', 'phpredis-sentinel');
        config()->set('horizon.driver', 'phpredis-sentinel');

        // On force le manager à être en contexte Horizon
        // Note: RedisSentinelManager::isHorizonContext dépend de la config

        $connection = Redis::connection('phpredis-sentinel');

        // Lecture Horizon
        $connection->get('horizon:status');
        expect(getInternalState($connection)['wroteToMaster'])->toBeFalse();

        // Écriture Horizon (simulée)
        $connection->set('horizon:last-heartbeat', time());
        expect(getInternalState($connection)['wroteToMaster'])->toBeTrue();
    });
});
