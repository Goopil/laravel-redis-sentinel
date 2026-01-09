<?php

namespace Goopil\LaravelRedisSentinel\Commands;

use Goopil\LaravelRedisSentinel\Concerns\Loggable;
use Illuminate\Console\Command;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

class HorizonWorkerReadiness extends Command
{
    use Loggable;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'horizon:ready';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Readiness checks for a worker operating horizon';

    /**
     * Execute the console command.
     */
    public function handle(MasterSupervisorRepository $masters): int
    {
        $result = collect($masters->all())
            ->filter(fn ($master) => str_contains($master->name, gethostname()) &&
                $master->status === 'running'
            );

        if ($result->count() === 1) {
            return 0;
        }

        $this->log(
            ' current master is not ready',
            [
                'current' => $result->toArray(),
                'masters' => $masters->all(),
            ]
        );

        return 1;
    }
}
