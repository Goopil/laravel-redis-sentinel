<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 5;

    public function __construct(
        public string $email,
        public string $subject,
        public string $message,
        public bool $shouldFail = false
    ) {}

    public function handle(): void
    {
        // Simulate failure for retry testing
        if ($this->shouldFail && $this->attempts() < $this->tries) {
            Cache::increment("email:{$this->email}:attempts");
            throw new \Exception('Simulated email sending failure');
        }

        // Store sent email in cache for test validation
        Cache::put("email:{$this->email}:sent", true, 3600);
        Cache::put("email:{$this->email}:subject", $this->subject, 3600);
        Cache::put("email:{$this->email}:message", $this->message, 3600);
        Cache::put("email:{$this->email}:attempts", $this->attempts(), 3600);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Cache::put("email:{$this->email}:failed", true, 3600);
        Cache::put("email:{$this->email}:error", $exception->getMessage(), 3600);
    }

    /**
     * Get the tags that should be assigned to the job (for Horizon)
     */
    public function tags(): array
    {
        return ['emails', "email:{$this->email}"];
    }
}
