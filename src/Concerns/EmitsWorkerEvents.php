<?php

namespace Goopil\LaravelRedisSentinel\Concerns;

trait EmitsWorkerEvents
{
    protected function emitSuccessEvent(object $event): void
    {
        if (config('phpredis-sentinel.commands.events.emit_success') === true) {
            event($event);
        }
    }

    protected function emitFailureEvent(object $event): void
    {
        event($event);
    }
}
