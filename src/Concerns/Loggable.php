<?php

namespace Goopil\LaravelRedisSentinel\Concerns;

use Illuminate\Support\Facades\Log;
use Throwable;

trait Loggable
{
    protected ?string $logPrefix = null;

    protected function log(string $message, array $context = [], string $level = 'info'): void
    {
        try {
            Log::channel(config('phpredis-sentinel.log.channel'))
                ->{$level}(sprintf('[%s] %s',
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
