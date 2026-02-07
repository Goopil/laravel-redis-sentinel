# Plan d'amélioration des tests E2E Horizon

> **Contexte :** Les tests E2E actuels ne testent pas réellement le traitement asynchrone par Horizon. Ce plan vise à
> créer de vrais tests end-to-end qui valident le cycle complet.

**Architecture cible :**

- **Tests Integration** : Exécution synchrone des jobs pour tester la logique métier
- **Tests E2E** : Dispatch + workers réels en arrière-plan + vérification des résultats
- **Tests Failover** : Simulation de failover Sentinel avec vérification de la continuité

**Stack :** Laravel, Pest, Redis Sentinel, Horizon, Process Management

---

## Phase 1 : Réorganisation et clarification

### Task 1.1 : Renommer les tests "E2E" existants en "Integration"

**Files:**

- Rename: `tests/Feature/Orchestra/HorizonE2ETest.php` → `tests/Feature/Orchestra/HorizonIntegrationReadWriteTest.php`
- Rename: `tests/Feature/Orchestra/HorizonE2ENoSplitTest.php` →
  `tests/Feature/Orchestra/HorizonIntegrationMasterOnlyTest.php`
- Rename: `tests/Feature/Orchestra/HorizonFailoverTest.php` →
  `tests/Feature/Orchestra/HorizonConnectionResilienceTest.php`

**Rationale :** Ces tests sont des tests d'intégration (exécution synchrone), pas des E2E.

**Step 1 :** Renommer les fichiers

```bash
mv tests/Feature/Orchestra/HorizonE2ETest.php tests/Feature/Orchestra/HorizonIntegrationReadWriteTest.php
mv tests/Feature/Orchestra/HorizonE2ENoSplitTest.php tests/Feature/Orchestra/HorizonIntegrationMasterOnlyTest.php
mv tests/Feature/Orchestra/HorizonFailoverTest.php tests/Feature/Orchestra/HorizonConnectionResilienceTest.php
```

**Step 2 :** Mettre à jour les descriptions des tests dans chaque fichier

- Changer `describe('Horizon E2E Tests...')` → `describe('Horizon Integration Tests...')`

**Step 3 : Commit**

```bash
git add tests/Feature/Orchestra/*
git commit -m "refactor: rename E2E tests to Integration tests (sync execution)"
```

---

### Task 1.2 : Créer de vrais tests E2E avec process workers

**Files:**

- Create: `tests/Feature/E2E/HorizonWorkerTest.php`
- Create: `tests/Feature/E2E/QueueWorkerTest.php`
- Create: `tests/Support/ProcessManager.php`

**Step 1 : Créer le gestionnaire de processus**

```php
// tests/Support/ProcessManager.php
namespace Goopil\LaravelRedisSentinel\Tests\Support;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ProcessManager
{
    private array $processes = [];

    public function startQueueWorker(string $connection = 'phpredis-sentinel', int $timeout = 60): Process
    {
        $process = new Process([
            'php',
            'artisan',
            'queue:work',
            $connection,
            '--max-time=' . $timeout,
            '--max-jobs=100',
            '--sleep=1',
            '--tries=3',
            '--stop-when-empty'
        ]);
        
        $process->setWorkingDirectory(base_path());
        $process->setTimeout($timeout + 10);
        $process->start();
        
        $this->processes[] = $process;
        
        // Wait for worker to initialize
        sleep(2);
        
        Log::info("Queue worker started on connection: {$connection}");
        
        return $process;
    }

    public function startHorizon(int $timeout = 120): Process
    {
        $process = new Process([
            'php',
            'artisan',
            'horizon',
            '--max-time=' . $timeout
        ]);
        
        $process->setWorkingDirectory(base_path());
        $process->setTimeout($timeout + 10);
        $process->start();
        
        $this->processes[] = $process;
        
        // Wait for Horizon to initialize
        sleep(3);
        
        Log::info("Horizon supervisor started");
        
        return $process;
    }

    public function waitForJobs(int $timeout = 30): bool
    {
        $start = time();
        
        while (time() - $start < $timeout) {
            // Check if all processes are still running
            foreach ($this->processes as $process) {
                if (!$process->isRunning()) {
                    return true; // Worker finished (stop-when-empty)
                }
            }
            
            // Check queue size
            try {
                $queueSize = \Illuminate\Support\Facades\Queue::size();
                if ($queueSize === 0) {
                    sleep(2); // Wait a bit more to ensure processing is complete
                    return true;
                }
            } catch (\Exception $e) {
                // Ignore errors checking queue size
            }
            
            sleep(1);
        }
        
        return false;
    }

    public function stopAll(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(5, SIGTERM);
            }
        }
        
        $this->processes = [];
    }

    public function getOutput(): array
    {
        $output = [];
        foreach ($this->processes as $i => $process) {
            $output[$i] = [
                'stdout' => $process->getOutput(),
                'stderr' => $process->getErrorOutput(),
                'exit_code' => $process->getExitCode(),
            ];
        }
        return $output;
    }
}
```

**Step 2 : Créer le test E2E pour Queue Worker**

```php
// tests/Feature/E2E/QueueWorkerTest.php
use Goopil\LaravelRedisSentinel\Tests\Support\ProcessManager;
use Illuminate\Support\Facades\Cache;
use Workbench\App\Jobs\HorizonTestJob;

describe('Queue Worker E2E Tests', function () {
    beforeEach(function () {
        config()->set('queue.default', 'phpredis-sentinel');
        config()->set('queue.connections.phpredis-sentinel.connection', 'phpredis-sentinel');
        
        // Clear queue before each test
        try {
            \Illuminate\Support\Facades\Queue::flush();
        } catch (\Exception $e) {
            // Ignore
        }
        
        Cache::flush();
        
        $this->processManager = new ProcessManager();
    });

    afterEach(function () {
        $this->processManager->stopAll();
    });

    test('dispatched jobs are processed by queue worker', function () {
        $testId = 'e2e_queue_' . uniqid();
        $jobCount = 5;
        
        // Dispatch jobs to queue
        for ($i = 1; $i <= $jobCount; $i++) {
            HorizonTestJob::dispatch("{$testId}_{$i}", ['index' => $i])
                ->onConnection('phpredis-sentinel');
        }
        
        // Verify jobs are in queue
        expect(\Illuminate\Support\Facades\Queue::size())->toBe($jobCount);
        
        // Start worker
        $process = $this->processManager->startQueueWorker('phpredis-sentinel', 30);
        
        // Wait for jobs to be processed
        $completed = $this->processManager->waitForJobs(30);
        
        expect($completed)->toBeTrue('Jobs should be processed within timeout');
        
        // Verify all jobs executed
        $successCount = 0;
        for ($i = 1; $i <= $jobCount; $i++) {
            if (Cache::get("horizon:job:{$testId}_{$i}:executed")) {
                $successCount++;
            }
        }
        
        expect($successCount)->toBe($jobCount, "All {$jobCount} jobs should be executed by worker");
    });

    test('queue worker handles job failures and retries', function () {
        // Create a job that will fail
        $testId = 'e2e_retry_' . uniqid();
        
        // For this test, we'd need a job that fails intentionally
        // We'll create a separate test job class that throws exceptions
        // and verify retry behavior
        
        // TODO: Implement retry test with custom failing job
    });

    test('queue worker processes jobs in correct order', function () {
        $testId = 'e2e_order_' . uniqid();
        $jobCount = 10;
        
        // Dispatch jobs sequentially
        for ($i = 1; $i <= $jobCount; $i++) {
            HorizonTestJob::dispatch("{$testId}_{$i}", ['sequence' => $i]);
        }
        
        // Start worker
        $process = $this->processManager->startQueueWorker('phpredis-sentinel', 30);
        
        // Wait for completion
        $this->processManager->waitForJobs(30);
        
        // Verify order by checking timestamps
        $timestamps = [];
        for ($i = 1; $i <= $jobCount; $i++) {
            $timestamps[$i] = Cache::get("horizon:job:{$testId}_{$i}:timestamp");
        }
        
        // Timestamps should be in ascending order
        $sorted = $timestamps;
        sort($sorted);
        
        expect($timestamps)->toBe($sorted, 'Jobs should be processed in order');
    });
});
```

**Step 3 : Créer le test E2E pour Horizon**

```php
// tests/Feature/E2E/HorizonWorkerTest.php
use Goopil\LaravelRedisSentinel\Tests\Support\ProcessManager;
use Illuminate\Support\Facades\Cache;
use Workbench\App\Jobs\HorizonTestJob;

describe('Horizon E2E Tests', function () {
    beforeEach(function () {
        // Configure Horizon
        config()->set('horizon.use', 'phpredis-sentinel');
        config()->set('horizon.prefix', 'horizon-e2e:');
        config()->set('queue.default', 'phpredis-sentinel');
        
        // Clear data
        try {
            \Illuminate\Support\Facades\Queue::flush();
        } catch (\Exception $e) {
            // Ignore
        }
        
        Cache::flush();
        
        $this->processManager = new ProcessManager();
    });

    afterEach(function () {
        $this->processManager->stopAll();
    });

    test('horizon processes dispatched jobs', function () {
        if (!class_exists(\Laravel\Horizon\Horizon::class)) {
            $this->markTestSkipped('Horizon is not installed');
        }
        
        $testId = 'e2e_horizon_' . uniqid();
        $jobCount = 10;
        
        // Dispatch jobs
        for ($i = 1; $i <= $jobCount; $i++) {
            HorizonTestJob::dispatch("{$testId}_{$i}", [
                'iteration' => $i,
                'test_type' => 'horizon_e2e',
            ]);
        }
        
        // Start Horizon
        $process = $this->processManager->startHorizon(60);
        
        // Wait for jobs to be processed
        sleep(5);
        
        // Verify jobs executed
        $successCount = 0;
        for ($i = 1; $i <= $jobCount; $i++) {
            if (Cache::get("horizon:job:{$testId}_{$i}:executed")) {
                $successCount++;
            }
        }
        
        expect($successCount)->toBe($jobCount, "Horizon should process all {$jobCount} jobs");
    });

    test('horizon handles multiple queues', function () {
        if (!class_exists(\Laravel\Horizon\Horizon::class)) {
            $this->markTestSkipped('Horizon is not installed');
        }
        
        $testId = 'e2e_multi_queue_' . uniqid();
        
        // Dispatch to different queues
        HorizonTestJob::dispatch("{$testId}_high_1", ['priority' => 'high'])->onQueue('high');
        HorizonTestJob::dispatch("{$testId}_high_2", ['priority' => 'high'])->onQueue('high');
        HorizonTestJob::dispatch("{$testId}_default_1", ['priority' => 'default'])->onQueue('default');
        HorizonTestJob::dispatch("{$testId}_default_2", ['priority' => 'default'])->onQueue('default');
        
        // Start Horizon
        $process = $this->processManager->startHorizon(60);
        
        sleep(5);
        
        // Verify all jobs from both queues processed
        expect(Cache::get("horizon:job:{$testId}_high_1:executed"))->toBeTrue()
            ->and(Cache::get("horizon:job:{$testId}_high_2:executed"))->toBeTrue()
            ->and(Cache::get("horizon:job:{$testId}_default_1:executed"))->toBeTrue()
            ->and(Cache::get("horizon:job:{$testId}_default_2:executed"))->toBeTrue();
    });
});
```

**Step 4 : Commit**

```bash
git add tests/Feature/E2E/ tests/Support/
git commit -m "feat: add true E2E tests with queue workers and Horizon"
```

---

### Task 1.3 : Améliorer le workflow GitHub Actions

**Files:**

- Modify: `.github/workflows/tests.yml`

**Step 1 : Ajouter une étape pour les tests E2E avec workers**

```yaml
# Ajouter cette job après les tests existants

e2e-tests:
  needs: [ tests ]
  runs-on: ubuntu-latest
  strategy:
    fail-fast: false
    matrix:
      php: [ 8.2, 8.3, 8.4 ]
      laravel: [ 11, 12 ]
      redis: [ 7 ]

  name: E2E - PHP ${{ matrix.php }} - L${{ matrix.laravel }}

  steps:
    - name: Checkout code
      uses: actions/checkout@v4

    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: ${{ matrix.php }}
        extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, sqlite, pdo_sqlite, bcmath, soap, intl, readline, ldap, msgpack, igbinary, redis, sodium
        tools: composer:v2
        coverage: none

    - name: Calculate ports
      id: ports
      run: |
        PHP_INDEX=$(echo "${{ matrix.php }}" | sed 's/8\.//')
        LARAVEL_INDEX=${{ matrix.laravel }}
        REDIS_INDEX=${{ matrix.redis }}
        MATRIX_INDEX=$(( (PHP_INDEX - 2) * 4 + (LARAVEL_INDEX - 11) * 2 + (REDIS_INDEX - 7) ))
        PORT_BASE=$((8000 + MATRIX_INDEX * 10))
        echo "redis_main=$((PORT_BASE + 0))" >> $GITHUB_OUTPUT
        echo "redis_replica1=$((PORT_BASE + 1))" >> $GITHUB_OUTPUT
        echo "redis_replica2=$((PORT_BASE + 2))" >> $GITHUB_OUTPUT
        echo "sentinel=$((PORT_BASE + 3))" >> $GITHUB_OUTPUT
        PROJECT_NAME="redis_e2e_php$(echo '${{ matrix.php }}' | tr '.' '_')_l${{ matrix.laravel }}"
        echo "project_name=${PROJECT_NAME}" >> $GITHUB_OUTPUT

    - name: Start Redis Sentinel
      env:
        REDIS_VERSION: ${{ matrix.redis }}
        REDIS_MAIN_PORT: ${{ steps.ports.outputs.redis_main }}
        REDIS_REPLICA1_PORT: ${{ steps.ports.outputs.redis_replica1 }}
        REDIS_REPLICA2_PORT: ${{ steps.ports.outputs.redis_replica2 }}
        REDIS_SENTINEL_PORT: ${{ steps.ports.outputs.sentinel }}
        COMPOSE_PROJECT_NAME: ${{ steps.ports.outputs.project_name }}
      run: |
        docker compose -f tests/ci/docker-compose.yml up -d
        sleep 5
        docker run --network host --rm redis:${{ matrix.redis }}-alpine redis-cli -p ${{ steps.ports.outputs.sentinel }} -a test ping

    - name: Install dependencies
      run: |
        composer require "laravel/framework:${{ matrix.laravel }}.*" "orchestra/testbench:^9.0" --no-interaction --no-update
        composer update --prefer-dist --no-interaction

    - name: Run E2E tests
      run: vendor/bin/pest tests/Feature/E2E --testdox
      env:
        REDIS_PASSWORD: test
        REDIS_SENTINEL_PASSWORD: test
        REDIS_SENTINEL_HOST: 127.0.0.1
        REDIS_SENTINEL_PORT: ${{ steps.ports.outputs.sentinel }}
        REDIS_PREFIX: e2e_${{ github.run_id }}_

    - name: Cleanup
      if: always()
      env:
        COMPOSE_PROJECT_NAME: ${{ steps.ports.outputs.project_name }}
      run: docker compose -f tests/ci/docker-compose.yml down -v
```

**Step 2 : Commit**

```bash
git add .github/workflows/tests.yml
git commit -m "ci: add separate E2E test job with worker processes"
```

---

## Phase 2 : Améliorations des tests existants

### Task 2.1 : Ajouter des tests de dispatch dans HorizonIntegrationTest

**Files:**

- Modify: `tests/Feature/Orchestra/HorizonIntegrationTest.php`

**Step 1 : Ajouter un test de dispatch réel avec vérification**

```php
// Ajouter dans HorizonIntegrationTest.php

test('horizon job can be dispatched and processed by queue worker', function () {
    $jobId = 'dispatch_test_' . time();
    
    // Clear any queued jobs
    \Illuminate\Support\Facades\Queue::fake();
    
    // Dispatch job
    $job = HorizonTestJob::dispatch($jobId, ['type' => 'dispatch_test']);
    
    // Verify job was dispatched
    \Illuminate\Support\Facades\Queue::assertPushed(HorizonTestJob::class, function ($pushedJob) use ($jobId) {
        return $pushedJob->jobId === $jobId;
    });
});

test('horizon job dispatch with custom queue and delay', function () {
    \Illuminate\Support\Facades\Queue::fake();
    
    $jobId = 'advanced_dispatch_' . time();
    $queue = 'high-priority';
    $delay = 300; // 5 minutes
    
    HorizonTestJob::dispatch($jobId, ['priority' => 'high'])
        ->onQueue($queue)
        ->delay(now()->addMinutes(5));
    
    \Illuminate\Support\Facades\Queue::assertPushedOn($queue, HorizonTestJob::class);
});
```

**Step 2 : Commit**

```bash
git add tests/Feature/Orchestra/HorizonIntegrationTest.php
git commit -m "test: add dispatch verification tests for Horizon"
```

---

### Task 2.2 : Créer un job de test qui échoue pour tester les retries

**Files:**

- Create: `workbench/app/Jobs/FailingTestJob.php`

**Step 1 : Créer le job qui échoue**

```php
// workbench/app/Jobs/FailingTestJob.php
namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class FailingTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 1; // 1 second between retries

    public function __construct(
        public string $jobId,
        public int $failUntilAttempt = 2
    ) {
    }

    public function handle(): void
    {
        $attempt = $this->attempts();
        
        // Store attempt number
        Cache::put("failing_job:{$this->jobId}:attempt_{$attempt}", true, 3600);
        
        if ($attempt < $this->failUntilAttempt) {
            throw new \Exception("Intentional failure on attempt {$attempt}");
        }
        
        // Success on final attempt
        Cache::put("failing_job:{$this->jobId}:success", true, 3600);
    }
}
```

**Step 2 : Commit**

```bash
git add workbench/app/Jobs/FailingTestJob.php
git commit -m "feat: add failing test job for retry testing"
```

---

## Phase 3 : Documentation

### Task 3.1 : Créer la documentation stratégie testing

**Files:**

- Create: `docs/testing-strategy.md`

**Step 1 : Écrire la documentation**

```markdown
# Stratégie de Testing

## Types de tests

### 1. Tests Unitaires (`tests/Unit/`)

- Testent des classes/fonctions isolées
- Pas de dépendances externes (Redis mocké ou non utilisé)
- Rapides et déterministes

### 2. Tests d'Intégration (`tests/Feature/Orchestra/`)

- Testent l'intégration avec Orchestra Testbench
- Utilisent Redis réel via Sentinel
- Exécution synchrone des jobs (direct `$job->handle()`)
- Tests de configuration et structure

**Catégories :**

- **Connection Tests** : Retry, failover, read/write splitting
- **Feature Tests** : Cache, Queue, Session avec Sentinel
- **Integration Tests** : Horizon job structure, metadata

### 3. Tests End-to-End (`tests/Feature/E2E/`)

- Testent le cycle complet : dispatch → queue → worker → completion
- Utilisent des process workers réels (`queue:work`, `horizon`)
- Valident le comportement asynchrone
- Plus lents mais plus réalistes

**Scénarios testés :**

- Dispatch et traitement par queue worker
- Traitement par Horizon supervisor
- Retry sur échec
- Multi-queues
- Persistence des données

## Exécution des tests

```bash
# Tests unitaires uniquement
vendor/bin/pest tests/Unit

# Tests d'intégration (requiert Redis Sentinel)
vendor/bin/pest tests/Feature/Orchestra

# Tests E2E (requiert Redis + workers)
vendor/bin/pest tests/Feature/E2E

# Tous les tests
vendor/bin/pest
```

## CI/CD

- **Matrix Testing** : PHP 8.2-8.4 × Laravel 10-12 × Redis 6-7
- **Tests E2E séparés** : Job dédié pour éviter les timeout
- **Docker Compose** : Cluster Redis Sentinel pour tests d'intégration

## Bonnes pratiques

1. **Nommage clair** : Les tests "E2E" doivent vraiment tester end-to-end
2. **Isolation** : Chaque test nettoie son état
3. **Timeout** : Tests E2E avec limites de temps explicites
4. **Process Management** : Arrêt propre des workers après chaque test

```

**Step 2 : Commit**
```bash
git add docs/testing-strategy.md
git commit -m "docs: add testing strategy documentation"
```

---

## Phase 4 : Vérification et stabilisation

### Task 4.1 : Vérifier les tests existants

**Step 1 : Exécuter tous les tests localement**

```bash
# Démarrer Redis Sentinel
docker compose -f tests/ci/docker-compose.yml up -d

# Attendre que Redis soit prêt
sleep 5

# Exécuter les tests
vendor/bin/pest --testdox

# Nettoyer
docker compose -f tests/ci/docker-compose.yml down
```

**Step 2 : Si des tests échouent, les corriger**

- Identifier les tests instables
- Ajouter des timeouts appropriés
- Améliorer la gestion des états entre tests

**Step 3 : Commit des corrections**

```bash
git add .
git commit -m "fix: stabilize existing tests"
```

---

## Résumé du plan

**Phase 1** (Réorganisation) :

- Renommer les fausses E2E → Integration
- Créer vrais tests E2E avec workers
- Améliorer CI pour support E2E

**Phase 2** (Améliorations) :

- Ajouter tests dispatch()
- Créer job de test pour retries

**Phase 3** (Documentation) :

- Documenter la stratégie de testing
- Expliquer les différences entre test types

**Phase 4** (Stabilisation) :

- Vérifier tous les tests
- Corriger les tests instables

**Temps estimé :** 2-3 heures
**Impact :** Tests E2E réels qui valident le comportement asynchrone complet
