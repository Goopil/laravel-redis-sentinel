<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ProcessOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $orderId,
        public array $items = []
    ) {}

    public function handle(): void
    {
        // Store processed order in cache for test validation
        Cache::put("order:{$this->orderId}:processed", true, 3600);
        Cache::put("order:{$this->orderId}:items", $this->items, 3600);
        Cache::put("order:{$this->orderId}:timestamp", now()->timestamp, 3600);
    }

    /**
     * Get the tags that should be assigned to the job (for Horizon)
     */
    public function tags(): array
    {
        return ['orders', "order:{$this->orderId}"];
    }
}
