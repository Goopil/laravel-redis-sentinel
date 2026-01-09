<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class HorizonTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $jobId,
        public array $metadata = [],
        public ?string $queueName = null,
        public ?int $delaySeconds = null
    ) {
        if ($queueName) {
            $this->onQueue($queueName);
        }

        if ($delaySeconds) {
            $this->delay($delaySeconds);
        }
    }

    public function handle(): void
    {
        // Store job execution data
        Cache::put("horizon:job:{$this->jobId}:executed", true, 3600);
        Cache::put("horizon:job:{$this->jobId}:metadata", $this->metadata, 3600);
        Cache::put("horizon:job:{$this->jobId}:queue", $this->queueName ?? 'default', 3600);
        Cache::put("horizon:job:{$this->jobId}:attempts", $this->attempts(), 3600);
        Cache::put("horizon:job:{$this->jobId}:timestamp", now()->timestamp, 3600);

        // Simulate some processing time
        usleep(100000); // 100ms
    }

    /**
     * Get the tags that should be assigned to the job.
     * This is crucial for Horizon's tagging and monitoring features.
     */
    public function tags(): array
    {
        $tags = ['horizon-test', "job:{$this->jobId}"];

        // Add custom tags from metadata
        if (isset($this->metadata['tags'])) {
            $tags = array_merge($tags, $this->metadata['tags']);
        }

        // Add user tag if present
        if (isset($this->metadata['user_id'])) {
            $tags[] = "user:{$this->metadata['user_id']}";
        }

        // Add priority tag if present
        if (isset($this->metadata['priority'])) {
            $tags[] = "priority:{$this->metadata['priority']}";
        }

        return $tags;
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(5);
    }

    /**
     * The middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [];
    }
}
