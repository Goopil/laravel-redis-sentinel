<?php

namespace Goopil\LaravelRedisSentinel\Events;

use Illuminate\Foundation\Events\Dispatchable;

class RedisSentinelMasterReconnected
{
    use Dispatchable;

    public function __construct(
        public readonly string $service,
        public readonly string $context,
        public readonly int $attempts = 0,
    ) {}
}
