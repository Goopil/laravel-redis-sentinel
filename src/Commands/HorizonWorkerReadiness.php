<?php

namespace Goopil\LaravelRedisSentinel\Commands;

use Goopil\LaravelRedisSentinel\Concerns\EmitsWorkerEvents;
use Goopil\LaravelRedisSentinel\Concerns\Loggable;
use Goopil\LaravelRedisSentinel\Events\HorizonWorkerNotReady;
use Goopil\LaravelRedisSentinel\Events\HorizonWorkerReady;
use Illuminate\Console\Command;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;

class HorizonWorkerReadiness extends Command
{
    use EmitsWorkerEvents;
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
            ->filter(fn ($master) => str_starts_with($master->name, MasterSupervisor::basename().'-') &&
                $master->status === 'running'
            );

        if ($result->count() === 1) {
            $this->emitSuccessEvent(new HorizonWorkerReady);

            return 0;
        }

        $all = $masters->all();

        $this->log(
            ' current master is not ready',
            [
                'current' => $result->toArray(),
                'masters' => $all,
            ]
        );

        $this->emitFailureEvent(new HorizonWorkerNotReady(
            masters: $all,
            running: $result->values()->all(),
        ));

        return 1;
    }
}
