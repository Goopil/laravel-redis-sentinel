# Laravel Redis Sentinel

[![Tests](https://github.com/goopil/laravel-redis-sentinel/actions/workflows/tests.yml/badge.svg)](https://github.com/goopil/laravel-redis-sentinel/actions/workflows/tests.yml)
[![codecov](https://codecov.io/gh/goopil/laravel-redis-sentinel/graph/badge.svg)](https://codecov.io/gh/goopil/laravel-redis-sentinel)
[![License: LGPL v3](https://img.shields.io/badge/License-LGPL%20v3-blue.svg)](https://www.gnu.org/licenses/lgpl-3.0)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4-blue)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-10%20%7C%2011%20%7C%2012-red)](https://laravel.com/)

A production-ready Laravel package providing seamless integration with Redis Sentinel using the PhpRedis extension.
Built for high-availability Redis deployments with automatic failover, intelligent read/write splitting, and
enterprise-grade reliability.

## Why This Package?

- **🔄 Automatic Failover**: Seamlessly handles master node failures with zero downtime
- **📊 Read/Write Splitting**: Intelligently routes reads to replicas and writes to master
- **🔁 Smart Retry Logic**: Configurable retry strategies with exponential backoff
- **🚀 Production Tested**: Battle-tested in production with comprehensive test coverage (342 tests)
- **⚡ High Performance**: Optimized for Laravel Octane and long-lived processes
- **🎯 Zero Config for Most Cases**: Sensible defaults that just work
- **🔍 Full Observability**: Built-in logging and event dispatching for monitoring

---

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
- [Events](#events)
- [Testing](#testing)
- [Contributing](#contributing)
- [License](#license)

---

## Features

### Core Features

- ✅ Connect to Redis via Sentinel using PhpRedis extension
- ✅ Automatic master discovery and failover handling
- ✅ Configurable retry logic for both Sentinel and Redis connections
- ✅ Full support for Laravel Cache, Queue, Session, Broadcasting
- ✅ Native Laravel Horizon integration
- ✅ Laravel Octane compatible (Swoole, RoadRunner)

### Advanced Features

- ✅ **Read/Write Splitting**: Route reads to replicas, writes to master
- ✅ **Sticky Sessions**: Automatic consistency guarantees after writes
- ✅ **Health Checks**: Built-in commands for Kubernetes readiness/liveness probes
- ✅ **Connection Pooling**: Efficient node address caching
- ✅ **Multi-Sentinel Support**: Automatic failover between Sentinel nodes
- ✅ **Event System**: Monitor all connection events for observability

---

## Requirements

- **PHP**: ^8.2, ^8.3, ^8.4
- **Laravel**: ^10, ^11, ^12
- **PHP Extension**: `redis` (PhpRedis)
- **Optional**: [Laravel Horizon](https://laravel.com/docs/horizon) for queue management

### Redis Setup

- Redis Sentinel cluster (minimum 3 nodes recommended)
- Redis version 6.0 or higher recommended

---

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
        'read_only_replicas' => env('REDIS_READ_REPLICAS', true),

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

---

## Configuration

The `config/phpredis-sentinel.php` file allows fine-tuning:

```php
return [
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

### Retry Strategy

The package uses **exponential backoff with jitter** to avoid thundering herd:

- First retry: ~1s
- Second retry: ~2s
- Third retry: ~4s
- And so on...

---

## Read/Write Splitting

When `read_only_replicas` is enabled, the package provides intelligent command routing:

### How It Works

```php
// Read commands → Replica
$value = Cache::get('user:123');        // → Replica
$users = Redis::smembers('active:users'); // → Replica

// Write commands → Master
Cache::put('user:123', $data);          // → Master
Redis::sadd('active:users', 'john');    // → Master

// After write, reads are sticky → Master
Cache::put('counter', 1);               // → Master
$count = Cache::get('counter');         // → Master (sticky)
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

### Read-Only Commands

The following commands are routed to replicas:

**Strings**: `get`, `mget`, `strlen`, `getrange`
**Hashes**: `hget`, `hgetall`, `hmget`, `hkeys`, `hvals`, `hexists`
**Lists**: `lindex`, `llen`, `lrange`
**Sets**: `scard`, `sismember`, `smembers`, `srandmember`
**Sorted Sets**: `zcard`, `zcount`, `zrange`, `zrank`, `zscore`
**Keys**: `exists`, `keys`, `scan`, `type`, `ttl`, `pttl`
**Info**: `info`, `memory`, `pubsub`

All other commands are routed to the master.

---

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
        'driver' => 'redis',
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

---

## Laravel Octane Support

The package is **fully optimized** for Laravel Octane's long-lived processes:

### Automatic State Management

```php
// The package automatically handles:
// ✅ Connection pooling and reuse
// ✅ Sticky session reset between requests
// ✅ Memory leak prevention
// ✅ Graceful reconnection on failures
```

### No Configuration Needed

Simply use Octane as normal:

```bash
php artisan octane:start --server=swoole
# or
php artisan octane:start --server=roadrunner
```

The package listens to Octane's `RequestReceived` event and resets state automatically.

---

## Horizon Integration

The package provides **production-ready Horizon commands** for Kubernetes deployments:

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

---

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

---

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
            'command' => $event->command,
            'attempts' => $event->attempts,
            'error' => $event->exception->getMessage(),
        ]);

        // Send to monitoring service
        // Sentry::captureException($event->exception);
    }
}
```

---

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
composer lint:fix
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

- ✅ PHP 8.2, 8.3, 8.4
- ✅ Laravel 10, 11, 12
- ✅ Redis 6, 7
- ✅ **18 parallel test jobs** with isolated Redis Sentinel clusters
- ✅ 342 tests with 2269 assertions

---

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

---

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

---

## Performance Tips

1. **Enable read_only_replicas**: Distribute read load across replicas
2. **Use pipelining**: Batch multiple commands for better throughput
3. **Configure connection pooling**: Reuse connections in Octane
4. **Monitor replica lag**: Ensure replicas are in sync
5. **Tune retry delays**: Adjust based on your infrastructure

---

## Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

---

## Security

If you discover a security vulnerability, please email security@example.com instead of using the issue tracker.

---

## Inspiration & alternatives

Thanks to both creators of these wonderfull libs.

- [Namoshek/laravel-redis-sentinel](https://github.com/Namoshek/laravel-redis-sentinel)
- [monospice/laravel-redis-sentinel-drivers](https://github.com/monospice/laravel-redis-sentinel-drivers)

## Credits

- **Author**: [Goopil](https://github.com/goopil)
- **Contributors**: [All Contributors](https://github.com/goopil/laravel-redis-sentinel/graphs/contributors)

---

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

See [LICENSE.md](LICENSE.md) for full details.

---

## Support

- 📖 [Documentation](https://github.com/goopil/laravel-redis-sentinel)
- 🐛 [Issue Tracker](https://github.com/goopil/laravel-redis-sentinel/issues)
- 💬 [Discussions](https://github.com/goopil/laravel-redis-sentinel/discussions)

---

**Built with ❤️ for the Laravel community**
