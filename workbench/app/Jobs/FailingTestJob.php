<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class FailingTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 1; // 1 second between retries

    public function __construct(
        public string $jobId,
        public int $failUntilAttempt = 2
    ) {}

    public function handle(): void
    {
        $attempt = $this->attempts();

        // Store attempt number
        Cache::put("failing_job:{$this->jobId}:attempt_{$attempt}", true, 3600);

        if ($attempt < $this->failUntilAttempt) {
            throw new \Exception("Intentional failure on attempt {$attempt}");
        }

        // Success on final attempt
        Cache::put("failing_job:{$this->jobId}:success", true, 3600);
    }
}
