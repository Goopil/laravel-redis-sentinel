<?php

namespace Goopil\LaravelRedisSentinel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HorizonWorkerNotAlive
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly array $failedChecks,
    ) {}
}
