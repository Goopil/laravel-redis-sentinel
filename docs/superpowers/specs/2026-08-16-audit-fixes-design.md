# Audit Fixes — laravel-redis-sentinel

## Vue d'ensemble

Correction des 31 findings de l'audit `AUDIT.md` (9 HIGH, 11 MEDIUM, 11 LOW) + 7 gaps de tests critiques, via TDD strict, un commit par finding, dans un worktree isolé.

- **Package** : `goopil/laravel-redis-sentinel`
- **Branche de travail** : `fix/audit-findings` (worktree)
- **Méthodologie** : TDD strict (test échouant → fix → test passe → commit)
- **Commits** : Un commit par finding, Conventional Commits
- **Vérification** : `composer test` + `composer lint` après chaque item, global à la fin

## Setup

1. `git worktree add ../laravel-redis-sentinel-audit-fixes -b fix/audit-findings`
2. `docker compose up -d` dans le worktree (cluster Redis Sentinel local)
3. `composer install`
4. `composer test` — baseline verte avant tout changement

## Phases d'exécution

### Phase 1 — HIGH (9 findings)

| # | Fichier | Fix | Commit message |
|---|---------|-----|----------------|
| 1 | `RedisSentinelManager.php:59` | `isset($this->config['clusters'][$normalizedName])` au lieu de `['clusters']['name']` | `fix: correct cluster detection check in RedisSentinelManager` |
| 2 | `RedisSentinelManager.php:32` | `parent::resolve($normalizedName)` au lieu de `$name` | `fix: pass normalized name to parent::resolve` |
| 3 | `HorizonWorkerLiveness.php:64` | `$data['ip'] ?? ($data[0] ?? false)` pour phpredis 6.0+ | `fix: handle associative array from phpredis 6.0+ in liveness probe` |
| 4 | `HorizonWorkerReadiness.php:33` | `str_starts_with` au lieu de `str_contains` | `fix: use str_starts_with to avoid false hostname matches in readiness` |
| 5 | `HorizonWorkerPreStop.php:56` | `str_starts_with` au lieu de `str_contains` | `fix: use str_starts_with to avoid false hostname matches in preStop` |
| 6 | `HorizonWorkerPreStop.php:102` | `return 1` dans la branche d'échec | `fix: return non-zero exit code on preStop failure` |
| 7 | `RedisSentinelServiceProvider.php:175-176` | Construire un `RedisStore` neuf au lieu de `clone` superficiel | `fix: prevent cache store corruption from shallow clone` |
| 8 | `RedisSentinelServiceProvider.php:116-123` | `forgetInstance('redis')` + `forgetInstance('redis.connection')` après bindings | `fix: flush resolved redis instances on boot override` |
| 9 | `HorizonWorkerLiveness.php:87` | `->setex(..., 300, ...)` au lieu de `->set(...)` | `fix: add TTL to liveness check key to prevent Redis key leak` |

### Phase 2 — MEDIUM (11 findings)

| # | Fichier | Fix | Commit message |
|---|---------|-----|----------------|
| 10 | `Retryable.php:58-93` | Corriger l'off-by-one : 5 retry = 5 tentatives, pas 6 | `fix: correct off-by-one error in retry attempt counting` |
| 11 | `Retryable.php:70` | Guard `if (empty($this->retryMessages)) { throw $exception; }` | `fix: prevent retry on all exceptions when retryMessages is empty` |
| 12 | `RedisSentinelConnection.php:310-316` | Passer le client au callback au lieu de muter `$this->client` | `fix: avoid thread-unsafe client mutation in Octane coroutine context` |
| 13 | `RedisSentinelManager.php:27-52` | Passer le driver explicitement à `connector()` au lieu de muter `$this->driver` | `fix: pass driver explicitly to connector to avoid singleton mutation` |
| 14 | `RedisSentinelConnection.php:257-274` | Ajouter mécanisme de cancellation à `subscribe`/`psubscribe` | `fix: add cancellation mechanism to blocking subscribe calls` |
| 15 | `RedisSentinelServiceProvider.php:51-69` | Écouter `TickReceived`, `TaskReceived`, `OperationTerminated` en plus de `RequestReceived` | `fix: listen to all Octane events for stickiness reset` |
| 16 | `RedisSentinelServiceProvider.php:217-229` | Reset `transactionLevel` dans `resetStickiness()` | `fix: reset transaction level in resetStickiness to prevent master-only reads` |
| 17 | `RedisSentinelConnector.php:38-40` | Injecter le config au lieu de `config()` global dans le constructeur | `fix: inject config instead of using global helper in connector` |
| 18 | `RedisSentinelConnector.php:217` | `random_int` au lieu de `array_rand` | `fix: use cryptographically safe random_int for replica selection` |
| 19 | `RedisSentinelConnector.php:256` | Default timeout à 1.0s au lieu de 0.2s | `fix: increase default sentinel connect timeout to 1.0s` |
| 20 | `RedisSentinelManager.php:95` | Valider `horizon.use` quand `isHorizonContext()` est true | `fix: validate horizon.use config when in horizon context` |

### Phase 3 — LOW (11 findings)

| # | Fichier | Fix | Commit message |
|---|---------|-----|----------------|
| 21 | `RedisSentinelConnection.php:429` | `getReadClient()` : retourner `masterClient` explicitement au lieu de `$this->client` | `fix: return masterClient explicitly in getReadClient fallback` |
| 22 | `RedisSentinelConnection.php:70-78` | Rendre `READ_ONLY_COMMAND` configurable via config | `feat: make read-only command list configurable` |
| 23 | `RedisSentinelConnection.php:70` | Ajouter `object`, `latency`, `memory`, `client`, `debug`, `cluster` à la liste read-only | `fix: add missing read-only commands to allowlist` |
| 24 | `RedisSentinelConnector.php:307-312` | Guard `phpversion('redis') === false` avec `ConfigurationException` | `fix: guard against unloaded phpredis extension in version detection` |
| 25 | `Loggable.php:13` | Documenter le comportement `Log::channel(null)` | `docs: clarify Loggable channel resolution behavior` |
| 26 | `HorizonWorkerPreStop.php:107` | Retirer `-x` du `pgrep` ou assouplir le pattern | `fix: relax pgrep pattern matching in preStop command` |
| 27 | `HorizonWorkerPreStop.php:68` | Gérer le cas multi-PID de `pgrep` (premier PID valide ou erreur) | `fix: handle multi-line pgrep output in preStop PID extraction` |
| 28 | `NodeAddressCache.php` | Ajouter TTL/expiration au cache (refresh périodique) | `fix: add TTL to NodeAddressCache to prevent stale entries` |
| 29 | `RedisSentinelServiceProvider.php:90-97` | Documenter la capture de config au premier resolve (multi-tenant) | `docs: document config capture behavior for multi-tenant setups` |
| 30 | `RedisSentinelConnection.php:225-235` | Exclure les exceptions applicatives du retry pipeline/transaction | `fix: prevent retry on application exceptions in pipeline/transaction` |
| 31 | `Events/*.php` | Retirer `SerializesModels` des events sans modèles | `refactor: remove unused SerializesModels trait from events` |

### Phase 4 — Gaps de tests (7 items)

| Méthode | Test à écrire | Commit message |
|---------|-------------|----------------|
| `RedisSentinelConnector::normalizeHost()` | Test validation sécurité (host malformé, injection) | `test: add coverage for normalizeHost security validation` |
| `RedisSentinelConnector::normalizePort()` | Test validation port (hors range, non-numérique) | `test: add coverage for normalizePort validation` |
| `RedisSentinelManager::patchHorizonPrefix()` | Test isolé de la logique de prefix | `test: add coverage for patchHorizonPrefix logic` |
| `RedisSentinelManager::patchHorizonConnectionName()` | Test isolé de la normalisation | `test: add coverage for patchHorizonConnectionName normalization` |
| `ReadOnlyErrorHandlingTest` | Un-skip le test clé READONLY auto-retry | `test: un-skip and fix ReadOnlyErrorHandling test` |
| `HorizonServiceBindings::getIterator()` | Test direct de l'iterator | `test: add direct coverage for HorizonServiceBindings iterator` |
| `NodeAddressCache::forgetMaster()` | Test des cas edge de cleanup | `test: add coverage for NodeAddressCache forgetMaster edge cases` |

## Workflow TDD par finding

1. Lire le code actuel du fichier concerné
2. Écrire un test Pest qui reproduit le bug (doit échouer)
3. Vérifier que le test échoue : `composer test -- --filter=<nom_test>`
4. Appliquer le fix décrit dans l'audit
5. Vérifier que le test passe
6. Lancer `composer lint` (Pint PSR-12)
7. Commit avec le message exact spécifié

## Vérification finale

1. `composer test` — tous les tests passent (unit + feature + E2E)
2. `composer lint` — Pint ne rapporte aucune erreur
3. `git log --oneline` — ~38 commits, un par finding/gap
4. `git diff main --stat` — vérifier la cohérence des changements
5. Résumé d'exécution rapporté par le sub agent

## Gestion des risques

- **Sub agent interrompu** : S'arrête proprement, rapporte sa progression. Reprise possible via `task_id`.
- **Findings #12 et #13 (Octane mutation)** : Les plus complexes — changent des signatures de méthode. Le sub agent doit adapter les callers et documenter les changements.
- **Docker indisponible** : Tests unit/feature seulement, E2E marqués comme skipped.
- **Tests E2E existants fragiles** : `MultiSentinelTest` termine par `expect(true)->toBeTrue()` — ne pas casser mais ne pas non plus corriger dans cette passe (scope limité aux 31 findings + 7 gaps).
