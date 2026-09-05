<?php

namespace Goopil\LaravelRedisSentinel\Concerns;

use Illuminate\Support\Facades\Log;
use Throwable;

trait Loggable
{
    protected ?string $logPrefix = null;

    /**
     * Static properties on traits are copied into each consuming class, so this
     * flag is once per consuming class per process, not once globally.
     */
    private static bool $swallowedLogNotified = false;

    /**
     * Log a message with context.
     *
     * If 'phpredis-sentinel.log.channel' is null, Log::channel(null) returns
     * the default logging channel. This is intentional and allows falling back
     * to the application's default log channel when no specific channel is configured.
     *
     * @param  array<string, mixed>  $context
     */
    protected function log(string $message, array $context = [], string $level = 'info'): void
    {
        try {
            $channel = config('phpredis-sentinel.log.channel');

            $logger = $channel !== null
                ? Log::channel($channel)
                : Log::getLogger();

            $logger->{$level}(sprintf('[%s] %s',
                $this->getLogPrefix(),
                $message
            ), $context);
        } catch (Throwable $e) {
            // Logging must never break the retry path (e.g. a Redis-backed log
            // channel failing while Redis itself is down).
            if (! self::$swallowedLogNotified && config('phpredis-sentinel.log.notify_swallowed', false)) {
                self::$swallowedLogNotified = true;

                error_log(sprintf(
                    '[laravel-redis-sentinel] logging is failing (%s); retry/failover telemetry may be dropped. Original message: %s',
                    $e->getMessage(),
                    $message
                ));
            }

            // One retry against a channel that does not depend on the failing
            // backend, unless stderr itself is the channel that just failed.
            try {
                if (config('phpredis-sentinel.log.channel') !== 'stderr') {
                    Log::channel('stderr')->{$level}(sprintf('[%s] %s', $this->getLogPrefix(), $message), $context);
                }
            } catch (Throwable) {
                // Nothing left to try — the swallow contract stands.
            }
        }
    }

    /**
     * Dispatch an event without ever breaking the caller: a throwing user listener
     * must not abort a retry loop or a failover path. Failures are logged (swallow
     * contract applies) and the flow continues.
     */
    protected function dispatchSafely(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            // A throwing listener is a real bug: feed the exception handler as well,
            // best-effort, then warn on the swallow-safe channel.
            try {
                report($e);
            } catch (Throwable) {
            }

            $this->log('event listener failed', ['event' => get_class($event), 'error' => $e->getMessage()], 'error');
        }
    }

    protected function getLogPrefix(): string
    {
        if ($this->logPrefix === null) {
            $this->logPrefix = class_basename(static::class);
        }

        return $this->logPrefix;
    }
}
