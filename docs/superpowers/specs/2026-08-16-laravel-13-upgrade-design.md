# Laravel 13 Upgrade Design

## Context

The `goopil/laravel-redis-sentinel` package currently supports Laravel 10, 11, and 12 with PHP 8.2 through 8.5. Laravel 13 was released on March 17, 2026, requiring PHP 8.3 minimum. This spec covers adding Laravel 13 support while maintaining backward compatibility with Laravel 10-12 and PHP 8.2.

## Approach

**Upgrade minimal** — Add Laravel 13 as an additional supported version. No dropping of existing Laravel/PHP version support. Only surgical changes where Laravel 13 introduces breaking changes that affect the package.

## Changes

### 1. Dependencies (`composer.json`)

| Field | Before | After |
|-------|--------|-------|
| `require.laravel/framework` | `^10.0 \|\| ^11.0 \|\| ^12.0` | `^10.0 \|\| ^11.0 \|\| ^12.0 \|\| ^13.0` |
| `require-dev.orchestra/testbench` | `^8.0 \|\| ^9.0 \|\| ^10.0` | `^8.0 \|\| ^9.0 \|\| ^10.0 \|\| ^11.0` |

No changes needed for:
- `require.php` — stays `^8.2 || ^8.3 || ^8.4 || ^8.5` (L13 requires 8.3, but we keep 8.2 for L10-12)
- `require-dev.laravel/horizon` — stays `^5.29` (Horizon 5.x already declares `^13.0` in its `illuminate/*` constraints)
- `require-dev.pestphp/pest` — stays `^2.36 || ^3.7 || ^4.0` (Pest 4 supports PHPUnit 12 / Laravel 13)
- `require-dev.pestphp/pest-plugin-laravel` — stays `^2.2 || ^3.0 || ^4.0`
- `require-dev.laravel/pint` — stays `^1.19` (Pint 1.x compatible with L13)

### 2. Breaking Change: `Manager::extend()` Callback Binding (`src/RedisSentinelServiceProvider.php`)

**The problem:** Laravel 13 changes how `extend()` closures are bound:

> "Custom driver closures registered via manager `extend` methods are now bound to the manager instance."

Previously, `$this` inside `extend()` closures referred to the ServiceProvider. In Laravel 13, `$this` becomes the manager instance (e.g., `RedisSentinelManager`, `BroadcastFactory`, `CacheManager`, `QueueManager`). This breaks closures that access `$this->app` or `$this->name`.

**Affected methods (4):**

1. **`bootConnector()` (line 139):**
   ```php
   // Before
   $this->app->make(RedisSentinelManager::class)->extend(
       $this->name,
       fn () => $this->app->make('redis.sentinel')
   );

   // After
   $app = $this->app;
   $name = $this->name;
   $this->app->make(RedisSentinelManager::class)->extend(
       $name,
       fn () => $app->make('redis.sentinel')
   );
   ```

2. **`bootBroadcaster()` (line 147):**
   ```php
   // Before
   fn ($app, $conf) => new RedisBroadcaster(
       $this->app->make($this->name),
       Arr::get($conf, 'connection', 'default')
   )

   // After
   $sentinelName = $this->name;
   fn ($app, $conf) => new RedisBroadcaster(
       $app->make($sentinelName),
       Arr::get($conf, 'connection', 'default')
   )
   ```
   Note: The closure already receives `$app` as first parameter, so we use that directly.

3. **`bootCacheStore()` (line 158):**
   ```php
   // Before
   fn ($app, $conf) => $app->make('cache')->repository(
       new RedisStore(
           $app->make(RedisSentinelManager::class),
           $app->make('config')->get('cache.prefix'),
           Arr::get($conf, 'connection', 'default')
       )
   )
   ```
   This closure already uses `$app` (the parameter), not `$this->app`. **No change needed** — it's already safe.

4. **`bootQueue()` (line 205):**
   ```php
   // Before
   $connector = $this->isHorizonContext()
       ? self::HORIZON_REDIS_CONNECTOR
       : RedisConnector::class;

   $this->app->make('queue')->extend(
       $this->name,
       fn () => new $connector($this->app->make($this->name))
   );

   // After
   $app = $this->app;
   $name = $this->name;
   $connector = $this->isHorizonContext()
       ? self::HORIZON_REDIS_CONNECTOR
       : RedisConnector::class;

   $this->app->make('queue')->extend(
       $name,
       fn () => new $connector($app->make($name))
   );
   ```

**Retrocompatibility:** These changes are safe on Laravel 10-12. The `$this` binding still exists but is simply unused. The captured variables via `use ()` replace `$this->app` and `$this->name` correctly.

### 3. CI/CD (`.github/workflows/tests.yml`)

#### Matrix

```yaml
# Before
php: [8.2, 8.3, 8.4, 8.5]
laravel: [10, 11, 12]
redis: [6, 7]

# After
php: [8.2, 8.3, 8.4, 8.5]
laravel: [10, 11, 12, 13]
redis: [6, 7]
```

#### Exclusions

```yaml
# Before
exclude:
  - php: 8.5
    laravel: 10
  - php: 8.5
    laravel: 11

# After
exclude:
  - php: 8.5
    laravel: 10
  - php: 8.5
    laravel: 11
  - php: 8.2
    laravel: 13    # Laravel 13 requires PHP 8.3+
```

Result: 4 PHP × 4 Laravel × 2 Redis = 32 combos, minus 6 (3 exclusions × 2 Redis variants each) = **26 jobs**.

#### Port Allocation Formula (line ~101)

The multiplier `6` = (3 Laravel × 2 Redis). With 4 Laravel versions, it must become `8`:

```
# Before
MATRIX_INDEX = (PHP_INDEX - 2) * 6 + (LARAVEL_INDEX - 10) * 2 + (REDIS_INDEX - 6)

# After
MATRIX_INDEX = (PHP_INDEX - 2) * 8 + (LARAVEL_INDEX - 10) * 2 + (REDIS_INDEX - 6)
```

Max port: `7000 + 31×10 + 4 = 7314` — safe.

#### Testbench Version Mapping (line ~133)

```bash
# Before
"orchestra/testbench:${{ matrix.laravel == 10 && '^8.0' || (matrix.laravel == 11 && '^9.0' || '^10.0') }}"

# After
"orchestra/testbench:${{ matrix.laravel == 10 && '^8.0' || (matrix.laravel == 11 && '^9.0' || (matrix.laravel == 12 && '^10.0' || '^11.0')) }}"
```

#### `tests-without-horizon` Job

No change. This job stays on Laravel 12 / PHP 8.4. It tests the package without Horizon installed, and this coverage is sufficient.

### 4. PHPUnit and Pest

**No changes needed.**

- `phpunit.xml`: Keep the existing XSD schema (`10.3`). It's valid for PHPUnit 10 and accepted by PHPUnit 12. The schema is only used for IDE validation, not execution.
- `pestphp/pest`: The constraint `^2.36 || ^3.7 || ^4.0` already covers Pest 4, which supports PHPUnit 12 and Laravel 13.
- `pint.json`: Pint 1.x is compatible with Laravel 13.

### 5. Code Source — Other Integration Points

After exhaustive analysis, **no other code changes are needed** beyond the `extend()` fix in Section 2:

| Integration Point | Laravel 13 Risk | Action |
|-------------------|-----------------|--------|
| `bootOverrides()`: `getDeferredServices()`/`setDeferredServices()` | No change documented | None |
| `RedisSentinelManager` extends `RedisManager`: `$driver`, `$config`, `$app` properties | Stable since L10, no L13 change | None |
| `RedisSentinelConnector` extends `PhpRedisConnector`: `connect()`, `createClient()` | No change documented | None |
| `RedisSentinelConnection` extends `PhpRedisConnection`: `$this->client` | No visibility change | None |
| `Str::contains()` with `ignoreCase:` named param (Retryable.php:70) | Stable since L9 | None |
| `RedisStore` constructor | `touch()` added to contract, but `RedisStore` is Laravel's class (already implements it) | None |
| `CacheBasedSessionHandler`, `RedisBroadcaster`, `RedisConnector` constructors | No signature changes | None |
| `JobProcessing` event class | Not affected by `JobAttempted` rename | None |
| `HorizonServiceBindings` uses Horizon `ServiceBindings` trait | Horizon 5.x supports L13 | None |
| Octane `RequestReceived` event (string reference) | No change documented | None |
| `Manager extend Callback Binding` | **Breaking change** | **Fixed in Section 2** |

### 6. Documentation (`README.md`)

| Section | Before | After |
|---------|--------|-------|
| Badge Laravel (line 7) | `Laravel-10%20%7C%2011%20%7C%2012` | `Laravel-10%20%7C%2011%20%7C%2012%20%7C%2013` |
| Requirements > Laravel (line 119) | `^10, ^11, ^12` | `^10, ^11, ^12, ^13` |
| Note after requirements (line 123) | Existing PHP 8.5 note | Add: "Laravel 13 requires PHP 8.3 or higher." |
| CI/CD section (line ~770) | `Laravel 10, 11, 12` | `Laravel 10, 11, 12, 13` |
| Matrix jobs count (line ~771) | `22 matrix test jobs` | `26 matrix test jobs` |

## Execution Order

1. **Fix ServiceProvider `extend()` closures** (most critical — can break L13)
2. **Update `composer.json`** (unblocks L13 installation)
3. **Update CI workflow** (validate everything passes)
4. **Update README** (cosmetic)

## Residual Risks

- **Undocumented changes in Laravel 13 Redis classes**: If protected properties/signatures were changed to private without being documented, the package would break. CI tests will detect this.
- **Horizon + Laravel 13 edge cases**: Horizon 5.x declares L13 support, but edge cases may exist. The `tests-without-horizon` job remains on L12 as a safety net.
- **Port collision risk**: If the formula multiplier is not updated correctly, CI jobs will fail with Redis connection errors. The formula change from 6 to 8 is critical.

## Validation

- Run `composer lint` (Pint)
- Run `composer test` locally with Docker Redis Sentinel cluster
- Verify CI passes on all new Laravel 13 jobs (PHP 8.3, 8.4, 8.5 × Laravel 13 × Redis 6, 7)
