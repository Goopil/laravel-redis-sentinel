<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class BatchableJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $testBatchId,
        public int $itemNumber,
        public bool $shouldFail = false
    ) {}

    public function handle(): void
    {
        // Check if the batch has been cancelled
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Simulate failure for testing batch failure handling
        if ($this->shouldFail) {
            throw new \Exception("Simulated failure for item {$this->itemNumber}");
        }

        // Record the processing of this item
        $processedItems = Cache::get("batch:{$this->testBatchId}:processed", []);
        $processedItems[] = $this->itemNumber;
        Cache::put("batch:{$this->testBatchId}:processed", $processedItems, 3600);

        // Mark this specific item as completed
        Cache::put("batch:{$this->testBatchId}:item:{$this->itemNumber}", true, 3600);
    }

    /**
     * Get the tags that should be assigned to the job (for Horizon)
     */
    public function tags(): array
    {
        return ['batches', "batch:{$this->testBatchId}"];
    }
}
