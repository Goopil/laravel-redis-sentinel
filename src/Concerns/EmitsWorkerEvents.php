<?php

namespace Goopil\LaravelRedisSentinel\Concerns;

use Throwable;

trait EmitsWorkerEvents
{
    protected function emitSuccessEvent(object $event): void
    {
        // env() keeps "1" as a string, so parse the value instead of comparing to true.
        if (filter_var(config('phpredis-sentinel.commands.events.emit_success'), FILTER_VALIDATE_BOOLEAN)) {
            // Kubernetes probes must keep their exit code even if a listener throws.
            try {
                event($event);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    protected function emitFailureEvent(object $event): void
    {
        // Kubernetes probes must keep their exit code even if a listener throws.
        try {
            event($event);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
