<?php

use Illuminate\Support\Facades\Redis;

describe('Network degradation toxics', function () {
    test('replica latency keeps read/write splitting functional', function () {
        // Seed on the default write-only connection: on a read-split connection a
        // write sticks later reads to the master, so the timed read must be the first
        // command of a freshly purged read-split connection to traverse a replica proxy
        expect($this->sentinelConnectionWithRetry()->set('chaos_latency', 'split'))->toBeTrue();

        // The read client is picked at random among Sentinel's healthy replicas, so
        // every proxy except the connected master's must be delayed for the
        // measurement to hold whichever replica serves the read
        foreach ($this->replicaProxyNames() as $replicaProxy) {
            $this->toxiproxy->addLatency($replicaProxy, 300);
        }

        // The beforeEach resetAll severs Sentinel's monitoring links to the replicas
        // (they are announced through the proxies), and while Sentinel still flags
        // them disconnected the connector silently falls back to the master
        $this->waitForHealthyReplicas();

        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);
        $this->purgeSentinelConnection();

        $connection = Redis::connection('phpredis-sentinel');

        $start = microtime(true);
        $read = null;
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            try {
                if (($read = $connection->get('chaos_latency')) === 'split') {
                    break;
                }
            } catch (Throwable) {
                $read = null;
            }

            usleep(200_000);
        }

        $elapsed = microtime(true) - $start;

        expect($read)->toBe('split')
            ->and($elapsed)->toBeGreaterThan(0.2, 'Reads should be measurably delayed by the latency toxic')
            ->and($connection->set('chaos_latency', 'still-split'))->toBeTrue('Writes are unaffected by replica latency');
    });

    test('large payload survives slicer toxic', function () {
        $connection = $this->sentinelConnectionWithRetry();

        // The payload only traverses the proxy the established connection actually
        // resolved, so slice that one instead of assuming the 'main' proxy
        $this->toxiproxy->addSlicer($this->masterProxyName(), 4096, 512, 0);

        $payload = bin2hex(random_bytes(512 * 1024)); // ~1 MB

        expect($connection->set('chaos_payload', $payload))->toBeTrue()
            ->and($connection->get('chaos_payload'))->toBe($payload);
    });
});
