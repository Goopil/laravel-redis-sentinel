# Laravel Redis Sentinel

[![Tests](https://github.com/goopil/laravel-redis-sentinel/actions/workflows/tests.yml/badge.svg)](https://github.com/goopil/laravel-redis-sentinel/actions/workflows/tests.yml)
[![codecov](https://codecov.io/gh/goopil/laravel-redis-sentinel/graph/badge.svg)](https://codecov.io/gh/goopil/laravel-redis-sentinel)
[![License: LGPL v3](https://img.shields.io/badge/License-LGPL%20v3-blue.svg)](https://www.gnu.org/licenses/lgpl-3.0)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-blue)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-10%20%7C%2011%20%7C%2012%20%7C%2013-red)](https://laravel.com/)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/goopil/laravel-redis-sentinel.svg)](https://packagist.org/packages/goopil/laravel-redis-sentinel)

A Laravel package that adds Redis Sentinel support through the PhpRedis extension.
It is intended for high-availability Redis setups and handles failover and read/write concerns transparently,
allowing applications to interact with Redis without having to manage Sentinel-specific logic.

## Why This Package?

- **🧠 Approach**: Built around patterns and behaviors observed in long-running Redis Sentinel deployments
- **🔄 Automatic Failover**: Detects master changes and reconnects automatically
- **📊 Read/Write Splitting**: Routes reads to replicas and writes to the master
- **🔁 Smart Retry Logic**: Configurable retry strategies with exponential backoff
- **🧪 Test Coverage**: Covered by an extensive automated test suite
- **⚡ Performance-Oriented**: Designed with performance in mind and suitable for long-lived processes
- **🎯 Sensible Defaults**: Works out of the box for most common setups
- **🔍 Observability**: Built-in logging and event dispatching for monitoring

## Stability & Maturity

This package focuses on providing a reliable Redis Sentinel integration for Laravel applications.

- **Failover handling** is considered stable and forms the core of the package.  
  Master discovery, reconnection logic, and retry strategies are designed to behave predictably during Sentinel-driven
  topology changes.

- **Read/Write splitting** is functional and covered by tests, but is still evolving.  
  It covers common Laravel read paths with a deliberately strict allowlist, while operational or expensive commands stay
  on the master by default.

Feedback from real-world usage is welcome to help further improve and harden these behaviors.

## Roadmap

The following items outline areas of ongoing and future improvement:

- **Read/Write Splitting Refinement**: Further refinement of read/write routing behavior in high-concurrency and
  edge-case scenarios.
- **Observability Improvements**: Better visibility into Sentinel discovery, failover events, and routing decisions.
- **Configuration & Extensibility**: Additional hooks and configuration options for advanced Redis Sentinel setups.

## Governance & Project Direction

This project is maintained with a focus on correctness, predictability, and long-term stability.

Feature requests and contributions are welcome, but inclusion depends on their relevance to Redis Sentinel
integration and their impact on overall complexity.

## Versioning & Backward Compatibility

This package follows [Semantic Versioning](https://semver.org/) and uses [Semantic Release](https://github.com/semantic-release/semantic-release) for automated versioning and package publishing.

To ensure the automated release process works correctly, please follow the [Conventional Commits](https://www.conventionalcommits.org/) specification for your commit messages.

- **Patch releases** (0.0.x) are triggered by `fix:` commits.
- **Minor releases** (0.x.0) are triggered by `feat:` commits.
- **Major releases** (X.0.0) are triggered by commits with `BREAKING CHANGE` in the footer.

Backward compatibility is a priority, but correctness and long-term maintainability take precedence when trade-offs are
required.

## Table of Contents

- [Why This Package?](#why-this-package)
- [Stability & Maturity](#stability--maturity)
- [Roadmap](#roadmap)
- [Governance & Project Direction](#governance--project-direction)
- [Versioning & Backward Compatibility](#versioning--backward-compatibility)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Read/Write Splitting](#readwrite-splitting)
- [Usage Examples](#usage-examples)
- [Laravel Octane Support](#laravel-octane-support)
- [Horizon Integration](#horizon-integration)
- [Kubernetes Deployment](#kubernetes-deployment)
- [Production Operations](#production-operations)
- [Events](#events)
- [Testing](#testing)
- [Limitations & Non-Goals](#limitations--non-goals)
- [When NOT to Use This Package](#when-not-to-use-this-package)
- [Performance Tips](#performance-tips)
- [Contributing](#contributing)
- [Inspiration & alternatives](#inspiration--alternatives)
- [Credits](#credits)
- [License](#license)
- [Support](#support)

## Features

### Core Features

- ✅ Connect to Redis via Sentinel using PhpRedis extension
- ✅ Automatic master discovery and failover handling
- ✅ Configurable retry logic for both Sentinel and Redis connections
- ✅ Full support for Laravel Cache, Queue, Session, Broadcasting
- ✅ Native Laravel Horizon integration
- ✅ Laravel Octane compatible (sequential runtimes — see [Laravel Octane Support](#laravel-octane-support) for coroutine limits)

### Advanced Features

- ✅ **Read/Write Splitting**: Routes reads to replicas while directing writes to the master
- ✅ **Sticky Sessions**: Automatic consistency guarantees after writes
- ✅ **Health Checks**: Built-in commands for Kubernetes readiness/liveness probes
- ✅ **Node Discovery**: Avoids repeated Sentinel queries by caching resolved node addresses during execution
- ✅ **Multi-Sentinel Support**: Automatic failover between Sentinel nodes
- ✅ **Event System**: Monitor all connection events for observability

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

The `config/phpredis-sentinel.php` file allows fine-tuning:

```php
return [
    'override_laravel_redis' => true,

    'log' => [
        'channel' => null, // Use Laravel's default log channel
    ],

    'retry' => [
        // Sentinel connection retries
        'sentinel' => [
            'attempts' => 5,
            'delay' => 1000, // milliseconds
            'messages' => [
                'No master found for service',
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
                'connection lost',
                'connection refused',
                'went away',
                'readonly',
                "can't write against a read only replica",
                // ...more in default config
            ],
        ],
    ],
];
```

### Data connection defaults (v1.3+)

- `timeout` for the data connection defaults to **5 seconds** and is no longer derived from `sentinel.timeout`.
- The data client uses strictly `password`; `sentinel.password` only authenticates against Sentinel nodes.
- `retry.redis.messages` can be overridden **per connection** (`retry.redis.messages` inside the connection config), like `attempts`/`delay`.
- Resolved master/replica addresses can be cached with a TTL: `phpredis-sentinel.node_cache.ttl` (seconds, `0` = disabled).

> If you previously published the config file, add `'No reachable Redis Sentinel host found'` to `retry.sentinel.messages` to retry total Sentinel outages.

### Laravel Redis Binding Override

By default, the package replaces Laravel's global `redis` and `redis.connection` container bindings with the Sentinel-aware manager. This is the recommended mode and the one the package is primarily designed for: it preserves plug-and-play compatibility with Laravel services, facades, queues, cache, sessions, broadcasting, Horizon, and third-party packages that resolve Redis through Laravel's default bindings.

```env
REDIS_SENTINEL_OVERRIDE_LARAVEL_REDIS=true
```

If your application needs to keep Laravel's native Redis manager as the global binding and use Sentinel only through explicit `phpredis-sentinel` connections, disable the override:

```env
REDIS_SENTINEL_OVERRIDE_LARAVEL_REDIS=false
```

This is an advanced opt-out mode. With this setting disabled, the package still registers its dedicated `phpredis-sentinel` manager and `redis.sentinel` connector, but it does not replace the global `redis` or `redis.connection` bindings.

Use this mode only when every Sentinel-backed integration is configured explicitly with the `phpredis-sentinel` driver/connection. Code or packages using Laravel's global Redis binding will keep using Laravel's native Redis manager instead:

```php
use Illuminate\Support\Facades\Redis;

Redis::connection('default'); // Uses Laravel's native Redis manager when the override is disabled.
app('redis');                 // Also resolves Laravel's native Redis manager.
```

Important limitations when `REDIS_SENTINEL_OVERRIDE_LARAVEL_REDIS=false`:

- `Redis::connection()` and `app('redis')` are not Sentinel-aware.
- Laravel Horizon is not compatible with this opt-out mode, because Horizon resolves Redis through Laravel's global Redis bindings.
- Third-party packages that call `app('redis')`, `app('redis.connection')`, or the `Redis` facade will not use Sentinel unless they support an explicit custom Redis manager/connection.
- Do not set Laravel's global `database.redis.client` to `phpredis-sentinel` while also disabling the override; the native Laravel Redis manager does not own this package's Sentinel connector.
- A custom `phpredis-sentinel` connection falls back to a regular Laravel Redis connection when its configuration does not contain the Sentinel-specific options required to open a Sentinel-managed connection.

For most applications, keep the override enabled. Disable it only for advanced mixed setups where Laravel's native Redis manager must remain global and Sentinel is used through explicitly configured package integrations.

### Retry Strategy

The package uses **exponential backoff with jitter** to avoid thundering herd:

- First retry: ~1s
- Second retry: ~2s
- Third retry: ~4s
- And so on...

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

The sticky mode **automatically resets** between requests in Octane/Horizon.

### Replica-Safe Commands

When `read_only_replicas` is enabled and the connection is not sticky, the following command families are considered
replica-safe and can be routed to replicas:

- **Strings**: `get`, `mget`, `strlen`, `getrange`
- **Hashes**: `hget`, `hgetall`, `hmget`, `hkeys`, `hvals`, `hexists`
- **Lists**: `lindex`, `llen`, `lrange`
- **Sets**: `scard`, `sismember`, `smembers`, `srandmember`
- **Sorted Sets**: `zcard`, `zcount`, `zrange`, `zrank`, `zscore`
- **Keys**: `exists`, `scan`, `type`, `ttl`, `pttl`

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

The package is compatible with Laravel Octane and supports long-lived processes:

### Automatic State Management

```php
// The package automatically handles:
// ✅ Connection reuse across requests
// ✅ Sticky session reset between requests
// ✅ Graceful reconnection on failures
```

### Coroutine runtimes (Swoole) and R/W splitting

The connection mutates shared per-command state (master/replica client swap, sticky flag), so **concurrent
coroutines sharing one worker are not fully supported for R/W splitting**: interleaving can flip routing
mid-request, and the retry backoff blocks the whole worker, not just the coroutine that hit the failure.

- **Safe**: sequential runtimes — FPM, FrankenPHP worker mode, RoadRunner, Swoole with a single in-flight
  request per worker.
- **Not safe**: Swoole coroutines sharing one connection concurrently (e.g. async requests in the same worker).

If you rely on concurrent coroutines, disable `read_only_replicas` (everything routes to the master, no client
swap) or keep R/W splitting on sequential workers.

### No Configuration Needed

Simply use Octane as normal:

```bash
php artisan octane:start --server=swoole
# or
php artisan octane:start --server=roadrunner
```

The package listens to Octane's lifecycle events (`RequestReceived`, `TaskReceived`, `TickReceived`,
`OperationTerminated`) and to queue `JobProcessing`, and resets stickiness automatically at each boundary.

## Horizon Integration

The package provides Horizon commands that are useful for Kubernetes deployments:

### Available Commands

```bash
# Readiness probe - checks if worker is ready to handle jobs
php artisan horizon:ready

# Liveness probe - checks if worker is still alive
php artisan horizon:alive

# Pre-stop hook - graceful shutdown
php artisan horizon:pre-stop
```

### Kubernetes Readiness/Liveness Probes

See [Kubernetes Deployment](#kubernetes-deployment) section below.

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

## Production Operations

Redis Sentinel is an infrastructure component. Before using this package in production, validate the following points with
your own Redis topology, workload, and deployment model.

### Runtime Behaviour

- **Failover is not instant**: Redis Sentinel needs time to detect a master failure, elect a new master, and expose the new
  topology. During this window, commands may be retried and application latency can temporarily increase.
- **Resolved node addresses are cached during execution**: the package avoids querying Sentinel for every command. When a
  connection error, read-only error, or failover-related error is detected, the connection is refreshed and Sentinel is
  queried again.
- **Read/write splitting is eventually consistent**: reads can be sent to replicas. If your workload requires read-after-write
  consistency, keep sticky reads enabled — sticky reads stay on the master until the next request/job boundary (Octane
  `RequestReceived`, queue `JobProcessing`) — there is no time-based sticky TTL; re-reads in a long-running process without
  those events remain on the master.
- **Commands classified as read-only still run on Redis**: avoid expensive production commands such as `KEYS`; prefer
  cursor-based alternatives like `SCAN` when possible.
- **Pipeline/transaction retries are at-least-once**: if a `pipeline()` or `transaction()` fails mid-flight, the whole callback
  is re-executed after reconnection. Only use idempotent operations inside, or handle duplicates in your callback.
- **Scan-family commands reset their cursor** on retry after a reconnection, so iteration restarts on the new node (SCAN
  semantics allow duplicates).
- **`read_timeout` defaults to 0** (no timeout, like Laravel's PhpRedis driver): blocking commands can hang indefinitely if the
  server disappears without closing the socket. Set an explicit `read_timeout` for latency-sensitive paths.
- **`flushdb($async)` / `flushall($sync)` flags are not reliably forwarded**: on phpredis 6.x the flag is ignored (commands
  run synchronously); on older 5.x releases a truthy argument may be sent as `ASYNC`. Do not rely on the parameter for
  predictable background flushing.
- **Laravel's built-in command retry is disabled for this connection**: Laravel 13+ wraps `command()` with an internal single
  retry whose connector is not Sentinel-aware and dispatches no events. This connection overrides `isRetryable()` to turn it
  off, so the package's `retry()` layer stays the single retry path and `RedisSentinelConnectionFailed` /
  `RedisSentinelConnectionReconnected` fire on the first failure.

### Long-Running Workers

Laravel workers, Horizon workers, Octane workers, daemons, and batch processes keep PHP state alive longer than a regular
HTTP request. For these runtimes:

- reset sticky read/write state at job or request boundaries when using custom workers;
- restart workers during deploys or after Redis/Sentinel topology changes if they keep stale state;
- configure graceful shutdown hooks for Horizon and Kubernetes so workers stop accepting work before the pod is terminated;
- monitor retry events to detect workers repeatedly reconnecting to stale Redis nodes.

### Timeouts and Retries

The default retry configuration is intentionally conservative. Tune it according to your SLOs:

- keep Redis and Sentinel timeouts lower than your HTTP/job timeout budget;
- account for the worst-case retry duration during failover;
- avoid very high retry counts on latency-sensitive paths;
- prefer observability-driven tuning using the package events rather than blindly increasing retry attempts.

### Security Checklist

- run Redis and Sentinel on a private network whenever possible;
- use Redis ACLs or strong passwords for both Redis and Sentinel;
- avoid logging DSNs, passwords, complete connection URLs, or raw configuration payloads;
- inject secrets through environment variables or your secret manager, not committed configuration files;
- consider TLS, stunnel, sidecars, or a private service mesh if traffic can cross untrusted networks;
- grant Sentinel only the permissions required by your Redis deployment model.

### Failure Modes to Monitor

Monitor and alert on these operational symptoms:

- repeated `RedisSentinelConnectionFailed` or `RedisSentinelConnectionMaxRetryFailed` events;
- repeated Sentinel discovery failures;
- `READONLY` errors after failover, which usually indicate a stale master connection;
- sudden increases in command latency during Sentinel elections;
- replica lag when read/write splitting is enabled;
- Horizon workers failing readiness/liveness checks.

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

# Fix code style
composer format
```

### Test Structure

```
tests/
├── Feature/           # Integration tests
│   ├── Orchestra/     # Full E2E tests with real Redis
│   └── *.php          # Feature tests with mocks
├── Unit/              # Unit tests
└── ci/                # CI-specific configs
```

### CI/CD

The package includes a comprehensive GitHub Actions workflow that tests:

- ✅ PHP 8.2, 8.3, 8.4, 8.5
- ✅ Laravel 10, 11, 12, 13
- ✅ Redis 6, 7
- ✅ Valkey 8 (dedicated `tests-valkey` job)
- ✅ Linting before the test matrix
- ✅ **Matrix test jobs** across isolated Redis Sentinel clusters
- ✅ A dedicated PHP 8.4 / Laravel 12 job without Horizon installed
- ✅ Coverage reporting with a minimum coverage threshold

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
docker-compose up -d
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

## Limitations & Non-Goals

This package intentionally focuses on Redis Sentinel integration and does not aim to cover every Redis deployment model.

- It does **not** replace Redis Cluster or provide cluster-level sharding.
- It does **not** attempt to abstract Redis behavior beyond what Sentinel exposes.
- It assumes Sentinel is correctly configured and healthy; misconfigured Sentinel setups may lead to connection
  failures.
- Read/Write splitting prioritizes correctness and consistency over aggressive load balancing.
- Extremely low-latency or ultra-high-throughput use cases may require custom tuning or alternative approaches.

The goal of the package is to offer predictable behavior and seamless integration within Laravel’s ecosystem, rather
than introducing complex Redis abstractions.

## When NOT to Use This Package

This package is a good fit for applications relying on Redis Sentinel for high availability, but it may not be
the right choice in all situations.

Consider alternatives if:

- You are using **Redis Cluster** and require native sharding support.
- Your workload requires **client-side sharding or partitioning**.
- You need **ultra-low-latency** Redis access with minimal routing logic.
- You rely on Redis features or deployment models that are not compatible with Sentinel.
- You prefer to manage Redis failover and topology changes entirely outside of the application layer.

In these cases, a simpler Redis client or a different Redis deployment model may be more appropriate.

## Performance Tips

1. **Enable read_only_replicas**: Distribute read load across replicas
2. **Use pipelining**: Batch multiple commands for better throughput
3. **Reuse connections** in long-lived runtimes: Avoid unnecessary reconnects in Octane or Horizon
4. **Monitor replica lag**: Ensure replicas are in sync
5. **Tune retry delays**: Adjust based on your infrastructure

## Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Inspiration & alternatives

This project is inspired by earlier Redis Sentinel integrations in the Laravel ecosystem.

A sincere thank you to the authors of the following projects for their work, ideas, and contributions to the
community:

- [Namoshek/laravel-redis-sentinel](https://github.com/Namoshek/laravel-redis-sentinel)
- [monospice/laravel-redis-sentinel-drivers](https://github.com/monospice/laravel-redis-sentinel-drivers)

## Credits

- **Author**: [Goopil](https://github.com/goopil)
- **Contributors**: [All Contributors](https://github.com/goopil/laravel-redis-sentinel/graphs/contributors)

## License

This package is licensed under the **GNU Lesser General Public License v3.0 (LGPL-3.0)**.

You are free to:

- ✅ Use this package in commercial and non-commercial projects
- ✅ Modify the package for your needs
- ✅ Distribute your modifications

Under the conditions that:

- 📄 You include the license and copyright notice
- 🔗 You state changes made to the code
- 📖 You make your modifications available under LGPL-3.0 if distributed

See [LICENSE](LICENSE) for full details.

## Support

- 🐛 [Issue Tracker](https://github.com/goopil/laravel-redis-sentinel/issues)
- 💬 [Discussions](https://github.com/goopil/laravel-redis-sentinel/discussions)

---

**Built with ❤️ for the Laravel community**
