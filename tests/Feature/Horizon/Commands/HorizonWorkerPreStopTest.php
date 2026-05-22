<?php

use Goopil\LaravelRedisSentinel\Commands\HorizonWorkerPreStop;
use Goopil\LaravelRedisSentinel\Exceptions\ConfigurationException;
use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Symfony\Component\Process\Process;

test('horizon:pre-stop returns 0 even if no pid found', function () {
    // We assume pcntl and posix are loaded in the test environment
    if (! extension_loaded('pcntl') || ! extension_loaded('posix')) {
        $this->expectException(ConfigurationException::class);
    }

    // Mock MasterSupervisorRepository to satisfy DI
    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->expects('all')->andReturn([]);
    app()->instance(MasterSupervisorRepository::class, $repository);

    $status = Artisan::call('horizon:pre-stop', ['--start-command' => 'non-existent-command-xyz']);
    expect($status)->toBe(0);
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
