<?php

namespace Goopil\LaravelRedisSentinel\Commands;

use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\RedisSentinelManager;
use Illuminate\Console\Command;
use Redis;
use RedisException;
use Throwable;

class SentinelStatus extends Command
{
    protected const EVENT_CHANNELS = [
        '+switch-master',
        '+failover-end',
        '+sdown',
        '+odown',
        '+convert-to-slave',
        '+promoted-slave',
    ];

    protected $signature = 'sentinel:status
        {--connection=* : Sentinel connection name from `database.redis` (defaults to all)}
        {--watch : Stream Sentinel pub/sub events (blocking — Ctrl+C to stop)}
        {--json : Emit machine-readable JSON instead of tables}';

    protected $description = 'Show the Redis Sentinel topology of the configured connections';

    /**
     * Exit 0 only when every inspected connection answered; unreachable sentinels
     * are reported per-connection (the loop continues) and fail the command.
     */
    public function handle(RedisSentinelManager $manager): int
    {
        $requested = (array) $this->option('connection');
        $names = $this->sentinelConnectionNames();

        $missing = array_diff($requested, $names);

        if ($missing !== []) {
            $this->error('Unknown or non-Sentinel connection(s): '.implode(', ', $missing));

            return 1;
        }

        if ($names === []) {
            $this->error('No Redis Sentinel connection defined in `database.redis`.');

            return 1;
        }

        if ($this->option('watch')) {
            if (count($names) > 1) {
                $this->error('Pass exactly one --connection to watch (found: '.implode(', ', $names).').');

                return 1;
            }

            return $this->watch((array) config('database.redis.'.$names[0]));
        }

        $failures = 0;
        $report = [];

        foreach ($names as $name) {
            try {
                $report[$name] = $this->inspect($manager, $name);
            } catch (Throwable $exception) {
                $failures++;
                $this->error(sprintf('%s: %s', $name, $exception->getMessage()));
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderTables($report);
        }

        return $failures === 0 ? 0 : 1;
    }

    /**
     * Render a Sentinel pub/sub event as a single console line.
     *
     * Public static so the --watch feed formatting stays testable without
     * opening a blocking subscribe loop.
     */
    public static function formatEvent(string $channel, string $message): string
    {
        if ($channel === '+switch-master') {
            [$name, $oldIp, $oldPort, $newIp, $newPort] = array_pad(explode(' ', trim($message)), 5, '?');

            return sprintf('[+switch-master] %s %s:%s -> %s:%s', $name, $oldIp, $oldPort, $newIp, $newPort);
        }

        return sprintf('[%s] %s', $channel, trim($message));
    }

    /**
     * @return list<string>
     */
    private function sentinelConnectionNames(): array
    {
        $names = [];

        foreach ((array) config('database.redis') as $name => $config) {
            if (! is_array($config) || ($config['client'] ?? null) !== 'phpredis-sentinel') {
                continue;
            }

            if (! isset($config['sentinel']) && ! isset($config['sentinels'])) {
                continue;
            }

            $names[] = $name;
        }

        $requested = (array) $this->option('connection');

        return $requested === [] ? $names : array_values(array_intersect($names, $requested));
    }

    /**
     * Inspect a connection through the same resolution path the runtime uses
     * (connector retry/breaker/TLS handling included) — what you see here is
     * what the application sees.
     */
    /**
     * @return array<string, mixed>
     */
    private function inspect(RedisSentinelManager $manager, string $name): array
    {
        $config = (array) config('database.redis.'.$name);
        $service = RedisSentinelConnector::serviceFromConfig($config);

        /** @var RedisSentinelConnector $connector */
        $connector = $manager->resolveConnector($name);
        $sentinel = $connector->createSentinel($name);

        return [
            'service' => $service,
            'master' => (array) $sentinel->master($service),
            // phpredis renamed slaves() to replicas() across releases; support both.
            'replicas' => (array) (method_exists($sentinel, 'replicas')
                ? $sentinel->replicas($service)
                : $sentinel->slaves($service)),
            'sentinels' => (array) $sentinel->sentinels($service),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $report
     */
    private function renderTables(array $report): void
    {
        foreach ($report as $name => $entry) {
            $master = $entry['master'];

            $this->info(sprintf('%s · service "%s"', $name, $entry['service'] ?? '?'));
            $this->line(sprintf(
                '  master: %s:%s  flags=%s  quorum=%s  down-after=%sms  failover-timeout=%sms',
                $master['ip'] ?? '?',
                $master['port'] ?? '?',
                $master['flags'] ?? '?',
                $master['quorum'] ?? '?',
                $master['down-after-milliseconds'] ?? '?',
                $master['failover-timeout'] ?? '?',
            ));
            $this->line(sprintf(
                '  replicas=%s  other sentinels=%s',
                $master['num-slaves'] ?? '?',
                $master['num-other-sentinels'] ?? '?',
            ));

            foreach ($entry['replicas'] as $replica) {
                $this->line(sprintf(
                    '  replica: %s:%s  flags=%s  master-link-status=%s',
                    $replica['ip'] ?? '?',
                    $replica['port'] ?? '?',
                    $replica['flags'] ?? '?',
                    $replica['master-link-status'] ?? '?',
                ));
            }

            $peers = [];

            foreach ($entry['sentinels'] as $peer) {
                $peers[] = ($peer['ip'] ?? '?').':'.($peer['port'] ?? '?');
            }

            $this->line('  sentinel peers: '.($peers === [] ? 'none' : implode(', ', $peers)));
            $this->newLine();
        }
    }

    /**
     * Stream Sentinel events over a plain pub/sub subscribe. This blocks until
     * the process is terminated (Ctrl+C); no timeout applies while subscribed.
     *
     * @param  array<string, mixed>  $config
     */
    private function watch(array $config): int
    {
        $sentinelConfig = (array) ($config['sentinel'] ?? []);
        $host = (string) ($sentinelConfig['host'] ?? '');
        $port = (int) ($sentinelConfig['port'] ?? 26379);

        if ($host === '') {
            $this->error('No sentinel host configured.');

            return 1;
        }

        if (isset($sentinelConfig['ssl'])) {
            $this->error('--watch does not support TLS sentinels.');

            return 1;
        }

        try {
            $redis = new Redis;
            $connected = $redis->connect($host, $port, 3.0);

            // @codeCoverageIgnoreStart
            // Defensive: phpredis 5.x returns false instead of throwing.
            if ($connected === false) {
                throw new RedisException('connection refused');
            }
            // @codeCoverageIgnoreEnd
        } catch (RedisException $exception) {
            $this->error(sprintf('Could not connect to sentinel %s:%d: %s', $host, $port, $exception->getMessage()));

            return 1;
        }

        // @codeCoverageIgnoreStart
        // Blocking pub/sub: manually smoke-tested against a live Sentinel; not
        // exercisable in-suite because subscribe() never returns until disconnect.
        $password = (string) ($sentinelConfig['password'] ?? $config['password'] ?? '');

        if ($password !== '') {
            $redis->auth($password);
        }

        $this->info(sprintf('Watching sentinel events on %s:%d (Ctrl+C to stop).', $host, $port));

        $redis->subscribe(self::EVENT_CHANNELS, function (Redis $redis, string $channel, string $message): void {
            $this->line(self::formatEvent($channel, $message));
        });

        return 0;
        // @codeCoverageIgnoreEnd
    }
}
