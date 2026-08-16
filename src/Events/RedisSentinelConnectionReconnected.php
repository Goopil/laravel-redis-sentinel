<?php

namespace Goopil\LaravelRedisSentinel\Events;

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Illuminate\Foundation\Events\Dispatchable;

class RedisSentinelConnectionReconnected
{
    use Dispatchable;

    public function __construct(
        public readonly RedisSentinelConnection $connection,
        public readonly string $context,
        public readonly int $attempts = 0,
    ) {}
}
