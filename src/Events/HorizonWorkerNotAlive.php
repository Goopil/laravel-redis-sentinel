<?php

namespace Goopil\LaravelRedisSentinel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HorizonWorkerNotAlive
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, int>  $failedChecks
     */
    public function __construct(
        public readonly array $failedChecks,
    ) {}
}
