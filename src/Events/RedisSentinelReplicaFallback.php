<?php

namespace Goopil\LaravelRedisSentinel\Events;

use Illuminate\Foundation\Events\Dispatchable;

class RedisSentinelReplicaFallback
{
    use Dispatchable;

    /**
     * @param  array<int, array<string, mixed>>  $replicas  The Sentinel-reported replica entries, all unhealthy.
     */
    public function __construct(
        public readonly string $service,
        public readonly array $replicas = [],
    ) {}
}
