<?php

namespace Goopil\LaravelRedisSentinel\Commands;

use Goopil\LaravelRedisSentinel\Concerns\EmitsWorkerEvents;
use Goopil\LaravelRedisSentinel\Concerns\Loggable;
use Goopil\LaravelRedisSentinel\Events\HorizonWorkerTerminateFailed;
use Goopil\LaravelRedisSentinel\Events\HorizonWorkerTerminating;
use Goopil\LaravelRedisSentinel\Exceptions\ConfigurationException;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Console\Command;
use Laravel\Horizon\Contracts\MasterSupervisorRepository as MasterSupervisorRepositoryAlias;
use Symfony\Component\Process\Process;

class HorizonWorkerPreStop extends Command
{
    use EmitsWorkerEvents;
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
            ->filter(fn ($master) => str_starts_with($master->name, gethostname().':'))
            ->map(fn ($master) => (int) ($master->pid ?? 0))
            ->filter()
            ->first();

        if (! $pid) {
            $process = $this->buildPgrepProcess(
                $startCommand,
                (int) $this->option('timeout')
            );

            $process->run();
            $output = trim($process->getOutput());
            $pids = array_filter(explode("\n", $output), fn ($line) => ctype_digit(trim($line)));

            if (! empty($pids)) {
                $pid = (int) trim($pids[0]);
            }
        }

        if ($pid) {
            $this->info(sprintf(
                'Sending TERM Signal To Process: %s',
                $pid
            ));

            if (! posix_kill($pid, SIGTERM)) {
                $error = posix_strerror(posix_get_last_error());

                $this->error(
                    sprintf(
                        'Failed to kill command:%s with process: {%s} (%s)',
                        $startCommand,
                        $pid,
                        $error
                    )
                );

                $this->emitFailureEvent(new HorizonWorkerTerminateFailed(
                    startCommand: $startCommand,
                    pid: $pid,
                    reason: $error,
                ));
            } else {
                $this->info(
                    sprintf(
                        'Killed command:%s with process: {%s}',
                        $startCommand,
                        $pid,
                    )
                );

                $this->emitSuccessEvent(new HorizonWorkerTerminating(
                    pid: $pid,
                    startCommand: $startCommand,
                ));
            }
        } else {
            $this->error(sprintf(
                'failed to find command %s pid',
                $startCommand
            ));

            $this->emitFailureEvent(new HorizonWorkerTerminateFailed(
                startCommand: $startCommand,
                pid: null,
                reason: sprintf('failed to find command %s pid', $startCommand),
            ));

            return 1;
        }

        return 0;
    }

    protected function buildPgrepProcess(string $startCommand, int $timeout): Process
    {
        return new Process(['pgrep', '-f', $startCommand], timeout: $timeout);
    }
}
