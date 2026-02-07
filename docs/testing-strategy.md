# Stratégie de Testing

Ce document décrit la stratégie de testing pour la librairie Laravel Redis Sentinel.

## Vue d'ensemble

La stratégie de testing est organisée en 3 niveaux hiérarchiques :

1. **Tests Unitaires** - Tests isolés de classes/fonctions
2. **Tests d'Intégration** - Tests avec Redis Sentinel (exécution synchrone)
3. **Tests End-to-End (E2E)** - Tests avec workers réels (exécution asynchrone)

## Structure des tests

```
tests/
├── Unit/                          # Tests unitaires
│   ├── Connectors/
│   ├── Concerns/
│   └── ...
├── Feature/
│   ├── Orchestra/                 # Tests d'intégration
│   │   ├── HorizonIntegrationReadWriteTest.php      # Renommé (étais E2E)
│   │   ├── HorizonIntegrationMasterOnlyTest.php     # Renommé (étais E2E)
│   │   ├── HorizonConnectionResilienceTest.php      # Renommé (étais Failover)
│   │   ├── HorizonIntegrationTest.php
│   │   ├── CacheIntegrationTest.php
│   │   └── ...
│   └── E2E/                       # Vrais tests E2E
│       ├── QueueWorkerTest.php
│       └── HorizonWorkerTest.php
└── Support/                       # Classes utilitaires
    └── ProcessManager.php
```

## Types de tests

### 1. Tests Unitaires (`tests/Unit/`)

**Caractéristiques :**
- Testent des classes/fonctions isolées
- Pas de dépendances externes (Redis mocké ou non utilisé)
- Rapides et déterministes
- Pas besoin de services externes

**Exemples :**
- `RedisSentinelConnectorTest` - Test du connecteur
- `LoggableTest` - Test du trait
- `NodeAddressCacheTest` - Test du cache d'adresses

**Exécution :**
```bash
vendor/bin/pest tests/Unit
```

### 2. Tests d'Intégration (`tests/Feature/Orchestra/`)

**Caractéristiques :**
- Utilisent Orchestra Testbench
- Nécessitent Redis Sentinel réel
- Exécution **synchrone** des jobs (`$job->handle()` directement)
- Tests de configuration, structure, et comportement
- **Ne testent PAS le traitement asynchrone**

**Catégories :**

#### a) Tests de connexion
- `ConnectionResilienceTest` - Retry, failover
- `ReadWriteSplittingTest` - Splitting lecture/écriture
- `SentinelRetryTest` - Reconnexion Sentinel

#### b) Tests de fonctionnalités
- `CacheIntegrationTest` - Opérations de cache
- `QueueIntegrationTest` - Opérations de queue
- `SessionIntegrationTest` - Sessions Redis

#### c) Tests Horizon (Intégration)
- `HorizonIntegrationTest` - Structure des jobs, tags, metadata
- `HorizonIntegrationReadWriteTest` - Splitting avec configuration
- `HorizonIntegrationMasterOnlyTest` - Mode master uniquement
- `HorizonConnectionResilienceTest` - Résilience connexion

**Exécution :**
```bash
# Démarrer Redis Sentinel
docker compose -f tests/ci/docker-compose.yml up -d

# Exécuter les tests
vendor/bin/pest tests/Feature/Orchestra

# Nettoyer
docker compose -f tests/ci/docker-compose.yml down
```

### 3. Tests End-to-End (`tests/Feature/E2E/`)

**Caractéristiques :**
- Tests le cycle complet : **Dispatch → Queue → Worker → Completion**
- Utilisent des **process workers réels** (`queue:work`, `horizon`)
- Valident le **comportement asynchrone**
- Plus lents mais plus réalistes
- Requièrent Redis + système de processus

**Fichiers :**
- `QueueWorkerTest.php` - Tests avec `php artisan queue:work`
- `HorizonWorkerTest.php` - Tests avec `php artisan horizon`

**Scénarios testés :**
- ✅ Dispatch et traitement par queue worker
- ✅ Traitement par Horizon supervisor
- ✅ Retry sur échec (avec `FailingTestJob`)
- ✅ Multi-queues
- ✅ Ordre de traitement des jobs

**Exécution :**
```bash
# Démarrer Redis Sentinel
docker compose -f tests/ci/docker-compose.yml up -d

# Exécuter les tests E2E
vendor/bin/pest tests/Feature/E2E

# Nettoyer
docker compose -f tests/ci/docker-compose.yml down
```

## Jobs de test (Workbench)

### HorizonTestJob
Job principal pour les tests :
- Implémente `ShouldQueue`
- Supporte les tags Horizon
- Stocke les métadonnées d'exécution dans le cache
- Supporte queue/delay personnalisés

```php
$job = new HorizonTestJob($jobId, $metadata, $queueName, $delay);
dispatch($job);
```

### FailingTestJob
Job pour tester les retries :
- Échoue intentionnellement sur les premières tentatives
- Paramètre `failUntilAttempt` pour contrôler le succès
- Stocke chaque tentative dans le cache

```php
FailingTestJob::dispatch($jobId, $failUntilAttempt = 2);
// Échoue aux tentatives 1 et 2, réussit à 3
```

## Bonnes pratiques

### 1. Nommage cohérent

| Type | Convention | Exemple |
|------|------------|---------|
| Unit | `ClassTest.php` | `RedisSentinelConnectorTest.php` |
| Integration | `FeatureTest.php` | `HorizonIntegrationTest.php` |
| E2E | `WorkerTest.php` | `QueueWorkerTest.php` |

### 2. Isolation des tests

Chaque test doit nettoyer son état :
```php
beforeEach(function () {
    Cache::flush();
    // Nettoyage de la queue
    Redis::flushall();
});

afterEach(function () {
    $this->processManager->stopAll();
});
```

### 3. Timeouts explicites

Toujours définir des timeouts pour éviter les blocages :
```php
$process = $this->processManager->startQueueWorker('phpredis-sentinel', 30);
$completed = $this->processManager->waitForJobs(30);
```

### 4. Gestion des processus

Les tests E2E utilisent `ProcessManager` :
- Démarrage propre des workers
- Attente conditionnelle (pas de delays fixes)
- Arrêt propre après chaque test

## CI/CD

### GitHub Actions

**Job `tests` (Intégration) :**
- Matrix : PHP 8.2-8.4 × Laravel 10-12 × Redis 6-7
- Exécute : `vendor/bin/pest --coverage-clover coverage.xml`
- Couverture de code avec codecov

**Job `e2e-tests` (E2E) :**
- Matrix : PHP 8.2-8.4 × Laravel 11-12
- Redis 7 uniquement
- Exécute : `vendor/bin/pest tests/Feature/E2E`
- Ports distincts pour éviter conflits

### Docker Compose

Redis Sentinel cluster pour les tests :
- 1 master
- 2 replicas
- 1 sentinel
- 1 standalone (pour tests de régression)

```bash
docker compose -f tests/ci/docker-compose.yml up -d
```

## Ajouter de nouveaux tests

### Test d'intégration

```php
// tests/Feature/Orchestra/MonNouveauTest.php
describe('Ma nouvelle fonctionnalité', function () {
    beforeEach(function () {
        config()->set('database.redis.phpredis-sentinel.option', 'valeur');
    });

    test('ça fait quelque chose', function () {
        // Test synchrone
        $result = service()->action();
        expect($result)->toBeTrue();
    });
});
```

### Test E2E

```php
// tests/Feature/E2E/MonWorkerTest.php
use Goopil\LaravelRedisSentinel\Tests\Support\ProcessManager;

describe('Mon Worker E2E', function () {
    beforeEach(function () {
        $this->processManager = new ProcessManager;
    });

    afterEach(function () {
        $this->processManager->stopAll();
    });

    test('process async jobs', function () {
        // Dispatch
        Job::dispatch($data);
        
        // Start worker
        $this->processManager->startQueueWorker();
        
        // Wait
        $this->processManager->waitForJobs(30);
        
        // Verify
        expect(Cache::get('result'))->toBe('expected');
    });
});
```

## Dépannage

### Tests qui échouent localement

1. **Vérifier Redis Sentinel :**
```bash
docker ps | grep redis
redis-cli -p 26379 -a test ping
```

2. **Nettoyer l'état :**
```bash
docker compose -f tests/ci/docker-compose.yml down -v
```

3. **Run un seul test :**
```bash
vendor/bin/pest tests/Feature/E2E/QueueWorkerTest.php --filter="dispatched jobs"
```

### Tests instables (flaky)

- Augmenter les timeouts
- Vérifiez l'ordre des opérations (attendre le worker avant de vérifier)
- Utilisez `sleep()` minimalement, privilégiez les checks conditionnels

## Ressources

- [Orchestra Testbench](https://packages.tools/testbench)
- [Laravel Horizon](https://laravel.com/docs/horizon)
- [Redis Sentinel](https://redis.io/docs/management/sentinel/)
- [Pest PHP](https://pestphp.com/)

---

**Version:** 1.0  
**Dernière mise à jour:** Février 2025
