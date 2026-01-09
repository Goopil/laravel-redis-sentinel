# Laravel Redis Sentinel (phpredis)

A Laravel package providing seamless integration with Redis Sentinel using the PhpRedis extension. It enables
high-availability Redis connections, automatic failover, and advanced retry logic, with full support for Laravel's
cache, queue, session, broadcasting, and Horizon.

---

## Features

- Connect to Redis via Sentinel using PhpRedis
- Automatic failover and reconnection
- Configurable retry logic for Sentinel and Redis errors
- Works with Laravel Cache, Queue, Session, Broadcasting, and Horizon
- Artisan commands for Horizon worker management
- Fully compatible with Laravel Octane (Swoole, RoadRunner)
- Efficient node address discovery and caching
- Ready-to-use Docker environment for local development

---

## Requirements

- PHP ^8.2, ^8.3, ^8.4
- Laravel ^10, ^11, ^12
- PHP `redis` extension
- (Optional) [Laravel Horizon](https://laravel.com/docs/10.x/horizon)

---

## Installation

1. **Install via Composer:**

```bash
composer require goopil/laravel-redis-sentinel
```

2. **Publish the configuration:**

```bash
php artisan vendor:publish --provider="Goopil\LaravelRedisSentinel\RedisSentinelServiceProvider" --tag=config
```

3. **Configure your Redis connection in `config/database.php`:**

```php
'redis' => [
    'client' => 'phpredis-sentinel',
    'default' => [
        'sentinels' => [
            ['host' => '127.0.0.1', 'port' => 26379],
            // Add more sentinels if needed
        ],
        'service' => 'master', // Sentinel service name
        'password' => env('REDIS_PASSWORD', null),
        'database' => 0,
        'read_only_replicas' => true, // Optional: enable read/write splitting
    ],
],
```

---

## Read/Write Splitting

When `read_only_replicas` is set to `true`, the package automatically:

- Discovers available replicas via Sentinel.
- Routes "read-only" commands (GET, HGET, SMEMBERS, etc.) to a random healthy replica.
- Routes all other commands to the Master.
- Ensures all commands within a `transaction()` or `pipeline()` are executed on the Master to maintain consistency.
- Automatically falls back to the Master if no healthy replicas are available.

### Sticky Connections

Since Redis replication is asynchronous, a "read" immediately following a "write" might hit a replica before the data
has been replicated, returning old or missing data.

The package ensures consistency by automatically enabling "sticky" mode when `read_only_replicas` is active. This means
that once a write operation is performed, all subsequent reads within the same request/lifecycle will be directed to the
Master, guaranteeing consistency for that session.

---

## Configuration

The file `config/phpredis-sentinel.php` allows you to customize:

- **Log channel**
- **Retry strategies** (number of attempts, delay, error messages)

Example:

```php
return [
    'log' => [
        'channel' => null, // Defaults to Laravel log
    ],
    'retry' => [
        'sentinel' => [
            'attempts' => 5,
            'delay' => 1000, // ms
            'messages' => [
                'broken pipe',
                'connection closed',
                // ...
            ],
        ],
        'redis' => [
            'attempts' => 5,
            'delay' => 1000,
            'messages' => [
                // ...
            ],
        ],
    ],
];
```

---

## Usage

### Using the Cache

Configure a cache store in `config/cache.php`:

```php
'stores' => [
    'sentinel' => [
        'driver' => 'phpredis-sentinel',
        'connection' => 'default',
    ],
],
```

**Example:**

```php
use Illuminate\Support\Facades\Cache;

// Store a value
Cache::store('sentinel')->put('foo', 'bar');

// Retrieve a value
$value = Cache::store('sentinel')->get('foo');

// Remove a value
Cache::store('sentinel')->forget('foo');
```

### Using Queues

Configure a queue connection in `config/queue.php`:

```php
'connections' => [
    'redis-sentinel' => [
        'driver' => 'phpredis-sentinel',
        'connection' => 'default',
        // ...
    ],
],
```

**Example:**

```php
use Illuminate\Support\Facades\Queue;

Queue::connection('redis-sentinel')->push(new MyJob());
```

### Using Sessions

Configure the session driver in `config/session.php`:

```php
'driver' => 'phpredis-sentinel',
'connection' => 'default',
```

### Broadcasting

Configure the broadcaster in `config/broadcasting.php`:

```php
'connections' => [
    'redis-sentinel' => [
        'driver' => 'phpredis-sentinel',
        'connection' => 'default',
    ],
],
```

### Horizon

If you use [Laravel Horizon](https://laravel.com/docs/10.x/horizon), the package integrates automatically. Just set the
Horizon driver to `phpredis-sentinel` in your Horizon config if needed.

### Laravel Octane

The package is designed for high performance in long-lived environments
like [Laravel Octane](https://laravel.com/docs/10.x/octane). It automatically resets the "sticky master" state between
requests to ensure optimal use of read replicas.

---

## Local Development with Docker

A `docker-compose.yml` is provided for local development and testing:

```bash
docker-compose up -d
```

This will start:

- 1 Redis master (port 6380)
- 2 Redis replicas (6381, 6382)
- 1 Sentinel (26379)
- 1 standard Redis (6379)

---

## Testing

Tests are written with Pest and Testbench. To run the tests:

```bash
composer test
```

**Example test:**

```php
test('Sentinel Store from facade is working', function () {
    $cache = Cache::driver('phpredis-sentinel');
    $cache->set('foo', 'bar');
    expect($cache->get('foo'))->toEqual('bar');
});
```

---

## Kubernetes Example

Here is a sample Kubernetes Deployment manifest for a Laravel application using this package and connecting to Redis
Sentinel, including readiness and liveness probes:

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: laravel-app
spec:
  replicas: 2
  selector:
    matchLabels:
      app: laravel-app
  template:
    metadata:
      labels:
        app: laravel-app
    spec:
      containers:
        - name: laravel-app
          image: your-dockerhub-username/your-laravel-app:latest
          command:
            - php
            - artisan
            - horizon
          readinessProbe:
            initialDelaySeconds: 10
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 3
            exec:
              command:
                - php
                - artisan
                - horizon:ready

          livenessProbe:
            initialDelaySeconds: 30
            periodSeconds: 20
            timeoutSeconds: 5
            failureThreshold: 5
            exec:
              command:
                - php
                - artisan
                - horizon:alive

          lifecycle:
            preStop:
              exec:
                command:
                  - php
                  - artisan
                  - horizon:pre-stop
```
