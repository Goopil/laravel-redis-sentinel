<?php

namespace Goopil\LaravelRedisSentinel\Concerns;

use Illuminate\Support\Facades\Log;

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
        $channel = config('phpredis-sentinel.log.channel');

        $logger = $channel !== null
            ? Log::channel($channel)
            : Log::getLogger();

        $logger->{$level}(sprintf('[%s] %s',
            $this->getLogPrefix(),
            $message
        ), $context);
    }

    protected function getLogPrefix(): string
    {
        if ($this->logPrefix === null) {
            $this->logPrefix = class_basename(static::class);
        }

        return $this->logPrefix;
    }
}
