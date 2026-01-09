<?php

namespace Goopil\LaravelRedisSentinel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RedisSentinelMasterReconnected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $service,
        public readonly string $context,
        public readonly int $attempts = 0,
    ) {}
}
