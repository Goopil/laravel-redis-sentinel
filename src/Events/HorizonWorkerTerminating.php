<?php

namespace Goopil\LaravelRedisSentinel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HorizonWorkerTerminating
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $pid,
        public readonly string $startCommand,
    ) {}
}
