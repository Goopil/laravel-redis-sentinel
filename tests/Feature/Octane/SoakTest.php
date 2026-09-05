<?php

use Laravel\Octane\OctaneServiceProvider;

const OCTANE_SOAK_STATE_FILE = 'vendor/orchestra/testbench-core/laravel/storage/logs/octane-server-state.json';

test('octane worker memory and file descriptors stay bounded across request churn', function () {
    if (getenv('OCTANE_SOAK') !== '1') {
        $this->markTestSkipped('Set OCTANE_SOAK=1 to run the real Octane/Swoole soak test (it boots a server by design).');
    }

    if (! extension_loaded('swoole')) {
        $this->markTestSkipped('ext-swoole is required to boot the Octane server.');
    }

    if (! class_exists(OctaneServiceProvider::class)) {
        $this->markTestSkipped('laravel/octane is not installed (composer require laravel/octane).');
    }

    if (! is_file($testbench = realpath('vendor/bin/testbench'))) {
        $this->markTestSkipped('Orchestra testbench CLI not found.');
    }

    $stderrFile = tempnam(sys_get_temp_dir(), 'octane-stderr-');
    $process = null;

    $failWithLogs = function (string $message) use ($stderrFile): never {
        $logs = substr((string) file_get_contents($stderrFile), -2000);

        throw new RuntimeException($message."\n--- octane stderr ---\n".$logs);
    };

    $processTree = function (int $pid) use (&$processTree): array {
        $pids = [$pid];

        $children = [];
        exec('pgrep -P '.(int) $pid.' 2>/dev/null', $children);

        foreach ($children as $child) {
            $pids = array_merge($pids, $processTree((int) $child));
        }

        return $pids;
    };

    // Master PID lives in Octane's server state file; key casing changed
    // across Octane versions, so read both known spellings.
    $masterPid = function (): int {
        $state = json_decode((string) file_get_contents(OCTANE_SOAK_STATE_FILE), true, 512, JSON_THROW_ON_ERROR);

        foreach (['masterProcessId', 'masterPid', 'master_pid'] as $key) {
            if (is_numeric($state[$key] ?? null)) {
                return (int) $state[$key];
            }
        }

        throw new RuntimeException('No master pid in '.OCTANE_SOAK_STATE_FILE);
    };

    // RSS in KB, sum over the whole server process tree. /proc first (Linux,
    // where ps may be busybox), ps fallback for macOS.
    $memoryKb = function (array $pids): int {
        $total = 0;

        foreach ($pids as $pid) {
            if (is_file("/proc/{$pid}/status")) {
                preg_match('/^VmRSS:\s+(\d+)\s+kB/m', (string) file_get_contents("/proc/{$pid}/status"), $m);
                $total += (int) ($m[1] ?? 0);
            } else {
                exec('ps -o rss= -p '.(int) $pid.' 2>/dev/null', $out);
                foreach ($out as $line) {
                    $total += (int) trim($line);
                }
            }
        }

        return $total;
    };

    $fdCount = function (array $pids): int {
        $total = 0;

        foreach ($pids as $pid) {
            if (is_dir("/proc/{$pid}/fd")) {
                $total += max(0, count((array) scandir("/proc/{$pid}/fd")) - 2);
            } else {
                exec('lsof -p '.(int) $pid.' 2>/dev/null', $out);
                $total += count($out);
            }
        }

        return $total;
    };

    $httpGet = function (int $port, string $query): array {
        $ch = curl_init("http://127.0.0.1:{$port}/octane-redis-soak?{$query}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, $body];
    };

    try {
        @mkdir(dirname(OCTANE_SOAK_STATE_FILE), 0777, true);

        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($sock === false) {
            $this->fail("Cannot allocate a local port: {$errstr}");
        }

        $address = (string) stream_socket_get_name($sock, false);
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);
        fclose($sock);

        $process = proc_open(
            // max-requests=0: a recycled worker would reset its memory mid-soak
            // and mask exactly the growth this test measures.
            [PHP_BINARY, $testbench, 'octane:start', '--server=swoole', '--host=127.0.0.1', "--port={$port}", '--workers=1', '--task-workers=0', '--max-requests=0', '--no-interaction'],
            [
                1 => ['file', tempnam(sys_get_temp_dir(), 'octane-stdout-'), 'w'],
                2 => ['file', $stderrFile, 'w'],
            ],
            $pipes,
            null,
            array_merge(getenv(), ['OCTANE_WORKER' => '1', 'APP_DEBUG' => '0'])
        );

        if (! is_resource($process)) {
            $this->fail('Failed to spawn the Octane server process.');
        }

        // Readiness: the soak endpoint must answer through the real server.
        $ready = false;
        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            [$status, $body] = $httpGet($port, 'k=ready');

            if ($status === 200) {
                $ready = true;
                break;
            }

            usleep(200_000);
        }

        if (! $ready) {
            $failWithLogs("Octane server did not become ready on port {$port} within 30s.");
        }

        // Warmup: lazy allocations (worker boot, opcache, first clients) must
        // not count as leaks.
        for ($i = 0; $i < 100; $i++) {
            [$status, $body] = $httpGet($port, "k=w{$i}");

            if ($status !== 200) {
                $failWithLogs("Warmup request {$i} failed with HTTP {$status}.");
            }
        }

        $baselinePids = $processTree($masterPid());
        $memoryBaseline = $memoryKb($baselinePids);
        $fdBaseline = $fdCount($baselinePids);

        // A broken measurement (0 fds seen) would make the growth assertion
        // meaningless.
        expect($fdBaseline)->toBeGreaterThan(0);

        // Phase 1 - sequential requests: one request lifecycle at a time
        // (Octane fires its stickiness reset between requests), replica read +
        // sticky master write/read-back each.
        for ($i = 0; $i < 400; $i++) {
            [$status, $body] = $httpGet($port, "k=s{$i}");

            if ($status !== 200) {
                $failWithLogs("Sequential request {$i} failed with HTTP {$status}.");
            }

            expect(json_decode($body, true)['master'])->toBe("vs{$i}");
        }

        // Phase 2 - concurrent requests: each request spawns real Swoole
        // coroutines sharing the worker's connection, interleaving the
        // replica-read and sticky-master-write paths; responses must stay
        // coroutine- and request-scoped (no cross-talk).
        for ($round = 0; $round < 200; $round++) {
            $key = "b{$round}";

            [$status, $body] = $httpGet($port, "k={$key}&c=5");

            if ($status !== 200) {
                $failWithLogs("Concurrent request {$key} failed with HTTP {$status}.");
            }

            $payload = json_decode($body, true);

            expect($payload['coroutine'])->toBeTrue();

            foreach ($payload['results'] as $index => $result) {
                expect($result['value'])->toBe($index % 2 === 0 ? "v{$key}" : null);
            }
        }

        gc_collect_cycles();

        $finalPids = $processTree($masterPid());
        $memoryGrowth = $memoryKb($finalPids) - $memoryBaseline;
        $fdGrowth = $fdCount($finalPids) - $fdBaseline;

        // Thresholds deliberately generous: the point is catching unbounded
        // growth (per-request clients or context state retained across
        // requests), not measuring per-request overhead.
        expect($memoryGrowth)->toBeLessThanOrEqual(8 * 1024)
            ->and($fdGrowth)->toBeLessThanOrEqual(25);
    } finally {
        if (is_resource($process)) {
            proc_terminate($process, 15);
            usleep(1_500_000);

            $status = proc_get_status($process);

            if (is_array($status) && $status['running']) {
                @exec('kill -9 '.implode(' ', $processTree($masterPid())).' 2>/dev/null');
            }

            proc_close($process);
        }

        @unlink($stderrFile);
    }
})->group('octane-soak');
