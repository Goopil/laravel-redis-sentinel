<?php

namespace Goopil\LaravelRedisSentinel\Commands;

use Goopil\LaravelRedisSentinel\Concerns\Loggable;
use Goopil\LaravelRedisSentinel\Exceptions\ConfigurationException;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Console\Command;
use Laravel\Horizon\Contracts\MasterSupervisorRepository as MasterSupervisorRepositoryAlias;
use Symfony\Component\Process\Process;

class HorizonWorkerPreStop extends Command
{
    use Loggable;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "horizon:pre-stop
                            {--wait : Wait for all workers to terminate}
                            {--start-command='php artisan horizon'}
                            {--timeout=60}
                            ";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Terminate horizon the master supervisor on one machine only';

    /**
     * Execute the console command.
     */
    public function handle(
        ConfigRepository $config,
        CacheManager $cache,
        MasterSupervisorRepositoryAlias $masters
    ): int {
        if (! extension_loaded('pcntl') || ! extension_loaded('posix')) {
            throw new ConfigurationException('pcntl & posix extension are required to run this command');
        }

        if ($config->get('horizon.fast_termination')) {
            $cache->forever(
                'horizon:terminate:wait', $this->option('wait')
            );
        }

        $startCommand = $this->option('start-command');

        $pid = collect($masters->all())
            ->filter(fn ($master) => str_contains($master->name, gethostname()))
            ->map(fn ($master) => (int) ($master->pid ?? 0))
            ->filter()
            ->first();

        if (! $pid) {
            $process = $this->buildPgrepProcess(
                $startCommand,
                (int) $this->option('timeout')
            );

            $process->run();
            $pid = (int) $process->getOutput();
        }

        if ($pid) {
            $this->info(sprintf(
                'Sending TERM Signal To Process: %s',
                $pid
            ));

            if (! posix_kill($pid, SIGTERM)) {
                $this->error(
                    sprintf(
                        'Failed to kill command:%s with process: {%s} (%s)',
                        $startCommand,
                        $pid,
                        posix_strerror(posix_get_last_error())
                    )
                );
            } else {
                $this->info(
                    sprintf(
                        'Killed command:%s with process: {%s}',
                        $startCommand,
                        $pid,
                    )
                );
            }
        } else {
            $this->error(sprintf(
                'failed to find command %s pid',
                $startCommand
            ));
        }

        return 0;
    }

    protected function buildPgrepProcess(string $startCommand, int $timeout): Process
    {
        return new Process(['pgrep', '-x', '-f', $startCommand], timeout: $timeout);
    }
}
