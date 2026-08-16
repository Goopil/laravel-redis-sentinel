<?php

use Goopil\LaravelRedisSentinel\Commands\HorizonWorkerPreStop;
use Goopil\LaravelRedisSentinel\Exceptions\ConfigurationException;
use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Symfony\Component\Process\Process;

test('horizon:pre-stop returns non-zero when no pid found', function () {
    // We assume pcntl and posix are loaded in the test environment
    if (! extension_loaded('pcntl') || ! extension_loaded('posix')) {
        $this->expectException(ConfigurationException::class);
    }

    // Mock MasterSupervisorRepository to satisfy DI
    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->expects('all')->andReturn([]);
    app()->instance(MasterSupervisorRepository::class, $repository);

    $status = Artisan::call('horizon:pre-stop', ['--start-command' => 'non-existent-command-xyz']);
    expect($status)->toBe(1);
});

test('horizon:pre-stop does not match hostname as substring of another master', function () {
    if (! extension_loaded('pcntl') || ! extension_loaded('posix')) {
        $this->expectException(ConfigurationException::class);
    }

    $realHostname = gethostname();

    $otherMaster = new stdClass;
    $otherMaster->name = $realHostname.'0:1';
    $otherMaster->pid = 99999;
    $otherMaster->status = 'running';

    $ourMaster = new stdClass;
    $ourMaster->name = $realHostname.':1';
    $ourMaster->pid = 88888;
    $ourMaster->status = 'running';

    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->expects('all')->andReturn([$otherMaster, $ourMaster]);
    app()->instance(MasterSupervisorRepository::class, $repository);

    $command = new class extends HorizonWorkerPreStop
    {
        public array $targetedPids = [];

        public function info($string, $verbosity = null)
        {
            if (preg_match('/(\d+)/', $string, $m)) {
                $this->targetedPids[] = (int) $m[1];
            }
        }

        public function error($string, $verbosity = null, $stderr = null)
        {
            if (preg_match('/\{(\d+)\}/', $string, $m)) {
                $this->targetedPids[] = (int) $m[1];
            }
        }
    };

    app()->bind(HorizonWorkerPreStop::class, fn () => $command);

    Artisan::call('horizon:pre-stop', ['--start-command' => 'non-existent-command-xyz']);

    expect($command->targetedPids)->toContain(88888)
        ->and($command->targetedPids)->not->toContain(99999);
});

test('horizon:pre-stop builds pgrep process safely', function () {
    $command = new class extends HorizonWorkerPreStop
    {
        public function build(string $startCommand, int $timeout): Process
        {
            return $this->buildPgrepProcess($startCommand, $timeout);
        }
    };

    $process = $command->build('php artisan horizon; rm -rf /', 5);
    $commandLine = $process->getCommandLine();

    expect($commandLine)->toContain('pgrep')
        ->and($commandLine)->toContain('-x')
        ->and($commandLine)->toContain('-f')
        ->and($commandLine)->toContain("'php artisan horizon; rm -rf /'");
});
