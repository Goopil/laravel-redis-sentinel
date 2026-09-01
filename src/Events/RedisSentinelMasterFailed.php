<?php

namespace Goopil\LaravelRedisSentinel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

class RedisSentinelMasterFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $service,
        public readonly Throwable $exception,
        public readonly string $context,
        public readonly int $attempts = 0,
    ) {}
}
