<?php

namespace Goopil\LaravelRedisSentinel\Events;

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RedisSentinelConnectionReconnected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly RedisSentinelConnection $connection,
        public readonly string $context,
        public readonly int $attempts = 0,
    ) {}
}
