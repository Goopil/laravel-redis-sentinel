<?php

namespace Goopil\LaravelRedisSentinel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HorizonWorkerNotReady
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<int, object>  $masters
     * @param  array<int, object>  $running
     */
    public function __construct(
        public readonly array $masters,
        public readonly array $running,
    ) {}
}
