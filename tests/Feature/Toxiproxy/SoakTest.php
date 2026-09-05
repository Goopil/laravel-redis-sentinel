<?php

use Goopil\LaravelRedisSentinel\RedisSentinelManager;

test('sustained connection churn and forced failovers do not leak memory or file descriptors', function () {
    if (getenv('SOAK') !== '1') {
        $this->markTestSkipped('Set SOAK=1 to run the soak test (it runs for minutes by design).');
    }

    if (trim((string) shell_exec('command -v lsof')) === '') {
        $this->markTestSkipped('lsof is not available on this host.');
    }

    $fdCount = fn (): int => count(explode("\n", trim((string) shell_exec('lsof -p '.(int) getmypid().' 2>/dev/null'))));

    // Long-lived connection, like a queue worker or an Octane worker: the leak
    // surface is its reconnect/refresh paths, not its construction.
    $connection = $this->sentinelConnectionWithRetry();

    // Warmup: lazy allocations (clients, node cache, context state) must not
    // count as leaks, so the baseline is taken after they settled.
    for ($i = 0; $i < 100; $i++) {
        $connection->setex("soak:warmup:{$i}", 120, str_repeat('x', 128));
        $connection->get("soak:warmup:{$i}");
    }

    gc_collect_cycles();
    $memoryBaseline = memory_get_usage();
    $fdBaseline = $fdCount();

    // Phase 1 — uncached resolves: every call builds a fresh connection with
    // fresh phpredis clients (the exact path FdLeakTest flags as a leak
    // candidate). Once unreferenced, the whole object graph must be
    // reclaimable: held references would show up as unbounded memory/FD growth.
    $manager = app(RedisSentinelManager::class);

    for ($i = 0; $i < 300; $i++) {
        $fresh = $manager->resolve('phpredis-sentinel');
        $fresh->setex("soak:churn:{$i}", 120, "v{$i}");
        $fresh = null;
    }

    // Phase 2 — command traffic across real Sentinel-driven failovers on the
    // long-lived connection (retry-triggered client refreshes included).
    for ($cycle = 1; $cycle <= 2; $cycle++) {
        $oldAddress = $this->sentinelMasterAddress();

        $this->toxiproxy->disable($this->proxyNameForPort($oldAddress['port']));

        $newAddress = $this->waitForMasterChange($oldAddress);
        $this->waitForProxyReady($newAddress['port'], 10);

        expect($this->reissueUntilSuccess(
            fn () => $connection->setex("soak:failover:{$cycle}", 120, "cycle-{$cycle}"),
            attempts: 20
        ))->toBeTrue();

        // Re-open the cut node so Sentinel can demote it and the next cycle has a candidate
        $this->toxiproxy->enable($this->proxyNameForPort($oldAddress['port']));
        $this->waitForProxyReady($oldAddress['port'], 10);
    }

    gc_collect_cycles();

    $memoryGrowth = memory_get_usage() - $memoryBaseline;
    $fdGrowth = $fdCount() - $fdBaseline;

    // Thresholds are deliberately generous: the point is catching unbounded
    // growth (references held on replaced clients, context or cache
    // accumulation), not measuring per-operation overhead.
    expect($memoryGrowth)->toBeLessThanOrEqual(4 * 1024 * 1024)
        ->and($fdGrowth)->toBeLessThanOrEqual(15);
})->group('soak');
