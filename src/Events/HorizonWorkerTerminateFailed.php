<?php

namespace Goopil\LaravelRedisSentinel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HorizonWorkerTerminateFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $startCommand,
        public readonly ?int $pid,
        public readonly string $reason,
    ) {}
}
