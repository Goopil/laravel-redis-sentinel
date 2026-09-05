# Laravel Redis Sentinel

[![Tests](https://github.com/goopil/laravel-redis-sentinel/actions/workflows/tests.yml/badge.svg)](https://github.com/goopil/laravel-redis-sentinel/actions/workflows/tests.yml)
[![codecov](https://codecov.io/gh/goopil/laravel-redis-sentinel/graph/badge.svg)](https://codecov.io/gh/goopil/laravel-redis-sentinel)
[![License: LGPL v3](https://img.shields.io/badge/License-LGPL%20v3-blue.svg)](https://www.gnu.org/licenses/lgpl-3.0)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-blue)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-10%20%7C%2011%20%7C%2012%20%7C%2013-red)](https://laravel.com/)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/goopil/laravel-redis-sentinel.svg)](https://packagist.org/packages/goopil/laravel-redis-sentinel)

A Laravel package that adds Redis Sentinel support through the PhpRedis extension.
It is intended for high-availability Redis setups and handles failover and read/write splitting
transparently, allowing applications to interact with Redis through Laravel's usual APIs without
managing Sentinel-specific logic.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Read/Write Splitting](#readwrite-splitting)
- [Usage Examples](#usage-examples)
- [Laravel Octane Support](#laravel-octane-support)
- [Horizon Integration](#horizon-integration)
- [Kubernetes Deployment](#kubernetes-deployment)
- [Operations](#operations)
- [Scope and Limitations](#scope-and-limitations)
- [Events](#events)
- [Testing](#testing)
- [Local Development](#local-development)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [Inspiration & Alternatives](#inspiration--alternatives)
- [Credits](#credits)
- [License](#license)
- [Support](#support)

## Features

- Connect to Redis via Sentinel using the PhpRedis extension, with automatic master discovery and failover handling
- Configurable retry logic for both Sentinel and data connections (exponential backoff with jitter, circuit breaker on Sentinel resolution)
- Read/write splitting: reads routed to replicas, writes to the master, with sticky read-after-write consistency
- Full support for Laravel Cache, Queue, Session and Broadcasting
- Native Laravel Horizon integration, including Kubernetes probe commands
- Laravel Octane compatible — per-coroutine connection state in split mode (see [Laravel Octane Support](#laravel-octane-support))
- Node address caching to avoid querying Sentinel on every command
- Multi-Sentinel support and event dispatching for observability

## Requirements

- **PHP**: ^8.2, ^8.3, ^8.4, ^8.5
- **Laravel**: ^10, ^11, ^12, ^13
- **PHP Extension**: `redis` (PhpRedis)
- **Optional**: [Laravel Horizon](https://laravel.com/docs/horizon) for queue management

> ⚠️ **Important**: PHP 8.5 requires Laravel 12. Laravel 11 does not officially support PHP 8.5.
> See [Laravel Support Matrix](https://laravel.com/docs/12.x/releases#support-policy) for details.
> ⚠️ **Important**: Laravel 13 requires PHP 8.3 or higher.

### Redis Setup

- Redis Sentinel cluster (minimum 3 nodes recommended)
- Redis version 6.0 or higher recommended

### Valkey Compatibility

[Valkey](https://valkey.io/) is wire-compatible with Redis and is supported out of the box. Compatibility is validated by
the automated test bench: a Valkey 8 Sentinel cluster runs the full test suite in CI (the `tests-valkey` job) and can be
run locally through the Docker environment (see [Local Development](#local-development)).

## Installation

### 1. Install via Composer

```bash
composer require goopil/laravel-redis-sentinel
```

### 2. Publish Configuration (Optional)

```bash
php artisan vendor:publish --provider="Goopil\LaravelRedisSentinel\RedisSentinelServiceProvider" --tag=config
```

This creates `config/phpredis-sentinel.php` with retry and logging configuration.

### 3. Configure Redis Connection

Add to your `config/database.php`:

```php
'redis' => [
    'client' => 'phpredis-sentinel',

    'default' => [
        // Multiple sentinels for high availability
        'sentinels' => [
            ['host' => '127.0.0.1', 'port' => 26379],
            ['host' => '127.0.0.2', 'port' => 26379],
            ['host' => '127.0.0.3', 'port' => 26379],
        ],

        // Or a single sentinel (for dev & or behind a proxy)
        'sentinel' => [
            'host' => env('REDIS_SENTINEL_HOST', '127.0.0.1'),
            'port' => env('REDIS_SENTINEL_PORT', 26379),
            'service' => env('REDIS_SENTINEL_SERVICE', 'master'),
            'password' => env('REDIS_SENTINEL_PASSWORD'),
        ],

        'password' => env('REDIS_PASSWORD'),
        'database' => env('REDIS_DATABASE', 0),

        // Enable read/write splitting (optional)
        'read_only_replicas' => env('REDIS_READ_REPLICAS', false),

        // Connection options
        'options' => [
            'prefix' => env('REDIS_PREFIX', 'laravel_'),
        ],
    ],
],
```

### 4. Environment Variables

Add to your `.env`:

```env
REDIS_SENTINEL_HOST=127.0.0.1
REDIS_SENTINEL_PORT=26379
REDIS_SENTINEL_SERVICE=master
REDIS_SENTINEL_PASSWORD=your-password
REDIS_PASSWORD=your-redis-password
REDIS_READ_REPLICAS=true
```

## Configuration

### Package Options

The `config/phpredis-sentinel.php` file allows fine-tuning:

```php
return [
    // Replace Laravel's global redis bindings (recommended, see below)
    'override_laravel_redis' => env('REDIS_SENTINEL_OVERRIDE_LARAVEL_REDIS', true),

    // Seconds a resolved master/replica address may stay cached in this process.
    // 0 disables expiry (discouraged: stale topology delays failover detection
    // in long-lived workers).
    'node_cache' => [
        'ttl' => env('REDIS_SENTINEL_NODE_CACHE_TTL', 15),
    ],

    'log' => [
        'channel' => env('REDIS_SENTINEL_LOG_CHANNEL', env('LOG_CHANNEL')),
        // Once per consuming class, error_log() a notice when a logging failure
        // is swallowed (opt-in: retry/failover telemetry is being lost otherwise).
        'notify_swallowed' => env('REDIS_SENTINEL_LOG_NOTIFY_SWALLOWED', false),
    ],

    'commands' => [
        'events' => [
            // Emit success events for Horizon probe commands (failure events are always emitted)
            'emit_success' => env('REDIS_SENTINEL_EMIT_SUCCESS_EVENTS', false),
        ],
    ],

    'retry' => [
        // Sentinel connection retries
        'sentinel' => [
            'attempts' => 5,
            'delay' => 1000, // milliseconds
            'messages' => [
                'No master found for service',
                'No reachable Redis Sentinel host found',
                // Add custom error messages to retry on
            ],
        ],

        // Redis connection retries
        'redis' => [
            'attempts' => 5,
            'delay' => 1000,
            'messages' => [
                'broken pipe',
                'connection closed',
                'connection refused',
                'connection lost',
                'failed while reconnecting',
                'is loading the dataset in memory',
                'php_network_getaddresses',
                'read error on connection',
                'socket error',
                'went away',
                'Connection reset by peer',
                "can't write against a read only replica",
                'Temporary failure in name resolution',
            ],
        ],
    ],
];
```

| Key | Default | Description |
|---|---|---|
| `override_laravel_redis` | `true` | Replace Laravel's global `redis`/`redis.connection` bindings with the Sentinel-aware manager (see [below](#laravel-redis-binding-override)) |
| `node_cache.ttl` | `15` | Seconds a resolved master/replica address stays cached in-process; `0` disables expiry |
| `log.channel` | Laravel default | Log channel used for retry/failover telemetry |
| `log.notify_swallowed` | `false` | `error_log()` notice (once per consuming class) when a logging failure is swallowed |
| `commands.events.emit_success` | `false` | Emit success events for Horizon probe commands; failure events are always emitted |
| `retry.sentinel.*` / `retry.redis.*` | see above | Retry attempts, delay and retryable error message fragments |

Cache entries are namespaced per Sentinel cluster (service name + sentinel endpoints), so two connections sharing a
service name across different clusters never exchange cached addresses.

### Connection Options

Per-connection settings in `config/database.php`:

| Option | Default | Description |
|---|---|---|
| `sentinels` / `sentinel` | — | Sentinel node(s); `sentinel.service` is the monitored master name |
| `password` | `null` | Authentication for the data Redis nodes |
| `sentinel.password` | `null` | Authentication for the Sentinel nodes only (never used for the data connection) |
| `read_only_replicas` | `false` | Enable read/write splitting (see [Read/Write Splitting](#readwrite-splitting)) |
| `timeout` | `5` | Data connection connect timeout (seconds) |
| `read_timeout` | `60` | Data connection read timeout (seconds); keep above your longest blocking command (`BLPOP`, `WAIT`, ...) or set `0` for unbounded blocking reads |
| `sentinel.timeout` | `1` | Sentinel node connect timeout (seconds) |
| `sentinel.read_timeout` | `60` | Sentinel node read timeout (seconds) |
| `retry.redis.attempts` / `delay` / `messages` | package defaults | Per-connection retry override |
| `read_commands` | `[]` | Additional commands routed to replicas when splitting is enabled (see [Replica-Safe Commands](#replica-safe-commands)) |
| `options.prefix` | — | phpredis key prefix |

Data-connection timeouts are isolated from Sentinel node timeouts: `sentinel.timeout`/`sentinel.read_timeout` only
affect the Sentinel nodes.

### Laravel Redis Binding Override

By default, the package replaces Laravel's global `redis` and `redis.connection` container bindings with the
Sentinel-aware manager. This preserves plug-and-play compatibility with Laravel services, facades, queues, cache,
sessions, broadcasting, Horizon, and third-party packages that resolve Redis through Laravel's default bindings.

```env
REDIS_SENTINEL_OVERRIDE_LARAVEL_REDIS=true
```

If your application needs to keep Laravel's native Redis manager as the global binding and use Sentinel only through
explicit `phpredis-sentinel` connections, disable the override:

```env
REDIS_SENTINEL_OVERRIDE_LARAVEL_REDIS=false
```

Important limitations when the override is disabled:

- `Redis::connection()` and `app('redis')` are not Sentinel-aware.
- **Laravel Horizon is not compatible with this opt-out mode**, because Horizon resolves Redis through Laravel's
  global Redis bindings.
- Third-party packages calling `app('redis')`, `app('redis.connection')`, or the `Redis` facade will not use Sentinel.
- Do not set Laravel's global `database.redis.client` to `phpredis-sentinel` while also disabling the override.
- A `phpredis-sentinel` connection falls back to a regular Laravel Redis connection when its configuration lacks the
  Sentinel-specific options required to open a Sentinel-managed connection.

For most applications, keep the override enabled. Resolving a `phpredis-sentinel` connection when the package's
connector is not registered throws a `ConfigurationException` instead of failing with a fatal PHP error.

### Retry Strategy

The package uses **exponential backoff with jitter** to avoid thundering herd:

- First retry: ~1s
- Second retry: ~2s
- Third retry: ~4s
- And so on...

When every Sentinel node is unreachable, resolution attempts are circuit-broken: after 2 consecutive failed
resolutions, further attempts fail immediately (rethrowing the last resolution error) for 5 seconds, instead of
paying the full retry/backoff cost (~30s) on every command. A successful resolution or the cooldown expiry resets
the breaker. The command that opens the breaker still completes its own retry cycle, so expect the first failing
command to take up to ~30s; the following ones fail fast until the breaker re-opens a resolution window.

### Retry Contract

Connection errors matched by the retry messages (`went away`, `read error on connection`, `connection lost`, ...) are
ambiguous: the server may not have executed the command, or may have executed it while the reply was lost. The retry
layer applies to every command, so a non-idempotent write (`INCR`, `LPUSH`, `RPUSH`, `SADD`, `ZADD`, ...) can execute
up to `retry.redis.attempts + 1` times on a flapping connection — duplicated queue jobs, doubled counters (Laravel
does not deduplicate jobs by content).

Only phpredis transport failures (`RedisException`) are candidates for a retry. Any other exception — including one
thrown by your `transaction()`/`pipeline()` callback (a database error, a domain exception) — propagates immediately
and its callback is never replayed, even when its message coincidentally matches a configured retry fragment.

Make write paths resilient to re-execution:

- prefer idempotent commands where the semantics allow it (`SET` over `INCR`, `SET ... NX`, ...);
- carry a unique job/command ID and deduplicate on the consumer side;
- wrap multi-step side effects in a Lua script with a side-effect guard (check a marker key before applying);
- treat `pipeline()`/`transaction()` callbacks as re-executable units.

## Read/Write Splitting

When `read_only_replicas` is enabled, the package provides intelligent command routing:

### How It Works

```php
// Read commands → Replica
$value = Cache::get('user:123');          // → Replica
$users = Redis::smembers('active:users'); // → Replica

// Write commands → Master
Cache::put('user:123', $data);            // → Master
Redis::sadd('active:users', 'john');      // → Master

// After write, reads are sticky → Master
Cache::put('counter', 1);                 // → Master
$count = Cache::get('counter');           // → Master (sticky)
```

### Command Routing Rules

| Scenario                        | Destination | Reason                   |
|---------------------------------|-------------|--------------------------|
| Read command, no prior write    | Replica     | Optimize read throughput |
| Write command                   | Master      | Writes require master    |
| Read after write (same request) | Master      | Consistency guarantee    |
| Inside transaction/pipeline     | Master      | ACID compliance          |
| No healthy replicas             | Master      | Automatic fallback       |

### Sticky Sessions Explained

Since Redis replication is **asynchronous**, a read immediately after a write might hit a replica that hasn't received
the update yet:

```php
// Without sticky sessions (❌ potential inconsistency)
Cache::put('user:123', 'John');  // Write to master
$name = Cache::get('user:123');   // Read from replica → might be stale

// With sticky sessions (✅ guaranteed consistency)
Cache::put('user:123', 'John');  // Write to master, enables sticky mode
$name = Cache::get('user:123');   // Read from master → guaranteed fresh
```

Stickiness lives in per-execution-context state: under concurrent runtimes (Swoole/OpenSwoole) each request
coroutine gets its own flag, so it is structurally scoped to the request; on sequential runtimes (FPM, RoadRunner,
CLI workers) the context is the long-lived worker process, which is why the package additionally resets stickiness
on each Octane lifecycle event and queue `JobProcessing` event as belt-and-suspenders.

### Replica-Safe Commands

When `read_only_replicas` is enabled and the connection is not sticky, the following commands are considered
replica-safe and can be routed to replicas:

- **Strings**: `get`, `mget`, `strlen`, `getrange`, `getbit`, `bitcount`, `bitpos`
- **Hashes**: `hget`, `hgetall`, `hmget`, `hkeys`, `hvals`, `hexists`, `hlen`, `hstrlen`, `hscan`
- **Lists**: `lindex`, `llen`, `lrange`
- **Sets**: `scard`, `sismember`, `smismember`, `smembers`, `srandmember`, `sscan`
- **Sorted Sets**: `zcard`, `zcount`, `zlexcount`, `zrange`, `zrank`, `zrevrange`, `zrevrank`, `zscore`, `zscan`, `zrangebyscore`, `zrevrangebyscore`, `zrangebylex`, `zrevrangebylex`
- **Keys**: `exists`, `scan`, `type`, `ttl`, `pttl`, `object`

You can extend this allowlist per connection with the `read_commands` option:

```php
'default' => [
    // ...
    'read_only_replicas' => true,
    'read_commands' => ['georadius_ro', 'getrange'],
],
```

All other commands are routed to the master. Operational or potentially expensive commands such as `KEYS`, `INFO`,
`MEMORY`, and `PUBSUB` are intentionally not routed to replicas by default.

## Usage Examples

### Cache

```php
use Illuminate\Support\Facades\Cache;

// Configure in config/cache.php
'stores' => [
    'redis-sentinel' => [
        'driver' => 'phpredis-sentinel',
        'connection' => 'default',
    ],
],

// Usage
Cache::store('redis-sentinel')->put('key', 'value', 3600);
$value = Cache::store('redis-sentinel')->get('key');
Cache::store('redis-sentinel')->forget('key');

// Or set as default
Cache::put('key', 'value');
```

### Queue

```php
// Configure in config/queue.php
'connections' => [
    'redis-sentinel' => [
        'driver' => 'phpredis-sentinel',
        'connection' => 'default',
        'queue' => 'default',
        'retry_after' => 90,
        'block_for' => null,
    ],
],

// Dispatch jobs
dispatch(new ProcessOrder($order))->onConnection('redis-sentinel');

// Or set as default in .env
QUEUE_CONNECTION="redis-sentinel"
```

### Session

```php
// Configure in config/session.php
'driver' => 'phpredis-sentinel',
'connection' => 'default',

// Sessions work automatically
session(['user_id' => 123]);
$userId = session('user_id');
```

### Broadcasting

```php
// Configure in config/broadcasting.php
'connections' => [
    'redis-sentinel' => [
        'driver' => 'phpredis-sentinel',
        'connection' => 'default',
    ],
],

// Broadcast events
broadcast(new OrderShipped($order));
```

### Direct Redis Usage

```php
use Illuminate\Support\Facades\Redis;

// Get connection
$redis = Redis::connection('default');

// String operations
$redis->set('key', 'value');
$value = $redis->get('key');

// Hash operations
$redis->hset('user:123', 'name', 'John');
$redis->hset('user:123', 'email', 'john@example.com');
$user = $redis->hgetall('user:123');

// List operations
$redis->lpush('queue', 'job1', 'job2');
$job = $redis->rpop('queue');

// Transactions
$redis->transaction(function ($redis) {
    $redis->incr('counter');
    $redis->set('updated_at', time());
});

// Pipelines
$redis->pipeline(function ($pipe) {
    $pipe->set('key1', 'value1');
    $pipe->set('key2', 'value2');
    $pipe->set('key3', 'value3');
});
```

## Laravel Octane Support

The package is compatible with Laravel Octane and supports long-lived processes. Connection state (master/replica
clients, stickiness, transaction level) lives in an execution context: the worker process on sequential runtimes, the
coroutine's own storage under Swoole/OpenSwoole. Concurrent coroutines sharing one worker therefore do not race on
shared routing state. Runtime detection is extension-based (`class_exists` + coroutine id), not server-based: a
Swoole/OpenSwoole extension loaded under any server — including RoadRunner or FrankenPHP workers — automatically
switches connections to the coroutine-safe path.

- **Split mode (`read_only_replicas: true`)**: each request coroutine lazily builds its own master/replica client
  pair — the same one-pair-per-request connection cost as FPM. Laravel's `pipeline()`/`transaction()` route to the
  coroutine's master through the `client()` override, and the per-command client swap restores the previous
  `$client` value, so interleaved commands cannot corrupt each other's routing.
- **`persistent` is ignored inside coroutines** (forced to `0` for coroutine-created clients): phpredis' persistent
  connection table is process-wide, so a persistent client created in a coroutine would share its socket with other
  coroutines and reintroduce response interleaving. Persistent sockets keep working normally in FPM/CLI.
- **Non-split mode**: every command uses the single client created at construction, shared by the whole worker.
  Sharing one phpredis socket across concurrent coroutines carries the same caveat as plain Laravel + phpredis on
  Octane — an upstream characteristic, not specific to Sentinel.
- **Subscriptions still block their coroutine** (`subscribe`/`psubscribe` loop until the connection ends).
- **Retry backoff still blocks the worker**: the retry delay sleeps the whole worker process, not just the
  coroutine that hit the failure. Sequential runtimes are unaffected.
- **Manager resolution is coroutine-safe**: `RedisSentinelManager` resolves `phpredis-sentinel` connections
  without mutating its shared `$driver` property (non-Sentinel connections still temporarily swap it, matching
  upstream's sequential assumption).

Safe/unsafe summary:

- **Safe**: sequential runtimes — FPM, FrankenPHP worker mode, RoadRunner, and Swoole workers with a single
  in-flight request per worker.
- **Safe**: concurrent Swoole/OpenSwoole coroutines with `read_only_replicas` enabled — state and clients are
  isolated per coroutine.
- **Shared-socket caveat**: concurrent coroutines without R/W splitting share one client/socket — same caveat as
  upstream Laravel on Octane.

Per-context clients are created lazily per request. If connection churn ever becomes measurable in your workload,
a connection pool is the documented upgrade path (deliberately not implemented).

The package listens to Octane's lifecycle events (`RequestReceived`, `TaskReceived`, `TickReceived`,
`OperationTerminated`) and to queue `JobProcessing`, and resets stickiness automatically at each boundary.

## Horizon Integration

The package provides Horizon commands that are useful for Kubernetes deployments:

```bash
# Readiness probe - checks if the worker has a running master supervisor
php artisan horizon:ready

# Liveness probe - checks Sentinel reachability, connection write access and Horizon supervision
php artisan horizon:alive

# Pre-stop hook - sends a TERM signal to the Horizon master supervisor for graceful shutdown
php artisan horizon:pre-stop
```

### Probe Failure Contract

The probes are designed to fail fast and self-heal during Sentinel failovers, so a temporary master outage does not
restart healthy pods:

- Every check swallows transport failures, so the command always terminates (never hangs).
- Exit code `0` only when Sentinel is reachable, the connection can write, and Horizon reports a running master.
- During a master outage the write check fails fast (a fresh client is resolved and creation itself throws when the
  reported master is unreachable), so the probe exits `1`.
- Once Sentinel promotes a new master, probes self-heal within the node cache TTL (`phpredis-sentinel.node_cache.ttl`,
  default 15 s).
- Worst-case runtime is bounded by the retry settings: roughly `(attempts + 1) × read_timeout + backoff`.

Size the Kubernetes probe (`timeoutSeconds` above that bound, `failureThreshold` ≥ 3) so a promotion window shorter
than the retry budget never restarts the pod. See [Kubernetes Deployment](#kubernetes-deployment) for a full example.

### Horizon Configuration

```php
// config/horizon.php
'use' => 'phpredis-sentinel', // Use Sentinel for Horizon

'environments' => [
    'production' => [
        'supervisor-1' => [
            'connection' => 'redis-sentinel',
            'queue' => ['default'],
            'balance' => 'auto',
            'processes' => 10,
            'tries' => 3,
        ],
    ],
],
```

## Kubernetes Deployment

### Complete Deployment Example

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: laravel-horizon
  namespace: production
spec:
  replicas: 3
  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxSurge: 1
      maxUnavailable: 0
  selector:
    matchLabels:
      app: laravel-horizon
  template:
    metadata:
      labels:
        app: laravel-horizon
    spec:
      terminationGracePeriodSeconds: 3600

      containers:
        - name: horizon
          image: your-registry/laravel-app:latest
          command:
            - php
            - artisan
            - horizon

          env:
            - name: REDIS_SENTINEL_HOST
              value: "redis-sentinel.redis.svc.cluster.local"
            - name: REDIS_SENTINEL_PORT
              value: "26379"
            - name: REDIS_SENTINEL_SERVICE
              value: "master"
            - name: REDIS_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: redis-credentials
                  key: password
            - name: REDIS_READ_REPLICAS
              value: "true"

          resources:
            requests:
              cpu: 1
              memory: 1Gi
            limits:
              cpu: 2
              memory: 2Gi

          # Readiness: Is the worker ready to process jobs?
          readinessProbe:
            exec:
              command:
                - php
                - artisan
                - horizon:ready
            initialDelaySeconds: 10
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 3

          # Liveness: Is the worker still alive?
          livenessProbe:
            exec:
              command:
                - php
                - artisan
                - horizon:alive
            initialDelaySeconds: 30
            periodSeconds: 20
            timeoutSeconds: 5
            failureThreshold: 5

          # Graceful shutdown
          lifecycle:
            preStop:
              exec:
                command:
                  - php
                  - artisan
                  - horizon:pre-stop
```

### Redis Sentinel Service

```yaml
apiVersion: v1
kind: Service
metadata:
  name: redis-sentinel
  namespace: redis
spec:
  type: ClusterIP
  ports:
    - port: 26379
      targetPort: 26379
      name: sentinel
  selector:
    app: redis-sentinel
```

## Operations

### Runtime Behaviour

- **Failover is not instant**: Redis Sentinel needs time to detect a master failure, elect a new master, and expose the
  new topology. During this window, commands may be retried and application latency can temporarily increase.
- **Resolved node addresses are cached during execution** (`node_cache.ttl`, default 15 s): the package avoids querying
  Sentinel for every command. When a connection error, read-only error, or failover-related error is detected, the
  connection is refreshed and Sentinel is queried again.
- **Read/write splitting is eventually consistent**: reads can be sent to replicas. If your workload requires
  read-after-write consistency, keep sticky reads enabled — sticky reads stay on the master until the next
  request/job boundary (Octane `RequestReceived`, queue `JobProcessing`); there is no time-based sticky TTL.
- **Commands classified as read-only still run on Redis**: avoid expensive production commands such as `KEYS`; prefer
  cursor-based alternatives like `SCAN` when possible.
- **Pipeline/transaction retries are at-least-once**: if a `pipeline()` or `transaction()` fails mid-flight, the whole
  callback is re-executed after reconnection (see [Retry Contract](#retry-contract)).
- **Scan-family commands reset their cursor** on retry after a reconnection, so iteration restarts on the new node
  (SCAN semantics allow duplicates).
- **`flushdb($async)` / `flushall($sync)` flush semantics are normalized**: `flushdb(async: true)` and
  `flushall(sync: false)` request a non-blocking flush; any other value performs a blocking flush. Blocking-vs-async
  remains best-effort: do not rely on the flag for strict ordering guarantees.
- **Laravel's built-in command retry is disabled for this connection**: Laravel 13+ wraps `command()` with an internal
  single retry whose connector is not Sentinel-aware and dispatches no events. This connection overrides `isRetryable()`
  to turn it off, so the package's `retry()` layer stays the single retry path and
  `RedisSentinelConnectionFailed` / `RedisSentinelConnectionReconnected` fire on the first failure.

### Long-Running Workers

Laravel workers, Horizon workers, Octane workers, daemons, and batch processes keep PHP state alive longer than a
regular HTTP request. For these runtimes:

- reset sticky read/write state at job or request boundaries when using custom workers;
- restart workers during deploys or after Redis/Sentinel topology changes if they keep stale state;
- configure graceful shutdown hooks for Horizon and Kubernetes so workers stop accepting work before the pod is
  terminated;
- monitor retry events to detect workers repeatedly reconnecting to stale Redis nodes.

### Timeouts and Retries

The default retry configuration is intentionally conservative. Tune it according to your SLOs:

- keep Redis and Sentinel timeouts lower than your HTTP/job timeout budget;
- account for the worst-case retry duration during failover;
- avoid very high retry counts on latency-sensitive paths;
- prefer observability-driven tuning using the package events rather than blindly increasing retry attempts;
- remember the [Retry Contract](#retry-contract): connection-loss retries re-execute commands, so keep write paths
  idempotent or deduplicate on the consumer side.

### Security

- run Redis and Sentinel on a private network whenever possible;
- use Redis ACLs or strong passwords for both Redis and Sentinel;
- inject secrets through environment variables or your secret manager, not committed configuration files;
- consider TLS, stunnel, sidecars, or a private service mesh if traffic can cross untrusted networks.

### What to Monitor

- repeated `RedisSentinelReplicaFallback` events (splitting disabled, every read lands on the master);
- repeated `RedisSentinelConnectionFailed` or `RedisSentinelConnectionMaxRetryFailed` events;
- repeated Sentinel discovery failures;
- `READONLY` errors after failover (usually a stale master connection);
- latency spikes during Sentinel elections and replica lag when splitting is enabled;
- Horizon workers failing readiness/liveness checks.

## Scope and Limitations

This package focuses on Redis Sentinel integration for Laravel. It may not be the right choice if:

- You are using **Redis Cluster** and require native sharding support — this package does not replace Redis Cluster
  or provide cluster-level sharding.
- Your workload requires **client-side sharding or partitioning**.
- You need **ultra-low-latency** Redis access with minimal routing logic.
- You prefer to manage Redis failover and topology changes entirely outside of the application layer.

It assumes Sentinel is correctly configured and healthy, and read/write splitting prioritizes correctness and
consistency over aggressive load balancing.

## Events

The package dispatches events for monitoring and observability:

### Available Events

```php
use Goopil\LaravelRedisSentinel\Events;

// Sentinel connection events
Events\RedisSentinelMasterFailed::class
Events\RedisSentinelMasterReconnected::class
Events\RedisSentinelMasterMaxRetryFailed::class

// Redis connection events
Events\RedisSentinelConnectionFailed::class
Events\RedisSentinelConnectionReconnected::class
Events\RedisSentinelConnectionMaxRetryFailed::class

// Replica fallback event
Events\RedisSentinelReplicaFallback::class
```

### Horizon Worker Events

The Kubernetes probe commands also dispatch worker lifecycle events:

```php
use Goopil\LaravelRedisSentinel\Events;

// horizon:ready
Events\HorizonWorkerReady::class;           // worker is ready (opt-in, see emit_success below)
Events\HorizonWorkerNotReady::class;        // no running master supervisor found (always emitted)
                                            // properties: $masters, $running

// horizon:alive
Events\HorizonWorkerAlive::class;           // all liveness checks pass (opt-in, see emit_success below)
Events\HorizonWorkerNotAlive::class;        // one or more checks failed (always emitted)
                                            // property: $failedChecks (check name => exit code)

// horizon:pre-stop
Events\HorizonWorkerTerminating::class;     // TERM signal sent to the master supervisor (opt-in, see emit_success below)
                                            // properties: $pid, $startCommand
Events\HorizonWorkerTerminateFailed::class; // TERM signal failed or PID not found (always emitted)
                                            // properties: $startCommand, $pid (null when not found), $reason
```

Success events are disabled by default so probes stay quiet during normal operation. Enable them in
`config/phpredis-sentinel.php`:

```php
'commands' => [
    'events' => [
        'emit_success' => env('REDIS_SENTINEL_EMIT_SUCCESS_EVENTS', false),
    ],
],
```

or by setting the environment variable:

```env
REDIS_SENTINEL_EMIT_SUCCESS_EVENTS=true
```

Failure events are always dispatched, regardless of this setting.

### Listening to Events

```php
// In your EventServiceProvider
protected $listen = [
    \Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionFailed::class => [
        \App\Listeners\NotifyRedisFailure::class,
    ],
];

// Listener example
class NotifyRedisFailure
{
    public function handle(RedisSentinelConnectionFailed $event)
    {
        Log::error('Redis connection failed', [
            'connection' => $event->connection->getName(),
            'context' => $event->context,
            'attempts' => $event->attempts,
            'error' => $event->exception->getMessage(),
        ]);

        // Send to monitoring service
        // Sentry::captureException($event->exception);
    }
}

// Horizon worker lifecycle example
protected $listen = [
    \Goopil\LaravelRedisSentinel\Events\HorizonWorkerNotAlive::class => [
        \App\Listeners\AlertHorizonUnhealthy::class,
    ],
];

class AlertHorizonUnhealthy
{
    public function handle(HorizonWorkerNotAlive $event): void
    {
        Log::error('Horizon worker failed liveness checks', [
            'failed_checks' => $event->failedChecks,
        ]);
    }
}
```

## Testing

### Running Tests

```bash
# Run all tests
composer test

# Run with coverage
composer test:coverage

# Lint code
composer lint

# Static analysis
composer stan

# Fix code style
composer format
```

### Test Structure

```
tests/
├── Feature/           # Integration tests against a real Redis Sentinel cluster
│   ├── Horizon/       # Horizon integration tests (skipped when Horizon is not installed)
│   ├── Orchestra/     # Full E2E tests through Laravel's stack (Orchestra Testbench)
│   ├── Toxiproxy/     # Chaos tests: failover, READONLY retries, network toxics
│   └── *.php
├── Unit/              # Unit tests
├── Support/           # Test helpers (Toxiproxy manager, fake execution context)
└── ci/                # CI-specific compose files
```

### CI/CD

The package includes a comprehensive GitHub Actions workflow that tests:

- PHP 8.2, 8.3, 8.4, 8.5
- Laravel 10, 11, 12, 13
- Redis 6, 7
- Valkey 8 (dedicated `tests-valkey` job)
- Linting and static analysis before the test matrix
- Matrix test jobs across isolated Redis Sentinel clusters
- A dedicated PHP 8.4 / Laravel 12 job without Horizon installed
- A `tests-chaos` job exercising real Sentinel failover over Toxiproxy
- Coverage reporting with a minimum coverage threshold

### Resilience Testing

An optional chaos suite in `tests/Feature/Toxiproxy/` exercises real-world failure scenarios over a toxified network
using [Toxiproxy](https://github.com/Shopify/toxiproxy): actual Sentinel-driven master promotion, `READONLY` retries
caused by a stale cached master address, and network toxics such as timeouts, latency, and connection cuts. Run it with:

```bash
docker compose -f docker-compose.yml -f docker-compose.chaos.yml up -d
vendor/bin/pest --group=toxiproxy
```

The chaos tests are skipped automatically when the chaos stack is not running.

## Local Development

### Docker Environment

Start a complete Redis Sentinel cluster locally:

```bash
docker compose up -d
```

This starts:

- 1 Redis Master (port 6380)
- 2 Redis Replicas (ports 6381, 6382)
- 1 Redis Sentinel (port 26379)
- 1 Standalone Redis (port 6379)

### Connect to Services

```bash
# Connect to master
redis-cli -h 127.0.0.1 -p 6380 -a test

# Connect to sentinel
redis-cli -h 127.0.0.1 -p 26379 -a test

# Check sentinel status
redis-cli -h 127.0.0.1 -p 26379 -a test sentinel masters
```

### Running Tests Against Valkey

The Docker environment also includes a Valkey 8 Sentinel cluster (wire-compatible with Redis) on dedicated ports:

- 1 Valkey Master (port 6385)
- 2 Valkey Replicas (ports 6386, 6387)
- 1 Valkey Sentinel (port 26380)
- 1 standalone Valkey (port 6388) used by the plain `redis` connection, kept out of the Sentinel topology

Start the Valkey services and run the test suite against them:

```bash
docker compose up -d valkey-main valkey-replica1 valkey-replica2 valkey-sentinel valkey

REDIS_SENTINEL_PORT=26380 REDIS_STANDALONE_PORT=6388 vendor/bin/pest
```

## Troubleshooting

### Connection Issues

```bash
# Check if Sentinel is reachable
redis-cli -h <sentinel-host> -p 26379 -a <password> ping

# Check master address
redis-cli -h <sentinel-host> -p 26379 -a <password> sentinel get-master-addr-by-name master

# Check replicas
redis-cli -h <sentinel-host> -p 26379 -a <password> sentinel replicas master
```

### Enable Debug Logging

```php
// config/phpredis-sentinel.php
'log' => [
    'channel' => 'redis-sentinel', // Custom channel
],

// config/logging.php
'channels' => [
    'redis-sentinel' => [
        'driver' => 'daily',
        'path' => storage_path('logs/redis-sentinel.log'),
        'level' => 'debug',
    ],
],
```

### Common Issues

**"No master found"**: Check Sentinel configuration and service name
**"READONLY replica"**: Write commands hitting replica (check `read_only_replicas` config)
**"Connection lost"**: Network issues or Redis restart (auto-retry will handle)
**"Auth failed"**: Check `REDIS_PASSWORD` and `REDIS_SENTINEL_PASSWORD`

## Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

Releases follow [Semantic Versioning](https://semver.org/) and are automated with
[semantic-release](https://github.com/semantic-release/semantic-release): `fix:` commits cut patch releases, `feat:`
commits cut minor releases, and `BREAKING CHANGE` footers trigger major releases. Please follow
[Conventional Commits](https://www.conventionalcommits.org/).

## Inspiration & Alternatives

This project is inspired by earlier Redis Sentinel integrations in the Laravel ecosystem.

A sincere thank you to the authors of the following projects for their work, ideas, and contributions to the
community:

- [Namoshek/laravel-redis-sentinel](https://github.com/Namoshek/laravel-redis-sentinel)
- [monospice/laravel-redis-sentinel-drivers](https://github.com/monospice/laravel-redis-sentinel-drivers)

## Credits

- **Author**: [Goopil](https://github.com/goopil)
- **Contributors**: [All Contributors](https://github.com/goopil/laravel-redis-sentinel/graphs/contributors)

## License

This package is licensed under the **GNU Lesser General Public License v3.0 (LGPL-3.0)**. You are free to use it in
commercial and non-commercial projects, modify it, and distribute your modifications, under the LGPL-3.0 conditions
(license notice, statement of changes, and re-licensing of modifications under LGPL-3.0 when distributed).
See [LICENSE](LICENSE) for full details.

## Support

- [Issue Tracker](https://github.com/goopil/laravel-redis-sentinel/issues)
- [Discussions](https://github.com/goopil/laravel-redis-sentinel/discussions)
