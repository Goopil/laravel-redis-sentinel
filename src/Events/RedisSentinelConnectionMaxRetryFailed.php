<?php

namespace Goopil\LaravelRedisSentinel\Events;

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RedisSentinelConnectionMaxRetryFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly RedisSentinelConnection $connection,
        public readonly Throwable $exception,
        public readonly string $context,
        public readonly ?int $attempts = 0,
    ) {}
}
