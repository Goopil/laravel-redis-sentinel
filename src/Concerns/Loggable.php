<?php

namespace Goopil\LaravelRedisSentinel\Concerns;

use Illuminate\Support\Facades\Log;
use Throwable;

trait Loggable
{
    protected ?string $logPrefix = null;

    /**
     * Log a message with context.
     *
     * If 'phpredis-sentinel.log.channel' is null, Log::channel(null) returns
     * the default logging channel. This is intentional and allows falling back
     * to the application's default log channel when no specific channel is configured.
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
        } catch (Throwable) {
            // Logging must never break the retry path (e.g. a Redis-backed log
            // channel failing while Redis itself is down).
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
