<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ChainableJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $step,
        public string $chainId
    ) {}

    public function handle(): void
    {
        // Record the execution of this step in the chain
        $executedSteps = Cache::get("chain:{$this->chainId}:steps", []);
        $executedSteps[] = [
            'step' => $this->step,
            'timestamp' => now()->timestamp,
        ];
        Cache::put("chain:{$this->chainId}:steps", $executedSteps, 3600);

        // Mark this specific step as completed
        Cache::put("chain:{$this->chainId}:step:{$this->step}", true, 3600);
    }

    /**
     * Get the tags that should be assigned to the job (for Horizon)
     */
    public function tags(): array
    {
        return ['chains', "chain:{$this->chainId}"];
    }
}
