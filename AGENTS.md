# AGENTS.md

High-signal guidance for OpenCode sessions working in this repo.

## Commands

```bash
composer test                 # Run all tests (vendor/bin/pest)
composer test:coverage        # Tests + coverage, min 50%
composer lint                 # Pint check (does not modify)
composer format               # Pint auto-fix

vendor/bin/pest tests/Unit/NodeAddressCacheTest.php          # Single file
vendor/bin/pest --filter=testName                            # Single test
vendor/bin/pest tests/Feature/Orchestra                      # Directory
```

CI runs `lint` as a gate before the test matrix. Always run `composer lint` before `composer test` locally.

## Tests require live Redis Sentinel

Tests do NOT mock Redis. A real Sentinel cluster must be running or tests will hang/fail.

```bash
docker compose up -d          # Root compose file: master 6380, replicas 6381/6382, sentinel 26379, standalone 6379
docker compose down           # Stop
```

All Redis services use password `test`. Two compose files exist:
- `docker-compose.yml` — local dev, hardcoded ports (use this for local testing)
- `tests/ci/docker-compose.yml` — CI only, ports via env vars, do not call directly

## TLS tests

```bash
./tests/tls/generate-certs.sh && docker compose -f docker-compose.yml -f docker-compose.tls.yml up -d
```
Adds `tls-main` (Redis TLS :6393) and `tls-sentinel` (Sentinel TLS :26383, monitors `tls-master` over TLS, requires `tls-replication yes`). `tests/Feature/TlsConnectionTest.php` skips when the overlay is down.

## Chaos tests (toxiproxy)

```bash
docker compose -f docker-compose.yml -f docker-compose.chaos.yml up -d   # overlay: toxiproxy API :8474, proxies 16380/16381/16382
vendor/bin/pest --group=toxiproxy                                        # run only the chaos tests
```

The overlay gives Sentinel a quorum of 1, a 5000 ms down-after and a 5000 ms failover-timeout, so cutting the master proxy triggers a real promotion and the post-failover cool-down is only ~10 s (tests may trigger promotions back-to-back). The 5000 ms down-after gives 5x headroom over the worst Sentinel ping-reply jitter observed on Docker Desktop (~1 s); a lower value self-sustains sdown/odown flapping even when idle. Chaos tests skip automatically when the overlay is not running.

## Test wiring

`tests/TestCase.php` defines two connections for comparison testing:
- `phpredis-sentinel` — Sentinel-backed connection
- `redis` — standalone Redis (uses `REDIS_STANDALONE_PORT`, not the master port)

Pest helpers in `tests/Pest.php`: `getRedisSentinelConnection()` / `getRedisConnection()`.
Retry delays are overridden to 1ms in `TestCase` for speed.

## Horizon tests are conditional

`tests/Feature/Horizon/*` runs only when `laravel/horizon` is installed. CI has a dedicated job (`tests-without-horizon`) that removes Horizon and excludes those tests via a PHP file filter. If you remove Horizon locally, expect those tests to be skipped/excluded.

## Architecture

- `RedisSentinelManager` extends Laravel's `RedisManager`; registers the `phpredis-sentinel` driver via `RedisSentinelConnector`.
- `RedisSentinelConnection` extends `PhpRedisConnection` and implements read/write splitting with per-execution-context state (`src/Context/`): each coroutine (Swoole/OpenSwoole) or worker process owns its master/replica clients, stickiness and transaction level. The per-command swap still mutates `$this->client` but targets context clients and restores the previous value; split mode creates per-context clients lazily, non-split keeps the shared constructor client.
- `NodeAddressCache` is an in-memory singleton — not shared across workers/processes, but persists between requests in Octane. Stale cache can mask failovers in long-running processes.
- `RedisSentinelServiceProvider` overrides Laravel's global `redis`/`redis.connection` bindings by default (`override_laravel_redis: true`). Disabling this breaks Horizon compatibility — Horizon resolves Redis through the global binding.
- Read-only command allowlist is hardcoded in `RedisSentinelConnection::READ_ONLY_COMMAND` (not configurable).

## Conventional Commits required

Releases are automated via `semantic-release` (runs on `main` after the Tests workflow passes).
- `feat:` → minor, `fix:` → patch, `BREAKING CHANGE` footer → major
- Branch `dev-*` triggers CI but does not release

## Style

- Pint with `laravel` preset (PSR-12)
- Type-hint all parameters and return types
- PHPDoc blocks on complex methods only
- No comments unless explaining non-obvious intent (see `RedisSentinelConnection` header for the nested-retry avoidance rationale)
