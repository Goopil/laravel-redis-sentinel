<?php

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
            '--max-time='.$timeout,
            '--max-jobs=100',
            '--sleep=1',
            '--tries=3',
            '--stop-when-empty',
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
            '--max-time='.$timeout,
        ]);

        $process->setWorkingDirectory(base_path());
        $process->setTimeout($timeout + 10);
        $process->start();

        $this->processes[] = $process;

        // Wait for Horizon to initialize
        sleep(3);

        Log::info('Horizon supervisor started');

        return $process;
    }

    public function waitForJobs(int $timeout = 30): bool
    {
        $start = time();

        while (time() - $start < $timeout) {
            // Check if all processes are still running
            foreach ($this->processes as $process) {
                if (! $process->isRunning()) {
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
